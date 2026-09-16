<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * mexc_positions — open positions on MEXC, full replace per api_key on each
 * poller tick (same semantics as binance_positions).
 *
 * `position_amt` is in COINS, not MEXC contracts: the engine multiplies
 * `holdVol` by the contract's `contractSize` before posting, so the stack cap
 * and every reader see the same unit on every exchange. SHORT is negative,
 * as on Binance. `mark_price` / `unrealized_profit` / `notional` are derived
 * from MEXC's fair price (its position rows carry neither); `maint_margin`
 * and `isolated_wallet` have no MEXC source and stay null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mexc_positions', function (Blueprint $table) {
            $table->id();
            $table->string('api_key', 128)->index('idx_mexc_positions_api_key')->comment('MEXC API key (FK mexc_accounts)');
            $table->string('uni_id', 36)->index('idx_mexc_positions_uni_id')->comment('User ID (FK user_credentials)');
            $table->string('symbol', 32)->index('idx_mexc_positions_symbol')->comment('Ticker form (BTCUSDT), not MEXC BTC_USDT');
            $table->string('position_side', 16)->comment('LONG or SHORT');
            $table->decimal('position_amt', 30, 8)->comment('Position size in coins (+ long, - short)');
            $table->decimal('entry_price', 30, 8)->nullable();
            $table->decimal('mark_price', 30, 8)->nullable();
            $table->decimal('unrealized_profit', 30, 8)->nullable();
            $table->decimal('notional', 30, 8)->nullable();
            $table->decimal('initial_margin', 30, 8)->nullable();
            $table->decimal('maint_margin', 30, 8)->nullable();
            $table->decimal('isolated_margin', 30, 8)->nullable();
            $table->decimal('isolated_wallet', 30, 8)->nullable();
            $table->bigInteger('update_time')->nullable()->comment('MEXC updateTime (ms)');
            $table->dateTime('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mexc_positions');
    }
};
