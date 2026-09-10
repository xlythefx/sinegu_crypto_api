<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Tells the trading engine to drop a cached list the moment the thing it
 * caches changes — a Binance account being connected, an asset's base_size
 * being edited — instead of waiting out the engine's TTL.
 *
 * This is INVALIDATION, not a fetch. The engine only marks its list stale and
 * reloads it lazily, on the next signal or poller tick, in ONE call covering
 * every account. So a hundred users connecting in a burst costs a hundred
 * sub-millisecond pings to 127.0.0.1 — not a hundred reloads, and not a single
 * extra call to Binance. Doing it the other way round (the engine polling
 * harder, or a shorter TTL) spends requests on the 99.9% of minutes when
 * nothing changed.
 *
 * Best-effort by design. The engine's TTL remains the safety net, so a ping
 * that fails — engine restarting, not deployed, local dev without it — is
 * logged and swallowed. It must never make "Connect Binance" fail or hang,
 * and it must never turn an admin save into an error.
 */
class EngineCache
{
    /**
     * Deliberately short: this runs inside a user-facing request, and the
     * engine is on the same box answering from memory. If it cannot answer in
     * two seconds it is down, and the TTL will cover us.
     */
    private const TIMEOUT = 2;

    /** Who may trade changed: connected, disconnected, enabled, suspended. */
    public function refreshAccounts(): bool
    {
        return $this->ping('refresh-accounts');
    }

    /** What may be traded, or how big: base_size, max_increments, side, enabled. */
    public function refreshAssets(): bool
    {
        return $this->ping('refresh-assets');
    }

    /**
     * Sync balances for specific accounts NOW, instead of at the poller's next
     * tick. Scoped by api_key on purpose: one trader pressing "Refresh balance"
     * must cost one Binance call, not one per account on the platform.
     *
     * Slower than the invalidation pings — it makes a real exchange round trip
     * and then posts the rows back to this API — so it gets its own timeout and
     * is never called from anywhere hot.
     *
     * @param  list<string>  $apiKeys
     */
    public function syncBalances(array $apiKeys, int $timeout = 15): bool
    {
        if ($apiKeys === []) {
            return false;
        }

        return $this->ping('refresh-balances', ['api_keys' => array_values($apiKeys)], $timeout);
    }

    /** @return array{refreshed: list<string>, error: ?string} */
    public function refreshAll(): array
    {
        $refreshed = [];
        foreach (['refresh-accounts', 'refresh-assets'] as $path) {
            if ($this->ping($path)) {
                $refreshed[] = $path;
            }
        }

        return [
            'refreshed' => $refreshed,
            'error' => $refreshed === [] ? 'Engine did not respond.' : null,
        ];
    }

    /** @param  array<string, mixed>  $body */
    private function ping(string $path, array $body = [], ?int $timeout = null): bool
    {
        $base = rtrim((string) config('services.engine.targets.local', 'http://127.0.0.1:5010'), '/');
        $secret = (string) (config('services.engine.webhook_secrets.binance') ?? '');

        if ($secret === '') {
            // Not an error worth shouting about on a box without the engine.
            return false;
        }

        try {
            $response = Http::withHeaders(['X-Admin-Secret' => $secret])
                ->timeout($timeout ?? self::TIMEOUT)
                ->post("{$base}/admin/{$path}", $body);

            if ($response->successful()) {
                return true;
            }

            Log::warning('Engine cache refresh refused.', [
                'path' => $path,
                'status' => $response->status(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Engine cache refresh failed.', [
                'path' => $path,
                'exception' => $e->getMessage(),
            ]);
        }

        return false;
    }
}
