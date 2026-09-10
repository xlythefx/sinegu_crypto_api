<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\ReferralTracking;
use App\Models\UserCredential;
use App\Services\InvoiceService;
use App\Services\UserStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Admin user-detail endpoints (auth:sanctum + admin middleware) — everything
 * the /admin/users/{uniId} page renders: profile + accounts, headline stats,
 * daily P&L calendar, positions, invoices, and the admin edits (fee
 * percentages, suspend / reactivate).
 */
class AdminUserController extends Controller
{
    public function __construct(
        private UserStatsService $stats,
        private InvoiceService $invoices,
    ) {}

    /**
     * GET /api/admin/users/{uniId}
     * Profile block + every exchange account (incl. demo / disabled /
     * disconnected) with per-account realized P&L and high-water mark.
     */
    public function show(string $uniId): JsonResponse
    {
        $user = UserCredential::find($uniId);
        if (! $user) {
            return $this->userNotFound();
        }

        $accounts = $this->stats->allAccounts($uniId);

        // Per-account aggregates in two grouped queries (no N+1).
        $realizedByKey = DB::table('binance_pastpositions')
            ->where('uni_id', $uniId)
            ->groupBy('api_key')
            ->selectRaw('api_key, SUM(realized_pnl) AS pnl')
            ->pluck('pnl', 'api_key');

        $hwmByAccount = DB::table('invoices')
            ->where('user_id', $uniId)
            ->groupBy('account_id')
            ->selectRaw('account_id, MAX(hwm_after) AS hwm')
            ->pluck('hwm', 'account_id');

        return response()->json([
            'success' => true,
            'user' => $this->userPayload($user) + [
                'accounts' => $accounts->map(fn ($a) => [
                    'id' => $a->id,
                    'name' => $a->name,
                    'exchange' => 'binance',
                    'api_key' => $this->maskKey($a->api_key),
                    'demo' => (bool) $a->demo,
                    'enabled' => (bool) $a->enabled,
                    'balance' => round((float) $a->balance, 2),
                    'unrealized_pnl' => round((float) $a->unrealized_pnl, 2),
                    'realized_pnl' => round((float) ($realizedByKey[$a->api_key] ?? 0), 2),
                    'initial_deposit' => round((float) $a->initial_deposit, 2),
                    'currency_type' => $a->currency_type,
                    'hwm' => round((float) ($hwmByAccount[$a->id] ?? 0), 2),
                    'deleted_at' => $a->deleted_at?->toISOString(),
                ])->values(),
            ],
        ]);
    }

    /**
     * GET /api/admin/users/{uniId}/summary
     * Headline figures computed over LIVE accounts — the same filter the
     * user's own dashboard uses, so both show identical numbers.
     */
    public function summary(string $uniId): JsonResponse
    {
        $user = UserCredential::find($uniId);
        if (! $user) {
            return $this->userNotFound();
        }

        $accounts = $this->stats->displayAccounts($uniId);
        $balance = (float) $accounts->sum('balance');
        $unrealized = (float) $accounts->sum('unrealized_pnl');
        $equity = $balance + $unrealized;

        $past = DB::table('binance_pastpositions')
            ->where('uni_id', $uniId)
            ->get(['realized_pnl', 'closed_at']);
        $realized = (float) $past->sum('realized_pnl');

        $flow = $this->stats->capitalFlow($uniId);
        $netDeposits = $flow['net_flow'];
        $pctBase = $netDeposits > 0 ? $netDeposits : max($equity, 1);

        // Same HWM the user dashboard shows: never below current equity.
        $hwm = (float) (DB::table('invoices')
            ->where('user_id', $uniId)
            ->where('exchange', 'binance')
            ->max('hwm_after') ?? 0);
        $hwm = max($hwm, $equity);

        $wins = $past->where('realized_pnl', '>', 0);
        $losses = $past->where('realized_pnl', '<', 0);
        $grossWin = (float) $wins->sum('realized_pnl');
        $grossLoss = abs((float) $losses->sum('realized_pnl'));
        $tradeCount = $past->count();

        $dailyTotals = $past
            ->groupBy(fn ($p) => substr((string) $p->closed_at, 0, 10))
            ->map(fn ($rows) => round((float) $rows->sum('realized_pnl'), 2))
            ->sortKeys();
        $streaks = $this->stats->dayStreaks($dailyTotals);

        $initialDeposit = (float) $accounts->sum('initial_deposit');

        return response()->json([
            'success' => true,
            'summary' => [
                'balance' => round($balance, 2),
                'unrealized_pnl' => round($unrealized, 2),
                'realized_pnl' => round($realized, 2),
                'total_pnl' => round($realized + $unrealized, 2),
                'equity' => round($equity, 2),
                'net_deposits' => round($netDeposits, 2),
                'pct_base' => round($pctBase, 2),
                'hwm' => round($hwm, 2),
                'metrics' => [
                    'total_trades' => $tradeCount,
                    'wins' => $wins->count(),
                    'losses' => $losses->count(),
                    'win_rate' => $tradeCount ? round($wins->count() / $tradeCount * 100, 1) : null,
                    'profit_factor' => $grossLoss > 0 ? round($grossWin / $grossLoss, 2) : null,
                    'max_win_streak_days' => $streaks['max_win_streak_days'],
                    'max_loss_streak_days' => $streaks['max_loss_streak_days'],
                ],
                'capital_flow' => $flow + [
                    'initial_deposit' => round($initialDeposit, 2),
                    'capital' => round($initialDeposit + $flow['net_flow'], 2),
                ],
                'commissions' => $this->stats->commissions($uniId),
            ],
        ]);
    }

