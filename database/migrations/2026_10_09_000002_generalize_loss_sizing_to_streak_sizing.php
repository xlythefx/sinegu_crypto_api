<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Loss-streak sizing becomes STREAK sizing: a step may now apply after N
 * losses in a row OR after N wins in a row (owner, 2026-10-09 — the same day
 * the loss-only version shipped).
 *
 * `asset_loss_sizes` (asset_id, losses, size) → `asset_streak_sizes`
 * (asset_id, kind 'loss'|'win', streak, size), unique per (asset, kind,
 * streak). A new table + copy rather than renames: every existing row is a
 * loss step, so it lands as kind = 'loss' with its count unchanged, and the
 * swap avoids MySQL refusing to drop the old unique key the foreign key leans
 * on. `assets.loss_sizing_enabled` → `streak_sizing_enabled`, value kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_streak_sizes', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedInteger('asset_id');
            $t->string('kind', 4)->comment("'loss' = after N losses in a row, 'win' = after N wins in a row");
            $t->unsignedTinyInteger('streak')->comment('Trades in a row this step starts at (1-10)');
            $t->decimal('size', 20, 6)->comment('Entry size at this step, in the asset\'s units, before balance scaling');
            $t->timestamps();

            $t->unique(['asset_id', 'kind', 'streak'], 'uq_asset_streak_sizes_asset_kind_streak');
            $t->foreign('asset_id', 'fk_asset_streak_sizes_asset')
                ->references('asset_id')->on('assets')->cascadeOnDelete();
        });

        if (Schema::hasTable('asset_loss_sizes')) {
            foreach (DB::table('asset_loss_sizes')->get() as $row) {
                DB::table('asset_streak_sizes')->insert([
                    'asset_id' => $row->asset_id,
                    'kind' => 'loss',
                    'streak' => $row->losses,
                    'size' => $row->size,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ]);
            }
            Schema::drop('asset_loss_sizes');
        }

        Schema::table('assets', function (Blueprint $t) {
            $t->boolean('streak_sizing_enabled')->default(false)->after('base_size')
                ->comment('Streak sizing on/off; steps live in asset_streak_sizes');
        });
        if (Schema::hasColumn('assets', 'loss_sizing_enabled')) {
            DB::table('assets')->update(['streak_sizing_enabled' => DB::raw('loss_sizing_enabled')]);
            Schema::table('assets', fn (Blueprint $t) => $t->dropColumn('loss_sizing_enabled'));
        }
    }

    public function down(): void
    {
        Schema::create('asset_loss_sizes', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedInteger('asset_id');
            $t->unsignedTinyInteger('losses');
            $t->decimal('size', 20, 6);
            $t->timestamps();

            $t->unique(['asset_id', 'losses'], 'uq_asset_loss_sizes_asset_losses');
            $t->foreign('asset_id', 'fk_asset_loss_sizes_asset')
                ->references('asset_id')->on('assets')->cascadeOnDelete();
        });
        // Win steps have no loss-only equivalent and are dropped on the way down.
        foreach (DB::table('asset_streak_sizes')->where('kind', 'loss')->get() as $row) {
            DB::table('asset_loss_sizes')->insert([
                'asset_id' => $row->asset_id,
                'losses' => $row->streak,
                'size' => $row->size,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        }
        Schema::drop('asset_streak_sizes');

        Schema::table('assets', function (Blueprint $t) {
            $t->boolean('loss_sizing_enabled')->default(false)->after('base_size');
        });
        DB::table('assets')->update(['loss_sizing_enabled' => DB::raw('streak_sizing_enabled')]);
        Schema::table('assets', fn (Blueprint $t) => $t->dropColumn('streak_sizing_enabled'));
    }
};
