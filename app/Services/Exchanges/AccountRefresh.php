<?php

namespace App\Services\Exchanges;

use App\Services\EngineCache;
use App\Services\UserStatsService;

/**
 * Read one user's connected exchange accounts NOW — balance and open
 * positions — instead of waiting for the pollers (300 s).
 *
 * The engine does the reading (it is the only process holding the keys and
 * allow-listed at the exchange) and posts the rows back to this API, so the
 * caller re-reads its figures from the DB afterwards. Scoped to the user's
 * keys: one press costs one exchange read per account, never one per account
 * on the platform.
 *
 * Shared by the admin's "Refresh" on a user's page and a tester's own
 * "Fetch" on /dashboard/positions — one definition of what a refresh reads.
 */
class AccountRefresh
{
    public function __construct(
        private UserStatsService $stats,
        private EngineCache $engine,
    ) {}

    /**
     * @return array{accounts: int, reached: bool}
     *   `accounts` = connected accounts asked for (0 = nothing to read, which
     *   is an answer, not a failure); `reached` = the engine took the request.
     */
    public function forUser(string $uniId): array
    {
        $keys = $this->stats->allAccounts($uniId)
            ->whereNull('deleted_at')
            ->pluck('api_key')->filter()->values()->all();

        if ($keys === []) {
            return ['accounts' => 0, 'reached' => true];
        }

        $balances = $this->engine->syncBalances($keys, 30);
        $positions = $this->engine->syncPositions($keys, 30);

        return ['accounts' => count($keys), 'reached' => $balances || $positions];
    }
}
