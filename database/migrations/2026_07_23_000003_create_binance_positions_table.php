<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * binance_positions — current open positions (full replace on each fetch).
 * Ported 1:1 from sinegu_db.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('binance_positions', function (Blueprint $table) {
            $table->id();
            $table->string('api_key', 128)->index('idx_binance_positions_api_key')->comment('Binance API key (FK binance_accounts)');
            $table->string('uni_id', 36)->index('idx_binance_positions_uni_id')->comment('User ID (FK user_credentials)');
            $table->string('symbol', 32)->index('idx_binance_positions_symbol')->comment('Trading pair (e.g. BTCUSDT)');
            $table->string('position_side', 16)->comment('BOTH, LONG, or SHORT');
            $table->decimal('position_amt', 30, 8)->comment('Position size (+ long, - short)');
            $table->decimal('entry_price', 30, 8)->nullable();
            $table->decimal('mark_price', 30, 8)->nullable();
            $table->decimal('unrealized_profit', 30, 8)->nullable();
            $table->decimal('notional', 30, 8)->nullable();
            $table->decimal('initial_margin', 30, 8)->nullable();
            $table->decimal('maint_margin', 30, 8)->nullable();
            $table->decimal('isolated_margin', 30, 8)->nullable();
            $table->decimal('isolated_wallet', 30, 8)->nullable();
            $table->bigInteger('update_time')->nullable()->comment('Binance updateTime (ms)');
            $table->dateTime('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('binance_positions');
    }
};
