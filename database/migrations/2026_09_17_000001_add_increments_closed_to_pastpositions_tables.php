<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many entry-sized increments a close took off the book.
 *
 * One past-position row is one CLOSE ORDER, and the engine closes the whole
 * stacked position in one order — so a position built from three signals
 * (`Increment (1/3)` … `(3/3)` in the channel) closes as ONE row. Counting rows
 * therefore undercounts what the audience watched happen: the daily recap said
 * "Trades closed: 4" on a day the channel itself had announced six increments
 * closing. The engine already derives the figure per close
 * (`_closed_increments`, the `Increments Closed (3/3)` line); this column keeps
 * it on the row so a reader can count increments instead of orders.
 *
 * NULL means "not recorded" — every row before this migration, and any row
 * written by the reconciliation poller rather than the webhook close path.
 * Readers treat NULL as 1: a row is at least one close. It is never backfilled
 * from `position_amt`, because the divisor (the account's scaled entry size at
 * the time) is not recoverable after the fact.
 */
return new class extends Migration
{
    private const TABLES = ['binance_pastpositions', 'mexc_pastpositions'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) {
                $t->unsignedSmallInteger('increments_closed')->nullable()->after('position_amt')
                    ->comment('Entry-sized increments this close took off; NULL = not recorded (count as 1)');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'increments_closed')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('increments_closed'));
            }
        }
    }
};
