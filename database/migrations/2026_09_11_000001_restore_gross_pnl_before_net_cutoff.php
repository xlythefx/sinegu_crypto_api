<?php

use App\Services\Pnl\TradingFee;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Put every closed trade before TradingFee::NET_SINCE back on the GROSS basis.
 *
 * 2026_09_09_000001 netted ALL of history so the dashboard would match the
 * customer's Binance app. Two days later it was reversed for the historical
 * rows: those figures had already been reported on, and a track record that
 * restates itself reads as invalid data even when the new number is the more
 * honest one. The compromise is dated, not undone — closes from the cutoff on
 * keep landing net through the ingest path, and everything before it is what
 * Binance's fills reported, exactly as it was before 2026-09-09.
 *
 * `exchange_fee` is set back to NULL on those rows on purpose. NULL is the
 * row-level marker for "nothing was taken out of realized_pnl" (the ingest
 * already treats an unknown fee that way), and it is also what stops the
 * poller from ever re-netting them: the null-fill branch of the sync writes
 * P&L only while it is still null, and these rows carry one. Keeping the fee
 * beside a gross figure would have made the column mean two different things
 * depending on which migration last touched the row.
 *
 * Rows netted by the 2026-09-09 migration AND rows ingested net between then
 * and the cutoff are both covered — the predicate is the close date and a
 * non-null fee, not how the fee got there.
 *
 * Untouched, as before: issued invoices (own snapshot), the HWM (exchange
 * equity), and `SBXINV-…` scratch rows (never netted, so never in the set).
 */
return new class extends Migration
{
    public function up(): void
    {
        $netted = fn () => DB::table('binance_pastpositions')
            ->where('closed_at', '<', TradingFee::NET_SINCE)
            ->whereNotNull('exchange_fee')
            ->whereNotNull('realized_pnl');

        // Two statements, not one: MySQL evaluates SET left to right against
        // the row as already modified, and the add must read the fee before
        // it is cleared.
        $netted()->update(['realized_pnl' => DB::raw('ROUND(realized_pnl + exchange_fee, 8)')]);
        $netted()->update(['exchange_fee' => null]);
    }

    /** Re-net the pre-cutoff rows, recomputing the fee the way 2026_09_09 did. */
    public function down(): void
    {
        $rate = TradingFee::rate();

        DB::table('binance_pastpositions')
            ->where('closed_at', '<', TradingFee::NET_SINCE)
            ->whereNull('exchange_fee')
            ->whereNotNull('realized_pnl')
            ->whereNotNull('exit_price')
            ->where('exit_price', '!=', 0)
            ->where('position_amt', '!=', 0)
            ->where('api_key', 'not like', 'SBXINV-%')
            ->update([
                'exchange_fee' => DB::raw(
                    'ROUND(ABS(position_amt) * ABS(exit_price) * '.$rate.' * 2, 8)'
                ),
            ]);

        DB::table('binance_pastpositions')
            ->where('closed_at', '<', TradingFee::NET_SINCE)
            ->whereNotNull('exchange_fee')
            ->update(['realized_pnl' => DB::raw('ROUND(realized_pnl - exchange_fee, 8)')]);
    }
};
