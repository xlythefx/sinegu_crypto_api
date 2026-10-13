<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who closed a trade (2026-10-14): `bot` = the trading engine's exit path (a
 * TradingView exit, a retry, or an admin's manual close — all go through it),
 * `outside` = closed on the exchange without the bot (the customer by hand,
 * their own take-profit / stop-loss, a liquidation). NULL = not classified:
 * history from before the signal log existed, sandbox rows, or a close the
 * classifier has not reached yet. Set by App\Services\Positions\CloseSource.
 *
 * An `outside` close is left out of streak sizing — the run follows the
 * strategy's trades, not a customer taking profit early.
 */
return new class extends Migration
{
    private const TABLES = ['binance_pastpositions', 'mexc_pastpositions', 'bybit_pastpositions'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'close_source')) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) {
                $t->string('close_source', 16)->nullable()->after('closed_at')
                    ->comment('bot | outside; NULL = not classified');
                $t->index('close_source');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'close_source')) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) {
                $t->dropIndex(['close_source']);
                $t->dropColumn('close_source');
            });
        }
    }
};
