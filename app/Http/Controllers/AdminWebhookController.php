<?php

namespace App\Http\Controllers;

use App\Models\UserCredential;
use App\Services\Engine\WebhookPaths;
use App\Services\EngineCache;
use App\Services\Exchanges\ExchangeSchema;
use Illuminate\Http\JsonResponse;

/**
 * Admin → Webhooks: every webhook the engine serves (one per venue per signal
 * feed), what posting to it would reach, and whether the engine has it on.
 * Full admin only (`admin` middleware) — not the collaborator `staff` group.
 *
 * Read-only and cheap: the paths come from WebhookPaths, the engine facts from
 * its `/health` (EngineCache::health, cached 30 s), the receiver counts from
 * the accounts tables. Nothing here calls an exchange.
 *
 * Secrets are reported as PRESENCE only (`main_set` / `test_set`) — never a
 * value, not even a masked one: this page answers "is it configured", and the
 * manual-trade console is the one place a masked token is shown.
 */
class AdminWebhookController extends Controller
{
    /**
     * GET /api/admin/webhooks
     *
     * `webhooks[]`, main feed first then test, venues in WebhookPaths order:
     *  - `venue_live` — the engine trades this venue (`/health` → `exchanges`);
     *    null when the engine did not answer.
     *  - `enabled` — the engine's own verdict on this path (`/health` →
     *    `webhooks[]`, matched on exchange + feed); null when the engine did
     *    not answer or predates that key, false when it answers without
     *    listing the path (it does not serve it).
     *  - `receivers` — accounts a signal on this path would fan out to, split
     *    live / demo, with the engine accounts endpoint's own filters.
     */
    public function index(EngineCache $engine): JsonResponse
    {
        $health = $engine->health();
        $reachable = $health !== null;

        $traded = $reachable && is_array($health['exchanges'] ?? null)
            ? array_map('strval', array_keys($health['exchanges']))
            : null;
        $engineHooks = $reachable && is_array($health['webhooks'] ?? null) ? $health['webhooks'] : null;
        $testFeed = $reachable ? data_get($health, 'test_feed.enabled') : null;

        $receivers = $this->receivers();

        $webhooks = [];
        foreach (WebhookPaths::FEEDS as $feed) {
            foreach (WebhookPaths::exchanges() as $exchange) {
                $webhooks[] = [
                    'exchange' => $exchange,
                    'feed' => $feed,
                    'path' => WebhookPaths::path($exchange, $feed),
                    'venue_live' => $traded === null ? null : in_array($exchange, $traded, true),
                    'enabled' => $this->engineEnabled($engineHooks, $exchange, $feed),
                    'receivers' => $receivers[$exchange][$feed],
                ];
            }
        }

        return response()->json([
            'success' => true,
            'engine' => [
                'reachable' => $reachable,
                'test_feed_enabled' => is_bool($testFeed) ? $testFeed : null,
            ],
            'bases' => [
                'prod' => (string) config('services.engine.public_webhook_base', 'https://pixel-alpha.com'),
                'local' => (string) config('services.engine.targets.local', 'http://127.0.0.1:5010'),
            ],
            'secrets' => [
                'main_set' => (string) config('services.engine.webhook_secrets.binance') !== '',
                'test_set' => (string) config('services.engine.test_webhook_secret') !== '',
            ],
            'webhooks' => $webhooks,
        ]);
    }

    /**
     * The engine's `enabled` for one path. Null = unknown (engine silent, or
     * too old to list its webhooks); false = it lists its webhooks and this
     * one is not among them.
     *
     * @param  list<mixed>|null  $engineHooks
     */
    private function engineEnabled(?array $engineHooks, string $exchange, string $feed): ?bool
    {
        if ($engineHooks === null) {
            return null;
        }
        foreach ($engineHooks as $hook) {
            if (is_array($hook) && ($hook['exchange'] ?? null) === $exchange && ($hook['feed'] ?? null) === $feed) {
                return (bool) ($hook['enabled'] ?? false);
            }
        }

        return false;
    }

    /**
     * Accounts each path would fan out to, per venue per feed, split live /
     * demo. The SAME filters as EngineController::accounts (enabled, connected
     * — soft-deleted rows excluded by the model — not sandbox, owner not
     * suspended) and the same feed rule (tester owner → test, anyone else →
     * main), so the page states exactly what the fan-out hits. A blocked key
     * is still counted, as the engine still lists it (it skips it on entries).
     *
     * @return array<string, array{main: array{live: int, demo: int}, test: array{live: int, demo: int}}>
     */
    private function receivers(): array
    {
        $out = [];
        foreach (WebhookPaths::exchanges() as $exchange) {
            $counts = array_fill_keys(WebhookPaths::FEEDS, ['live' => 0, 'demo' => 0]);
            if (ExchangeSchema::isSupported($exchange)) {
                $schema = ExchangeSchema::for($exchange);
                $t = $schema->accountsTable;
                $rows = $schema->accountQuery()
                    ->join('user_credentials', 'user_credentials.uni_id', '=', "{$t}.uni_id")
                    ->where("{$t}.enabled", 1)
                    ->where("{$t}.is_sandbox", 0)
                    ->where('user_credentials.status', '!=', 'suspended')
                    ->toBase()
                    ->get(["{$t}.demo", 'user_credentials.type as owner_type']);

                foreach ($rows as $row) {
                    $feed = $row->owner_type === UserCredential::TESTER ? 'test' : 'main';
                    $counts[$feed][(int) $row->demo === 1 ? 'demo' : 'live']++;
                }
            }
            $out[$exchange] = $counts;
        }

        return $out;
    }
}
