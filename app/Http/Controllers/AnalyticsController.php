<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    /**
     * GET /api/analytics
     * Aggregated performance analytics for the authenticated user,
     * computed from the binance_* tables (live accounts only).
     * Per-exchange data is shaped as arrays so mexc / bybit tables
     * can append later without contract changes.
     */
    public function index(Request $request): JsonResponse
    {
        $uniId = $request->user()->uni_id;

        $accounts = DB::table('binance_accounts')
            ->where('uni_id', $uniId)
            ->whereNull('deleted_at')
            ->where('demo', 0)
            ->where('enabled', 1)
            ->get();

        $past = DB::table('binance_pastpositions')
            ->where('uni_id', $uniId)
            ->orderBy('closed_at')
            ->get();

        $transactions = DB::table('binance_transactions')
            ->where('uni_id', $uniId)
            ->orderBy('created_at')
            ->get();

        // ---- Capital & returns ----------------------------------------
        $deposits = (float) $transactions->where('type', 'DEPOSIT')->sum('amount');
        $withdrawals = (float) $transactions->where('type', 'WITHDRAWAL')->sum('amount');
        $baseline = $deposits - $withdrawals;

        $currentCapital = (float) $accounts->sum('balance');
        $totalUnrealized = (float) $accounts->sum('unrealized_pnl');
        $totalRealized = (float) $past->sum('realized_pnl');
        $totalReturnAbs = $totalRealized + $totalUnrealized;
        $totalReturnPct = $baseline > 0 ? round($totalReturnAbs / $baseline * 100, 2) : null;

        // ---- Daily realized P&L (ascending by close date) -------------
        $dailyPnl = $past
            ->groupBy(fn ($p) => Carbon::parse($p->closed_at)->toDateString())
            ->map(fn ($rows) => round((float) $rows->sum('realized_pnl'), 2))
            ->sortKeys();

        $tradingDays = $dailyPnl->count();
        $avgDailyPnl = $tradingDays > 0 ? round($totalRealized / $tradingDays, 2) : null;

        $bestDay = null;
        $worstDay = null;
        foreach ($dailyPnl as $date => $pnl) {
            if ($bestDay === null || $pnl > $bestDay['pnl']) {
                $bestDay = ['date' => $date, 'pnl' => $pnl];
            }
            if ($worstDay === null || $pnl < $worstDay['pnl']) {
                $worstDay = ['date' => $date, 'pnl' => $pnl];
            }
        }

        // ---- Day-of-week breakdown (always all 7 keys, Mon..Sun) ------
        $dayOfWeek = [];
        foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $day) {
            $dayOfWeek[$day] = ['pnl' => 0.0, 'trades' => 0];
        }
        foreach ($past as $p) {
            $day = Carbon::parse($p->closed_at)->format('D');
            $dayOfWeek[$day]['pnl'] += (float) $p->realized_pnl;
            $dayOfWeek[$day]['trades']++;
        }
        foreach ($dayOfWeek as $day => $row) {
            $dayOfWeek[$day]['pnl'] = round($row['pnl'], 2);
        }

        // ---- Monthly breakdown (ascending) ----------------------------
        $monthly = [];
        $byMonth = $past
            ->groupBy(fn ($p) => Carbon::parse($p->closed_at)->format('Y-m'))
            ->sortKeys();
        foreach ($byMonth as $month => $rows) {
            $wins = $rows->where('realized_pnl', '>', 0)->count();
            $monthly[] = [
                'month' => $month,
                'pnl' => round((float) $rows->sum('realized_pnl'), 2),
                'trades' => $rows->count(),
                'win_rate' => $rows->count() ? round($wins / $rows->count() * 100, 2) : 0.0,
            ];
        }

        // ---- Per-symbol breakdown (top 12 by |realized P&L|) ----------
        $bySymbol = [];
        foreach ($past->groupBy('symbol') as $symbol => $rows) {
            $bySymbol[] = [
                'symbol' => $symbol,
                'trades' => $rows->count(),
                'realized_pnl' => round((float) $rows->sum('realized_pnl'), 2),
            ];
        }
        usort($bySymbol, fn ($a, $b) => abs($b['realized_pnl']) <=> abs($a['realized_pnl']));
        $bySymbol = array_slice($bySymbol, 0, 12);

        // ---- Per-exchange (array so new exchanges append later) -------
        $byExchange = [
            [
                'exchange' => 'Binance',
                'balance' => round($currentCapital, 2),
                'unrealized' => round($totalUnrealized, 2),
            ],
        ];

        // ---- Flows -----------------------------------------------------
        $recentFlows = $transactions
            ->sortByDesc('created_at')
            ->take(5)
            ->map(fn ($t) => [
                'type' => $t->type,
                'amount' => round((float) $t->amount, 2),
                'date' => Carbon::parse($t->created_at)->toDateString(),
            ])
            ->values()
            ->all();

        $flows = [
            'deposits' => round($deposits, 2),
            'withdrawals' => round($withdrawals, 2),
            'net_flow' => round($baseline, 2),
            'recent' => $recentFlows,
        ];

        // ---- Trade quality --------------------------------------------
        $wins = $past->where('realized_pnl', '>', 0);
        $losses = $past->where('realized_pnl', '<', 0);
        $grossWin = (float) $wins->sum('realized_pnl');
        $grossLoss = abs((float) $losses->sum('realized_pnl'));
        $tradeCount = $past->count();

        $quality = [
            'wins' => $wins->count(),
            'losses' => $losses->count(),
            'win_rate' => $tradeCount ? round($wins->count() / $tradeCount * 100, 2) : 0.0,
            'profit_factor' => $grossLoss > 0 ? round($grossWin / $grossLoss, 2) : null,
            'avg_win' => $wins->count() ? round($grossWin / $wins->count(), 2) : 0.0,
            'avg_loss' => $losses->count() ? round(-$grossLoss / $losses->count(), 2) : 0.0,
            'largest_win' => $tradeCount ? round(max(0.0, (float) $past->max('realized_pnl')), 2) : 0.0,
            'largest_loss' => $tradeCount ? round(min(0.0, (float) $past->min('realized_pnl')), 2) : 0.0,
            'expectancy' => $tradeCount ? round($totalRealized / $tradeCount, 2) : 0.0,
        ];

        // ---- Risk: drawdown on the cumulative daily P&L curve ---------
        // Curve seeded at baseline, then one point per trading day.
        $equity = $baseline;
        $peak = $baseline;
        $maxDrawdownAbs = 0.0;
        $peakAtMaxDrawdown = $baseline;
        foreach ($dailyPnl as $pnl) {
            $equity += $pnl;
            if ($equity > $peak) {
                $peak = $equity;
            }
            $drawdown = $peak - $equity;
            if ($drawdown > $maxDrawdownAbs) {
                $maxDrawdownAbs = $drawdown;
                $peakAtMaxDrawdown = $peak;
            }
        }
        $maxDrawdownPct = $peakAtMaxDrawdown > 0
            ? round($maxDrawdownAbs / $peakAtMaxDrawdown * 100, 2)
            : null;

        // Streaks: longest runs of consecutive winning / losing days.
        $bestStreak = 0;
        $worstStreak = 0;
        $winRun = 0;
        $lossRun = 0;
        foreach ($dailyPnl as $pnl) {
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

        // ---- Risk: return-based ratios (daily fractional returns) -----
        // Reuse the same daily P&L series (ascending). Walk an equity
        // curve seeded at baseline; each day's return is pnl / equity
        // *before* that day's P&L is applied. Days where equity <= 0 are
        // skipped (no meaningful return when underwater / no capital).
        $returns = [];
        $equityR = $baseline;
        foreach ($dailyPnl as $pnl) {
            if ($equityR > 0) {
                $returns[] = $pnl / $equityR;
            }
            $equityR += $pnl;
        }

        $n = count($returns);
        $annualize = sqrt(252);

        $volatility = null;
        $sharpe = null;
        $sortino = null;
        $riskScore = null;

        if ($n >= 2) {
            $mean = array_sum($returns) / $n;

            // Sample standard deviation (denominator n-1).
            $sumSq = 0.0;
            foreach ($returns as $r) {
                $sumSq += ($r - $mean) ** 2;
            }
            $sd = sqrt($sumSq / ($n - 1));

            if ($sd > 0) {
                $volatility = round($sd * $annualize * 100, 2);
                $sharpe = round(($mean / $sd) * $annualize, 2);
            }

            // Downside deviation: sqrt( sum(min(r,0)^2) / n ). No losing
            // days => 0 => Sortino undefined (null, not infinite).
            $downSq = 0.0;
            foreach ($returns as $r) {
                $downSq += (min($r, 0.0)) ** 2;
            }
            $downsideDev = sqrt($downSq / $n);
            if ($downsideDev > 0) {
                $sortino = round(($mean / $downsideDev) * $annualize, 2);
            }

            // Composite 0-100 heuristic (higher = better): centred at 55,
            // rewards Sharpe, penalises drawdown depth. Clamped to [0,100].
            if ($sharpe !== null) {
                $rawScore = 55 + 12 * $sharpe - 0.4 * abs($maxDrawdownPct ?? 0);
                $riskScore = round(max(0.0, min(100.0, $rawScore)), 2);
            }
        }

        $risk = [
            'max_drawdown_abs' => round($maxDrawdownAbs, 2),
            'max_drawdown_pct' => $maxDrawdownPct,
            'best_streak' => $bestStreak,
            'worst_streak' => $worstStreak,
            'volatility' => $volatility,
            'sharpe' => $sharpe,
            'sortino' => $sortino,
            'risk_score' => $riskScore,
        ];

        return response()->json([
            'success' => true,
            'analytics' => [
                'baseline' => round($baseline, 2),
                'current_capital' => round($currentCapital, 2),
                'total_unrealized' => round($totalUnrealized, 2),
                'total_realized' => round($totalRealized, 2),
                'total_return_abs' => round($totalReturnAbs, 2),
                'total_return_pct' => $totalReturnPct,
                'trading_days' => $tradingDays,
                'avg_daily_pnl' => $avgDailyPnl,
                'best_day' => $bestDay,
                'worst_day' => $worstDay,
                'daily_pnl' => $dailyPnl,
                'day_of_week' => $dayOfWeek,
                'monthly' => $monthly,
                'by_symbol' => $bySymbol,
                'by_exchange' => $byExchange,
                'flows' => $flows,
                'quality' => $quality,
                'risk' => $risk,
            ],
        ]);
    }
}
