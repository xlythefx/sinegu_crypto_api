<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leverage per coin (2026-11-10). When set, the engine asks the exchange for
 * this leverage on every entry instead of the one the TradingView alert names;
 * NULL = the alert's (25x when it names none).
 *
 * Prompted by SANTOSUSDT on 9 Nov 2026: Binance stopped accepting 25x on the
 * coin, every alert still asked for 25x, and every entry was refused (-2027)
 * at every size. The only fix was editing each alert by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $t) {
            $t->unsignedTinyInteger('leverage')->nullable()->after('max_increments')
                ->comment('Leverage the engine asks for on entries (1-125); NULL = the alert\'s');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $t) {
            $t->dropColumn('leverage');
        });
    }
};
