<?php

namespace App\Services;

use App\Models\BinanceAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Per-user (uni_id) trading statistics shared by the user dashboard and the
 * admin user-detail endpoints, so both compute identical numbers.
 */
class UserStatsService
{
    /**
     * Accounts whose money a user's dashboard should show: everything still
     * connected (`deleted_at IS NULL`).
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
    public function displayAccounts(string $uniId): Collection
    {
        return DB::table('binance_accounts')
            ->where('uni_id', $uniId)
            ->whereNull('deleted_at')
            ->get();
    }

    /**
     * Every exchange account including soft-deleted (disconnected) ones —
     * feeds the admin account cards.
     */
    public function allAccounts(string $uniId)
    {
        return BinanceAccount::withTrashed()
            ->where('uni_id', $uniId)
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * The full trading-dashboard summary block (GET /dashboard/summary).
     * Mechanical extraction of DashboardController::summary — the math is
     * unchanged; the controller only wraps this in the JSON envelope.
     */
    public function summary(string $uniId, ?Collection $accounts = null): array
    {
        $accounts ??= $this->displayAccounts($uniId);

        $balance = (float) $accounts->sum('balance');
        $unrealized = (float) $accounts->sum('unrealized_pnl');
        $equity = $balance + $unrealized;

        $past = DB::table('binance_pastpositions')
            ->where('uni_id', $uniId)
            ->orderBy('closed_at')
            ->get();

        $transactions = DB::table('binance_transactions')
            ->where('uni_id', $uniId)
            ->orderBy('created_at')
            ->get();

        $realized = (float) $past->sum('realized_pnl');
        $deposits = (float) $transactions->where('type', 'DEPOSIT')->sum('amount');
        $withdrawals = (float) $transactions->where('type', 'WITHDRAWAL')->sum('amount');
        $netDeposits = $deposits - $withdrawals;
        $pctBase = $netDeposits > 0 ? $netDeposits : max($equity, 1);

        // ---- Equity curve: replay deposits/withdrawals + closed trades ----
        $events = collect();
        foreach ($transactions as $t) {
            $events->push([
                'at' => Carbon::parse($t->created_at),
                'delta' => $t->type === 'WITHDRAWAL' ? -(float) $t->amount : (float) $t->amount,
            ]);
        }
        foreach ($past as $p) {
            $events->push([
                'at' => Carbon::parse($p->closed_at),
                'delta' => (float) $p->realized_pnl,
            ]);
        }
        $events = $events->sortBy('at')->values();

        $curve = [];
        $running = 0.0;
        foreach ($events as $e) {
            $running += $e['delta'];
            $curve[] = ['date' => $e['at']->toDateString(), 'equity' => round($running, 2)];
        }
        // Present point includes unrealized P&L
        $curve[] = ['date' => Carbon::now()->toDateString(), 'equity' => round($running + $unrealized, 2)];

        // ---- Metrics ---------------------------------------------------
        $wins = $past->where('realized_pnl', '>', 0);
        $losses = $past->where('realized_pnl', '<', 0);
        $grossWin = (float) $wins->sum('realized_pnl');
        $grossLoss = abs((float) $losses->sum('realized_pnl'));
        $tradeCount = $past->count();

        $peak = 0.0;
        $maxDrawdown = 0.0;
        foreach ($curve as $point) {
            $peak = max($peak, $point['equity']);
            if ($peak > 0) {
                $maxDrawdown = min($maxDrawdown, ($point['equity'] - $peak) / $peak * 100);
            }
        }

        // Daily realized P&L (also feeds the calendar)
        $dailyPnl = $past
            ->groupBy(fn ($p) => Carbon::parse($p->closed_at)->toDateString())
            ->map(fn ($rows) => round((float) $rows->sum('realized_pnl'), 2));

        $sharpe = null;
        if ($dailyPnl->count() >= 2) {
            $values = $dailyPnl->values();
            $mean = $values->avg();
            $variance = $values->map(fn ($v) => ($v - $mean) ** 2)->avg();
            $std = sqrt($variance);
            if ($std > 0) {
                $sharpe = round($mean / $std * sqrt(252) / 10, 2); // scaled annualized approximation
            }
        }

        $metrics = [
            'net_pnl' => round($realized + $unrealized, 2),
            'win_rate' => $tradeCount ? round($wins->count() / $tradeCount * 100, 1) : null,
            'profit_factor' => $grossLoss > 0 ? round($grossWin / $grossLoss, 2) : null,
            'expectancy' => $tradeCount ? round($realized / $tradeCount, 2) : null,
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
                $points = [];
                foreach ($rows as $r) {
                    $cum += (float) $r->realized_pnl;
                    $points[] = ['date' => Carbon::parse($r->closed_at)->toDateString(), 'cum' => round($cum, 2)];
                }
                $w = $rows->where('realized_pnl', '>', 0);
                $l = $rows->where('realized_pnl', '<', 0);
                $gw = (float) $w->sum('realized_pnl');
                $gl = abs((float) $l->sum('realized_pnl'));
                $out[] = [
                    'id' => $key,
                    'total' => round($cum, 2),
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
        $invoices = DB::table('invoices')
            ->where('user_id', $uniId)
            ->where('exchange', 'binance')
            ->get();
        $hwm = (float) ($invoices->max('hwm_after') ?? 0);
        $hwm = max($hwm, $equity);

        $currentMonth = Carbon::now()->format('Y-m');
        $monthFee = (float) $invoices->where('month_year', $currentMonth)->sum('total_fee');
        $commissions = [
            'total' => round($monthFee, 2),
            'month' => $currentMonth,
            'rows' => [
                ['exchange' => 'Binance', 'amount' => round($monthFee, 2)],
            ],
        ];

        // ---- Realized P&L breakdown (today / 7d / month-to-date) ------
        $today = Carbon::today();
        $sumSince = fn (Carbon $since) => round(
            (float) $past->filter(fn ($p) => Carbon::parse($p->closed_at)->gte($since))->sum('realized_pnl'),
            2
        );
        $pnlBreakdown = [
            'daily' => $sumSince($today),
            'weekly' => $sumSince($today->copy()->subDays(7)),
            'monthly' => $sumSince($today->copy()->startOfMonth()),
        ];

        return [
            'equity' => round($equity, 2),
            'balance' => round($balance, 2),
            'realized_pnl' => round($realized, 2),
            'unrealized_pnl' => round($unrealized, 2),
            'total_pnl' => round($realized + $unrealized, 2),
            'net_deposits' => round($netDeposits, 2),
            'pct_base' => round($pctBase, 2),
            'equity_curve' => $curve,
            'metrics' => $metrics,
            'daily_pnl' => $dailyPnl,
            'by_asset' => $byAsset,
            'by_strategy' => $byStrategy,
            'hwm' => round($hwm, 2),
            'commissions' => $commissions,
            'pnl_breakdown' => $pnlBreakdown,
        ];
    }

    /**
     * Closed-trade P&L grouped by calendar day, each day carrying its
     * individual trades — the days map behind every P&L calendar
     * (user dashboard, admin dashboard and admin user detail).
     */
    public function dailyPnlDays(string $uniId): array
    {
        $past = DB::table('binance_pastpositions')
            ->where('uni_id', $uniId)
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

        return $days;
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
     * Deposit / withdrawal totals and transaction counts from
     * binance_transactions.
     */
    public function capitalFlow(string $uniId): array
    {
        $transactions = DB::table('binance_transactions')
            ->where('uni_id', $uniId)
            ->get(['type', 'amount']);

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
     * all time, and all time actually paid.
     */
    public function commissions(string $uniId): array
    {
        $invoices = DB::table('invoices')
            ->where('user_id', $uniId)
            ->get(['month_year', 'total_fee', 'status']);

        $currentMonth = Carbon::now()->format('Y-m');

        return [
            'this_month' => round((float) $invoices->where('month_year', $currentMonth)->sum('total_fee'), 2),
            'all_time' => round((float) $invoices->sum('total_fee'), 2),
            'all_time_paid' => round((float) $invoices->where('status', 'paid')->sum('total_fee'), 2),
        ];
    }
}
