<?php

namespace App\Http\Controllers;

use App\Services\UserStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private UserStatsService $stats) {}

    /**
     * The `?exchange=` filter every endpoint here honours: the dashboard's
     * top-bar pills. Missing/'all' pools every supported exchange; a venue
     * name narrows to it; a venue we have no tables for (bybit) is a 400
     * rather than a silent Binance answer under a MEXC label.
     *
     * @return string|JsonResponse  the normalized scope, or the error response
     */
    private function exchangeScope(Request $request): string|JsonResponse
    {
        $exchange = UserStatsService::normalizeExchange($request->query('exchange'));
        if ($exchange === null) {
            return response()->json([
                'success' => false,
                'error' => 'EXCHANGE_NOT_SUPPORTED',
                'message' => 'That exchange is not available yet.',
            ], 400);
        }

        return $exchange;
    }

    /**
     * GET /api/dashboard/asset-performance?exchange=
     * Per-asset trading metrics + cumulative-P&L equity series, ranked by
     * total P&L. Mirrors the mother dashboard's UserAssetPerformance math.
     */
    public function assetPerformance(Request $request): JsonResponse
    {
        $exchange = $this->exchangeScope($request);
        if ($exchange instanceof JsonResponse) {
            return $exchange;
        }
        $uniId = $request->user()->uni_id;

        $accounts = $this->stats->displayAccounts($uniId, $exchange);
        $balance = (float) $accounts->sum('balance') + (float) $accounts->sum('unrealized_pnl');

        // Same account scope as $balance above — see
        // UserStatsService::displayApiKeys().
        // BASIS: before exchange fees, like the dashboard it sits under; each
        // asset carries `total_pnl_net` and `fees` for the hover breakdown, and
        // its curve points `cumulative_net` beside `cumulative`.
        $past = UserStatsService::withFeeBasis($this->stats->pastPositions($accounts));

        $assets = [];
        foreach ($past->groupBy('symbol') as $symbol => $trades) {
            $wins = [];
            $losses = [];
            $cumulative = 0.0;
            $cumulativeNet = 0.0;
            $peak = 0.0;
            $maxDrawdown = 0.0;
            $winStreak = 0;
            $maxWinStreak = 0;
            $series = [];

            foreach ($trades as $t) {
                $pnl = (float) $t->pnl_gross;
                $cumulative += $pnl;
                $cumulativeNet += (float) $t->pnl_net;
                $series[] = [
                    'date' => (string) $t->closed_at,
                    'cumulative' => round($cumulative, 2),
                    'cumulative_net' => round($cumulativeNet, 2),
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
                'total_pnl_net' => round($cumulativeNet, 2),
                'fees' => round((float) $trades->sum('pnl_fee'), 2),
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
            'exchange' => $exchange,
            'balance' => round($balance, 2),
            'assets' => $assets,
        ]);
    }

    /**
     * GET /api/dashboard/daily-pnl?exchange=
     * The authenticated user's closed-trade P&L grouped by calendar day, each
     * day carrying its individual trades — feeds the dashboard P&L calendar
     * (grid coloring + the per-day trades modal).
     */
    public function dailyPnl(Request $request): JsonResponse
    {
        $exchange = $this->exchangeScope($request);
        if ($exchange instanceof JsonResponse) {
            return $exchange;
        }

        return response()->json([
            'success' => true,
            'exchange' => $exchange,
            'days' => $this->stats->dailyPnlDays($request->user()->uni_id, $exchange),
        ]);
    }

    /**
     * GET /api/binance/positions?exchange=
     * Current open positions for the authenticated user on every connected
     * account, each row stamped `exchange`. The path keeps its historical
     * name; the rows have not been Binance-only since MEXC landed.
     */
    public function openPositions(Request $request): JsonResponse
    {
        $exchange = $this->exchangeScope($request);
        if ($exchange instanceof JsonResponse) {
            return $exchange;
        }

        $rows = $this->stats->openPositions(
            $this->stats->displayAccounts($request->user()->uni_id, $exchange),
            [
                'id', 'api_key', 'symbol', 'position_side', 'position_amt',
                'entry_price', 'mark_price', 'unrealized_profit', 'notional',
                'update_time',
            ],
        );

        return response()->json(['success' => true, 'exchange' => $exchange, 'positions' => $rows]);
    }

    /**
     * GET /api/binance/past-positions?exchange=
     * Closed positions for the authenticated user, newest first, every
     * exchange's table merged and each row stamped `exchange`.
     *
     * `realized_pnl` is NET of `exchange_fee` for closes from
     * TradingFee::NET_SINCE on — the figure the customer's own exchange app
     * shows — and GROSS, with a null fee, for anything earlier. Both columns
     * are sent so the UI can explain the difference rather than just quoting
     * a smaller number.
     */
    public function pastPositions(Request $request): JsonResponse
    {
        $exchange = $this->exchangeScope($request);
        if ($exchange instanceof JsonResponse) {
            return $exchange;
        }

        $rows = $this->stats->pastPositions(
            $this->stats->displayAccounts($request->user()->uni_id, $exchange),
            [
                'id', 'api_key', 'symbol', 'position_side', 'position_amt',
                'entry_price', 'exit_price', 'realized_pnl', 'exchange_fee', 'fee_source',
                'side', 'order_id', 'closed_at', 'strategy',
            ],
        )->sortByDesc('closed_at')->values();

        return response()->json(['success' => true, 'exchange' => $exchange, 'positions' => $rows]);
    }

    /**
     * GET /api/dashboard/summary?exchange=
     * Everything the trading dashboard renders, computed from the connected
     * accounts' `{exchange}_*` tables — every exchange pooled, or the one the
     * top-bar filter names.
     */
    public function summary(Request $request): JsonResponse
    {
        $exchange = $this->exchangeScope($request);
        if ($exchange instanceof JsonResponse) {
            return $exchange;
        }

        return response()->json([
            'success' => true,
            'summary' => $this->stats->summary($request->user()->uni_id, $exchange),
        ]);
    }
}
