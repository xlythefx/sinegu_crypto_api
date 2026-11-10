<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admin → Creatives (owner's request, 2026-11-10): the marketing team (the
 * `collaborator` role) plans X / TikTok content and generates images and
 * videos with Higgsfield, always with one invented pixel-art avatar.
 *
 *  - creative_settings — ONE row (id 1): the monthly spending cap in USD,
 *    set by a full admin. Generation locks this row (`lockForUpdate`) while it
 *    checks the month's spend, so two simultaneous generations cannot both
 *    slip under the cap.
 *  - creative_characters — the avatar's identity as text (look, style, rules),
 *    added to every prompt that "uses the character". Seeded with "Pixel".
 *  - creative_character_refs — the avatar's reference images. The ONLY files
 *    this feature stores (private local disk, creatives/refs/), max 8 per
 *    character. Re-uploaded to Higgsfield for every generation that uses them.
 *  - creative_plans — the content plan: one row per post idea, idea → posted.
 *  - creative_generations — one row per Higgsfield request: who, what was
 *    sent, what it cost, the result links and when they expire. The images
 *    and videos themselves are NEVER stored here; Higgsfield keeps them for
 *    7 days. A row is never deleted: its `charged_usd` is the month's spend.
 *
 * One migration, so `migrate:rollback --step=1` removes the feature's tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creative_settings', function (Blueprint $table) {
            $table->id();
            $table->decimal('monthly_cap_usd', 10, 2)->default(100.00)->comment('0 = generation switched off');
            $table->string('updated_by_uni_id', 64)->nullable();
            $table->string('updated_by_name', 120)->nullable();
            $table->timestamps();
        });

        DB::table('creative_settings')->insert([
            'id' => 1,
            'monthly_cap_usd' => 100.00,
            'created_at' => now(),
            // Nobody has set it yet: the page prints "default" until someone does.
            'updated_at' => null,
        ]);

        Schema::create('creative_characters', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->text('look')->nullable()->comment('What the character looks like, added to prompts');
            $table->text('style_prompt')->nullable();
            $table->text('rules')->nullable();
            $table->string('updated_by_name', 120)->nullable();
            $table->timestamps();
        });

        DB::table('creative_characters')->insert([
            'name' => 'Pixel',
            'look' => 'A small friendly robot trader mascot with a square head, a glowing gold visor and a navy-blue body with the Pixel Alpha "PA" badge on its chest.',
            'style_prompt' => '16-bit pixel art, crisp hard-edged pixels, limited palette of deep navy, gold and violet, clean dark background, no blur, no photorealism.',
            'rules' => 'Always the same robot design and colours. Never show real people, real logos of other brands, or any profit figures or money amounts.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::create('creative_character_refs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained('creative_characters')->cascadeOnDelete();
            $table->string('label', 80)->nullable();
            $table->string('path', 255)->comment('Path on the private local disk (creatives/refs/…)');
            $table->string('mime', 40);
            $table->unsignedInteger('bytes');
            $table->string('source', 12)->comment('upload | generation');
            $table->unsignedBigInteger('generation_id')->nullable()->comment('The generation it was copied from, when source = generation');
            $table->string('created_by_name', 120)->nullable();
            $table->timestamps();
        });

        Schema::create('creative_plans', function (Blueprint $table) {
            $table->id();
            $table->string('title', 160);
            $table->string('status', 12)->default('idea')->comment('idea | script | generating | review | approved | posted');
            $table->string('platform', 12)->comment('x | tiktok');
            $table->string('format', 12)->comment('video | image | carousel');
            $table->date('planned_for')->nullable();
            $table->text('hook')->nullable();
            $table->text('script')->nullable();
            $table->text('caption')->nullable();
            $table->string('hashtags', 500)->nullable();
            $table->text('notes')->nullable();
            $table->string('posted_url', 500)->nullable();
            $table->string('created_by_uni_id', 64)->nullable();
            $table->string('created_by_name', 120)->nullable();
            $table->string('updated_by_name', 120)->nullable();
            $table->timestamps();

            $table->index('status', 'idx_creative_plans_status');
            $table->index('planned_for', 'idx_creative_plans_planned_for');
        });

        Schema::create('creative_generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->nullable()->constrained('creative_plans')->nullOnDelete();
            $table->foreignId('character_id')->nullable()->constrained('creative_characters')->nullOnDelete();
            $table->string('kind', 8)->comment('image | video');
            $table->string('model', 40)->comment('Preset key from the CreativeModels registry');
            $table->string('endpoint', 120);
            $table->text('prompt')->comment('What the person typed');
            $table->text('full_prompt')->comment('What was sent: the prompt plus the character text');
            $table->json('params');
            $table->boolean('used_character');
            $table->string('request_id', 64)->nullable()->unique();
            $table->string('status', 16)->default('submitting')
                ->comment('submitting | queued | in_progress | completed | failed | nsfw | canceled | expired');
            $table->string('error', 500)->nullable();
            $table->json('outputs')->nullable()->comment('[{type, url}] from Higgsfield; cleared on expiry');
            $table->decimal('estimated_usd', 10, 4);
            $table->decimal('charged_usd', 10, 4)->comment('The reservation; 0 once failed / nsfw / canceled');
            $table->dateTime('expires_at')->nullable()->comment('When Higgsfield deletes the files');
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('last_checked_at')->nullable();
            $table->string('created_by_uni_id', 64)->nullable();
            $table->string('created_by_name', 120)->nullable();
            $table->timestamps();

            $table->index('status', 'idx_creative_generations_status');
            $table->index('created_at', 'idx_creative_generations_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creative_generations');
        Schema::dropIfExists('creative_plans');
        Schema::dropIfExists('creative_character_refs');
        Schema::dropIfExists('creative_characters');
        Schema::dropIfExists('creative_settings');
    }
};
