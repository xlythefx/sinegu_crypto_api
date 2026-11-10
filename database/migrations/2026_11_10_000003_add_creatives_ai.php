<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin → Creatives, the AI writer (owner's request, 2026-11-10): Claude
 * drafts a post (title, hook, script, caption, hashtags, a Studio video
 * prompt) from a short brief, suggests ideas, and writes a weekly recap draft
 * on Saturdays. A draft is never published; the team edits and saves it.
 *
 *  - creative_ai_calls — one row per Claude call: what kind, the tokens, the
 *    cost. AI writing counts against the SAME monthly cap as Higgsfield
 *    generations (App\Services\Creatives\CreativeBudget sums both), so a row
 *    is never deleted once Claude answered — refusals included, because the
 *    tokens were spent. A row with `stop_reason` NULL is a call still in
 *    flight: its `cost_usd` is the reservation (CreativeAi::MAX_CALL_USD)
 *    until the answer replaces it with the real figure.
 *  - creative_plans += `video_prompt` (the Studio prompt the AI wrote,
 *    editable) and `ai_drafted_at` (set when the plan's fields came from the
 *    AI and were saved).
 *  - creative_settings += `ai_weekly_recap` (the Saturday draft, on by
 *    default; a full admin switches it).
 *
 * Its own migration (000002 is live on prod), so `migrate:rollback --step=1`
 * removes the AI writer and leaves the rest of Creatives standing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creative_ai_calls', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 12)->comment('draft | ideas | weekly');
            $table->string('model', 40)->comment('The model that answered (a refusal fallback may name another)');
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0)->comment('Thinking included — it is billed as output');
            $table->decimal('cost_usd', 10, 4)->comment('Counts against the monthly cap; the reservation while stop_reason is NULL');
            $table->string('stop_reason', 24)->nullable()->comment('NULL = in flight; unconfirmed = the answer was lost, reservation kept');
            $table->foreignId('plan_id')->nullable()->constrained('creative_plans')->nullOnDelete();
            $table->string('brief', 500)->nullable();
            $table->string('created_by_uni_id', 64)->nullable();
            $table->string('created_by_name', 120)->nullable();
            $table->timestamps();

            $table->index('created_at', 'idx_creative_ai_calls_created');
        });

        Schema::table('creative_plans', function (Blueprint $table) {
            $table->text('video_prompt')->nullable()->after('notes')->comment('The Studio prompt the AI wrote; editable');
            $table->dateTime('ai_drafted_at')->nullable()->after('posted_url')->comment('When AI-drafted fields were saved');
        });

        Schema::table('creative_settings', function (Blueprint $table) {
            $table->boolean('ai_weekly_recap')->default(true)->after('monthly_cap_usd')
                ->comment('Draft a weekly recap plan every Saturday 00:15 Manila');
        });
    }

    public function down(): void
    {
        Schema::table('creative_settings', function (Blueprint $table) {
            $table->dropColumn('ai_weekly_recap');
        });

        Schema::table('creative_plans', function (Blueprint $table) {
            $table->dropColumn(['video_prompt', 'ai_drafted_at']);
        });

        Schema::dropIfExists('creative_ai_calls');
    }
};
