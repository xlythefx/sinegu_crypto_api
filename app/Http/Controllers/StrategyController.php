<?php

namespace App\Http\Controllers;

use App\Models\Strategy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
     */
    public function index(): JsonResponse
    {
        $trades = DB::table('binance_pastpositions')
            ->whereNotNull('strategy')
            ->where('strategy', '!=', '')
            ->orderBy('closed_at')
            ->get(['strategy', 'symbol', 'realized_pnl', 'closed_at']);

        $enabled = Strategy::all()->pluck('enabled', 'strategy_key');

        return response()->json([
            'success' => true,
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
