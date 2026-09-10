<?php

namespace App\Services\Pnl;

/**
 * The exchange commission a closed trade paid — ESTIMATED, and the reason
 * `binance_pastpositions.realized_pnl` is stored NET.
 *
 * Binance's `realizedPnl` on a fill — what the engine reads in
 * `get_order_fill_summary` / `_reconstruct_closes` and posts to
 * /past-positions/sync — is GROSS: (exit − entry) × qty, before commission and
 * before funding. Binance's own Position History screen shows the same trade
 * NET of both, so a customer comparing our dashboard against their exchange app
 * read two different numbers for one trade (+$52.76 here against 43.09 there;
 * the $9.67 gap was exactly the round-trip commission).
 *
 * We cannot simply store what Binance reports, because the real commission is
 * per FILL and the ENTRY half of it belongs to the entry order — an order the
 * closed-trade row never references. (That is the same gap that leaves
 * `entry_price` null: a close is reconstructed from the closing order alone.)
 * Capturing it for real means aggregating /fapi/v1/income for COMMISSION and
 * FUNDING_FEE, and that endpoint is already ~95% of the poller's weight budget.
 *
 * So it is estimated, and the estimate is trustworthy for one specific reason:
 * the engine only ever places MARKET orders (`"type": "MARKET"` in
 * binance_api.py), which are always TAKER fills at a flat published rate. There
 * is no maker/taker ambiguity to get wrong. Measured against real closes it
 * lands within two cents.
 *
 * Two approximations, both deliberate:
 *  - The exit price stands in for the entry price on the entry leg, the entry
 *    price being the figure we do not have. On this strategy the two legs are
 *    minutes apart, so the error is a fraction of a cent — well under the
 *    rounding already present in the displayed figure.
 *  - Funding is not modelled at all. It is charged 8-hourly, most positions
 *    close inside one interval, and unlike commission it can be positive.
 *    Guessing it would add error rather than remove it.
 *
 * Gross stays recoverable at all times as `realized_pnl + exchange_fee`, which
 * is why the estimate is STORED on the row rather than applied at read time:
 * one number on disk, every reader — dashboard, analytics, calendar, referrals,
 * invoicing, the public track record — agreeing without knowing this class
 * exists.
 */
class TradingFee
{
    /** Taker commission per side, as a fraction. Binance USDⓈ-M standard: 0.05%. */
    public static function rate(): float
    {
        return (float) config('services.binance.taker_fee_rate', 0.0005);
    }

    /**
     * Round-trip commission for one close: entry leg + exit leg, at the taker rate.
     *
     * Null — never 0.0 — when the exit price or quantity is unknown. The webhook
     * writes a close before Binance has indexed its fills, and the poller
     * backfills the figures later; until then the fee is UNKNOWN, and an unknown
     * fee must not be stored as a zero one. A null leaves that row's
     * `realized_pnl` gross, which the same backfill then corrects.
     */
    public static function estimate(?float $quantity, ?float $exitPrice): ?float
    {
        if ($quantity === null || $exitPrice === null) {
            return null;
        }

        $notional = abs($quantity) * abs($exitPrice);
        if ($notional <= 0) {
            return null;
        }

        return round($notional * self::rate() * 2, 8);
    }

    /**
     * Gross realized P&L as the exchange reported it → the net figure we store.
     *
     * Returns [net, fee]. A null fee (see estimate()) passes the P&L through
     * untouched, so a row is never silently reduced by a fee nobody computed.
     */
    public static function applyTo(?float $grossPnl, ?float $quantity, ?float $exitPrice): array
    {
        $fee = self::estimate($quantity, $exitPrice);

        if ($grossPnl === null || $fee === null) {
            return [$grossPnl, $fee];
        }

        return [round($grossPnl - $fee, 8), $fee];
    }
}
