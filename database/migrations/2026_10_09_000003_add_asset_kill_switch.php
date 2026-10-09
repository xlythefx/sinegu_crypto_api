<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kill switch per asset (owner, 2026-10-09): when the MASTER account loses
 * `kill_switch_losses` trades in a row on the coin, the asset is switched off
 * for everyone (enabled = 0) until an admin turns it back on.
 *
 * - `kill_switch_armed_at` — losses before this moment never count. Set when
 *   the switch is configured or changed and when the coin is turned back on,
 *   so an old losing run cannot trip it again the moment it is re-enabled.
 * - `killed_at` / `killed_reason` — set when it trips, cleared when the coin
 *   is turned back on; the Admin Overview's caution strip reads them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $t) {
            $t->unsignedTinyInteger('kill_switch_losses')->nullable()->after('streak_sizing_enabled')
                ->comment('Master losses in a row that switch the asset off; NULL = kill switch off');
            $t->dateTime('kill_switch_armed_at')->nullable()->after('kill_switch_losses')
                ->comment('Only master closes after this count toward the kill switch');
            $t->dateTime('killed_at')->nullable()->after('kill_switch_armed_at')
                ->comment('When the kill switch switched the asset off; NULL = not tripped');
            $t->string('killed_reason', 255)->nullable()->after('killed_at');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $t) {
            $t->dropColumn(['kill_switch_losses', 'kill_switch_armed_at', 'killed_at', 'killed_reason']);
        });
    }
};
