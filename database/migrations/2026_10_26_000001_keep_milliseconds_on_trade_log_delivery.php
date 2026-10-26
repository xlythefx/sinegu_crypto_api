<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * trade_logs.sent_at / received_at keep MILLISECONDS from now on.
 *
 * The engine has always sent both with milliseconds, but the columns were
 * plain DATETIME, so MySQL kept whole seconds. That was enough to prove a
 * TradingView alert arrived 8 s late; it is not enough to say which of two
 * feeds sending the same signal (TradingView on the main feed, QuantLab on
 * the test feed) reached us first when they land in the same second.
 *
 * Rows written before this keep their whole seconds — the milliseconds were
 * never stored, so there is nothing to backfill. TradeLog casts both columns
 * with MillisecondDateTime, which is what writes the fraction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trade_logs', function (Blueprint $table) {
            $table->dateTime('sent_at', 3)->nullable()
                ->comment("TradingView's {{timenow}} — when the alert fired (UTC)")->change();
            $table->dateTime('received_at', 3)->nullable()
                ->comment('When the engine received the webhook (UTC, ms)')->change();
        });
    }

    public function down(): void
    {
        Schema::table('trade_logs', function (Blueprint $table) {
            $table->dateTime('sent_at')->nullable()
                ->comment("TradingView's {{timenow}} — when the alert fired (UTC)")->change();
            $table->dateTime('received_at')->nullable()
                ->comment('When the engine received the webhook (UTC)')->change();
        });
    }
};
