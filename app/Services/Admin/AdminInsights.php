<?php

namespace App\Services\Admin;

use App\Models\BinanceAccount;
use App\Models\ExchangeAccount;
use App\Models\Invoice;
use App\Models\TradeLog;
use App\Models\TronTransfer;
use App\Models\UserCredential;
use App\Services\Exchanges\ExchangeSchema;
use App\Services\InvoiceService;
use App\Services\Payments\PaymentEnvironment;
use App\Services\Payments\TronGateway;
use App\Services\UserStatsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only aggregates behind the admin dashboard's tabs (Overview,
 * Customers, Money, System, Strategies).
 *
 * Every figure is a DB read — nothing here calls an exchange, so opening the
 * dashboard costs no API weight. Three scoping rules hold throughout:
 *   - A CUSTOMER is a `user_credentials.type = 'user'` row; staff, developer
 *     and master accounts are never counted as customers.
 *   - Customer money excludes demo (testnet), sandbox and `SBXINV-` scenario
 *     accounts — play money is never business.
 *   - The master's figures follow the published track record's scope: real
 *     accounts only, soft-deleted ones included (a rotated key still traded
 *     real money).
 * Days are UTC, like the rest of the admin side.
 */
class AdminInsights
{
    /** Invoice-scenario scratch accounts (SandboxInvoiceController) — never business. */
    public const SANDBOX_KEY_PREFIX = 'SBXINV-';

    /** The Overview's Platform view: whose money it pools. */
    public const PLATFORM_SCOPES = ['all', 'customers', 'master'];

    public function __construct(
        private PaymentEnvironment $env,
        private UserStatsService $stats,
        private InvoiceService $invoices,
    ) {}

    // ------------------------------------------------------------------
    // Overview
    // ------------------------------------------------------------------