    /**
     * GET /api/admin/users/{uniId}/daily-pnl
     * Days map — identical shape to GET /admin/daily-pnl so the frontend
     * reuses its DailyPnlMap type.
     */
    public function dailyPnl(string $uniId): JsonResponse
    {
        $user = UserCredential::find($uniId);
        if (! $user) {
            return $this->userNotFound();
        }

        return response()->json([
            'success' => true,
            'days' => $this->stats->dailyPnlDays($uniId),
        ]);
    }

    /**
     * GET /api/admin/users/{uniId}/positions
     * Open positions + closed trades — same row shapes as the admin-wide
     * AdminController::positions, scoped to one user.
     */
    public function positions(string $uniId): JsonResponse
    {
        $user = UserCredential::find($uniId);
        if (! $user) {
            return $this->userNotFound();
        }

        $open = DB::table('binance_positions as p')
            ->leftJoin('binance_accounts as a', 'p.api_key', '=', 'a.api_key')
            ->where('p.uni_id', $uniId)
            ->orderBy('a.name')
            ->orderBy('p.symbol')
            ->get([
                'p.id', 'a.id as account_id', 'a.name as account_name',
                'a.balance as account_balance', 'p.symbol', 'p.position_side',
                'p.position_amt', 'p.entry_price', 'p.mark_price', 'p.unrealized_profit',
            ]);

        $past = DB::table('binance_pastpositions as t')
            ->leftJoin('binance_accounts as a', 't.api_key', '=', 'a.api_key')
            ->where('t.uni_id', $uniId)
            ->orderByDesc('t.closed_at')
            ->get([
                't.id', 'a.id as account_id', 'a.name as account_name',
                'a.balance as account_balance', 't.symbol', 't.exit_price',
                't.realized_pnl', 't.exchange_fee', 't.side', 't.strategy',
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
                // Net of the commission, like everywhere else; the fee rides
                // along so an admin looking at a trade sees the same breakdown
                // the customer does rather than a number they cannot reconcile.
                'realized_pnl' => round((float) ($t->realized_pnl ?? 0), 2),
                'exchange_fee' => $t->exchange_fee === null ? null : round((float) $t->exchange_fee, 2),
                'side' => $t->side,
                'strategy' => $t->strategy,
                'closed_at' => $t->closed_at,
                'position_amt' => (float) $t->position_amt,
                'broker' => 'Binance',
            ])->values(),
        ]);
    }

    /**
     * GET /api/admin/users/{uniId}/invoices
     * One user's invoices, newest first — same mapping as the user-facing
     * invoice list.
     */
    public function invoices(string $uniId): JsonResponse
    {
        $user = UserCredential::find($uniId);
        if (! $user) {
            return $this->userNotFound();
        }

        $invoices = Invoice::with('account')
            ->forUser($uniId)
            ->orderByDesc('month_year')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'success' => true,
            'invoices' => $this->invoices->mapList($invoices),
        ]);
    }

    /**
     * POST /api/admin/users
     * Create an account from the admin side (the "Create user" modal on
     * /admin/users) — the manual counterpart of a self-service sign-up.
     *
     * Differences from AuthController::register, all deliberate:
     *  - status defaults to `active`, not `pending`: an admin typing the
     *    account in IS the approval, so it would be absurd to queue it for
     *    their own review. `pending` is still selectable for the rare case
     *    where they want the normal queue.
     *  - no token is issued and no referral code is bound — nobody is signing
     *    in here, and a referral is a claim the referred user makes at sign-up.
     *  - the fee percentages are settable up front, so a negotiated rate does
     *    not need a second edit round-trip.
     * The account is NOT sandbox: `is_sandbox` stays false, so it is a real
     * user everywhere (engine fan-out, invoicing) — unlike SandboxController.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:user_credentials,email'],
            'password' => ['required', 'string', 'min:8'],
            'type' => ['sometimes', Rule::in(['user', 'admin', 'master', 'developer'])],
            'status' => ['sometimes', Rule::in(['pending', 'active', 'suspended'])],
            'realized_percentage' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'unrealized_percentage' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'affiliate_percentage' => ['sometimes', 'numeric', 'min:0', 'max:100'],
        ]);

        // Same single-master rule update() enforces — "the master" is resolved
        // with ->first() everywhere it is read, so a second one would make
        // which account is the house account silently arbitrary.
        if (($validated['type'] ?? 'user') === 'master') {
            $existing = UserCredential::where('type', 'master')->first();
            if ($existing) {
                return $this->statusRejected(
                    "{$existing->email} is already the master account. Change that account's role first — there can only be one."
                );
            }
        }

        $user = UserCredential::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'type' => $validated['type'] ?? 'user',
            'status' => $validated['status'] ?? 'active',
            // Created by hand — there is no address to verify against.
            'email_verified' => true,
        ]);

        // Percentage columns are not fillable — forceFill, as update() does.
        $percentages = array_intersect_key($validated, array_flip([
            'realized_percentage', 'unrealized_percentage', 'affiliate_percentage',
        ]));
        if ($percentages) {
            $user->forceFill($percentages)->save();
            // Read the columns back so the response carries the stored decimals
            // (15 in, "15.00" out) — the same numbers a later GET would return.
            $user->refresh();
        }

        return response()->json([
            'success' => true,
            'message' => 'User created.',
            'user' => $this->userPayload($user) + ['accounts' => []],
        ], 201);
    }

    /**
     * PUT /api/admin/users/{uniId}
     * Edit fee percentages and/or status (suspend / reactivate). Status
     * guards: master is immutable, pending belongs to the approval queue,
     * and an admin can never change their own status.
     */
    public function update(Request $request, string $uniId): JsonResponse
    {
        $user = UserCredential::find($uniId);
        if (! $user) {
            return $this->userNotFound();
        }

        $validated = $request->validate([
            'realized_percentage' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'unrealized_percentage' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'affiliate_percentage' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'status' => ['sometimes', Rule::in(['active', 'suspended'])],
            'type' => ['sometimes', Rule::in(['user', 'admin', 'master', 'developer'])],
        ]);

        if (isset($validated['type']) && $validated['type'] !== $user->type) {
            // Changing your own role is allowed — dropping yourself to `user`
            // is not, because it revokes the very access you would need to undo
            // it, and with one admin left it locks the portal for everyone.
            if ($request->user()->uni_id === $user->uni_id && $validated['type'] === 'user') {
                return $this->statusRejected(
                    'You cannot demote yourself to a plain user — you would lose admin access. Ask another admin to do it.'
                );
            }

            // "The master" is a single row everywhere it is read
            // (AdminController, PublicStatsController, InvoiceService), resolved
            // with ->first(). Two masters would make which one silently
            // arbitrary, so the transfer is explicit: demote, then promote.
            if ($validated['type'] === 'master') {
                $existing = UserCredential::where('type', 'master')
                    ->where('uni_id', '!=', $user->uni_id)
                    ->first();

                if ($existing) {
                    return $this->statusRejected(
                        "{$existing->email} is already the master account. Change that account's role first — there can only be one."
                    );
                }
            }
        }

        // Status guards apply only to an actual change (echoing the current
        // status back is a harmless no-op).
        if (isset($validated['status']) && $validated['status'] !== $user->status) {
            if ($user->type === 'master') {
                return $this->statusRejected("The master account's status cannot be changed.");
            }
            if ($user->status === 'pending') {
                return $this->statusRejected('Pending registrations are resolved from the approval queue (accept / reject).');
            }
            if ($request->user()->uni_id === $user->uni_id) {
                return $this->statusRejected('You cannot change your own status.');
            }
        }

        // Percentage columns are not fillable — forceFill is deliberate.
        $user->forceFill($validated)->save();

        return response()->json([
            'success' => true,
            'message' => 'User updated.',
            'user' => $this->userPayload($user),
        ]);
    }

    /** The profile block shared by show() and update() (accounts excluded). */
    private function userPayload(UserCredential $user): array
    {
        return $user->toAuthPayload() + [
            'realized_percentage' => (float) $user->realized_percentage,
            'unrealized_percentage' => (float) $user->unrealized_percentage,
            'affiliate_percentage' => $user->affiliate_percentage !== null
                ? (float) $user->affiliate_percentage
                : null,
            'last_activity' => $user->last_activity?->toISOString(),
            'referrals_count' => ReferralTracking::where('referrer_uni_id', $user->uni_id)->count(),
        ];
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

    private function userNotFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'User not found.',
        ], 404);
    }

    private function statusRejected(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], 422);
    }
}
