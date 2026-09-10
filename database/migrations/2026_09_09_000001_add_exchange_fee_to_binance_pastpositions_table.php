<?php

use App\Services\Pnl\TradingFee;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make `binance_pastpositions.realized_pnl` mean NET profit, and record the
 * commission that was taken out of it in the new `exchange_fee` column.
 *
 * Until now the column held Binance's raw `realizedPnl`, which is GROSS of
 * commission — so every screen we own published a bigger profit than the
 * customer's own Binance app showed for the same trade, and invoices billed a
 * share of money the customer never received. See App\Services\Pnl\TradingFee
 * for why the fee is estimated rather than fetched.
 *
 * This rewrites history on purpose: the mismatch is on trades that already
 * happened, so netting only new rows would leave every past trade still
 * disagreeing with the exchange. It is reversible — `down()` adds the stored
 * fee back — which is the whole reason the fee is kept on the row instead of
 * being subtracted and forgotten.
 *
 * Untouched:
 *  - Already-issued invoices. They store their own `realized_pnl` snapshot, so
 *    what a customer was billed (and paid) does not move. Only invoices
 *    generated from here on read the new basis.
 *  - The high-water mark, which is measured on exchange-reported EQUITY
 *    (`balance + unrealized`), not on this column.
 *  - Invoice-scenario scratch rows (`SBXINV-…`), seeded straight into the table
 *    with hand-derived figures the runner asserts against. They never pass
 *    through the ingest path that nets, so netting them here would fail the
 *    suite for a reason that has nothing to do with the suite.
 */
return new class extends Migration
{
    /** Scratch rows owned by the invoice scenario runner. */
    private const SANDBOX_PREFIX = 'SBXINV-%';

    public function up(): void
    {
        Schema::table('binance_pastpositions', function (Blueprint $table) {
            $table->decimal('exchange_fee', 30, 8)->nullable()
                ->comment('Estimated round-trip exchange commission already deducted from realized_pnl');
        });

        $rate = TradingFee::rate();

        // Both legs at the taker rate, priced off the exit — the entry price is
        // null on rows reconstructed from the closing order, which is most of them.
        DB::table('binance_pastpositions')
            ->whereNotNull('realized_pnl')
            ->whereNotNull('exit_price')
            ->where('exit_price', '!=', 0)
            ->where('position_amt', '!=', 0)
            ->where('api_key', 'not like', self::SANDBOX_PREFIX)
            ->update([
                'exchange_fee' => DB::raw(
                    'ROUND(ABS(position_amt) * ABS(exit_price) * '.$rate.' * 2, 8)'
                ),
            ]);

        DB::table('binance_pastpositions')
            ->whereNotNull('exchange_fee')
            ->update(['realized_pnl' => DB::raw('ROUND(realized_pnl - exchange_fee, 8)')]);
    }

    public function down(): void
    {
        DB::table('binance_pastpositions')
            ->whereNotNull('exchange_fee')
            ->whereNotNull('realized_pnl')
            ->update(['realized_pnl' => DB::raw('ROUND(realized_pnl + exchange_fee, 8)')]);

        Schema::table('binance_pastpositions', function (Blueprint $table) {
            $table->dropColumn('exchange_fee');
        });
    }
};
