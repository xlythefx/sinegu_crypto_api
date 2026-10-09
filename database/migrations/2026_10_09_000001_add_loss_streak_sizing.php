<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Loss-streak sizing: after N losing trades in a row on a coin, an account's
 * next entry uses a size the admin typed for that step instead of base_size.
 *
 * - `assets.loss_sizing_enabled` — the per-asset switch. Off = the asset sizes
 *   exactly as before, and the engine makes no extra read for it.
 * - `asset_loss_sizes` — the ladder, one row per configured step. A step left
 *   blank is simply absent: the engine carries the previous step forward.
 *   Rows rather than a JSON column because every other admin-edited setting in
 *   this schema is plain columns behind a unique key.
 * - `(api_key, symbol, closed_at)` on every past-positions table — the engine
 *   reads each account's last few closes on one coin per entry signal, newest
 *   first; the existing single-column indexes cannot serve that sort.
 */
return new class extends Migration
{
    private const PAST_POSITIONS = ['binance_pastpositions', 'bybit_pastpositions', 'mexc_pastpositions'];

    public function up(): void
    {
        Schema::table('assets', function (Blueprint $t) {
            $t->boolean('loss_sizing_enabled')->default(false)->after('base_size')
                ->comment('Loss-streak sizing on/off; steps live in asset_loss_sizes');
        });

        Schema::create('asset_loss_sizes', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedInteger('asset_id');
            $t->unsignedTinyInteger('losses')->comment('Losing trades in a row this step starts at (1-10)');
            $t->decimal('size', 20, 6)->comment('Entry size at this step, in the asset\'s units, before balance scaling');
            $t->timestamps();

            $t->unique(['asset_id', 'losses'], 'uq_asset_loss_sizes_asset_losses');
            $t->foreign('asset_id', 'fk_asset_loss_sizes_asset')
                ->references('asset_id')->on('assets')->cascadeOnDelete();
        });

        foreach (self::PAST_POSITIONS as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->index(['api_key', 'symbol', 'closed_at'], "idx_{$table}_api_symbol_closed");
            });
        }
    }

    public function down(): void
    {
        foreach (self::PAST_POSITIONS as $table) {
            if (Schema::hasTable($table)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropIndex("idx_{$table}_api_symbol_closed"));
            }
        }

        Schema::dropIfExists('asset_loss_sizes');

        Schema::table('assets', fn (Blueprint $t) => $t->dropColumn('loss_sizing_enabled'));
    }
};
