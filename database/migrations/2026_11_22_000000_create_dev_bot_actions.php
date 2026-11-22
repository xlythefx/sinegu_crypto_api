<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The developer error bot's suggestions (2026-11-22): one row per fix it
 * offered in Telegram — today "add this coin" with size buttons — and what
 * became of it (applied / skipped / already added / failed). The row is the
 * record of who approved what; the asset's own change history records the
 * write itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dev_bot_actions', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 32);
            $table->string('status', 16)->default('open');
            $table->string('exchange', 16);
            $table->string('ticker', 20);
            $table->string('side', 8)->nullable();
            $table->string('feed', 16)->nullable();
            $table->unsignedBigInteger('trade_log_id')->nullable();
            $table->json('payload');
            $table->unsignedInteger('occurrences')->default(1);
            $table->bigInteger('message_id')->nullable();
            $table->json('chosen')->nullable();
            $table->text('result_text')->nullable();
            $table->string('resolved_by', 100)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['kind', 'exchange', 'ticker', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dev_bot_actions');
    }
};
