<?php

namespace App\Http\Controllers;

use App\Models\Strategy;
use App\Services\Admin\AdminInsights;
use App\Services\EngineCache;
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
     * both plus tester accounts' test-feed trades (demo included, from
     * `AdminInsights::TEST_FEED_SINCE`). `?exchange=` narrows to one venue.
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
     *
     * Paused = the engine refuses every ENTRY whose alert names this
     * strategy, on every exchange; exits still close what is open
     * (GET /engine/{x}/assets → paused_strategies). The engine's list is
     * dropped at once (EngineCache, best-effort — its 90 s TTL is the net),
     * so the switch acts on the next signal. Until 2026-11-27 nothing read it.
     */
    public function setEnabled(Request $request, string $key, EngineCache $engine): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $strategy = Strategy::updateOrCreate(
            ['strategy_key' => $key],
            ['enabled' => $validated['enabled']],
        );
        $engine->refreshAssets();

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
