<?php

namespace App\Http\Controllers;

use App\Models\UserCredential;
use App\Services\Exchanges\AccountRefresh;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * POST /api/user/positions/refresh — a TESTER's "Fetch" on /dashboard/positions.
 *
 * Reads the caller's OWN connected accounts from the exchange now (balance +
 * open positions, AccountRefresh) so a test signal's position shows at once
 * rather than after the positions poller's next 300 s tick. Owner's request
 * (2026-10-16) for testers only: a customer's page never needed it, and every
 * press is a real exchange read, so the role is checked here — the button
 * being hidden is cosmetic.
 *
 * One press per COOLDOWN seconds per user (`Cache::add`, so a double click
 * cannot spend two reads), on top of the route's throttle.
 */
class PositionRefreshController extends Controller
{
    public const COOLDOWN = 15;

    public function refresh(Request $request, AccountRefresh $refresh): JsonResponse
    {
        $user = $request->user();
        if (! $user || $user->type !== UserCredential::TESTER) {
            return response()->json([
                'success' => false,
                'error_code' => 'FORBIDDEN',
                'message' => 'Fetching positions on demand is for tester accounts.',
            ], 403);
        }

        $key = "positions-refresh:{$user->uni_id}";
        if (! Cache::add($key, now()->getTimestamp(), self::COOLDOWN)) {
            $since = now()->getTimestamp() - (int) Cache::get($key, now()->getTimestamp());

            return response()->json([
                'success' => false,
                'error_code' => 'REFRESH_COOLDOWN',
                'message' => 'Just fetched — try again in a few seconds.',
                'retry_after' => max(1, self::COOLDOWN - $since),
            ], 429);
        }

        return self::respond($refresh->forUser($user->uni_id));
    }

    /**
     * The one response shape for a refresh, shared with the admin's
     * POST /admin/users/{uniId}/refresh.
     *
     * @param  array{accounts: int, reached: bool}  $result
     */
    public static function respond(array $result): JsonResponse
    {
        if ($result['accounts'] === 0) {
            return response()->json([
                'success' => true,
                'accounts' => 0,
                'message' => 'No connected exchange account — nothing to read.',
            ]);
        }

        return response()->json([
            'success' => $result['reached'],
            'accounts' => $result['accounts'],
            'message' => $result['reached']
                ? 'Balances and positions read from the exchange.'
                : 'Could not reach the trading engine — showing the last synced figures.',
        ], $result['reached'] ? 200 : 503);
    }
}
