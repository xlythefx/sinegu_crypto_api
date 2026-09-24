<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * bybit_positions — open positions on Bybit, full replace per api_key on each
 * poller tick (same semantics as binance_positions / mexc_positions).
 *
 * `position_amt` is in COINS and SIGNED the Binance way (SHORT negative).
 * Bybit's own rows carry an unsigned `size` plus a Title-case `side`
 * (Buy/Sell), so the adapter derives the sign before it gets here — the stack
 * cap and every reader see one unit and one convention on every exchange.
 *
 * Field origins (/v5/position/list, category=linear): `entry_price` = avgPrice,
 * `mark_price` = markPrice, `unrealized_profit` = unrealisedPnl, `notional` =
 * positionValue, `initial_margin` = positionIM, `maint_margin` = positionMM.
 * `isolated_margin` is only meaningful when tradeMode = 1 (isolated);
 * `isolated_wallet` has no Bybit source and stays null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bybit_positions', function (Blueprint $table) {
            $table->id();
            $table->string('api_key', 128)->index('idx_bybit_positions_api_key')->comment('Bybit API key (FK bybit_accounts)');
            $table->string('uni_id', 36)->index('idx_bybit_positions_uni_id')->comment('User ID (FK user_credentials)');
            $table->string('symbol', 32)->index('idx_bybit_positions_symbol')->comment('Ticker form (BTCUSDT) — Bybit uses the same form');
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
            $table->bigInteger('update_time')->nullable()->comment('Bybit updatedTime (ms)');
            $table->dateTime('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bybit_positions');
    }
};
