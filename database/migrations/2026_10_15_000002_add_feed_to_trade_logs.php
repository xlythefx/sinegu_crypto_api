<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * trade_logs.feed — which signal feed a logged signal arrived on.
 *
 * `main` is TradingView's webhooks, fanned out to every customer, staff and
 * master account; `test` is the engine's separate test webhooks, fanned out
 * to tester-owned accounts only (App\Services\Engine\WebhookPaths). Every
 * existing row is a main-feed signal, which is what the default says, and an
 * engine deployed before this column sends no feed and is stored as main too.
 *
 * Indexed with `ts` because the two readers that must tell the feeds apart
 * are time-windowed: the Signal Log's `?feed=` filter and the health banner,
 * which counts MAIN signals only so test traffic can never move it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trade_logs', function (Blueprint $table) {
            $table->string('feed', 8)
                ->default('main')
                ->after('exchange')
                ->comment('Signal feed: main (TradingView, everyone) | test (testers only)');

            $table->index(['feed', 'ts'], 'idx_trade_logs_feed_ts');
        });
    }

    public function down(): void
    {
        Schema::table('trade_logs', function (Blueprint $table) {
            $table->dropIndex('idx_trade_logs_feed_ts');
            $table->dropColumn('feed');
        });
    }
};
