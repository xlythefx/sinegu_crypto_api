<?php

namespace App\Http\Controllers;

use App\Models\UserCredential;
use App\Services\Engine\WebhookPaths;
use App\Services\Exchanges\ExchangeSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;

/**
 * Admin manual-trade console — the browser-side replacement for the engine's
 * Tkinter tester. Admin-only (`admin` middleware).
 *
 * The webhook secret NEVER reaches the browser: the frontend posts an intent
 * (exchange, action, symbol, recipients, target key) and this controller signs
 * and forwards it to the engine. The console only ever sees a masked copy for
 * its payload preview. Admins choose a target *key*, never a URL, so the proxy
 * can only ever reach hosts configured in services.engine.targets.
 *
 * Every call takes a `feed` (main | test, default main). One engine, one
 * webhook path per venue PER FEED (App\Services\Engine\WebhookPaths): the
 * path decides which venue's accounts trade the signal AND which feed it is.
 * `main` reaches every non-tester account with TradingView's secret; `test`
 * reaches tester-owned accounts only, with the test feed's own secret.
 */
class AdminManualTradeController extends Controller
{
    private const MAX_INCREMENTS = 20;

    /** `?feed=` / body `feed`: main | test, absent = main; null for anything else. */
    private function feed(mixed $value): ?string
    {
        $feed = $value === null || $value === '' ? 'main' : $value;

        return WebhookPaths::isFeed($feed) ? $feed : null;
    }

