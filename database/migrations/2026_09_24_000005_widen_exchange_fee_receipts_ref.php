<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `exchange_fee_receipts.ref` becomes a string, because Bybit's fill id is not
 * a number.
 *
 * `ref` is the exchange's OWN id for the charge — Binance's userTrades fill id
 * and MEXC's deal id, both numeric, which is why the column started as a
 * bigint. Bybit's `/v5/execution/list` identifies a fill by `execId`, a UUID
 * (`8c48b6ba-a6a5-…`). It cannot be stored as an integer, and it is the only
 * per-execution identifier Bybit publishes: `orderId` is not a candidate
 * because one order legitimately produces several executions, and the unique
 * key (exchange, api_key, symbol, kind, ref) is what makes re-sent pages
 * harmless.
 *
 * The rejected alternative was hashing the UUID into 63 bits. It works
 * arithmetically, but a `ref` nobody can paste back into Bybit's UI turns
 * every "why is this trade's fee $11 and not $9" question into a dead end —
 * which is the one question this table exists to answer.
 *
 * Lossless for the existing rows: MySQL and SQLite both render an integer as
 * its decimal string, so Binance's `12345` becomes `'12345'` and the engine's
 * next POST of the same fill still collides on the unique key. Nothing reads
 * `ref` arithmetically — EngineSyncController writes it and FeeAttribution
 * keys on `order_id`, never on this column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exchange_fee_receipts', function (Blueprint $table) {
            $table->string('ref', 64)
                ->comment('Exchange charge id: Binance fill id, MEXC deal id, Bybit execId (UUID)')
                ->change();
        });
    }

    public function down(): void
    {
        // Bybit rows are the only ones that can hold a non-numeric ref, and they
        // can only exist after this migration ran. Drop them rather than let the
        // narrowing silently coerce every UUID to 0 and collapse them onto one
        // another through the unique key.
        DB::table('exchange_fee_receipts')->where('exchange', 'bybit')->delete();

        Schema::table('exchange_fee_receipts', function (Blueprint $table) {
            $table->bigInteger('ref')
                ->comment('fill: userTrades id (per symbol); funding: income tranId')
                ->change();
        });
    }
};
