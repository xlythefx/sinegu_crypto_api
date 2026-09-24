<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * bybit_pastpositions — closed trades on Bybit, in the FINAL
 * binance_pastpositions shape (`is_sandbox`, `exchange_fee`, `fee_source` and
 * `increments_closed` from day one).
 *
 * Same contract as the other two tables: `realized_pnl` is stored NET of the
 * exchange fee, `exchange_fee` is the estimate (TradingFee, at Bybit's own
 * taker rate) until the receipts ledger replaces it, and `fee_source` says
 * which figure the row holds. There is no gross/net cutoff here — the table
 * starts long after TradingFee::NET_SINCE, so every row is net.
 *
 * `order_id` is bigint, NOT a string, deliberately: the shared pipeline is
 * numeric end to end — EngineSyncController validates `rows.*.order_id` as
 * `integer`, FeeAttribution and FeeRebase cast `(int)`, and FeeRebase selects
 * `where('order_id', '>', 0)` because 0 is the webhook's marker for "the venue
 * returned no id". Bybit V5 orderIds are numeric strings (19-digit snowflakes,
 * inside signed-bigint range), so they fit. The adapter must convert once and
 * REFUSE a non-numeric id loudly rather than truncate it: order_id is what
 * joins a close to its fee receipts, and a silently mangled one attributes a
 * fee to the wrong trade.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bybit_pastpositions', function (Blueprint $table) {
            $table->id();
            $table->string('api_key', 128)->index('idx_bybit_pastpositions_api_key')->comment('Bybit API key (FK bybit_accounts)');
            $table->string('uni_id', 36)->index('idx_bybit_pastpositions_uni_id')->comment('User ID (FK user_credentials)');
            $table->string('symbol', 32)->index('idx_bybit_pastpositions_symbol')->comment('Ticker form (BTCUSDT)');
            $table->string('position_side', 16)->comment('LONG or SHORT');
            $table->decimal('position_amt', 30, 8)->comment('Closed quantity in coins');
            $table->unsignedSmallInteger('increments_closed')->nullable()
                ->comment('Entry-sized increments this close took off; NULL = not recorded (count as 1)');
            $table->decimal('entry_price', 30, 8)->nullable();
            $table->decimal('exit_price', 30, 8)->nullable();
            $table->decimal('realized_pnl', 30, 8)->nullable()->comment('NET of exchange_fee');
            $table->string('side', 8)->comment('BUY or SELL (close direction)');
            $table->bigInteger('order_id')->nullable()->comment('Bybit orderId (numeric); 0 = venue returned none');
            $table->dateTime('closed_at')->index('idx_bybit_pastpositions_closed_at')->comment('When position was closed (UTC)');
            $table->string('strategy', 100)->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->boolean('is_sandbox')->default(false)->index('idx_bybit_pastpositions_is_sandbox');
            $table->decimal('exchange_fee', 30, 8)->nullable()
                ->comment('Exchange fee already deducted from realized_pnl');
            $table->string('fee_source', 16)->nullable()
                ->comment('NULL = gross; estimated | actual | manual — see TradingFee');

            $table->unique(['api_key', 'symbol', 'order_id'], 'uq_bybit_pastpositions_api_symbol_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bybit_pastpositions');
    }
};
