<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether streak sizing set a trade's size (2026-10-14). The engine knows it at
 * ENTRY — "3 losses in a row on RENDER, so size 40 instead of 60" — and until
 * now wrote it only to the signal log. It now rides on the entry's tag
 * (`open_strategies`, where the strategy name already travels) and lands on the
 * closed trade with it, so Past Positions can say a trade was sized by a streak.
 *
 * Every entry of one open stack shares one step (the run only moves when a
 * trade on the coin closes), so one closed row has exactly one answer. NULL =
 * normal size, or a close from before this existed — never reconstructed from
 * history, because the ladder may have changed since.
 *
 *   streak_kind       loss | win
 *   streak_run        the run's length at entry (3 = three in a row)
 *   streak_step       the ladder step that applied (deepest at or below the run)
 *   streak_size       the size that step set, before balance scaling
 *   streak_base_size  the asset's base_size at that moment — what it replaced
 *
 * `open_strategies.strategy` becomes nullable: an entry with a streak step but
 * no strategy label still needs its tag remembered.
 */
return new class extends Migration
{
    private const TABLES = ['open_strategies', 'binance_pastpositions', 'mexc_pastpositions', 'bybit_pastpositions'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'streak_kind')) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) {
                $t->string('streak_kind', 8)->nullable()->comment('loss | win; NULL = normal size');
                $t->unsignedSmallInteger('streak_run')->nullable()->comment('run length at entry');
                $t->unsignedTinyInteger('streak_step')->nullable()->comment('ladder step applied');
                $t->decimal('streak_size', 20, 6)->nullable()->comment('size the step set, before scaling');
                $t->decimal('streak_base_size', 20, 6)->nullable()->comment('asset base_size it replaced');
            });
        }

        if (Schema::hasTable('open_strategies')) {
            Schema::table('open_strategies', function (Blueprint $t) {
                $t->string('strategy', 100)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('open_strategies')) {
            // A streak-only tag has no strategy to keep; it was only ever a
            // carrier for the columns being dropped.
            DB::table('open_strategies')->whereNull('strategy')->delete();
            Schema::table('open_strategies', function (Blueprint $t) {
                $t->string('strategy', 100)->nullable(false)->change();
            });
        }

        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'streak_kind')) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn(['streak_kind', 'streak_run', 'streak_step', 'streak_size', 'streak_base_size']);
            });
        }
    }
};
