<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Strategy tag for each OPEN position, keyed (exchange, api_key, symbol,
 * position_side). Written by the engine when an entry fills; read back when an
 * EXIT alert arrives without a `strategy` field so the closing
 * `binance_pastpositions` row keeps the entry's tag; deleted once consumed.
 *
 * Exchange-agnostic via the `exchange` discriminator (same pattern as the
 * unified `invoices` table).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('open_strategies', function (Blueprint $table) {
            $table->id();
            $table->enum('exchange', ['binance', 'bybit', 'mexc'])->default('binance');
            $table->string('api_key', 128);
            $table->string('symbol', 32);
            $table->string('position_side', 8);          // LONG | SHORT | BOTH
            $table->string('strategy', 100);
            $table->dateTime('created_at')->useCurrent();

            $table->unique(
                ['exchange', 'api_key', 'symbol', 'position_side'],
                'uq_open_strategies_exchange_key_symbol_side'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('open_strategies');
    }
};
