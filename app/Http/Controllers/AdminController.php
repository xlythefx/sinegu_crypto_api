<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureStaff;
use App\Models\BinanceAccount;
use App\Models\UserCredential;
use App\Services\Discord\DiscordRoleSync;
use App\Services\EngineCache;
use App\Services\Exchanges\ExchangeSchema;
use App\Services\Notifications\AccountMail;
use App\Services\Pnl\TradingFee;
use App\Services\UserStatsService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Admin portal endpoints (auth:sanctum + admin middleware).
 * Master-account statistics and the user-management list with the
 * registration approval queue (accept / reject).
 */
class AdminController extends Controller
{
    public function __construct(
        private UserStatsService $stats,
        private DiscordRoleSync $discordRoles,
        private AccountMail $mail,
    ) {}

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
                // null (not 0) when the poller has never written a balance —
                // the dashboard must not present "unsynced" as a real $0.00.
                'balance' => $account && $account->balance !== null
                    ? round((float) $account->balance, 2)
                    : null,
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

        return response()->json([
            'success' => true,
            'days' => $this->stats->dailyPnlDays($master->uni_id),
        ]);
    }

    /**
     * POST /api/admin/positions/refresh
     *
     * Admin → Trading Positions' "Refresh". The page reads the DB and the
     * positions poller rewrites it every 300 s, so re-reading alone showed
     * nothing new. This asks the engine to read every account's open
     * positions now; the page re-reads once it answers. One exchange read per
     * account — throttled at the route.
     */
    public function refreshPositions(EngineCache $engine): JsonResponse
    {
        $reached = $engine->syncPositions(null);

        return response()->json([
            'success' => $reached,
            'message' => $reached
                ? 'Open positions read from the exchanges.'
                : 'Could not reach the trading engine — showing the last synced positions.',
        ], $reached ? 200 : 503);
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
                'a.balance as account_balance', 't.symbol', 't.entry_price', 't.exit_price',
                't.realized_pnl', 't.exchange_fee', 't.fee_source', 't.side', 't.strategy',
                't.closed_at', 't.position_amt',
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
                // Null when never recorded (a close row seldom references its
                // entry order) — shown as "—", never as $0.
                'entry_price' => $t->entry_price === null ? null : round((float) $t->entry_price, 8),
                // Net of the exchange's commission (which rides along beside
                // it) from TradingFee::NET_SINCE on; gross, null fee, before.
                'realized_pnl' => round((float) ($t->realized_pnl ?? 0), 2),
                'exchange_fee' => $t->exchange_fee === null ? null : round((float) $t->exchange_fee, 2),
                'fee_source' => $t->fee_source,
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
     * PUT /api/admin/positions/{id}
     * Correct a single OPEN position row.
     *
     * `api_key` / `uni_id` are deliberately not editable here: they are what
     * joins a row to an account and, through it, to that account's invoices
     * and published track record. Re-owning a row would silently rewrite
     * someone's billed P&L, so a mis-owned row is deleted and re-synced, never
     * moved.
     *
     * An edit here is a DISPLAY correction only: the positions poller does a
     * full replace per api_key, so the account's next successful sync
     * overwrites whatever is written — it never reaches Binance.
     */
    public function updatePosition(Request $request, int $id): JsonResponse
    {
        if (! DB::table('binance_positions')->where('id', $id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Position not found.',
            ], 404);
        }

        $data = $request->validate([
            'symbol' => ['sometimes', 'string', 'max:32'],
            'position_side' => ['sometimes', 'string', 'in:BOTH,LONG,SHORT'],
            'position_amt' => ['sometimes', 'numeric'],
            'mark_price' => ['sometimes', 'nullable', 'numeric'],
            'unrealized_profit' => ['sometimes', 'nullable', 'numeric'],
        ]);

        if ($data === []) {
            return response()->json([
                'success' => false,
                'message' => 'Nothing to update.',
            ], 422);
        }

        if (isset($data['symbol'])) {
            $data['symbol'] = strtoupper(trim($data['symbol']));
        }

        DB::table('binance_positions')->where('id', $id)->update($data);

        return response()->json(['success' => true, 'message' => 'Position updated.']);
    }

    /**
     * PUT /api/admin/past-positions/{id}
     * Correct a single CLOSED trade row.
     *
     * Same ownership rule as updatePosition(). Unlike an open position this
     * edit is permanent — nothing re-syncs it — and `realized_pnl` /
     * `closed_at` are read by invoicing (BinancePnlSource) and by the public
     * track record, so a correction here changes what a customer is billed and
     * what the landing page publishes.
     *
     * A typed `realized_pnl` stamps `fee_source = manual`, which is what stops
     * the fee reconciler (FeeRebase) from later "correcting" the correction:
     * it rebases from `realized_pnl + exchange_fee`, and after a hand edit
     * that sum is no longer the exchange's gross figure. `exchange_fee` is left
     * as it was — the admin typed a net number, and the label says why the fee
     * beside it may not add up. Clearing the P&L (null) clears the label too:
     * the row is back to "unknown", which the poller's backfill may fill.
     */
    public function updatePastPosition(Request $request, int $id): JsonResponse
    {
        $existing = DB::table('binance_pastpositions')->where('id', $id)->first(['id', 'realized_pnl']);
        if (! $existing) {
            return response()->json([
                'success' => false,
                'message' => 'Trade not found.',
            ], 404);
        }

        $data = $request->validate([
            'symbol' => ['sometimes', 'string', 'max:32'],
            'side' => ['sometimes', 'string', 'in:BUY,SELL'],
            'position_amt' => ['sometimes', 'numeric'],
            'exit_price' => ['sometimes', 'nullable', 'numeric'],
            'realized_pnl' => ['sometimes', 'nullable', 'numeric'],
            'strategy' => ['sometimes', 'nullable', 'string', 'max:100'],
            'closed_at' => ['sometimes', 'date'],
        ]);

        if ($data === []) {
            return response()->json([
                'success' => false,
                'message' => 'Nothing to update.',
            ], 422);
        }

        if (isset($data['symbol'])) {
            $data['symbol'] = strtoupper(trim($data['symbol']));
        }
        if (isset($data['closed_at'])) {
            $data['closed_at'] = Carbon::parse($data['closed_at'])->format('Y-m-d H:i:s');
        }
        if (array_key_exists('strategy', $data) && $data['strategy'] !== null) {
            $data['strategy'] = trim($data['strategy']) ?: null;
        }

        if (array_key_exists('realized_pnl', $data)) {
            $before = $existing->realized_pnl === null ? null : (float) $existing->realized_pnl;
            $after = $data['realized_pnl'] === null ? null : (float) $data['realized_pnl'];
            if ($after === null) {
                $data['fee_source'] = null;
            } elseif ($before === null || abs($after - $before) >= 1e-9) {
                $data['fee_source'] = TradingFee::SOURCE_MANUAL;
            }
        }

        try {
            DB::table('binance_pastpositions')->where('id', $id)->update($data);
        } catch (QueryException $e) {
            // uq_binance_pastpositions_api_symbol_order — retyping the symbol can
            // collide with another close of the same Binance order.
            if ($e->getCode() === '23000') {
                return response()->json([
                    'success' => false,
                    'message' => 'That account already has a trade with this symbol and order id.',
                ], 409);
            }
            throw $e;
        }

        return response()->json(['success' => true, 'message' => 'Trade updated.']);
    }

    /**
     * GET /api/admin/performance
     * Master account's closed-trade P&L as a cumulative series, plus fixed
     * daily/weekly/monthly snapshots — feeds the admin dashboard's cumulative
     * chart and Performance Breakdown card.
     *
     * Query params (all optional, they apply to `series` only):
     *   period  daily|weekly|monthly|all   bucket size (default monthly)
     *   from    YYYY-MM-DD                 inclusive lower bound on closed_at
     *   to      YYYY-MM-DD                 inclusive upper bound on closed_at
     *   exclude BTCUSDT,ETHUSDT            symbols to leave out
     *
     * `breakdown` and `tickers` are deliberately computed on UNFILTERED data:
     * the breakdown card is a fixed "today / last 7 days / month to date"
     * summary, and the ticker list has to keep offering symbols the user has
     * currently excluded or they could never be re-enabled.
     */
    public function performance(Request $request): JsonResponse
    {
        $master = UserCredential::where('type', 'master')->first();

        if (! $master) {
            return response()->json([
                'success' => false,
                'message' => 'No master account configured.',
            ], 404);
        }

        $base = fn () => DB::table('binance_pastpositions')->where('uni_id', $master->uni_id);

        $period = (string) $request->query('period', 'monthly');
        $from = $request->query('from');
        $to = $request->query('to');
        $exclude = array_values(array_filter(
            array_map('trim', explode(',', (string) $request->query('exclude', '')))
        ));

        $q = $base();
        if ($from) {
            $q->whereDate('closed_at', '>=', $from);
        }
        if ($to) {
            $q->whereDate('closed_at', '<=', $to);
        }
        if ($exclude) {
            $q->whereNotIn('symbol', $exclude);
        }

        $rows = $q->orderBy('closed_at')->get(['symbol', 'realized_pnl', 'closed_at']);

        // 'all' collapses to monthly buckets so a multi-year history stays readable.
        $format = match ($period) {
            'daily' => 'Y-m-d',
            'weekly' => 'o-\WW',
            default => 'Y-m',
        };

        $buckets = [];
        foreach ($rows as $row) {
            $key = date($format, strtotime((string) $row->closed_at));
            $buckets[$key] = ($buckets[$key] ?? 0) + (float) $row->realized_pnl;
        }
        ksort($buckets);

        $series = [];
        $cumulative = 0.0;
        foreach ($buckets as $bucket => $pnl) {
            $cumulative += $pnl;
            $series[] = [
                'bucket' => $bucket,
                'pnl' => round($pnl, 2),
                'cumulative' => round($cumulative, 2),
            ];
        }

        $today = now()->toDateString();
        $windows = [
            'daily' => [$today, $today],
            'weekly' => [now()->subDays(6)->toDateString(), $today],
            'monthly' => [now()->startOfMonth()->toDateString(), $today],
        ];

        $breakdown = [];
        foreach ($windows as $label => [$start, $end]) {
            $window = $base()
                ->whereDate('closed_at', '>=', $start)
                ->whereDate('closed_at', '<=', $end)
                ->get(['realized_pnl']);

            $breakdown[$label] = [
                'from' => $start,
                'to' => $end,
                'pnl' => round((float) $window->sum('realized_pnl'), 2),
                'trades' => $window->count(),
            ];
        }

        return response()->json([
            'success' => true,
            'period' => $period,
            'series' => $series,
            'breakdown' => $breakdown,
            'tickers' => $base()->distinct()->orderBy('symbol')->pluck('symbol')->values(),
        ]);
    }

    /**
     * GET /api/admin/users
     * Every user_credentials row (master / admin / user) with their exchange
     * accounts — feeds the User Management page.
     */
    public function users(Request $request): JsonResponse
    {
        // A read-only collaborator gets the list without fee settings and
        // without the masked API key on each account.
        $limited = EnsureStaff::isLimited($request);

        // Pending first (the approval queue), then newest. CASE rather than
        // MySQL's FIELD() so the same query runs on the SQLite test database.
        // Only a VERIFIED pending sign-up is in the queue; an unverified one is
        // still listed (staff can find it) but sorts with everyone else.
        $users = UserCredential::orderByRaw("CASE WHEN status = 'pending' AND email_verified = 1 THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->get([
                'uni_id', 'name', 'email', 'status', 'type', 'email_verified',
                'realized_percentage', 'unrealized_percentage',
                'created_at', 'last_activity',
            ]);

        // Exchange accounts per user (incl. disconnected ones), keys masked —
        // one query per exchange table, every row stamped with its `exchange`
        // because ids repeat across the tables.
        $accounts = collect();
        foreach (ExchangeSchema::supported() as $exchange) {
            $rows = ExchangeSchema::for($exchange)->accountQuery()
                ->withTrashed()
                ->whereIn('uni_id', $users->pluck('uni_id'))
                ->get()
                ->map(fn ($a) => [
                    'id' => $a->id,
                    'uni_id' => $a->uni_id,
                    'name' => $a->name,
                    'exchange' => $exchange,
                    'api_key' => $this->maskKey($a->api_key),
                    'demo' => (bool) $a->demo,
                    'enabled' => (bool) $a->enabled,
                    'balance' => round((float) $a->balance, 2),
                    'unrealized_pnl' => round((float) $a->unrealized_pnl, 2),
                    'currency_type' => $a->currency_type,
                    'created_at' => $a->created_at,
                    'deleted_at' => $a->deleted_at?->toISOString(),
                ]);
            $accounts = $accounts->concat($rows);
        }
        $accounts = $accounts
            ->sortByDesc(fn (array $a) => (string) $a['created_at'])
            ->groupBy('uni_id')
            ->map(fn ($rows) => $rows
                ->map(fn (array $a) => array_diff_key(
                    $a,
                    ['uni_id' => 1, 'created_at' => 1] + ($limited ? ['api_key' => 1] : []),
                ))
                ->values());

        return response()->json([
            'success' => true,
            'users' => $users->map(fn ($u) => array_diff_key([
                'uni_id' => $u->uni_id,
                'name' => $u->name,
                'email' => $u->email,
                'status' => $u->status,
                'type' => $u->type,
                // Lets the client keep unverified sign-ups out of its pending queue.
                'email_verified' => (bool) $u->email_verified,
                'realized_percentage' => (float) $u->realized_percentage,
                'unrealized_percentage' => (float) $u->unrealized_percentage,
                'created_at' => $u->created_at?->toISOString(),
                'last_activity' => $u->last_activity?->toISOString(),
                'accounts' => $accounts->get($u->uni_id, collect())->values(),
            ], $limited ? ['realized_percentage' => 1, 'unrealized_percentage' => 1] : []))->values(),
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

        // A rejected sign-up is a suspended account: whatever session the
        // registration opened (register signs the user in, so there is one)
        // ends here, same rule as AdminUserController::update.
        if ($status === 'suspended') {
            $user->tokens()->delete();
        }

        // The Overview's pending strip is approved FROM the dashboard, so a
        // resolved sign-up must not linger there for the cache's minute.
        Cache::forget(AdminInsightsController::CACHE_PREFIX.'overview');
        Cache::forget(AdminInsightsController::CACHE_PREFIX.'customers');

        // Approval is what earns the Discord "Member" role; best-effort, after
        // the row is saved, so a Discord outage can never fail an approval.
        $this->discordRoles->syncUser($user);

        // Approval is also the only moment the user learns the wait is over —
        // nothing in the app tells them, and they may not be looking at it.
        // A rejection deliberately sends nothing.
        if ($status === 'active') {
            $this->mail->approved($user);
        }

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
