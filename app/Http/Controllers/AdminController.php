<?php

namespace App\Http\Controllers;

use App\Models\BinanceAccount;
use App\Models\UserCredential;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin portal endpoints (auth:sanctum + admin middleware).
 * Master-account statistics and the user-management list with the
 * registration approval queue (accept / reject).
 */
class AdminController extends Controller
{
    /**
     * GET /api/admin/master-stats
     * The master account (user_credentials.type = master) and the trading
     * statistics of that account, for the admin dashboard.
     */
    public function masterStats(): JsonResponse
    {
        $master = UserCredential::where('type', 'master')->first();

        if (! $master) {
            return response()->json([
                'success' => false,
                'message' => 'No master account configured.',
            ], 404);
        }

        $account = BinanceAccount::where('uni_id', $master->uni_id)
            ->orderByDesc('created_at')
            ->first();

        $open = DB::table('binance_positions')
            ->where('uni_id', $master->uni_id)
            ->get(['unrealized_profit', 'update_time']);

        $past = DB::table('binance_pastpositions')
            ->where('uni_id', $master->uni_id)
            ->get(['realized_pnl', 'closed_at']);

        $lastTrade = $past->max('closed_at');

        return response()->json([
            'success' => true,
            'master' => [
                'uni_id' => $master->uni_id,
                'name' => $master->name,
                'email' => $master->email,
                'account' => $account ? [
                    'id' => $account->id,
                    'name' => $account->name,
                    'api_key' => $this->maskKey($account->api_key),
                    'demo' => (bool) $account->demo,
                    'enabled' => (bool) $account->enabled,
                    'currency_type' => $account->currency_type,
                ] : null,
            ],
            'stats' => [
                'balance' => round((float) ($account->balance ?? 0), 2),
                'unrealized_pnl' => round((float) $open->sum('unrealized_profit'), 2),
                'total_closed_pnl' => round((float) $past->sum('realized_pnl'), 2),
                'trades_executed' => $past->count(),
                'active_positions' => $open->count(),
                'closed_positions' => $past->count(),
                'last_trade_at' => $lastTrade,
            ],
        ]);
    }

    /**
     * GET /api/admin/daily-pnl
     * Master account's closed-trade P&L grouped by calendar day, each day
     * carrying its individual trades — feeds the admin Daily P&L calendar.
     */
    public function dailyPnl(): JsonResponse
    {
        $master = UserCredential::where('type', 'master')->first();

        if (! $master) {
            return response()->json([
                'success' => false,
                'message' => 'No master account configured.',
            ], 404);
        }

        $past = DB::table('binance_pastpositions')
            ->where('uni_id', $master->uni_id)
            ->orderByDesc('closed_at')
            ->get([
                'symbol', 'position_side', 'position_amt', 'realized_pnl',
                'side', 'strategy', 'closed_at',
            ]);

        $days = [];
        foreach ($past->groupBy(fn ($t) => substr((string) $t->closed_at, 0, 10)) as $date => $trades) {
            $days[$date] = [
                'total' => round((float) $trades->sum('realized_pnl'), 2),
                'wins' => $trades->where('realized_pnl', '>', 0)->count(),
                'losses' => $trades->where('realized_pnl', '<', 0)->count(),
                'trades' => $trades->map(fn ($t) => [
                    'symbol' => $t->symbol,
                    'position_side' => $t->position_side,
                    'position_amt' => (float) $t->position_amt,
                    'realized_pnl' => round((float) $t->realized_pnl, 2),
                    'side' => $t->side,
                    'strategy' => $t->strategy,
                    'closed_at' => $t->closed_at,
                ])->values(),
            ];
        }

        return response()->json(['success' => true, 'days' => $days]);
    }

    /**
     * GET /api/admin/positions
     * Every open position and every closed trade across ALL exchange accounts,
     * each joined to its account (name, balance) — feeds the admin Positions
     * page. The frontend groups same account + ticker rows together.
     */
    public function positions(): JsonResponse
    {
        $open = DB::table('binance_positions as p')
            ->leftJoin('binance_accounts as a', 'p.api_key', '=', 'a.api_key')
            ->orderBy('a.name')
            ->orderBy('p.symbol')
            ->get([
                'p.id', 'a.id as account_id', 'a.name as account_name',
                'a.balance as account_balance', 'p.symbol', 'p.position_side',
                'p.position_amt', 'p.entry_price', 'p.mark_price', 'p.unrealized_profit',
            ]);

        $past = DB::table('binance_pastpositions as t')
            ->leftJoin('binance_accounts as a', 't.api_key', '=', 'a.api_key')
            ->orderByDesc('t.closed_at')
            ->get([
                't.id', 'a.id as account_id', 'a.name as account_name',
                'a.balance as account_balance', 't.symbol', 't.exit_price',
                't.realized_pnl', 't.side', 't.strategy', 't.closed_at', 't.position_amt',
            ]);

        return response()->json([
            'success' => true,
            'positions' => $open->map(fn ($p) => [
                'id' => $p->id,
                'account_id' => $p->account_id,
                'account_name' => $p->account_name,
                'account_balance' => round((float) ($p->account_balance ?? 0), 2),
                'symbol' => $p->symbol,
                'position_side' => $p->position_side,
                'position_amt' => (float) $p->position_amt,
                'price' => round((float) ($p->mark_price ?? $p->entry_price ?? 0), 8),
                'unrealized_pnl' => round((float) ($p->unrealized_profit ?? 0), 2),
                'broker' => 'Binance',
            ])->values(),
            'trades' => $past->map(fn ($t) => [
                'id' => $t->id,
                'account_id' => $t->account_id,
                'account_name' => $t->account_name,
                'account_balance' => round((float) ($t->account_balance ?? 0), 2),
                'symbol' => $t->symbol,
                'price' => round((float) ($t->exit_price ?? 0), 8),
                'realized_pnl' => round((float) ($t->realized_pnl ?? 0), 2),
                'side' => $t->side,
                'strategy' => $t->strategy,
                'closed_at' => $t->closed_at,
                'position_amt' => (float) $t->position_amt,
                'broker' => 'Binance',
            ])->values(),
        ]);
    }

