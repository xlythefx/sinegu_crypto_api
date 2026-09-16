<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * mexc_pastpositions — closed trades on MEXC, in the FINAL binance_pastpositions
 * shape (`is_sandbox`, `exchange_fee`, `fee_source` from day one).
 *
 * Same contract as the Binance table: `realized_pnl` is stored NET of the
 * exchange fee, `exchange_fee` is the estimate (TradingFee, at MEXC's own taker
 * rate) until the receipts ledger replaces it, `fee_source` says which. There
 * is no gross/net cutoff here — the table starts after TradingFee::NET_SINCE,
 * so every row is net. `order_id` is MEXC's numeric orderId (fits bigint).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mexc_pastpositions', function (Blueprint $table) {
            $table->id();
            $table->string('api_key', 128)->index('idx_mexc_pastpositions_api_key')->comment('MEXC API key (FK mexc_accounts)');
            $table->string('uni_id', 36)->index('idx_mexc_pastpositions_uni_id')->comment('User ID (FK user_credentials)');
            $table->string('symbol', 32)->index('idx_mexc_pastpositions_symbol')->comment('Ticker form (BTCUSDT)');
            $table->string('position_side', 16)->comment('LONG or SHORT');
            $table->decimal('position_amt', 30, 8)->comment('Closed quantity in coins');
            $table->decimal('entry_price', 30, 8)->nullable();
            $table->decimal('exit_price', 30, 8)->nullable();
            $table->decimal('realized_pnl', 30, 8)->nullable()->comment('NET of exchange_fee');
            $table->string('side', 8)->comment('BUY or SELL (close direction)');
            $table->bigInteger('order_id')->nullable()->comment('MEXC orderId');
            $table->dateTime('closed_at')->index('idx_mexc_pastpositions_closed_at')->comment('When position was closed (UTC)');
            $table->string('strategy', 100)->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->boolean('is_sandbox')->default(false)->index('idx_mexc_pastpositions_is_sandbox');
            $table->decimal('exchange_fee', 30, 8)->nullable()
                ->comment('Exchange fee already deducted from realized_pnl');
            $table->string('fee_source', 16)->nullable()
                ->comment('NULL = gross; estimated | actual | manual — see TradingFee');

            $table->unique(['api_key', 'symbol', 'order_id'], 'uq_mexc_pastpositions_api_symbol_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mexc_pastpositions');
    }
};
