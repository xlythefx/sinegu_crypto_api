<?php

namespace App\Services;

use App\Services\Exchanges\ExchangeSchema;
use App\Services\Pnl\TradingFee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Per-user (uni_id) trading statistics shared by the user dashboard and the
 * admin user-detail endpoints, so both compute identical numbers.
 *
 * Every read here spans EVERY supported exchange by default (Binance + MEXC
 * today, each in its own `{x}_*` tables) and narrows to one when the caller
 * passes the venue the dashboard's top-bar filter names. The scope is decided
 * ONCE, in displayAccounts(): rows come back stamped with `exchange`, and
 * pastPositions() / transactions() follow that stamp to the right table per
 * account — so a screen can never sum MEXC equity beside Binance-only P&L.
 */
class UserStatsService
{
    /**
     * The exchanges a per-user read spans: all supported ones for null/'all',
     * else exactly the one named. Unknown names throw — controllers validate
     * with normalizeExchange() first and answer 400.
     *
     * @return list<string>
     */
    public static function exchanges(?string $exchange = null): array
    {
        return $exchange === null || $exchange === 'all'
            ? ExchangeSchema::supported()
            : [ExchangeSchema::for($exchange)->exchange];
    }

    /**
     * A `?exchange=` query value → 'all' | a supported exchange, or null when
     * it names nothing we have tables for (bybit, a typo). Missing means all.
     */
    public static function normalizeExchange(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return 'all';
        }
        if (! is_string($raw)) {
            return null;
        }
        $value = strtolower(trim($raw));