    public function overview(): array
    {
        $today = Carbon::now('UTC')->startOfDay();
        $monthStart = $today->copy()->startOfMonth();

        $blocked = $this->blockedKeys();
        $invoices = $this->invoiceQuery()->get();
        $unpaid = $invoices->whereIn('status', ['pending', 'overdue', 'failed']);
        $overdue = $unpaid->filter(fn ($i) => $this->isOverdue($i));

        $signalsToday = TradeLog::where('ts', '>=', $today)->get();
        $outcomes = $this->outcomes($signalsToday);

        $master = $this->masterAccounts();
        $masterToday = $this->pastRows($master)
            ->filter(fn ($p) => $p->closed_at >= $today->toDateTimeString());

        $pendingUsers = UserCredential::where('status', 'pending')
            ->orderBy('created_at')
            ->get(['uni_id', 'name', 'email', 'created_at']);

        return [
            'attention' => [
                'blocked_keys' => $blocked->take(25)->values(),
                'blocked_keys_count' => $blocked->count(),
                'pending_users' => $pendingUsers->take(10)->map(fn ($u) => [
                    'uni_id' => $u->uni_id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'created_at' => $this->iso($u->created_at),
                ])->values(),
                'pending_users_count' => $pendingUsers->count(),
                'overdue_invoices' => [
                    'count' => $overdue->count(),
                    'amount' => round((float) $overdue->sum('total_fee'), 2),
                ],
                'unpaid_invoices' => [
                    'count' => $unpaid->count(),
                    'amount' => round((float) $unpaid->sum('total_fee'), 2),
                ],
                'unmatched_transfers' => TronTransfer::where('status', TronTransfer::STATUS_UNMATCHED)->count(),
                'signals_today' => [
                    'signals' => $signalsToday->count(),
                    'with_problems' => $signalsToday->where('success', false)->count(),
                    'filled' => (int) $signalsToday->sum('filled'),
                    'skipped' => (int) $signalsToday->sum('skipped'),
                    'failed' => (int) $signalsToday->sum('failed'),
                    'reasons' => $outcomes['reasons'],
                ],
                'paused_for_payment' => $this->pausedForPayment($overdue)->count(),
            ],
            'headline' => [
                'master_balance' => $master->isEmpty() ? null
                    : round((float) $master->whereNull('deleted_at')->sum('balance'), 2),
                'master_today_pnl' => round((float) $masterToday->sum('realized_pnl'), 2),
                'master_today_trades' => $masterToday->count(),
                'active_traders_today' => count($outcomes['filled_users']),
                'customers_live' => $this->customerAccounts()
                    ->whereNull('deleted_at')->pluck('uni_id')->unique()->count(),
                'collected_this_month' => round((float) $invoices
                    ->where('status', 'paid')
                    ->filter(fn ($i) => $i->paid_at && Carbon::parse($i->paid_at)->gte($monthStart))
                    ->sum(fn ($i) => (float) ($i->paid_amount ?? $i->total_fee)), 2),
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Platform (Overview → Platform): every real account pooled
    // ------------------------------------------------------------------

    /**
     * The whole platform as ONE portfolio: money under management, realized
     * P&L over a few windows and today's activity, for customers, the master,
     * or both.
     *
     * LIVE accounts only (`deleted_at IS NULL`), unlike the master's track
     * record: this pairs P&L with capital, and a disconnected account's
     * capital is no longer on record, so its trades beside today's capital
     * would describe two different sets of money — the same rule
     * UserStatsService::displayAccounts() applies to every per-user screen.
     * P&L is AFTER fees as the headline, `gross` + `fees` beside it.
     */
    public function platform(string $scope): array
    {
        $now = Carbon::now('UTC');
        $today = $now->copy()->startOfDay();
        $accounts = $this->scopedAccounts($scope);
        $trades = UserStatsService::withFeeBasis(
            $this->platformTrades($accounts, ['uni_id', 'realized_pnl', 'exchange_fee', 'closed_at'])
        );

        $window = function (?Carbon $since) use ($trades): array {
            $rows = $since === null ? $trades
                : $trades->filter(fn ($t) => Carbon::parse($t->closed_at)->gte($since));

            return [
                'net' => round((float) $rows->sum('pnl_net'), 2),
                'gross' => round((float) $rows->sum('pnl_gross'), 2),
                'fees' => round((float) $rows->sum('pnl_fee'), 2),
                'trades' => $rows->count(),
            ];
        };

        $customerIds = UserCredential::where('type', 'user')->pluck('uni_id')->flip();
        $isCustomer = fn ($a) => isset($customerIds[$a->uni_id]);
        $customers = $accounts->filter($isCustomer);
        $master = $accounts->reject($isCustomer);
        $flows30 = $this->flowsSince($accounts, $now->copy()->subDays(30));
        $balance = (float) $accounts->sum('balance');
        $unrealized = (float) $accounts->sum('unrealized_pnl');
        $scored = $trades->whereNotNull('pnl_net');

        return [
            'scope' => $scope,
            'under_management' => [
                // Same keys as money()['under_management'], so one card renders both.
                'customers' => round((float) $customers->sum('balance'), 2),
                'customer_accounts' => $customers->count(),
                'master' => round((float) $master->sum('balance'), 2),
                'deposits_30d' => round($flows30['deposits'], 2),
                'withdrawals_30d' => round($flows30['withdrawals'], 2),
                'balance' => round($balance, 2),
                'unrealized' => round($unrealized, 2),
                'equity' => round($balance + $unrealized, 2),
                'by_exchange' => $accounts->groupBy('exchange')->map(fn ($accs, $ex) => [
                    'exchange' => $ex,
                    'accounts' => $accs->count(),
                    'balance' => round((float) $accs->sum('balance'), 2),
                ])->sortByDesc('balance')->values(),
            ],
            'pnl' => [
                'today' => $window($today),
                'd7' => $window($today->copy()->subDays(7)),
                'month' => $window($today->copy()->startOfMonth()),
                'all' => $window(null),
            ],
            'win_rate' => $scored->isEmpty() ? null
                : round($scored->where('pnl_net', '>', 0)->count() / $scored->count() * 100, 1),
            'accounts_live' => $accounts->count(),
            'owners' => $accounts->pluck('uni_id')->unique()->count(),
            'traders_today' => $trades
                ->filter(fn ($t) => Carbon::parse($t->closed_at)->gte($today))
                ->pluck('uni_id')->unique()->count(),
        ];
    }

    /**
     * The platform's P&L calendar — the same days map as every per-user
     * calendar (UserStatsService::calendarDays), over the pooled accounts:
     * each day's `pct` is the pooled P&L over the pooled capital that day
     * started with, and every trade names its owner.
     */
    public function platformDailyPnl(string $scope): array
    {
        $accounts = $this->scopedAccounts($scope);
        $trades = $this->platformTrades($accounts, UserStatsService::DAY_TRADE_COLUMNS);
        $names = UserCredential::whereIn('uni_id', $accounts->pluck('uni_id')->unique())
            ->pluck('name', 'uni_id')->all();

        return $this->stats->calendarDays($accounts, $trades, $names);
    }

    /** Live real-money accounts of the scope: customers, the master, or both. */
    private function scopedAccounts(string $scope): Collection
    {
        $accounts = match ($scope) {
            'customers' => $this->customerAccounts(),
            'master' => $this->masterAccounts(),
            default => $this->customerAccounts()->concat($this->masterAccounts()),
        };

        return $accounts->whereNull('deleted_at')->values();
    }

    /** Closed trades of $accounts, sandbox-flagged rows dropped like pastRows(). */
    private function platformTrades(Collection $accounts, array $columns): Collection
    {
        return $this->stats->pastPositions($accounts, [...$columns, 'is_sandbox'])
            ->filter(fn ($t) => (int) $t->is_sandbox === 0)
            ->values();
    }

    // ------------------------------------------------------------------
    // Customers
    // ------------------------------------------------------------------

    public function customers(): array
    {
        $now = Carbon::now('UTC');
        $customers = UserCredential::where('type', 'user')
            ->get(['uni_id', 'name', 'email', 'status', 'created_at']);
        $names = $customers->pluck('name', 'uni_id');

        // Every customer account, live AND demo, for the per-exchange split;
        // the funnel and money figures use the live ones only.
        $all = $this->customerAccounts(includeDemo: true);
        $live = $all->where('demo', 0);
        $connected = $live->whereNull('deleted_at');
        $flows = $this->netFlowsByKey($connected);
        $minDeposit = (float) config('services.engine.min_deposit', 1000);

        $funded = $connected->filter(
            fn ($a) => (float) $a->initial_deposit + ($flows[$a->exchange.'|'.$a->api_key] ?? 0.0) >= $minDeposit
        );

        $closed7 = $this->pastRows($live, $now->copy()->subDays(7));
        $closed30 = $this->pastRows($live, $now->copy()->subDays(30));

        $logs30 = TradeLog::where('ts', '>=', $now->copy()->subDays(30))->get();
        $filledSince = fn (Carbon $since) => count($this->outcomes(
            $logs30->filter(fn ($l) => $l->ts && $l->ts->gte($since))
        )['filled_users']);

        // Customers the engine passed over most in the last 7 days.
        $missed = $this->outcomes(
            $logs30->filter(fn ($l) => $l->ts && $l->ts->gte($now->copy()->subDays(7)))
        )['missed_by_user'];
        $customerIds = $names->keys()->flip();
        $mostMissed = collect($missed)
            ->filter(fn ($row, $uni) => isset($customerIds[$uni]))
            ->map(fn ($row, $uni) => [
                'uni_id' => $uni,
                'name' => $names[$uni] ?? $uni,
                'missed' => $row['count'],
                'top_reason' => collect($row['reasons'])->sortDesc()->keys()->first(),
            ])
            ->sortByDesc('missed')->take(10)->values();

        $weeks = [];
        for ($i = 11; $i >= 0; $i--) {
            $start = $now->copy()->startOfWeek()->subWeeks($i);
            $weeks[] = [
                'week' => $start->toDateString(),
                'signups' => $customers->filter(
                    fn ($c) => $c->created_at && Carbon::parse($c->created_at)->between($start, $start->copy()->endOfWeek())
                )->count(),
            ];
        }

        $byExchange = [];
        foreach (ExchangeSchema::supported() as $ex) {
            $rows = $all->where('exchange', $ex)->whereNull('deleted_at');
            $byExchange[] = [
                'exchange' => $ex,
                'live' => $rows->where('demo', 0)->count(),
                'demo' => $rows->where('demo', 1)->count(),
                'capital' => round((float) $rows->where('demo', 0)->sum('balance'), 2),
            ];
        }

        $capitalByUser = $connected->groupBy('uni_id')
            ->map(fn ($rows) => (float) $rows->sum('balance'));
        $pnlByUser = $closed30->groupBy('uni_id')
            ->map(fn ($rows) => (float) $rows->sum('realized_pnl'));

        $top = fn (Collection $values) => $values->sortDesc()->take(10)
            ->map(fn ($v, $uni) => ['uni_id' => $uni, 'name' => $names[$uni] ?? $uni, 'value' => round($v, 2)])
            ->values();

        return [
            'min_deposit' => $minDeposit,
            'funnel' => [
                ['key' => 'signed_up', 'count' => $customers->count()],
                ['key' => 'approved', 'count' => $customers->where('status', 'active')->count()],
                ['key' => 'connected', 'count' => $connected->pluck('uni_id')->unique()->count()],
                ['key' => 'funded', 'count' => $funded->pluck('uni_id')->unique()->count()],
                ['key' => 'traded_7d', 'count' => $closed7->pluck('uni_id')->unique()->count()],
            ],
            'active' => [
                'today' => $filledSince($now->copy()->startOfDay()),
                'd7' => $filledSince($now->copy()->subDays(7)),
                'd30' => $filledSince($now->copy()->subDays(30)),
            ],
            'status' => [
                'pending' => $customers->where('status', 'pending')->count(),
                'active' => $customers->where('status', 'active')->count(),
                'suspended' => $customers->where('status', 'suspended')->count(),
            ],
            'stopped_30d' => [
                'disconnected' => $live->filter(
                    fn ($a) => $a->deleted_at && Carbon::parse($a->deleted_at)->gte($now->copy()->subDays(30))
                )->count(),
                'disabled' => $connected->where('enabled', 0)->count(),
                'key_blocked' => $connected->where('key_status', ExchangeAccount::KEY_BLOCKED)->count(),
            ],
            'signups_weekly' => $weeks,
            'by_exchange' => $byExchange,
            'top_capital' => $top($capitalByUser),
            'top_pnl_30d' => $top($pnlByUser),
            'most_missed_7d' => $mostMissed,
        ];
    }

    // ------------------------------------------------------------------
    // Money
    // ------------------------------------------------------------------

    public function money(): array
    {
        $now = Carbon::now('UTC');
        $invoices = $this->invoiceQuery()->get();
        $names = UserCredential::whereIn('uni_id', $invoices->pluck('user_id')->unique())
            ->pluck('name', 'uni_id');

        $months = [];
        for ($i = 11; $i >= 0; $i--) {
            $m = $now->copy()->startOfMonth()->subMonths($i)->format('Y-m');
            $rows = $invoices->where('month_year', $m);
            $months[] = [
                'month' => $m,
                'invoiced' => round((float) $rows->sum('total_fee'), 2),
                'collected' => round((float) $rows->where('status', 'paid')->sum('total_fee'), 2),
                'outstanding' => round((float) $rows->where('status', '!=', 'paid')->sum('total_fee'), 2),
                'count' => $rows->count(),
            ];
        }

        $paid = $invoices->where('status', 'paid')->filter(fn ($i) => $i->paid_at);
        $daysToPay = $paid->map(
            fn ($i) => Carbon::parse($i->created_at)->floatDiffInDays(Carbon::parse($i->paid_at))
        );
        $onTime = $paid->filter(
            fn ($i) => ! $i->due_date || Carbon::parse($i->paid_at)->lte(Carbon::parse($i->due_date)->endOfDay())
        );

        $overdue = $invoices->whereIn('status', ['pending', 'overdue', 'failed'])
            ->filter(fn ($i) => $this->isOverdue($i))
            ->sortBy('due_date')
            ->take(25)
            ->map(fn ($i) => [
                'id' => $i->id,
                'uni_id' => $i->user_id,
                'name' => $names[$i->user_id] ?? $i->user_id,
                'exchange' => $i->exchange,
                'month' => $i->month_year,
                'amount' => round((float) $i->total_fee, 2),
                'due_date' => $this->iso($i->due_date),
                'days_late' => (int) floor(Carbon::parse($i->due_date)->endOfDay()->floatDiffInDays($now)),
            ])->values();

        $customer = $this->customerAccounts()->whereNull('deleted_at');
        $master = $this->masterAccounts()->whereNull('deleted_at');
        $flows30 = $this->flowsSince($customer, $now->copy()->subDays(30));

        return [
            'monthly' => $months,
            'totals' => [
                'invoiced' => round((float) $invoices->sum('total_fee'), 2),
                'collected' => round((float) $invoices->where('status', 'paid')->sum('total_fee'), 2),
                'outstanding' => round((float) $invoices->where('status', '!=', 'paid')->sum('total_fee'), 2),
            ],
            'avg_days_to_pay' => $daysToPay->isEmpty() ? null : round($daysToPay->avg(), 1),
            'on_time_share' => $paid->isEmpty() ? null : round($onTime->count() / $paid->count() * 100, 1),
            'overdue' => $overdue,
            'under_management' => [
                'customers' => round((float) $customer->sum('balance'), 2),
                'customer_accounts' => $customer->count(),
                'master' => round((float) $master->sum('balance'), 2),
                'deposits_30d' => round($flows30['deposits'], 2),
                'withdrawals_30d' => round($flows30['withdrawals'], 2),
            ],
            'transfers' => [
                'unmatched' => TronTransfer::where('status', TronTransfer::STATUS_UNMATCHED)->count(),
                'ignored' => TronTransfer::where('status', TronTransfer::STATUS_IGNORED)->count(),
                'settled' => TronTransfer::where('status', TronTransfer::STATUS_SETTLED)->count(),
            ],
            'forecast' => $this->forecast($now),
        ];
    }

    /**
     * "Future invoice": what the running month would bill if it closed NOW.
     *
     * Every figure comes from InvoiceService::computeForAccount — the same
     * math `generateForAccount` persists, run without writing — so the
     * forecast is the invoice the customer would get, not a second estimate
     * of it. REALIZED fee only (owner, 2026-09-29): the unrealized share
     * swings with open positions until the month closes. The high-water-mark
     * gate is still the invoice's own (equity incl. open P&L above the HWM),
     * because that is what decides whether a fee is charged at all.
     *
     * Customers' LIVE, real accounts only. An exchange with no P&L source
     * yet (MEXC, Bybit) is counted, not guessed at.
     */
    private function forecast(Carbon $now): array
    {
        $month = $now->format('Y-m');
        $live = $this->customerAccounts()->whereNull('deleted_at');
        $billable = $live->filter(fn ($a) => InvoiceService::canInvoice($a->exchange));
        $users = UserCredential::whereIn('uni_id', $live->pluck('uni_id')->unique())
            ->get(['uni_id', 'name', 'realized_percentage', 'unrealized_percentage'])
            ->keyBy('uni_id');
        $models = BinanceAccount::whereIn('id', $billable->where('exchange', 'binance')->pluck('id'))
            ->get()->keyBy('id');

        $rows = $billable->map(function ($a) use ($month, $users, $models) {
            $model = $models[$a->id] ?? null;
            if (! $model) {
                return null;
            }
            $user = $users[$a->uni_id] ?? null;
            $rate = (float) ($user?->realized_percentage ?? 20);
            $inv = $this->invoices->computeForAccount($model, $month, [
                'realized' => $rate,
                'unrealized' => (float) ($user?->unrealized_percentage ?? 6),
            ], $a->exchange);
            $realized = (float) $inv['realized_pnl'];

            return [
                'uni_id' => $a->uni_id,
                'name' => $user?->name ?? $a->uni_id,
                'account' => $a->name,
                'exchange' => $a->exchange,
                'realized_pnl' => round($realized, 2),
                'rate' => $rate,
                'hwm' => round((float) $inv['hwm_before'], 2),
                'equity' => round((float) $inv['equity_end'], 2),
                'fee' => round((float) $inv['fee_realized'], 2),
                'status' => $realized <= 0 ? 'no_profit'
                    : ((float) $inv['equity_end'] > (float) $inv['hwm_before'] ? 'billable' : 'below_hwm'),
            ];
        })->filter()->sortByDesc(fn ($r) => [$r['fee'], $r['realized_pnl']])->values();

        return [
            'month' => $month,
            'as_of' => $now->toIso8601String(),
            'total' => round($rows->sum('fee'), 2),
            'realized_pnl' => round($rows->sum('realized_pnl'), 2),
            'billable_accounts' => $rows->where('status', 'billable')->count(),
            'accounts' => $rows->all(),
            'not_supported' => $live->reject(fn ($a) => InvoiceService::canInvoice($a->exchange))
                ->countBy('exchange')
                ->map(fn ($n, $ex) => ['exchange' => $ex, 'accounts' => $n])
                ->values()->all(),
        ];
    }

    // ------------------------------------------------------------------
    // System
    // ------------------------------------------------------------------

    public function system(): array
    {
        $today = Carbon::now('UTC')->startOfDay();
        $venues = [];
        foreach (ExchangeSchema::supported() as $ex) {
            $s = ExchangeSchema::for($ex);
            $logs = TradeLog::where('exchange', $ex)->where('ts', '>=', $today)->get();
            $errors = [];
            foreach ($logs as $log) {
                foreach (is_array($log->details) ? $log->details : [] as $row) {
                    if (($row['status'] ?? null) === 'failed') {
                        $msg = $this->shortError((string) ($row['error'] ?? $row['reason'] ?? 'unknown'));
                        $errors[$msg] = ($errors[$msg] ?? 0) + 1;
                    }
                }
            }
            arsort($errors);

            $venues[] = [
                'exchange' => $ex,
                'last_signal_at' => $this->iso(TradeLog::where('exchange', $ex)->max('ts')),
                'last_close_synced_at' => $this->iso(DB::table($s->pastPositions)->max('created_at')),
                'last_key_check_at' => $this->iso(DB::table($s->accountsTable)->max('key_checked_at')),
                'signals_today' => $logs->count(),
                'failed_today' => (int) $logs->sum('failed'),
                'keys_blocked_today' => DB::table($s->accountsTable)
                    ->whereNull('deleted_at')->where('key_blocked_at', '>=', $today)->count(),
                'errors_today' => collect($errors)->take(5)
                    ->map(fn ($n, $msg) => ['message' => $msg, 'count' => $n])->values(),
            ];
        }

        $networks = array_map(function (string $network) {
            $t = $this->env->tron($network);

            return [
                'name' => $network,
                'label' => $t['label'],
                'configured' => $t['configured'],
                'last_scan_at' => TronGateway::lastScanAt($network)?->toIso8601String(),
                'scan_stale' => $t['configured'] && TronGateway::scanIsStale($network),
            ];
        }, array_keys((array) config('payments.tron.networks', [])));

        return ['venues' => $venues, 'payment_watcher' => $networks];
    }

    // ------------------------------------------------------------------
    // Strategies
    // ------------------------------------------------------------------

    /**
     * Closed trades per strategy for a scope, from every exchange's table.
     * The client computes the statistics (lib/strategyStats.ts), so every
     * trade row carries what that math reads plus `exchange` and `uni_id`.
     *
     * @param  'master'|'customers'|'all'  $scope
     */
    public function strategyTrades(string $scope, ?string $exchange = null): Collection
    {
        $accounts = match ($scope) {
            'master' => $this->masterAccounts(),
            'customers' => $this->customerAccounts(),
            default => $this->masterAccounts()->concat($this->customerAccounts()),
        };
        if ($exchange) {
            $accounts = $accounts->where('exchange', $exchange);
        }

        return $this->pastRows($accounts)
            ->filter(fn ($p) => trim((string) $p->strategy) !== '')
            ->sortBy('closed_at')
            ->map(fn ($p) => [
                'strategy' => $p->strategy,
                'symbol' => $p->symbol,
                'realized_pnl' => $p->realized_pnl !== null ? (float) $p->realized_pnl : null,
                'exchange_fee' => $p->exchange_fee !== null ? (float) $p->exchange_fee : null,
                'closed_at' => $p->closed_at,
                'exchange' => $p->exchange,
                'uni_id' => $p->uni_id,
                'increments' => (int) ($p->increments_closed ?? 1),
            ])
            ->values();
    }

    /**
     * Per-strategy signal reliability (from the engine's own signal log) and
     * how each customer's result compares with the master's on the same
     * strategy over the same window.
     */
    public function strategyInsights(?string $exchange, ?string $from, ?string $to): array
    {
        $logs = TradeLog::query()
            ->whereNotNull('strategy')->where('strategy', '!=', '')
            ->when($exchange, fn ($q) => $q->where('exchange', $exchange))
            ->when($from, fn ($q) => $q->where('ts', '>=', $from.' 00:00:00'))
            ->when($to, fn ($q) => $q->where('ts', '<=', $to.' 23:59:59'))
            ->get();

        $reliability = [];
        foreach ($logs->groupBy('strategy') as $strategy => $rows) {
            $signals = $rows->where('category', '!=', 'rejected');
            $fanned = $signals->filter(fn ($l) => (int) $l->target_count > 0 || (int) $l->filled + (int) $l->failed + (int) $l->skipped > 0);
            $out = $this->outcomes($rows);

            $reliability[$strategy] = [
                'signals' => $signals->count(),
                'rejected' => $rows->where('category', 'rejected')->count(),
                'full' => $fanned->filter(fn ($l) => (int) $l->filled > 0 && (int) $l->failed + (int) $l->skipped === 0)->count(),
                'partial' => $fanned->filter(fn ($l) => (int) $l->filled > 0 && (int) $l->failed + (int) $l->skipped > 0)->count(),
                'missed' => $fanned->filter(fn ($l) => (int) $l->filled === 0)->count(),
                'account_fills' => (int) $rows->sum('filled'),
                'account_skips' => (int) $rows->sum('skipped'),
                'account_fails' => (int) $rows->sum('failed'),
                'reasons' => $out['reasons'],
                'last_signal_at' => $this->iso($rows->max('ts')),
            ];
        }

        // Customers vs master: the master's result is what the strategy
        // "should" have delivered; a customer far below it on the same
        // strategy missed trades or was sized differently.
        $inRange = function (Collection $rows) use ($from, $to) {
            return $rows->filter(function ($p) use ($from, $to) {
                $d = substr((string) $p['closed_at'], 0, 10);

                return (! $from || $d >= $from) && (! $to || $d <= $to);
            });
        };
        $master = $inRange($this->strategyTrades('master', $exchange));
        $customers = $inRange($this->strategyTrades('customers', $exchange));
        $names = UserCredential::whereIn('uni_id', $customers->pluck('uni_id')->unique())
            ->pluck('name', 'uni_id');
        $capital = $this->customerAccounts()->whereNull('deleted_at')
            ->groupBy('uni_id')->map(fn ($r) => (float) $r->sum('balance'));
        $masterCapital = (float) $this->masterAccounts()->whereNull('deleted_at')->sum('balance');

        $compare = [];
        foreach ($master->concat($customers)->pluck('strategy')->unique() as $strategy) {
            $m = $master->where('strategy', $strategy);
            $mTrades = (int) $m->sum('increments');
            $mStats = $this->tradeStats($m, $masterCapital);

            $rows = $customers->where('strategy', $strategy)->groupBy('uni_id')
                ->map(function (Collection $t, $uni) use ($mTrades, $capital, $names) {
                    $stats = $this->tradeStats($t, (float) ($capital[$uni] ?? 0));

                    return [
                        'uni_id' => $uni,
                        'name' => $names[$uni] ?? $uni,
                        'trades' => (int) $t->sum('increments'),
                        'participation' => $mTrades > 0 ? round(min(1, $t->sum('increments') / $mTrades) * 100, 1) : null,
                    ] + $stats;
                })
                ->sortBy(fn ($r) => $r['participation'] ?? 101)
                ->values();

            $compare[$strategy] = [
                'master' => ['trades' => $mTrades] + $mStats,
                'customers_avg' => [
                    'customers' => $rows->count(),
                    'win_rate' => $rows->whereNotNull('win_rate')->avg('win_rate'),
                    'return_pct' => $rows->whereNotNull('return_pct')->avg('return_pct'),
                    'participation' => $rows->whereNotNull('participation')->avg('participation'),
                ],
                'customers' => $rows->take(15),
            ];
        }

        return ['reliability' => $reliability, 'compare' => $compare];
    }

    // ------------------------------------------------------------------
    // Shared helpers
    // ------------------------------------------------------------------

    /**
     * @return array{win_rate: float|null, pnl: float, return_pct: float|null}
     */
    private function tradeStats(Collection $trades, float $capital): array
    {
        $n = $trades->count();
        $pnl = (float) $trades->sum(fn ($t) => (float) ($t['realized_pnl'] ?? 0));

        return [
            'win_rate' => $n ? round($trades->filter(fn ($t) => (float) ($t['realized_pnl'] ?? 0) > 0)->count() / $n * 100, 1) : null,
            'pnl' => round($pnl, 2),
            // Over TODAY's balance — a rough "how much did it move this
            // account", good for comparing like with like, not a track record.
            'return_pct' => $capital > 0 ? round($pnl / $capital * 100, 2) : null,
        ];
    }

    /**
     * Every account of the master's that traded real money, soft-deleted
     * included.
     */
    private function masterAccounts(): Collection
    {
        $masterIds = UserCredential::where('type', 'master')->pluck('uni_id');

        return $this->accounts()
            ->filter(fn ($a) => $masterIds->contains($a->uni_id) && (int) $a->demo === 0);
    }

    /** Customer accounts (type = user), soft-deleted included; demo excluded unless asked. */
    private function customerAccounts(bool $includeDemo = false): Collection
    {
        $ids = UserCredential::where('type', 'user')->pluck('uni_id')->flip();

        return $this->accounts()->filter(
            fn ($a) => isset($ids[$a->uni_id]) && ($includeDemo || (int) $a->demo === 0)
        )->values();
    }

    /** Every non-sandbox account on every exchange, tagged with its exchange. */
    private function accounts(): Collection
    {
        if ($this->accountsCache !== null) {
            return $this->accountsCache;
        }
        $rows = collect();
        foreach (ExchangeSchema::supported() as $ex) {
            $rows = $rows->concat(
                DB::table(ExchangeSchema::for($ex)->accountsTable)
                    ->where('is_sandbox', 0)
                    ->where('api_key', 'not like', self::SANDBOX_KEY_PREFIX.'%')
                    ->get(['id', 'uni_id', 'api_key', 'name', 'balance', 'unrealized_pnl', 'initial_deposit', 'demo',
                        'enabled', 'key_status', 'key_blocked_at', 'deleted_at', 'created_at'])
                    ->each(fn ($a) => $a->exchange = $ex)
            );
        }

        return $this->accountsCache = $rows->values();
    }

    /** One read of every account per request; each tab slices it several ways. */
    private ?Collection $accountsCache = null;

    /** Closed trades of the given accounts, each from its own exchange's table. */
    private function pastRows(Collection $accounts, ?Carbon $since = null): Collection
    {
        $rows = collect();
        foreach ($accounts->groupBy('exchange') as $ex => $accs) {
            $rows = $rows->concat(
                DB::table(ExchangeSchema::for($ex)->pastPositions)
                    ->whereIn('api_key', $accs->pluck('api_key')->all())
                    ->where('is_sandbox', 0)
                    ->when($since, fn ($q) => $q->where('closed_at', '>=', $since))
                    ->get(['uni_id', 'symbol', 'strategy', 'realized_pnl', 'exchange_fee',
                        'closed_at', 'increments_closed'])
                    ->each(fn ($p) => $p->exchange = $ex)
            );
        }

        return $rows->values();
    }

    /** @return array<string, float> "exchange|api_key" => deposits − withdrawals */
    private function netFlowsByKey(Collection $accounts): array
    {
        $out = [];
        foreach ($accounts->groupBy('exchange') as $ex => $accs) {
            DB::table(ExchangeSchema::for($ex)->transactions)
                ->whereIn('api_key', $accs->pluck('api_key')->all())
                ->get(['api_key', 'type', 'amount'])
                ->each(function ($t) use ($ex, &$out) {
                    $sign = $t->type === 'DEPOSIT' ? 1 : ($t->type === 'WITHDRAWAL' ? -1 : 0);
                    $out[$ex.'|'.$t->api_key] = ($out[$ex.'|'.$t->api_key] ?? 0.0) + $sign * (float) $t->amount;
                });
        }

        return $out;
    }

    /** @return array{deposits: float, withdrawals: float} */
    private function flowsSince(Collection $accounts, Carbon $since): array
    {
        $in = 0.0;
        $out = 0.0;
        foreach ($accounts->groupBy('exchange') as $ex => $accs) {
            $rows = DB::table(ExchangeSchema::for($ex)->transactions)
                ->whereIn('api_key', $accs->pluck('api_key')->all())
                ->where('created_at', '>=', $since)
                ->get(['type', 'amount']);
            $in += (float) $rows->where('type', 'DEPOSIT')->sum('amount');
            $out += (float) $rows->where('type', 'WITHDRAWAL')->sum('amount');
        }

        return ['deposits' => $in, 'withdrawals' => $out];
    }

    /**
     * Walk the fan-out details of a set of signal-log rows.
     *
     * @return array{reasons: array<string,int>, filled_users: array<string,true>,
     *               missed_by_user: array<string, array{count:int, reasons: array<string,int>}>}
     */
    private function outcomes(iterable $logs): array
    {
        $reasons = [];
        $filled = [];
        $missed = [];
        foreach ($logs as $log) {
            foreach (is_array($log->details) ? $log->details : [] as $row) {
                $status = $row['status'] ?? null;
                $uni = $row['uni_id'] ?? null;
                if ($status === 'filled') {
                    if ($uni) {
                        $filled[$uni] = true;
                    }

                    continue;
                }
                if ($status !== 'skipped' && $status !== 'failed') {
                    continue;
                }
                $reason = $status === 'failed'
                    ? 'order failed'
                    : strtolower(trim((string) ($row['reason'] ?? 'skipped')));
                $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
                if ($uni) {
                    $missed[$uni]['count'] = ($missed[$uni]['count'] ?? 0) + 1;
                    $missed[$uni]['reasons'][$reason] = ($missed[$uni]['reasons'][$reason] ?? 0) + 1;
                }
            }
        }
        arsort($reasons);

        return ['reasons' => $reasons, 'filled_users' => $filled, 'missed_by_user' => $missed];
    }

    /** Blocked keys on live, connected accounts of any owner, most urgent first. */
    private function blockedKeys(): Collection
    {
        $names = UserCredential::pluck('name', 'uni_id');

        return $this->accounts()
            ->whereNull('deleted_at')
            ->where('key_status', ExchangeAccount::KEY_BLOCKED)
            ->map(function ($a) use ($names) {
                $graceEnds = $a->key_blocked_at
                    ? Carbon::parse($a->key_blocked_at)->addDays(ExchangeAccount::KEY_GRACE_DAYS)
                    : null;

                return [
                    'id' => $a->id,
                    'exchange' => $a->exchange,
                    'account' => $a->name,
                    'uni_id' => $a->uni_id,
                    'owner' => $names[$a->uni_id] ?? $a->uni_id,
                    'demo' => (bool) $a->demo,
                    'days_left' => $graceEnds ? (int) ceil(now()->floatDiffInDays($graceEnds, false)) : null,
                ];
            })
            ->sortBy(fn ($r) => $r['days_left'] ?? 99)
            ->values();
    }

    /** Accounts switched off while their owner has an overdue invoice (engine:mark-overdue). */
    private function pausedForPayment(Collection $overdue): Collection
    {
        $owing = $overdue->pluck('user_id')->unique()->flip();

        return $this->customerAccounts()
            ->whereNull('deleted_at')
            ->where('enabled', 0)
            ->filter(fn ($a) => isset($owing[$a->uni_id]));
    }

    private function invoiceQuery()
    {
        return Invoice::query()->where(function ($q) {
            $q->whereNull('api_key')->orWhere('api_key', 'not like', self::SANDBOX_KEY_PREFIX.'%');
        });
    }

    private function isOverdue($invoice): bool
    {
        if ($invoice->status === 'overdue') {
            return true;
        }

        return $invoice->due_date !== null
            && Carbon::parse($invoice->due_date)->endOfDay()->isPast();
    }

    /** First line of an exchange error, capped — the group key for "errors today". */
    private function shortError(string $message): string
    {
        $line = trim(strtok($message, "\n") ?: $message);

        return mb_strlen($line) > 120 ? mb_substr($line, 0, 117).'…' : $line;
    }

    private function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value, 'UTC')->toIso8601String();
    }
}
