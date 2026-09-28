<?php

namespace App\Support;

/**
 * Number formatting for email templates, so every template prints money the
 * same way: "$1,234.50", "+$84.10" / "−$12.00" (a real minus sign), "+2.41%".
 */
final class MailFormat
{
    public static function money(float $v): string
    {
        return ($v < 0 ? '−' : '').'$'.number_format(abs($v), 2);
    }

    public static function signedMoney(float $v): string
    {
        return ($v > 0 ? '+' : '').self::money($v);
    }

    public static function signedPct(float $v): string
    {
        return ($v > 0 ? '+' : ($v < 0 ? '−' : '')).number_format(abs($v), 2).'%';
    }

    /** Green for a gain, red for a loss, grey for flat — fixed colours, like the frame. */
    public static function tone(float $v): string
    {
        return $v > 0 ? '#15803d' : ($v < 0 ? '#b91c1c' : '#374151');
    }
}