    private function feedRejected(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error_code' => 'FEED_NOT_SUPPORTED',
            'message' => 'Unknown signal feed — use main or test.',
        ], 400);
    }

    /** Resolve a target key to its configured base URL, or null when unknown. */
    private function targetUrl(string $key): ?string
    {
        $url = config("services.engine.targets.{$key}");

        return is_string($url) && $url !== '' ? rtrim($url, '/') : null;
    }

    /**
     * The webhook token for one exchange on one feed: the main feed's per-venue
     * token (e.g. BINANCE_ENGINE_WEBHOOK_SECRET), or the test feed's single
     * ENGINE_TEST_WEBHOOK_SECRET — never one standing in for the other.
     */
    private function webhookSecret(string $exchange, string $feed = 'main'): string
    {
        return $feed === 'test'
            ? (string) config('services.engine.test_webhook_secret')
            : (string) config("services.engine.webhook_secrets.{$exchange}");
    }

    /**
     * Middle-masked secret for the console's payload preview: enough to eyeball
     * a mismatch against the engine's .env, useless to anyone who scrapes it.
     * The full value never leaves the server.
     */
    private function maskSecret(string $secret): ?string
    {
        if ($secret === '') {
            return null;
        }

        return strlen($secret) <= 12
            ? str_repeat('•', 10)
            : substr($secret, 0, 6).str_repeat('•', 10).substr($secret, -4);
    }

    /**
     * GET /api/admin/manual-trade/targets?exchange=binance&feed=main
     *
     * Users the engine would actually trade, grouped per user — same filters as
     * the engine's own accounts endpoint (enabled, not deleted, not sandbox,
     * owner not suspended), so what the admin sees is what the fan-out hits.
     * The feed narrows it the way the engine does: `main` = every owner except
     * testers, `test` = tester owners only.
     */
    public function targets(Request $request): JsonResponse
    {
        $exchange = (string) $request->query('exchange', 'binance');
        if (WebhookPaths::path($exchange, 'main') === null) {
            return response()->json([
                'success' => false,
                'error_code' => 'EXCHANGE_NOT_SUPPORTED',
                'message' => "Exchange '{$exchange}' has no engine yet.",
            ], 400);
        }
        $feed = $this->feed($request->query('feed'));
        if ($feed === null) {
            return $this->feedRejected();
        }

        // The venue's own accounts table — what its webhook path fans out to.
        $schema = ExchangeSchema::for($exchange);
        $t = $schema->accountsTable;
        $accounts = $schema->accountQuery()
            ->join('user_credentials', 'user_credentials.uni_id', '=', "{$t}.uni_id")
            ->where("{$t}.enabled", 1)
            ->where("{$t}.is_sandbox", 0)
            ->where('user_credentials.status', '!=', 'suspended')
            ->where('user_credentials.type', $feed === 'test' ? '=' : '!=', UserCredential::TESTER)
            ->orderBy('user_credentials.name')
            ->get([
                "{$t}.uni_id",
                "{$t}.demo",
                "{$t}.balance",
                'user_credentials.name as user_name',
                'user_credentials.email as user_email',
            ]);

        $targets = $accounts
            ->groupBy('uni_id')
            ->map(fn ($rows, $uniId) => [
                'uni_id' => $uniId,
                'display_name' => $rows->first()->user_name ?: 'Unnamed',
                'email' => $rows->first()->user_email,
                'exchange' => $exchange,
                'feed' => $feed,
                'account_count' => $rows->count(),
                'demo_count' => $rows->where('demo', 1)->count(),
                'live_count' => $rows->where('demo', 0)->count(),
                'balance' => (float) $rows->sum('balance'),
            ])
            ->values();

        return response()->json(['success' => true, 'targets' => $targets]);
    }

    /**
     * GET /api/admin/manual-trade/engine?target=local&exchange=binance&feed=main
     * Is the engine reachable? Proxied so the browser never has to talk to the
     * engine host directly.
     *
     * Also returns a *masked* webhook secret — the FEED's — so the console's
     * payload preview shows the shape of the real signal. The full token stays
     * server-side — copy it from CLAUDE.md when setting a TradingView alert up.
     * `test_feed_enabled` is the engine's own `/health` → `test_feed.enabled`
     * (null when the engine is unreachable or predates the test feed).
     */
    public function engineStatus(Request $request): JsonResponse
    {
        $key = (string) $request->query('target', 'local');
        $base = $this->targetUrl($key);
        if ($base === null) {
            return response()->json([
                'success' => false,
                'error_code' => 'UNKNOWN_TARGET',
                'message' => "No engine configured for target '{$key}'.",
            ], 400);
        }

        $exchange = (string) $request->query('exchange', 'binance');
        $feed = $this->feed($request->query('feed'));
        if ($feed === null) {
            return $this->feedRejected();
        }
        $masked = WebhookPaths::path($exchange, $feed) !== null
            ? $this->maskSecret($this->webhookSecret($exchange, $feed))
            : null;

        try {
            $response = Http::timeout(5)->get("{$base}/health");
        } catch (\Throwable $e) {
            return response()->json([
                'success' => true,
                'engine' => [
                    'reachable' => false,
                    'url' => $base,
                    'error' => $e->getMessage(),
                    'feed' => $feed,
                    'webhook_secret_masked' => $masked,
                    'test_feed_enabled' => null,
                ],
            ]);
        }

        $health = $response->successful() ? $response->json() : null;
        $testFeed = is_array($health) ? data_get($health, 'test_feed.enabled') : null;

        return response()->json([
            'success' => true,
            'engine' => [
                'reachable' => $response->successful(),
                'url' => $base,
                'health' => $health,
                'feed' => $feed,
                'webhook_secret_masked' => $masked,
                'test_feed_enabled' => is_bool($testFeed) ? $testFeed : null,
            ],
        ]);
    }

    /**
     * POST /api/admin/manual-trade/send
     *
     * Signs the payload with the FEED's webhook secret and forwards it to that
     * feed's path for the venue (`feed`: main | test, default main).
     * `increments` sends the same signal N times (each one stacks another
     * base_size on every recipient) — entries only; exits close everything at
     * once so repeating them is pointless.
     */
    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            // Every venue this engine has a webhook path for — NOT a second
            // hardcoded list. Until 2026-09-24 this said `in:binance`, so
            // targets()/engineStatus() offered MEXC while send() refused it.
            'exchange' => ['required', 'string', Rule::in(WebhookPaths::exchanges())],
            'feed' => ['nullable', 'string', Rule::in(WebhookPaths::FEEDS)],
            'target' => ['required', 'string', 'in:local,prod'],
            'action' => ['required', 'string', 'in:BUY,SELL,EXIT_LONG,EXIT_SHORT'],
            'symbol' => ['required', 'string', 'max:20'],
            'price' => ['nullable', 'numeric'],
            'leverage' => ['nullable', 'integer', 'between:1,125'],
            'strategy' => ['nullable', 'string', 'max:100'],
            'increments' => ['nullable', 'integer', 'between:1,'.self::MAX_INCREMENTS],
            'target_uni_ids' => ['nullable', 'array'],
            'target_uni_ids.*' => ['string', 'max:36'],
        ]);

        $feed = $data['feed'] ?? 'main';
        $secret = $this->webhookSecret($data['exchange'], $feed);
        if ($secret === '' && $feed === 'test') {
            return response()->json([
                'success' => false,
                'error_code' => 'ENGINE_TEST_WEBHOOK_SECRET_MISSING',
                'message' => 'ENGINE_TEST_WEBHOOK_SECRET is not configured on the API.',
            ], 503);
        }
        if ($secret === '') {
            $envVar = strtoupper($data['exchange']).'_ENGINE_WEBHOOK_SECRET';

            return response()->json([
                'success' => false,
                'error_code' => 'ENGINE_WEBHOOK_SECRET_MISSING',
                'message' => "{$envVar} is not configured on the API.",
            ], 503);
        }

        $base = $this->targetUrl($data['target']);
        if ($base === null) {
            return response()->json([
                'success' => false,
                'error_code' => 'UNKNOWN_TARGET',
                'message' => "No engine configured for target '{$data['target']}'.",
            ], 400);
        }

        $url = $base.WebhookPaths::path($data['exchange'], $feed);
        $isEntry = in_array($data['action'], ['BUY', 'SELL'], true);
        $increments = $isEntry ? (int) ($data['increments'] ?? 1) : 1;

        $payload = array_filter([
            'secret' => $secret,
            'action' => $data['action'],
            'symbol' => strtoupper($data['symbol']),
            'price' => $data['price'] ?? null,
            'leverage' => $data['leverage'] ?? null,
            'strategy' => $data['strategy'] ?? null,
            'target_uni_ids' => ! empty($data['target_uni_ids']) ? array_values($data['target_uni_ids']) : null,
        ], fn ($v) => $v !== null);

        $sent = 0;
        $failed = 0;
        $responses = [];

        for ($i = 0; $i < $increments; $i++) {
            try {
                $response = Http::timeout(20)->post($url, $payload);
                $body = $response->json() ?? ['raw' => substr((string) $response->body(), 0, 500)];
                $responses[] = ['status' => $response->status(), 'body' => $body];
                $response->successful() ? $sent++ : $failed++;
            } catch (\Throwable $e) {
                $failed++;
                $responses[] = ['status' => null, 'body' => ['error' => $e->getMessage()]];
            }
            if ($i < $increments - 1) {
                usleep(150_000);  // let the engine's dispatch pool breathe
            }
        }

        // The engine fast-ACKs: "sent" means accepted for execution, not filled.
        // Results per account land in trade_logs a moment later.
        return response()->json([
            'success' => $sent > 0,
            'feed' => $feed,
            'sent' => $sent,
            'failed' => $failed,
            'increments' => $increments,
            'url' => $url,
            'responses' => $responses,
            'message' => $sent > 0
                ? "Accepted {$sent} of {$increments} signal(s). Execution runs in the background."
                : 'The engine rejected every signal — check that it is running and the secret matches.',
        ], $sent > 0 ? 200 : 502);
    }
}
