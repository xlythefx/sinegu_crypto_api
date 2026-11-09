<?php

namespace App\Services\Ai\Tools;

/**
 * One name per coin across venues: Binance and Bybit write `LTCUSDT`, MEXC
 * `LTC_USDT`, and a person asks about "LTC". Every coin argument and every
 * coin in an answer goes through here.
 */
final class Coins
{
    /** 'LTC_USDT' | 'LTCUSDT' | 'ltc/usdt' | 'ltc' → 'LTC'. */
    public static function of(string $symbol): string
    {
        $s = preg_replace('/[^A-Z0-9]/', '', strtoupper($symbol)) ?? '';
        if (strlen($s) > 4 && str_ends_with($s, 'USDT')) {
            $s = substr($s, 0, -4);
        }

        return $s;
    }

    /** The symbols a coin is stored under, on any venue. @return list<string> */
    public static function symbols(string $coin): array
    {
        $c = self::of($coin);

        return [$c.'USDT', $c.'_USDT'];
    }
}
