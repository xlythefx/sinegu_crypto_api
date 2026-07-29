<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One append-only row per TradingView signal processed by the trading engine —
 * what came in (action/ticker/price/strategy/leverage) and how the fan-out went
 * (target/filled/failed/skipped counts + per-account details JSON).
 *
 * Exchange-agnostic from day one, discriminated by an `exchange` column (same
 * pattern as the unified `invoices` table) — Bybit/MEXC engines log here too
 * without a new table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_logs', function (Blueprint $table) {
            $table->id();
            $table->enum('exchange', ['binance', 'bybit', 'mexc'])
                ->default('binance')
                ->comment('Which exchange engine produced this log');
            $table->string('action', 16);              // BUY | SELL | EXIT_LONG | EXIT_SHORT
            $table->string('ticker', 20);
            $table->boolean('success')->default(false);
            $table->decimal('price', 20, 8)->nullable();
            $table->string('strategy', 100)->nullable();
            $table->unsignedSmallInteger('leverage')->nullable();
            $table->string('category', 32)->nullable();  // signal | retry | rejected | ...
            $table->unsignedSmallInteger('target_count')->default(0);
            $table->unsignedSmallInteger('filled')->default(0);
            $table->unsignedSmallInteger('failed')->default(0);
            $table->unsignedSmallInteger('skipped')->default(0);
            $table->json('details')->nullable();         // per-account results
            $table->dateTime('ts');                      // signal time (engine-supplied)
            $table->dateTime('created_at')->useCurrent();

            $table->index(['exchange', 'ts'], 'idx_trade_logs_exchange_ts');
            $table->index('ticker', 'idx_trade_logs_ticker');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_logs');
    }
};
