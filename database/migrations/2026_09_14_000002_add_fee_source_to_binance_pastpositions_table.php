<?php

use App\Services\Pnl\TradingFee;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Say WHERE a closed trade's `exchange_fee` came from.
 *
 * Until now every fee on the table was an estimate (TradingFee::estimate —
 * both legs at the taker rate, no funding). From here on the fee-receipts
 * ledger replaces that estimate with the exchange's own figures, and the
 * column would otherwise hold two kinds of number with no way to tell them
 * apart. `fee_source`:
 *
 *   NULL        — nothing was taken out of `realized_pnl` (a gross row: closed
 *                 before TradingFee::NET_SINCE, or its fee is still unknown).
 *                 Same meaning as `exchange_fee IS NULL`, kept in step with it.
 *   'estimated' — TradingFee's estimate; the receipts have not been matched
 *                 yet (or cannot be: BNB-paid fees, a position opened before
 *                 the ledger existed). Customers see this as "est.".
 *   'actual'    — commission + funding summed from matched receipts.
 *   'manual'    — an admin edited `realized_pnl` by hand; the reconciler
 *                 leaves the row alone so it cannot overwrite the correction.
 *
 * The data step stamps every post-cutoff row that carries a fee as
 * 'estimated'. That is exactly right today because TradingFee::applyTo is the
 * only writer that ever netted a row. Sandbox scratch rows (`is_sandbox`,
 * `SBXINV-…`) are skipped — they are seeded with hand-derived figures and never
 * pass through the netting ingest.
 *
 * `down()` restores the pre-feature figures: rows the ledger moved to 'actual'
 * are put back on the estimate (gross recovered from the invariant, estimate
 * recomputed as 2026_09_09 did), so a rollback returns exactly the numbers
 * that were on screen before. A row whose exit price is unknown cannot be
 * re-estimated and is left net of its actual fee — the invariant still holds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('binance_pastpositions', function (Blueprint $table) {
            $table->string('fee_source', 16)->nullable()->after('exchange_fee')
                ->comment('NULL = gross; estimated | actual | manual — see TradingFee');
        });

        DB::table('binance_pastpositions')
            ->where('closed_at', '>=', TradingFee::NET_SINCE)
            ->whereNotNull('exchange_fee')
            ->whereNotNull('realized_pnl')
            ->where('is_sandbox', 0)
            ->where('api_key', 'not like', 'SBXINV-%')
            ->update(['fee_source' => TradingFee::SOURCE_ESTIMATED]);
    }

    public function down(): void
    {
        $rate = TradingFee::rate();

        $actual = fn () => DB::table('binance_pastpositions')
            ->where('fee_source', TradingFee::SOURCE_ACTUAL)
            ->whereNotNull('exchange_fee')
            ->whereNotNull('realized_pnl');

        // Three statements, not one: MySQL evaluates SET left to right against
        // the row as already modified. Back to gross, re-estimate, re-net.
        $actual()->update(['realized_pnl' => DB::raw('ROUND(realized_pnl + exchange_fee, 8)')]);

        $actual()
            ->whereNotNull('exit_price')
            ->where('exit_price', '!=', 0)
            ->where('position_amt', '!=', 0)
            ->update([
                'exchange_fee' => DB::raw(
                    'ROUND(ABS(position_amt) * ABS(exit_price) * '.$rate.' * 2, 8)'
                ),
            ]);

        $actual()->update(['realized_pnl' => DB::raw('ROUND(realized_pnl - exchange_fee, 8)')]);

        Schema::table('binance_pastpositions', function (Blueprint $table) {
            $table->dropColumn('fee_source');
        });
    }
};
