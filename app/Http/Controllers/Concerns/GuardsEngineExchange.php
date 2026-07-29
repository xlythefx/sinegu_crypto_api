<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\JsonResponse;

/**
 * Shared guard for /api/engine/{exchange}/* controllers. The route constraint
 * already 404s unknown exchanges; this rejects the known-but-not-wired ones so
 * Bybit/MEXC engines can be merged in later without new routes.
 */
trait GuardsEngineExchange
{
    /** Exchanges the engine API actually serves today. */
    private const SUPPORTED_EXCHANGES = ['binance'];

    /** Null when the exchange is live; a 400 JSON response otherwise. */
    private function guardExchange(string $exchange): ?JsonResponse
    {
        if (in_array($exchange, self::SUPPORTED_EXCHANGES, true)) {
            return null;
        }

        return response()->json([
            'success' => false,
            'error_code' => 'EXCHANGE_NOT_SUPPORTED',
            'message' => "Exchange '{$exchange}' is not wired to the engine yet.",
        ], 400);
    }
}