    /**
     * DELETE /api/admin/positions/{id}
     * Remove a single open position row.
     */
    public function deletePosition(int $id): JsonResponse
    {
        $deleted = DB::table('binance_positions')->where('id', $id)->delete();

        return response()->json([
            'success' => $deleted > 0,
            'message' => $deleted > 0 ? 'Position deleted.' : 'Position not found.',
        ], $deleted > 0 ? 200 : 404);
    }

    /**
     * DELETE /api/admin/past-positions/{id}
     * Remove a single closed trade row.
     */
    public function deletePastPosition(int $id): JsonResponse
    {
        $deleted = DB::table('binance_pastpositions')->where('id', $id)->delete();

        return response()->json([
            'success' => $deleted > 0,
            'message' => $deleted > 0 ? 'Trade deleted.' : 'Trade not found.',
        ], $deleted > 0 ? 200 : 404);
    }

    /**
     * GET /api/admin/users
     * Every user_credentials row (master / admin / user) with their exchange
     * accounts — feeds the User Management page.
     */
    public function users(): JsonResponse
    {
        $users = UserCredential::orderByRaw("FIELD(status, 'pending') DESC")
            ->orderByDesc('created_at')
            ->get([
                'uni_id', 'name', 'email', 'status', 'type',
                'realized_percentage', 'unrealized_percentage',
                'created_at', 'last_activity',
            ]);

        // Exchange accounts per user (incl. disconnected ones), keys masked.
        $accounts = BinanceAccount::withTrashed()
            ->whereIn('uni_id', $users->pluck('uni_id'))
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('uni_id')
            ->map(fn ($rows) => $rows->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->name,
                'exchange' => 'Binance',
                'api_key' => $this->maskKey($a->api_key),
                'demo' => (bool) $a->demo,
                'enabled' => (bool) $a->enabled,
                'balance' => round((float) $a->balance, 2),
                'unrealized_pnl' => round((float) $a->unrealized_pnl, 2),
                'currency_type' => $a->currency_type,
                'deleted_at' => $a->deleted_at?->toISOString(),
            ])->values());

        return response()->json([
            'success' => true,
            'users' => $users->map(fn ($u) => [
                'uni_id' => $u->uni_id,
                'name' => $u->name,
                'email' => $u->email,
                'status' => $u->status,
                'type' => $u->type,
                'realized_percentage' => (float) $u->realized_percentage,
                'unrealized_percentage' => (float) $u->unrealized_percentage,
                'created_at' => $u->created_at?->toISOString(),
                'last_activity' => $u->last_activity?->toISOString(),
                'accounts' => $accounts->get($u->uni_id, collect())->values(),
            ])->values(),
        ]);
    }

    /**
     * POST /api/admin/users/{uniId}/accept
     * Approve a pending registration → status active.
     */
    public function acceptUser(Request $request, string $uniId): JsonResponse
    {
        return $this->resolvePending($uniId, 'active', 'User approved');
    }

    /**
     * POST /api/admin/users/{uniId}/reject
     * Reject a pending registration → status suspended (blocked from login).
     */
    public function rejectUser(Request $request, string $uniId): JsonResponse
    {
        return $this->resolvePending($uniId, 'suspended', 'User rejected');
    }

    /** Shared accept/reject transition — only pending users can be resolved. */
    private function resolvePending(string $uniId, string $status, string $message): JsonResponse
    {
        $user = UserCredential::find($uniId);

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found.',
            ], 404);
        }

        if ($user->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => "Only pending registrations can be resolved (user is {$user->status}).",
            ], 422);
        }

        $user->forceFill(['status' => $status])->save();

        return response()->json([
            'success' => true,
            'message' => $message,
            'user' => $user->toAuthPayload(),
        ]);
    }

    /** First/last 4 chars of an API key, middle hidden. */
    private function maskKey(?string $key): ?string
    {
        if (! $key) {
            return null;
        }

        return strlen($key) > 8
            ? substr($key, 0, 4).' •••• '.substr($key, -4)
            : '••••';
    }
}
