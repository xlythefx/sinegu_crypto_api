<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * GET /api/dashboard/asset-performance
     * Per-asset trading metrics + cumulative-P&L equity series, ranked by
     * total P&L. Mirrors the mother dashboard's UserAssetPerformance math.
     */
    public function assetPerformance(Request $request): JsonResponse
    {
        $uniId = $request->user()->uni_id;

        $accounts = DB::table('binance_accounts')
            ->where('uni_id', $uniId)
            ->whereNull('deleted_at')
            ->where('demo', 0)
            ->where('enabled', 1)
            ->get();
        $balance = (float) $accounts->sum('balance') + (float) $accounts->sum('unrealized_pnl');

        $past = DB::table('binance_pastpositions')
            ->where('uni_id', $uniId)
            ->orderBy('closed_at')
            ->get();

        $assets = [];
        foreach ($past->groupBy('symbol') as $symbol => $trades) {
            $wins = [];
            $losses = [];
            $cumulative = 0.0;
            $peak = 0.0;
            $maxDrawdown = 0.0;
            $winStreak = 0;
            $maxWinStreak = 0;
            $series = [];

            foreach ($trades as $t) {
                $pnl = (float) $t->realized_pnl;
                $cumulative += $pnl;
                $series[] = [
                    'date' => (string) $t->closed_at,
                    'cumulative' => round($cumulative, 2),
                ];

                $peak = max($peak, $cumulative);
                $maxDrawdown = max($maxDrawdown, $peak - $cumulative);

                if ($pnl > 0) {
                    $wins[] = $pnl;
                    $winStreak++;
                    $maxWinStreak = max($maxWinStreak, $winStreak);
                } elseif ($pnl < 0) {
                    $losses[] = abs($pnl);
                    $winStreak = 0;
                } else {
                    $winStreak = 0;
                }
            }

            $grossWin = array_sum($wins);
            $grossLoss = array_sum($losses);
            $count = $trades->count();

            $assets[] = [
                'ticker' => $symbol,
                'total_trades' => $count,
                'wins' => count($wins),
                'losses' => count($losses),
                'winrate' => $count ? round(count($wins) / $count * 100, 1) : 0,
                'total_pnl' => round($grossWin - $grossLoss, 2),
                // null = no losing trades yet ("Perfect")
                'profit_factor' => $grossLoss > 0 ? round($grossWin / $grossLoss, 2) : null,
                'max_drawdown' => round($maxDrawdown, 2),
                'avg_win' => count($wins) ? round($grossWin / count($wins), 2) : 0,
                'avg_loss' => count($losses) ? round($grossLoss / count($losses), 2) : 0,
                'largest_win' => count($wins) ? round(max($wins), 2) : 0,
                'largest_loss' => count($losses) ? round(max($losses), 2) : 0,
                'max_win_streak' => $maxWinStreak,
                'equity_series' => $series,
            ];
        }

        usort($assets, fn ($a, $b) => $b['total_pnl'] <=> $a['total_pnl']);

        return response()->json([
            'success' => true,
            'balance' => round($balance, 2),
            'assets' => $assets,
        ]);
    }

    /**
     * GET /api/dashboard/daily-pnl
     * The authenticated user's closed-trade P&L grouped by calendar day, each
     * day carrying its individual trades — feeds the dashboard P&L calendar
     * (grid coloring + the per-day trades modal).
     */
    public function dailyPnl(Request $request): JsonResponse
    {
        $past = DB::table('binance_pastpositions')
            ->where('uni_id', $request->user()->uni_id)
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
     * GET /api/binance/positions
     * Current open positions for the authenticated user.
     */
    public function openPositions(Request $request): JsonResponse
    {
        $rows = DB::table('binance_positions')
            ->where('uni_id', $request->user()->uni_id)
            ->orderBy('symbol')
            ->get([
                'id', 'api_key', 'symbol', 'position_side', 'position_amt',
                'entry_price', 'mark_price', 'unrealized_profit', 'notional',
                'update_time',
            ]);

        return response()->json(['success' => true, 'positions' => $rows]);
    }

    /**
     * GET /api/binance/past-positions
     * Closed positions for the authenticated user, newest first.
     */
    public function pastPositions(Request $request): JsonResponse
    {
        $rows = DB::table('binance_pastpositions')
            ->where('uni_id', $request->user()->uni_id)
            ->orderByDesc('closed_at')
            ->get([
                'id', 'api_key', 'symbol', 'position_side', 'position_amt',
                'entry_price', 'exit_price', 'realized_pnl', 'side',
                'order_id', 'closed_at', 'strategy',
            ]);

        return response()->json(['success' => true, 'positions' => $rows]);
    }

    /**
     * GET /api/dashboard/summary
     * Everything the trading dashboard renders, computed from the
     * binance_* tables for the authenticated user (live accounts only).
     */
    public function summary(Request $request): JsonResponse
    {
        $uniId = $request->user()->uni_id;

        $accounts = DB::table('binance_accounts')
            ->where('uni_id', $uniId)
            ->whereNull('deleted_at')
            ->where('demo', 0)
            ->where('enabled', 1)
            ->get();

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

        return response()->json([
            'success' => true,
            'summary' => [
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
            ],
        ]);
    }
}
