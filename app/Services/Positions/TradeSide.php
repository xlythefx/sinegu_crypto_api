<?php

namespace App\Services\Positions;

/**
 * A closed trade's direction, LONG or SHORT — the PHP twin of the website's
 * `lib/tradeSide.ts` `closedTradeSide`.
 *
 * `position_side` IS the direction (LONG / SHORT; BOTH on a one-way
 * account). A closed row's `side` is the ORDER THAT CLOSED IT — a short is
 * closed by a BUY — so it is only ever the fallback, read inverted.
 */
final class TradeSide
{
    public static function closed(?string $positionSide, ?string $closeSide): ?string
    {
        $direct = strtoupper((string) $positionSide);
        if ($direct === 'LONG' || $direct === 'SHORT') {
            return $direct;
        }

        return match (strtoupper((string) $closeSide)) {
            'SELL' => 'LONG',
            'BUY' => 'SHORT',
            default => null,
        };
    }
}
