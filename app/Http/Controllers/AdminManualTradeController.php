<?php

namespace App\Http\Controllers;

use App\Models\BinanceAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Admin manual-trade console — the browser-side replacement for the engine's
 * Tkinter tester. Admin-only (`admin` middleware).
 *
 * The webhook secret NEVER reaches the browser: the frontend posts an intent
 * (exchange, action, symbol, recipients, target key) and this controller signs
 * and forwards it to the engine. Admins choose a target *key*, never a URL, so
 * the proxy can only ever reach hosts configured in services.engine.targets.
 */
class AdminManualTradeController extends Controller
{
    /** Only Binance has a live engine today (bybit/mexc land with their tables). */
    private const WEBHOOK_PATHS = ['binance' => '/binance_abcd_webhook'];

    private const MAX_INCREMENTS = 20;

    /** Resolve a target key to its configured base URL, or null when unknown. */
    private function targetUrl(string $key): ?string
    {
        $url = config("services.engine.targets.{$key}");

        return is_string($url) && $url !== '' ? rtrim($url, '/') : null;
    }

    /**
     * GET /api/admin/manual-trade/targets?exchange=binance
     *
     * Users the engine would actually trade, grouped per user — same filters as
     * the engine's own accounts endpoint (enabled, not deleted, not sandbox,
     * owner not suspended), so what the admin sees is what the fan-out hits.
     */
    public function targets(Request $request): JsonResponse
    {
        $exchange = (string) $request->query('exchange', 'binance');
        if (! isset(self::WEBHOOK_PATHS[$exchange])) {
            return response()->json([
                'success' => false,
                'error_code' => 'EXCHANGE_NOT_SUPPORTED',
                'message' => "Exchange '{$exchange}' has no engine yet.",
            ], 400);
        }

        $accounts = BinanceAccount::query()
            ->join('user_credentials', 'user_credentials.uni_id', '=', 'binance_accounts.uni_id')
            ->where('binance_accounts.enabled', 1)
            ->where('binance_accounts.is_sandbox', 0)
            ->where('user_credentials.status', '!=', 'suspended')
            ->orderBy('user_credentials.name')
            ->get([
                'binance_accounts.uni_id',
                'binance_accounts.demo',
                'binance_accounts.balance',
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
                'account_count' => $rows->count(),
                'demo_count' => $rows->where('demo', 1)->count(),
                'live_count' => $rows->where('demo', 0)->count(),
                'balance' => (float) $rows->sum('balance'),
            ])
            ->values();

        return response()->json(['success' => true, 'targets' => $targets]);
    }

    /**
     * GET /api/admin/manual-trade/engine?target=local — is the engine reachable?
     * Proxied so the browser never has to talk to the engine host directly.
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

        try {
            $response = Http::timeout(5)->get("{$base}/health");
        } catch (\Throwable $e) {
            return response()->json([
                'success' => true,
                'engine' => ['reachable' => false, 'url' => $base, 'error' => $e->getMessage()],
            ]);
        }

        return response()->json([
            'success' => true,
            'engine' => [
                'reachable' => $response->successful(),
                'url' => $base,
                'health' => $response->successful() ? $response->json() : null,
            ],
        ]);
    }

    /**
     * POST /api/admin/manual-trade/send
     *
     * Signs the payload with the engine's webhook secret and forwards it.
     * `increments` sends the same signal N times (each one stacks another
     * base_size on every recipient) — entries only; exits close everything at
     * once so repeating them is pointless.
     */
    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'exchange' => ['required', 'string', 'in:binance'],
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

        $secret = (string) config('services.engine.webhook_secret');
        if ($secret === '') {
            return response()->json([
                'success' => false,
                'error_code' => 'ENGINE_WEBHOOK_SECRET_MISSING',
                'message' => 'ENGINE_WEBHOOK_SECRET is not configured on the API.',
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

        $url = $base.self::WEBHOOK_PATHS[$data['exchange']];
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
