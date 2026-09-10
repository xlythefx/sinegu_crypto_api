<?php

namespace App\Services\Payments;

/**
 * Integer base units of a TRC-20 token, handled with bcmath only.
 *
 * The chain reports a transfer's `value` as a DECIMAL STRING OF INTEGER BASE
 * UNITS — for 6-decimal USDT, "12340000" means 12.34. Two rules follow, and
 * both are the same rule Invoice::feeCents() already enforces one layer up:
 *
 *  1. Never cast it. A hostile token can emit a 256-bit value that overflows
 *     PHP's int and silently degrades to a float; and even in range,
 *     $cents * 10000 on a double reproduces exactly the off-by-one-cent bug
 *     feeCents() exists to avoid.
 *  2. Anything that will be stored or compared must first pass
 *     isSafeInteger(). Out-of-range values are recorded verbatim and then
 *     never arithmetic'd.
 */
final class TronUnits
{
    /** Largest value that fits both PHP's int and the DB's BIGINT column. */
    private const MAX = '9223372036854775807';

    /**
     * Invoice cents → token base units. For 6-decimal USDT this multiplies by
     * 10^4; the general form is here so a 2-decimal or 18-decimal token needs
     * no new code path.
     */
    public static function centsToUnits(int $cents, int $decimals): string
    {
        $cents = max(0, $cents);

        if ($decimals >= 2) {
            return bcmul((string) $cents, bcpow('10', (string) ($decimals - 2), 0), 0);
        }

        return bcdiv((string) $cents, bcpow('10', (string) (2 - $decimals), 0), 0);
    }

    /**
     * Base units → the exact decimal string a human is asked to send,
     * e.g. '12.340000'. Full precision on purpose: the amount displayed is the
     * amount matched, so trimming trailing zeroes here would invite a payer to
     * round.
     */
    public static function format(string $units, int $decimals): string
    {
        if (! self::isSafeInteger($units)) {
            return '0';
        }

        if ($decimals <= 0) {
            return $units;
        }

        $padded = str_pad($units, $decimals + 1, '0', STR_PAD_LEFT);
        $whole = substr($padded, 0, -$decimals);
        $fraction = substr($padded, -$decimals);

        return $whole.'.'.$fraction;
    }

    /**
     * Base units → USD, for DISPLAY and audit rows only.
     *
     * Deliberately not used to settle an invoice: InvoiceService::settle() is
     * given the invoice's own USD figure, so if USDT ever depegs we under-record
     * by the depeg instead of billing a number the customer never agreed to.
     */
    public static function toUsd(string $units, int $decimals): float
    {
        return (float) self::format($units, $decimals);
    }

    /** A percentage of an amount, floored — used for the tolerance bands. */
    public static function pctUnits(string $units, float $pct): string
    {
        if (! self::isSafeInteger($units) || $pct <= 0) {
            return '0';
        }

        $scaled = bcmul($units, sprintf('%.8F', $pct), 8);

        return bcdiv($scaled, '100', 0);
    }

    /**
     * Does this chain-supplied string fit in a signed 64-bit integer? The gate
     * every value must pass before it is stored in a BIGINT column or compared
     * against one.
     */
    public static function isSafeInteger(?string $raw): bool
    {
        if ($raw === null || preg_match('/^\d{1,19}$/', $raw) !== 1) {
            return false;
        }

        return bccomp($raw, self::MAX, 0) <= 0;
    }
}
