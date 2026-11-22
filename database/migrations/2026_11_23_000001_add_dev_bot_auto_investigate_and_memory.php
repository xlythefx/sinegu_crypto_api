<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The developer error bot learns (2026-11-23, Christian): a NEW kind of error
 * is investigated by the PC agent on its own (`auto_investigate`), and what
 * the agent learns is kept as its memory — read before every job, visible and
 * editable on Admin → Error Bot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dev_bot_settings', function (Blueprint $table) {
            $table->boolean('auto_investigate')->default(true)->after('agent_paused');
        });

        Schema::create('dev_bot_memories', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200);
            $table->text('body');
            // What it explains, e.g. "signal:binance:main:LTCUSDT:order_failed" or "exc:<hash>".
            $table->string('signature', 120)->nullable()->index();
            $table->string('source', 16)->default('agent'); // agent | admin
            $table->unsignedBigInteger('job_id')->nullable();
            $table->string('updated_by_name', 100)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dev_bot_memories');
        Schema::table('dev_bot_settings', function (Blueprint $table) {
            $table->dropColumn('auto_investigate');
        });
    }
};
