<?php

namespace App\Http\Controllers;

use App\Services\UserStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __construct(private UserStatsService $stats) {}

    /**
     * GET /api/dashboard/asset-performance
     * Per-asset trading metrics + cumulative-P&L equity series, ranked by
     * total P&L. Mirrors the mother dashboard's UserAssetPerformance math.
     */
    public function assetPerformance(Request $request): JsonResponse
    {
        $uniId = $request->user()->uni_id;

        $accounts = $this->stats->displayAccounts($uniId);
        $balance = (float) $accounts->sum('balance') + (float) $accounts->sum('unrealized_pnl');

        // Same account scope as $balance above — see
        // UserStatsService::displayApiKeys().
        $past = DB::table('binance_pastpositions')
            ->whereIn('api_key', $accounts->pluck('api_key')->all())
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
        return response()->json([
            'success' => true,
            'days' => $this->stats->dailyPnlDays($request->user()->uni_id),
        ]);
    }

    /**
     * GET /api/binance/positions
     * Current open positions for the authenticated user.
     */
    public function openPositions(Request $request): JsonResponse
    {
        $rows = DB::table('binance_positions')
            ->whereIn('api_key', $this->stats->displayApiKeys($request->user()->uni_id))
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
     *
     * `realized_pnl` is NET of `exchange_fee` for closes from
     * TradingFee::NET_SINCE on — the figure the customer's own Binance app
     * shows — and GROSS, with a null fee, for anything earlier. Both columns
     * are sent so the UI can explain the difference rather than just quoting
     * a smaller number.
     */
    public function pastPositions(Request $request): JsonResponse
    {
        $rows = DB::table('binance_pastpositions')
            ->whereIn('api_key', $this->stats->displayApiKeys($request->user()->uni_id))
            ->orderByDesc('closed_at')
            ->get([
                'id', 'api_key', 'symbol', 'position_side', 'position_amt',
                'entry_price', 'exit_price', 'realized_pnl', 'exchange_fee', 'fee_source',
                'side', 'order_id', 'closed_at', 'strategy',
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
        return response()->json([
            'success' => true,
            'summary' => $this->stats->summary($request->user()->uni_id),
        ]);
    }
}
