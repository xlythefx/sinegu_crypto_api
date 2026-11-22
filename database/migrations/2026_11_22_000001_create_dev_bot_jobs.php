<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Work for the developer agent on Christian's PC (2026-11-22): one row per
 * "investigate this" — a tap on a problem message, or anything he types to
 * the error bot. The PC polls for queued rows (it dials out; nothing reaches
 * into the PC), works in its own git worktree, and reports back here; the bot
 * relays every step to the DM.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dev_bot_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('status', 16)->default('queued');
            $table->string('source', 16);
            $table->text('prompt');
            $table->json('context')->nullable();
            $table->bigInteger('message_id')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('summary')->nullable();
            $table->json('links')->nullable();
            $table->timestamps();

            $table->index(['status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dev_bot_jobs');
    }
};
