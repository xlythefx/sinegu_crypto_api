<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin → Error Bot's switches (2026-11-23): which kinds of message the
 * developer error bot may send, a mute-everything-until, and the PC agent's
 * pause. One row, read on every send.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dev_bot_settings', function (Blueprint $table) {
            $table->id();
            $table->json('disabled_kinds')->nullable();
            $table->timestamp('muted_until')->nullable();
            $table->boolean('agent_paused')->default(false);
            $table->string('updated_by_name', 100)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dev_bot_settings');
    }
};
