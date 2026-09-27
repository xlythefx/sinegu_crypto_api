<?php

namespace App\Http\Controllers;

use App\Models\Strategy;
use App\Services\Admin\AdminInsights;
use App\Services\Exchanges\ExchangeSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin strategy overview (auth:sanctum + admin middleware).
 * Trades are returned raw and grouped client-side (mirrors the mother
 * dashboard) so the page can recompute stats when tickers are excluded.
 */
class StrategyController extends Controller
{
    /**
     * GET /api/admin/strategies
     * Platform-wide closed trades that carry a strategy tag, plus the
     * global enabled/paused map from the strategies table.
     *
     * `exchange_fee` rides along so the client-side strategy math
     * (lib/strategyStats.ts) can draw the curve BEFORE fees — the strategy's
     * own result — and still show what landed on hover.
     *
     * Every exchange's table, real money only (no demo, sandbox or SBXINV-
     * rows). `?scope=master` is the master account alone — the strategy's
     * true result; `customers` is every customer pooled; `all` (default) is
     * both. `?exchange=` narrows to one venue.
     */
    public function index(Request $request, AdminInsights $insights): JsonResponse
    {
        $scope = in_array($request->query('scope'), ['master', 'customers'], true)
            ? $request->query('scope') : 'all';
        $exchange = $request->query('exchange');
        $exchange = is_string($exchange) && ExchangeSchema::isSupported($exchange) ? $exchange : null;

        $trades = $insights->strategyTrades($scope, $exchange);

        $enabled = Strategy::all()->pluck('enabled', 'strategy_key');

        return response()->json([
            'success' => true,
            'scope' => $scope,
            'enabled' => $enabled,
            'trades' => $trades,
        ]);
    }

    /**
     * PUT /api/admin/strategies/{key}
     * Toggle a strategy globally (upserts the config row).
     */
    public function setEnabled(Request $request, string $key): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $strategy = Strategy::updateOrCreate(
            ['strategy_key' => $key],
            ['enabled' => $validated['enabled']],
        );

        return response()->json([
            'success' => true,
            'message' => $strategy->enabled ? 'Strategy activated' : 'Strategy paused',
            'strategy' => [
                'strategy_key' => $strategy->strategy_key,
                'enabled' => $strategy->enabled,
            ],
        ]);
    }
}
