<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One append-only row per charge the exchange actually made — the RECEIPTS
 * behind `binance_pastpositions.exchange_fee`.
 *
 * A closed trade does not pay one fee; it pays several: a commission on every
 * ENTRY fill, a commission on every EXIT fill, and a funding payment (or
 * credit) every 8 hours the position was held. The per-trade column holds the
 * total; this table holds the pieces, so "why is this trade's fee $11 and not
 * $9" has an answer, and so attribution can be re-run from stored data — the
 * exchange keeps this history for a limited window and charges request weight
 * for every read, so each receipt is fetched once and kept.
 *
 * Two kinds, one shape:
 *  - `fill`    — one userTrades fill. `ref` is the fill id (Binance
 *                `userTrades.id`, which is PER SYMBOL — hence `symbol` in the
 *                unique key), `amount` is its `commission`. EVERY fill is
 *                stored, including ones that cost nothing: the quantity of the
 *                entry fills is what the attribution walk needs to know which
 *                close a funding charge belongs to.
 *  - `funding` — one FUNDING_FEE income row. `ref` is the income `tranId`.
 *
 * Sign convention: `amount > 0` is money OUT. A fill's commission is already
 * positive; funding arrives signed from the exchange (negative = paid) and is
 * stored negated, so a funding CREDIT is a negative amount and a trade's total
 * fee is a plain sum. `asset` is stored exactly as reported and never assumed:
 * an account paying fees in BNB produces BNB receipts, and a trade with one of
 * those is left on the estimate rather than summed across two currencies.
 *
 * `exchange` comes from the engine route, never from the payload — same rule as
 * `trade_logs`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_fee_receipts', function (Blueprint $table) {
            $table->id();
            $table->enum('exchange', ['binance', 'bybit', 'mexc'])
                ->default('binance')
                ->comment('Which exchange engine reported this charge');
            $table->string('api_key', 128);
            $table->string('uni_id', 36);
            $table->string('symbol', 32);
            $table->string('kind', 16)->comment('fill | funding');
            $table->bigInteger('ref')->comment('fill: userTrades id (per symbol); funding: income tranId');
            $table->bigInteger('order_id')->nullable()->comment('fill only');
            $table->string('side', 8)->nullable()->comment('BUY | SELL, fill only');
            $table->string('position_side', 16)->nullable()->comment('LONG | SHORT | BOTH, fill only');
            $table->decimal('qty', 30, 8)->nullable()->comment('fill only');
            $table->decimal('price', 30, 8)->nullable()->comment('fill only');
            $table->decimal('realized_pnl', 30, 8)->nullable()->comment('the fill\'s own realizedPnl, fill only');
            $table->decimal('amount', 30, 8)->comment('> 0 is money out; funding stored negated');
            $table->string('asset', 16)->default('USDT')->comment('as reported: commissionAsset / income asset');
            $table->bigInteger('charged_at')->comment('exchange timestamp, epoch ms');
            $table->dateTime('created_at')->useCurrent();

            $table->unique(
                ['exchange', 'api_key', 'symbol', 'kind', 'ref'],
                'uq_exchange_fee_receipts_exchange_api_symbol_kind_ref',
            );
            $table->index(['api_key', 'symbol', 'charged_at'], 'idx_exchange_fee_receipts_api_symbol_charged');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_fee_receipts');
    }
};
