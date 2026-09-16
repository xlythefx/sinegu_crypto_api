<?php

namespace App\Http\Controllers\Concerns;

use App\Services\Exchanges\ExchangeSchema;
use Illuminate\Http\JsonResponse;

/**
 * Shared guard for /api/engine/{exchange}/* controllers. The route constraint
 * already 404s unknown exchanges; this rejects the known-but-not-wired ones
 * (bybit, until its tables land) with a 400 the engine can tell apart from an
 * outage. What is wired is decided by ExchangeSchema, the same registry that
 * names each exchange's tables — so an exchange cannot be "supported" here
 * and have nowhere to write.
 */
trait GuardsEngineExchange
{
    /** Null when the exchange is live; a 400 JSON response otherwise. */
    private function guardExchange(string $exchange): ?JsonResponse
    {
        if (ExchangeSchema::isSupported($exchange)) {
            return null;
        }

        return response()->json([
            'success' => false,
            'error_code' => 'EXCHANGE_NOT_SUPPORTED',
            'message' => "Exchange '{$exchange}' is not wired to the engine yet.",
        ], 400);
    }

    /** The tables/model behind a guarded exchange. Call after guardExchange(). */
    private function schema(string $exchange): ExchangeSchema
    {
        return ExchangeSchema::for($exchange);
    }
}
