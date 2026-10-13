<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two safety rails on asset settings (2026-10-14, after the 13 Oct RENDER
 * incident):
 *
 * - `assets.pending_sizing` — a size change (base size, max position size,
 *   streak ladder) saved while the main account holds the coin WAITS here and
 *   goes live when that position closes (App\Services\Assets\PendingSizing).
 *   Changing size in the middle of a stack makes the engine re-measure the
 *   open stack in the new entry size, so it would allow more entries than the
 *   cap intends. NULL = nothing waiting.
 * - `asset_changes` — every change to an asset's settings, who made it and
 *   from → to, including the automatic ones (a waiting size going live, the
 *   kill switch). Append-only; no foreign key, so a deleted asset's history
 *   stays readable (ticker + broker are copied onto each row).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $t) {
            $t->json('pending_sizing')->nullable()->after('killed_reason')
                ->comment('Waiting size change {base_size, max_increments, streak_sizing_enabled, streak_sizes}; NULL = none');
            $t->dateTime('pending_since')->nullable()->after('pending_sizing');
            $t->string('pending_by', 36)->nullable()->after('pending_since')->comment('uni_id of the admin who saved it');
            $t->string('pending_by_name', 255)->nullable()->after('pending_by');
        });

        Schema::create('asset_changes', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('asset_id')->nullable()->index();
            $t->string('ticker', 20);
            $t->string('broker', 64)->nullable();
            // created | updated | waiting | applied | cancelled | deleted | kill_switch
            $t->string('event', 16);
            $t->string('actor_uni_id', 36)->nullable()->comment('NULL = the system (kill switch, a waiting size going live)');
            $t->string('actor_name', 255)->nullable();
            $t->json('changes')->nullable()->comment('{field: {from, to}}');
            $t->string('note', 255)->nullable();
            $t->dateTime('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_changes');
        Schema::table('assets', function (Blueprint $t) {
            $t->dropColumn(['pending_sizing', 'pending_since', 'pending_by', 'pending_by_name']);
        });
    }
};