        return $value === 'all' || ExchangeSchema::isSupported($value) ? $value : null;
    }

    /**
     * Accounts whose money a user's dashboard should show: everything still
     * connected (`deleted_at IS NULL`) on the exchange(s) asked for, each row
     * stamped with its `exchange`.
     *
     * Deliberately NOT filtered by `demo` or `enabled`, because the realized
     * P&L / trade history on the same screens is keyed by uni_id alone. When
     * this filtered demo accounts out, a testnet user saw $0.00 equity beside
     * a real P&L figure — and the percentage divided by the `max($equity, 1)`
     * fallback, producing nonsense like +17515%. `enabled` is excluded for the
     * same reason: the overdue-invoice job flips it to stop trading, and that
     * must not make a user's balance look like it vanished.
     *
     * Who may TRADE is a separate question, answered by EngineController
     * (enabled + non-sandbox + owner not suspended). Billing is likewise
     * independent — invoices are generated per account, demo opt-in.
     */
    public function displayAccounts(string $uniId, ?string $exchange = null): Collection
    {
        $rows = collect();
        foreach (self::exchanges($exchange) as $ex) {
            $rows = $rows->concat(
                DB::table(ExchangeSchema::for($ex)->accountsTable)
                    ->where('uni_id', $uniId)
                    ->whereNull('deleted_at')
                    ->get()
                    ->each(fn ($a) => $a->exchange = $ex)
            );
        }

        return $rows->values();
    }

    /**
     * The api_keys behind displayAccounts() — the scope every per-user trade
     * and funding query must use.
     *
     * Trade and transaction rows carry `uni_id` denormalized, so scoping them
     * by uni_id alone counts rows belonging to accounts displayAccounts() has
     * already excluded. Disconnecting an account soft-deletes it but leaves its
     * trades behind, so its P&L kept being reported beside an equity figure
     * that no longer included that account — one screen describing two
     * different sets of money.
     *
     * Demo accounts deliberately stay IN, for the reason documented on
     * displayAccounts(): equity and P&L have to agree, and filtering `demo`
     * here would resurrect the $0.00-equity-vs-real-P&L bug from the other
     * direction.
     *
     * Prefer pastPositions() / transactions(), which pair each key with its
     * own exchange's table; this flat list is for callers that already know
     * which table they are reading.
     *
     * @return list<string>
     */
    public function displayApiKeys(string $uniId, ?string $exchange = null): array
    {
        return $this->displayAccounts($uniId, $exchange)->pluck('api_key')->all();
    }

    /**
     * Closed trades of the given accounts, read from each account's own
     * exchange table and merged oldest-first, every row stamped `exchange`.
     * Accounts come from displayAccounts() (or allAccounts()); a row without
     * the stamp is treated as Binance, the only table that predates it.
     */
    public function pastPositions(Collection $accounts, array $columns = ['*']): Collection
    {
        return $this->perExchange($accounts, fn (ExchangeSchema $s) => $s->pastPositions, $columns)
            ->sortBy('closed_at')
            ->values();
    }

    /**
     * Deposits / withdrawals of the given accounts, per exchange table,
     * oldest-first, stamped `exchange`.
     */
    public function transactions(Collection $accounts, array $columns = ['*']): Collection
    {
        return $this->perExchange($accounts, fn (ExchangeSchema $s) => $s->transactions, $columns)
            ->sortBy('created_at')
            ->values();
    }

    /**
     * Open positions of the given accounts, per exchange table, stamped
     * `exchange`, ordered by symbol.
     */
    public function openPositions(Collection $accounts, array $columns = ['*']): Collection
    {
        return $this->perExchange($accounts, fn (ExchangeSchema $s) => $s->positions, $columns)
            ->sortBy('symbol')
            ->values();
    }

    /**
     * One query per exchange present in $accounts against the table $table
     * picks off that exchange's schema, keyed by the api_keys of that
     * exchange's accounts only — a Binance key is never looked up in
     * `mexc_pastpositions` and vice versa.
     */
    private function perExchange(Collection $accounts, callable $table, array $columns): Collection
    {
        $rows = collect();
        foreach ($accounts->groupBy(fn ($a) => $a->exchange ?? 'binance') as $exchange => $group) {
            $schema = ExchangeSchema::for($exchange);
            $rows = $rows->concat(
                DB::table($table($schema))
                    ->whereIn('api_key', $group->pluck('api_key')->all())
                    ->get($columns)
                    ->each(fn ($r) => $r->exchange = $exchange)
            );
        }

        return $rows;
    }

    /**
     * Every exchange account on every supported exchange, including
     * soft-deleted (disconnected) ones, newest first, each model stamped
     * `exchange` — feeds the admin account cards.
     */
    public function allAccounts(string $uniId)
    {
        $rows = collect();
        foreach (ExchangeSchema::supported() as $ex) {
            $model = ExchangeSchema::for($ex)->accountModel;
            $rows = $rows->concat(
                $model::withTrashed()
                    ->where('uni_id', $uniId)
                    ->get()
                    ->each(fn ($a) => $a->exchange = $ex)
            );
        }

        return $rows->sortByDesc('created_at')->values();
    }

    /**
     * The dashboard's equity curve: one point per TRADING DAY, shaped by
     * realized P&L alone and anchored so the last point IS the live equity.
     *
     * Shape and level are computed separately, and that separation is the whole
     * design:
     *
     *  - **Shape** is the daily realized P&L walk over $base. Deposits and
     *    withdrawals are not points on this line; they only move $base. The
     *    chart therefore answers "what did trading do to the capital", and a
     *    withdrawal can never draw itself as a crash.
     *  - **Level** is pinned by a single constant offset, `$equity - $lastCum`,
     *    applied to every point. Shifting the whole series preserves each day's
     *    move exactly while landing the final point on the balance the exchange
     *    itself reports — the one figure here that is not a reconstruction.
     *
     * This replaced a replay of funding + trades accumulated from zero, which
     * could not be made honest. That curve was only ever as complete as
     * `binance_transactions`, and the transfers poller asks Binance for the last
     * three days only: an account trading before it was connected has no early
     * funding rows, so the replay opened at whatever its first trade happened to
     * make. On the live master that was **$2.52** against ~1,050 of real
     * capital, and the drawdown divided by it to publish −3392%. Anchoring
     * needs no complete ledger — unseen transfers, commissions and funding fees
     * all land in the one offset instead of bending the line.
     *
     * Pure (no DB, no clock) so the shape is testable.
     *
     * @param  Collection  $dailyPnl  date string => net realized P&L, ascending
     * @param  float  $base    committed capital (adjustedDeposit)
     * @param  float  $equity  live account equity, the anchor
     */
    public static function buildDailyEquityCurve(Collection $dailyPnl, float $base, float $equity): array
    {
        $curve = [];
        $running = $base;
        foreach ($dailyPnl as $date => $pnl) {
            $running += (float) $pnl;
            $at = Carbon::parse($date)->endOfDay();
            $curve[] = [
                'date' => $at->toDateString(),
                'at' => $at->toIso8601String(),
                'equity' => $running,
            ];
        }

        if ($curve === []) {
            // Funded, nothing closed yet: a single point at today's equity.
            $now = Carbon::now();

            return [[
                'date' => $now->toDateString(),
                'at' => $now->toIso8601String(),
                'equity' => round($equity, 2),
            ]];
        }

        $offset = $equity - $running;

        return array_map(fn (array $p) => [
            'date' => $p['date'],
            'at' => $p['at'],
            'equity' => round($p['equity'] + $offset, 2),
        ], $curve);
    }

    /**
     * Max drawdown, as a NEGATIVE percentage, measured on the cumulative
     * realized-P&L curve seeded at the account's capital base.
     *
     * Deliberately NOT measured on buildEquityCurve()'s output, which is what
     * this replaced. That curve is a replay of FUNDING plus trades starting
     * from zero, and each half of it breaks a drawdown in its own way:
     *
     *  - **The denominator was whatever the running peak happened to be at the
     *    time.** The live master's first closed trade (2026-05-11) was +2.52
     *    and its first RECORDED deposit did not land until 2026-07-01, so the
     *    peak was still $2.52 when a −82.30 trade closed two days later, and
     *    the dashboard published **−3392.46%**. A drawdown divided by the first
     *    trade's own profit describes nothing about the account.
     *  - **Funding sat inside the curve**, so taking capital OUT scored as
     *    losing it. A withdrawal is not a drawdown.
     *
     * Seeded at $baseline — net deposits, the same `pct_base` every other
     * percentage on the card divides by and the same baseline
     * AnalyticsController seeds — so `/dashboard` and `/dashboard/analytics`
     * stop publishing two different drawdowns for one account.
     *
     * The percentage is taken against the peak AT THE TROUGH, not the highest
     * peak ever reached: a later run-up must not shrink a drawdown that already
     * happened.
     *
     * @param  list<float>  $dailyPnl  net realized P&L per trading day, ascending
     */
    public static function maxDrawdownPct(array $dailyPnl, float $baseline): float
    {
        if ($baseline <= 0) {
            return 0.0;
        }

        $equity = $baseline;
        $peak = $baseline;
        $worst = 0.0;
        $peakAtWorst = $baseline;
        foreach ($dailyPnl as $pnl) {
            $equity += $pnl;
            $peak = max($peak, $equity);
            if ($peak - $equity > $worst) {
                $worst = $peak - $equity;
                $peakAtWorst = $peak;
            }
        }

        $pct = round($worst / $peakAtWorst * 100, 2);

        return $pct > 0 ? -$pct : 0.0;
    }

    /**
     * Annualized Sharpe from the equity curve's daily fractional RETURNS.
     *
     * Returns, not dollar P&L: a $50 swing is a different risk on a 500 account
     * than on a 50,000 one, and the ratio is meant to be comparable between
     * users. The previous version took mean/σ of daily P&L in dollars and then
     * divided by an unexplained 10, which published 0.09 for an account the
     * mother dashboard read at 1.08 — the /10 was a display fudge, not maths.
     *
     * Null (rather than 0) when there is nothing to measure: fewer than two
     * daily returns, or a flat series with no deviation. 0 would read as "we
     * measured this account and it scored zero".
     *
     * @param  list<array{equity: float}>  $curve
     */
    public static function sharpeFromCurve(array $curve): ?float
    {
        $returns = [];
        for ($i = 1, $n = count($curve); $i < $n; $i++) {
            $prev = (float) $curve[$i - 1]['equity'];
            if ($prev > 0) {
                $returns[] = ((float) $curve[$i]['equity'] - $prev) / $prev;
            }
        }

        $count = count($returns);
        if ($count < 2) {
            return null;
        }

        $mean = array_sum($returns) / $count;
        $variance = 0.0;
        foreach ($returns as $r) {
            $variance += ($r - $mean) ** 2;
        }
        $sd = sqrt($variance / $count);

        return $sd > 0 ? round($mean / $sd * sqrt(252), 2) : null;
    }

    /**
     * Stamp every closed-trade row with BOTH P&L bases, so a screen can show
     * the strategy's result before exchange fees and still say what landed.
     *
     * `realized_pnl` on the row is what the account received — net of the
     * exchange's commission + funding from TradingFee::NET_SINCE on, gross
     * before it (a decision, see TradingFee). This adds:
     *  - `pnl_net`    the stored figure, as a float (null while unbackfilled)
     *  - `pnl_fee`    the fee taken out of it; 0.0 when none is recorded
     *  - `fee_known`  whether a fee is recorded at all — false on every
     *                 pre-cutoff row, so a total of `pnl_fee` across the
     *                 cutoff is "fees we know of", not "fees paid"
     *  - `pnl_gross`  pnl_net + pnl_fee: the price move alone, the number the
     *                 trading dashboard and analytics lead with
     *
     * Nothing here reads the DB; the caller's query decides the rows.
     */
    public static function withFeeBasis(Collection $rows): Collection
    {
        return $rows->map(function ($p) {
            $net = $p->realized_pnl === null ? null : (float) $p->realized_pnl;
            $fee = $p->exchange_fee === null ? null : (float) $p->exchange_fee;
            $p->pnl_net = $net;
            $p->pnl_fee = $fee ?? 0.0;
            $p->fee_known = $fee !== null;
            $p->pnl_gross = $net === null ? null : round($net + ($fee ?? 0.0), 8);

            return $p;
        });
    }

    /**
     * The fee block every before-fees screen carries beside its totals: how
     * much the exchange took out of the rows shown, and how many of those rows
     * have no fee on record (history before the cutoff), so the UI can say
     * "fees recorded from …" instead of printing a total that quietly omits
     * a third of the trades.
     */
    public static function feeSummary(Collection $rows): array
    {
        $known = $rows->where('fee_known', true)->count();

        return [
            'total' => round((float) $rows->sum('pnl_fee'), 2),
            'trades_with_fee' => $known,
            'trades_without_fee' => $rows->count() - $known,
            'since' => Carbon::parse(TradingFee::NET_SINCE)->toDateString(),
        ];
    }

    /**
     * The full trading-dashboard summary block (GET /dashboard/summary).
     *
     * BASIS: every P&L-derived figure here — the metrics rail, the equity
     * curve, the by-asset / by-strategy series, the period breakdown — is
     * computed BEFORE exchange fees (`pnl_gross`), because the dashboard's
     * question is "how did the strategy do", and fees are a cost of running
     * it, not a property of it. Each money figure ships its after-fees twin
     * (`*_net`, `equity` beside `equity_gross`, `cum_net` beside `cum`) so the
     * UI can show the three-line breakdown on hover; `realized_pnl` /
     * `total_pnl` keep their after-fees meaning for the readers that list
     * trades beside them (Positions page, admin user detail).
     *
     * SCOPE: $exchange is the top-bar filter — null/'all' pools every
     * supported exchange into one portfolio (equity summed, closed trades
     * merged, one commissions row per venue); a venue name narrows every
     * figure to that venue's accounts and tables alone. The payload names the
     * scope it was computed for (`exchange`, `exchanges`, `accounts`).
     */
    public function summary(string $uniId, ?string $exchange = null, ?Collection $accounts = null): array
    {
        $exchanges = self::exchanges($exchange);
        $accounts ??= $this->displayAccounts($uniId, $exchange);

        $balance = (float) $accounts->sum('balance');
        $unrealized = (float) $accounts->sum('unrealized_pnl');
        $equity = $balance + $unrealized;

        // Scoped by the same accounts that produced $equity above, not by
        // uni_id — see displayApiKeys(). A caller passing its own $accounts
        // gets its trades narrowed to that same list.
        $past = self::withFeeBasis($this->pastPositions($accounts));

        $transactions = $this->transactions($accounts);

        $realized = (float) $past->sum('pnl_net');
        $realizedGross = (float) $past->sum('pnl_gross');
        $fees = self::feeSummary($past);
        $deposits = (float) $transactions->where('type', 'DEPOSIT')->sum('amount');
        $withdrawals = (float) $transactions->where('type', 'WITHDRAWAL')->sum('amount');
        $netDeposits = $deposits - $withdrawals;

        // Committed capital: what the account was funded with plus every net
        // flow since. `initial_deposit` stands in for funding that predates the
        // transfers poller, which only ever asks Binance for the last few days
        // — so an account that traded before it was connected has NO early
        // transfer rows at all. Leaving it out divided the header percentages
        // by transfers alone. Same figure BinancePnlSource::adjustedDeposit
        // bills on, so the dashboard and the invoice agree on the capital base.
        $adjustedDeposit = (float) $accounts->sum('initial_deposit') + $netDeposits;
        $pctBase = $adjustedDeposit > 0 ? $adjustedDeposit : max($equity, 1);

        // ---- Equity curve ----------------------------------------------
        // Daily realized P&L over the capital base, anchored to live equity.
        // The anchored (after-fees) curve ends on the balance the exchange
        // reports; the before-fees curve is the same walk with each day's fees
        // added back, so it shares the start level and ends at balance +
        // fees — what the account would hold had the exchange charged nothing.
        $byDay = $past->groupBy(fn ($p) => Carbon::parse($p->closed_at)->toDateString());
        $dailyPnl = $byDay->map(fn ($rows) => round((float) $rows->sum('pnl_net'), 2));
        $dailyGross = $byDay->map(fn ($rows) => round((float) $rows->sum('pnl_gross'), 2));
        $dailyFees = $byDay->map(fn ($rows) => round((float) $rows->sum('pnl_fee'), 2));

        $curve = self::buildDailyEquityCurve($dailyPnl, $adjustedDeposit, $equity);
        $cumFees = 0.0;
        foreach ($curve as $i => $point) {
            $cumFees += (float) ($dailyFees[$point['date']] ?? 0.0);
            $curve[$i]['equity_gross'] = round($point['equity'] + $cumFees, 2);
        }

        // ---- Metrics (before fees) --------------------------------------
        $wins = $past->where('pnl_gross', '>', 0);
        $losses = $past->where('pnl_gross', '<', 0);
        $grossWin = (float) $wins->sum('pnl_gross');
        $grossLoss = abs((float) $losses->sum('pnl_gross'));
        $tradeCount = $past->count();

        // Drawdown is measured on the curve the user is looking at, so its
        // baseline is that curve's own starting level — equity BEFORE any of
        // the realized P&L was made — not $pctBase. The two differ whenever
        // `initial_deposit` is set, and the curve is the honest denominator:
        // a dip is only meaningful against the capital it dipped from. Both
        // curves start at the same level; the walk is the before-fees one.
        $maxDrawdown = self::maxDrawdownPct($dailyGross->values()->all(), $equity - $realized);

        $sharpe = self::sharpeFromCurve(array_map(
            fn (array $p) => ['equity' => $p['equity_gross']],
            $curve,
        ));

        $metrics = [
            'net_pnl' => round($realized + $unrealized, 2),
            'gross_pnl' => round($realizedGross + $unrealized, 2),
            'fees' => $fees['total'],
            'win_rate' => $tradeCount ? round($wins->count() / $tradeCount * 100, 1) : null,
            'profit_factor' => $grossLoss > 0 ? round($grossWin / $grossLoss, 2) : null,
            'expectancy' => $tradeCount ? round($realizedGross / $tradeCount, 2) : null,
            'avg_rr' => ($losses->count() && $wins->count() && $grossLoss > 0)
                ? round(($grossWin / $wins->count()) / ($grossLoss / $losses->count()), 1)
                : null,
            'max_drawdown' => round($maxDrawdown, 2),
            'sharpe' => $sharpe,
            'trades' => $tradeCount,
        ];

        // ---- Grouped cumulative P&L (by asset / by strategy) ----------
        $groupSeries = function ($grouped) {
            $out = [];
            foreach ($grouped as $key => $rows) {
                $rows = collect($rows)->sortBy('closed_at')->values();
                $cum = 0.0;
                $cumNet = 0.0;
                $points = [];
                foreach ($rows as $r) {
                    $cum += (float) $r->pnl_gross;
                    $cumNet += (float) $r->pnl_net;
                    $points[] = [
                        'date' => Carbon::parse($r->closed_at)->toDateString(),
                        'cum' => round($cum, 2),
                        'cum_net' => round($cumNet, 2),
                    ];
                }
                $w = $rows->where('pnl_gross', '>', 0);
                $l = $rows->where('pnl_gross', '<', 0);
                $gw = (float) $w->sum('pnl_gross');
                $gl = abs((float) $l->sum('pnl_gross'));
                $out[] = [
                    'id' => $key,
                    'total' => round($cum, 2),
                    'total_net' => round($cumNet, 2),
                    'fees' => round((float) $rows->sum('pnl_fee'), 2),
                    'trades' => $rows->count(),
                    'win_rate' => $rows->count() ? round($w->count() / $rows->count() * 100, 1) : null,
                    'profit_factor' => $gl > 0 ? round($gw / $gl, 2) : null,
                    'curve' => $points,
                ];
            }
            usort($out, fn ($a, $b) => $b['total'] <=> $a['total']);

            return $out;
        };

        $byAsset = $groupSeries($past->groupBy('symbol'));
        $byStrategy = $groupSeries($past->groupBy(fn ($p) => $p->strategy ?? 'Manual'));

        // ---- High-water mark & commissions ----------------------------
        // Invoices are already per exchange (the unified table's
        // discriminator), so the same filter narrows them.
        $invoices = DB::table('invoices')
            ->where('user_id', $uniId)
            ->whereIn('exchange', $exchanges)
            ->get();
        $hwm = (float) ($invoices->max('hwm_after') ?? 0);
        $hwm = max($hwm, $equity);

        $currentMonth = Carbon::now()->format('Y-m');
        $monthInvoices = $invoices->where('month_year', $currentMonth);
        $commissions = [
            'total' => round((float) $monthInvoices->sum('total_fee'), 2),
            'month' => $currentMonth,
            'rows' => array_map(fn (string $ex) => [
                'exchange' => ExchangeSchema::for($ex)->brokerLabel,
                'amount' => round((float) $monthInvoices->where('exchange', $ex)->sum('total_fee'), 2),
            ], $exchanges),
        ];

        // ---- Realized P&L breakdown (today / 7d / month-to-date) ------
        $today = Carbon::today();
        $sumSince = fn (Carbon $since, string $field) => round(
            (float) $past->filter(fn ($p) => Carbon::parse($p->closed_at)->gte($since))->sum($field),
            2
        );
        $periods = [
            'daily' => $today,
            'weekly' => $today->copy()->subDays(7),
            'monthly' => $today->copy()->startOfMonth(),
        ];
        $pnlBreakdown = [];
        $pnlBreakdownNet = [];
        $pnlBreakdownFees = [];
        foreach ($periods as $key => $since) {
            $pnlBreakdown[$key] = $sumSince($since, 'pnl_gross');
            $pnlBreakdownNet[$key] = $sumSince($since, 'pnl_net');
            $pnlBreakdownFees[$key] = $sumSince($since, 'pnl_fee');
        }

        return [
            'exchange' => $exchange ?? 'all',
            'exchanges' => $exchanges,
            // Connected accounts inside this scope — zero under a venue filter
            // means "nothing connected on that exchange", which the card shows
            // instead of a $0.00 equity that reads as a wiped account.
            'accounts' => $accounts->count(),
            'equity' => round($equity, 2),
            'balance' => round($balance, 2),
            'realized_pnl' => round($realized, 2),
            'realized_pnl_gross' => round($realizedGross, 2),
            'unrealized_pnl' => round($unrealized, 2),
            'total_pnl' => round($realized + $unrealized, 2),
            'total_pnl_gross' => round($realizedGross + $unrealized, 2),
            'fees' => $fees,
            'net_deposits' => round($netDeposits, 2),
            'pct_base' => round($pctBase, 2),
            'equity_curve' => $curve,
            'metrics' => $metrics,
            'daily_pnl' => $dailyPnl,
            'daily_pnl_gross' => $dailyGross,
            'by_asset' => $byAsset,
            'by_strategy' => $byStrategy,
            'hwm' => round($hwm, 2),
            'commissions' => $commissions,
            'pnl_breakdown' => $pnlBreakdown,
            'pnl_breakdown_net' => $pnlBreakdownNet,
            'pnl_breakdown_fees' => $pnlBreakdownFees,
        ];
    }

    /** The columns every P&L calendar reads off a closed trade. */
    public const DAY_TRADE_COLUMNS = [
        // `id` and `exit_price` are what let an admin correct a row straight
        // from the calendar's day popup (PUT/DELETE /admin/past-positions/{id});
        // the write itself is still gated by the admin middleware, this only
        // names the row. Each trade also says which exchange table that id
        // lives in, because ids repeat across the per-exchange tables.
        'id', 'uni_id', 'symbol', 'position_side', 'position_amt', 'realized_pnl',
        'exchange_fee', 'fee_source', 'exit_price', 'side', 'strategy', 'closed_at',
    ];

    /**
     * Closed-trade P&L grouped by calendar day, each day carrying its
     * individual trades — the days map behind every per-user P&L calendar
     * (user dashboard, admin master card and admin user detail).
     */
    public function dailyPnlDays(string $uniId, ?string $exchange = null): array
    {
        $accounts = $this->displayAccounts($uniId, $exchange);

        return $this->calendarDays($accounts, $this->pastPositions($accounts, self::DAY_TRADE_COLUMNS));
    }

    /**
     * The calendar days map for ANY set of accounts and their closed trades —
     * one user's (dailyPnlDays) or the whole platform's (the admin Overview).
     * $names, when given (uni_id => name), stamps each trade with its owner,
     * which a calendar pooling several users needs and a per-user one does not.
     *
     * @param  array<string, string>|null  $names
     */
    public function calendarDays(Collection $accounts, Collection $trades, ?array $names = null): array
    {
        $past = self::withFeeBasis($trades->sortByDesc('closed_at')->values());

        // The balance each day STARTED with — after that day's transfers,
        // before its trades — so a cell's percentage is that day's P&L over
        // the money actually at work that day. Dividing by today's balance
        // (what the toggle did before) made a deposit rewrite the percentage
        // of every day already shown. Same walk as Performance Analytics'
        // `daily_capital`, so the two pages agree on any given day.
        $returns = self::dailyReturns(
            $past,
            self::flowsByDay($this->transactions($accounts, ['type', 'amount', 'created_at'])),
            $accounts,
        );

        // The calendar is the one before-fees-era screen that keeps AFTER fees
        // as its headline: a cell is "what landed that day". `total_gross` and
        // `fees` ride along for the hover.
        $days = [];
        foreach ($past->groupBy(fn ($t) => substr((string) $t->closed_at, 0, 10)) as $date => $trades) {
            $day = $returns[$date];
            $days[$date] = [
                'total' => $day['pnl'],
                'total_gross' => $day['pnl_gross'],
                // Null when the walk has no positive capital for the day (an
                // account with no deposit on record) — never a divide-by-pennies.
                'start_balance' => $day['start_balance'],
                'pct' => $day['pct'],
                'pct_gross' => $day['pct_gross'],
                'fees' => round((float) $trades->sum('pnl_fee'), 2),
                'wins' => $trades->where('pnl_net', '>', 0)->count(),
                'losses' => $trades->where('pnl_net', '<', 0)->count(),
                'trades' => $trades->map(fn ($t) => [
                    'id' => $t->id,
                    'exchange' => $t->exchange,
                    'symbol' => $t->symbol,
                    'position_side' => $t->position_side,
                    'position_amt' => (float) $t->position_amt,
                    'realized_pnl' => round((float) $t->realized_pnl, 2),
                    // The fee already taken out of realized_pnl, and whether
                    // it is the estimate or the exchange's receipts (null on
                    // a gross row) — so the popup can mark a pending "est.".
                    'exchange_fee' => $t->exchange_fee === null ? null : round((float) $t->exchange_fee, 2),
                    'fee_source' => $t->fee_source,
                    'exit_price' => $t->exit_price !== null
                        ? round((float) $t->exit_price, 8)
                        : null,
                    'side' => $t->side,
                    'strategy' => $t->strategy,
                    'closed_at' => $t->closed_at,
                ] + ($names === null ? [] : [
                    'uni_id' => $t->uni_id,
                    'name' => $names[$t->uni_id] ?? $t->uni_id,
                ]))->values(),
            ];
        }

        return $days;
    }

    /**
     * Each trading day's P&L and its percentage of the balance the day STARTED
     * with — the figure printed on every P&L calendar cell, and the row
     * {@see \App\Services\Pnl\DailyReturnStore} saves. One function so the
     * calendar and the saved table can never disagree about a day.
     *
     * `pct` is rounded to 2 dp HERE, on purpose: the Date Range card adds the
     * stored percentages up, and the sum has to be exactly the sum of the
     * numbers the calendar shows (4.00 + 1.00 = 5.00), not of their hidden
     * decimals.
     *
     * $trades must already carry the fee basis ({@see withFeeBasis}).
     *
     * @return array<string, array{start_balance: ?float, pnl: float, pnl_gross: float, pct: ?float, pct_gross: ?float, trades: int}>
     */
    public static function dailyReturns(Collection $trades, array $flowByDay, Collection $accounts): array
    {
        ['start' => $startBalance] = self::capitalWalk($trades, $flowByDay, $accounts);
        $pctOf = fn (float $pnl, ?float $base) => $base !== null && $base > 0
            ? round($pnl / $base * 100, 2)
            : null;

        $days = [];
        foreach ($trades->groupBy(fn ($t) => substr((string) $t->closed_at, 0, 10)) as $date => $rows) {
            $pnl = round((float) $rows->sum('pnl_net'), 2);
            $pnlGross = round((float) $rows->sum('pnl_gross'), 2);
            $balance = $startBalance[$date] ?? null;
            $days[$date] = [
                'start_balance' => $balance,
                'pnl' => $pnl,
                'pnl_gross' => $pnlGross,
                'pct' => $pctOf($pnl, $balance),
                'pct_gross' => $pctOf($pnlGross, $balance),
                'trades' => $rows->count(),
            ];
        }
        ksort($days);

        return $days;
    }

    /**
     * Net transfer per day, signed — a deposit positive, a withdrawal
     * negative, both netted when they land on the same day. Only days that
     * actually moved money appear, ascending.
     *
     * It is the capital chart's whole input, and the flow half of the walk
     * below. One map, because a day's transfers must mean the same thing to
     * the chart a customer reads and to the denominator it is measured on.
     *
     * @return array<string, float>
     */
    public static function flowsByDay(Collection $transactions): array
    {
        $flowByDay = [];
        foreach ($transactions as $tx) {
            $day = Carbon::parse($tx->created_at)->toDateString();
            $delta = (float) $tx->amount * ($tx->type === 'WITHDRAWAL' ? -1 : 1);
            $flowByDay[$day] = round(($flowByDay[$day] ?? 0.0) + $delta, 2);
        }
        ksort($flowByDay);

        return $flowByDay;
    }

    /**
     * Capital at the START of every day that carries a trade or a transfer —
     * after that day's deposits/withdrawals, before its P&L. One entry per
     * such day, keyed 'YYYY-MM-DD'.
     *
     * Shared by Performance Analytics (`daily_capital` / `daily_balance`)
     * and the P&L calendar (each cell's percentage), so a day reads the same
     * on both. It exists so the Performance card can report a period return
     * that a deposit cannot move. Dividing a window's P&L by one all-time baseline
     * made a September deposit change the percentage August had already
     * reported; chaining each day's P&L over the capital THAT day started
     * with cannot, because a flow only changes the days after it.
     *
     * The walk is deliberately the one {@see PublicStatsController} publishes,
     * so the two screens differ in scope and not in method: flows land before
     * the day's trades, and capital compounds with realized P&L.
     *
     * Two details are load-bearing:
     * - **Seeded from `initial_deposit`**, plus flows — the same capital base
     *   `BinancePnlSource` bills on. `{exchange}_transactions` holds only
     *   transfers the poller has SEEN, so an account funded before it was
     *   connected has none; a walk starting at zero divides day one's P&L by
     *   the pennies that happened to precede it, which is how the landing page
     *   once published −980% against a real +46.6%.
     * - **A gross row's commission comes off the CAPITAL.** Rows before
     *   `TradingFee::NET_SINCE` keep gross `realized_pnl` by the 2026-09-11
     *   decision, but the exchange still took the fee, so a walk that
     *   compounds them accumulates money the account never had (1,103 adrift
     *   on the live master). Estimated exactly as `TradingFee::estimate` does,
     *   at the row's own venue rate; `realized_pnl` is never touched.
     *
     * Days are UTC, like every other day bucket on this page — NOT the public
     * track record's Asia/Manila days, so the two use the same method on
     * slightly different boundaries and will not tie out to the decimal.
     *
     * `end` is the same walk read one step later — the balance the day CLOSED
     * on, after its transfers and its P&L. It is what the Date Range card
     * prints as the balance on its end date: the all-time net flow plus P&L to
     * date (what it printed before) counted every transfer made AFTER that
     * date and left out `initial_deposit`, so a range ending in August showed
     * September's deposits.
     *
     * `unrecorded_fees` is the per-day commission the walk charged to capital
     * for gross rows — returned so the Date Range card can print it as its
     * own line. Without it the card's "trading" figure silently carried the
     * estimate and disagreed with the realized P&L beside it (−984 against
     * −157 for August on the master).
     *
     * @return array{start: array<string, float>, end: array<string, float>, unrecorded_fees: array<string, float>}
     */
    public static function capitalWalk(
        Collection $trades,
        array $flowByDay,
        Collection $accounts,
    ): array {
        $pnlByDay = [];
        $unchargedFeeByDay = [];
        foreach ($trades as $trade) {
            if ($trade->pnl_net === null) {
                continue;
            }
            $day = Carbon::parse($trade->closed_at)->toDateString();
            $pnlByDay[$day] = ($pnlByDay[$day] ?? 0.0) + (float) $trade->pnl_net;

            if ($trade->exchange_fee === null) {
                $uncharged = TradingFee::estimate(
                    (float) $trade->position_amt,
                    $trade->exit_price === null ? null : (float) $trade->exit_price,
                    (string) $trade->exchange,
                );
                $unchargedFeeByDay[$day] = ($unchargedFeeByDay[$day] ?? 0.0) + ($uncharged ?? 0.0);
            }
        }

        $days = array_unique(array_merge(array_keys($pnlByDay), array_keys($flowByDay)));
        sort($days);

        $capital = (float) $accounts->sum('initial_deposit');
        $start = [];
        $end = [];
        foreach ($days as $day) {
            $capital += $flowByDay[$day] ?? 0.0;
            // Recorded even when non-positive: the card's `capital > 0` guard
            // is what drops such a day out of the chain, and a missing key
            // would instead read as "no data for this day".
            $start[$day] = round($capital, 2);
            $capital += ($pnlByDay[$day] ?? 0.0) - ($unchargedFeeByDay[$day] ?? 0.0);
            $end[$day] = round($capital, 2);
        }

        $unrecorded = [];
        foreach ($unchargedFeeByDay as $day => $fee) {
            if ($fee > 0) {
                $unrecorded[$day] = round($fee, 2);
            }
        }
        ksort($unrecorded);

        return ['start' => $start, 'end' => $end, 'unrecorded_fees' => $unrecorded];
    }

    /**
     * Longest runs of consecutive winning / losing DAYS over a
     * date-ascending map of daily P&L totals. Zero days break both runs.
     * (The streak loop lifted from AnalyticsController.)
     *
     * @return array{max_win_streak_days: int, max_loss_streak_days: int}
     */
    public function dayStreaks(iterable $dailyTotals): array
    {
        $bestStreak = 0;
        $worstStreak = 0;
        $winRun = 0;
        $lossRun = 0;
        foreach ($dailyTotals as $pnl) {
            if ($pnl > 0) {
                $winRun++;
                $lossRun = 0;
            } elseif ($pnl < 0) {
                $lossRun++;
                $winRun = 0;
            } else {
                $winRun = 0;
                $lossRun = 0;
            }
            $bestStreak = max($bestStreak, $winRun);
            $worstStreak = max($worstStreak, $lossRun);
        }

        return [
            'max_win_streak_days' => $bestStreak,
            'max_loss_streak_days' => $worstStreak,
        ];
    }

    /**
     * Deposit / withdrawal totals and transaction counts over the connected
     * accounts' transfer tables (every exchange, or the one named).
     */
    public function capitalFlow(string $uniId, ?string $exchange = null): array
    {
        $transactions = $this->transactions(
            $this->displayAccounts($uniId, $exchange),
            ['type', 'amount', 'created_at'],
        );

        $deposits = $transactions->where('type', 'DEPOSIT');
        $withdrawals = $transactions->where('type', 'WITHDRAWAL');
        $in = (float) $deposits->sum('amount');
        $out = (float) $withdrawals->sum('amount');

        return [
            'deposits' => round($in, 2),
            'deposit_count' => $deposits->count(),
            'withdrawals' => round($out, 2),
            'withdrawal_count' => $withdrawals->count(),
            'net_flow' => round($in - $out, 2),
        ];
    }

    /**
     * Commission (invoices.total_fee) sums for a user: current month,
     * all time, and all time actually paid — every exchange's invoices, or
     * the one named (the table carries the exchange discriminator).
     */
    public function commissions(string $uniId, ?string $exchange = null): array
    {
        $invoices = DB::table('invoices')
            ->where('user_id', $uniId)
            ->whereIn('exchange', self::exchanges($exchange))
            ->get(['month_year', 'total_fee', 'status']);

        $currentMonth = Carbon::now()->format('Y-m');

        return [
            'this_month' => round((float) $invoices->where('month_year', $currentMonth)->sum('total_fee'), 2),
            'all_time' => round((float) $invoices->sum('total_fee'), 2),
            'all_time_paid' => round((float) $invoices->where('status', 'paid')->sum('total_fee'), 2),
        ];
    }
}
