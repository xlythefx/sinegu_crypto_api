<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * binance_pastpositions — closed positions (append on exit).
 * Ported 1:1 from sinegu_db.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('binance_pastpositions', function (Blueprint $table) {
            $table->id();
            $table->string('api_key', 128)->index('idx_binance_pastpositions_api_key')->comment('Binance API key (FK binance_accounts)');
            $table->string('uni_id', 36)->index('idx_binance_pastpositions_uni_id')->comment('User ID (FK user_credentials)');
            $table->string('symbol', 32)->index('idx_binance_pastpositions_symbol')->comment('Trading pair');
            $table->string('position_side', 16)->comment('LONG, SHORT, or BOTH');
            $table->decimal('position_amt', 30, 8)->comment('Closed quantity');
            $table->decimal('entry_price', 30, 8)->nullable();
            $table->decimal('exit_price', 30, 8)->nullable();
            $table->decimal('realized_pnl', 30, 8)->nullable();
            $table->string('side', 8)->comment('BUY or SELL (close direction)');
            $table->bigInteger('order_id')->nullable()->comment('Binance orderId');
            $table->dateTime('closed_at')->index('idx_binance_pastpositions_closed_at')->comment('When position was closed');
            $table->string('strategy', 100)->nullable();
            $table->dateTime('created_at')->useCurrent();

            $table->unique(['api_key', 'symbol', 'order_id'], 'uq_binance_pastpositions_api_symbol_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('binance_pastpositions');
    }
};
