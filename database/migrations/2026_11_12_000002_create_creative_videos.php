<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creatives v2, part 2: VIDEO PROJECTS — the editor that turns clips into
 * one MP4 (exported in the browser; the MP4 is never stored, only its
 * metadata).
 *
 *  - creative_videos — one project: platform, shape (fixed once a clip is on
 *    it — every scene is generated in that shape), the client-owned TimelineDoc
 *    (captions, look, music, voiceover, sound effects) as JSON.
 *  - creative_video_scenes — the scene track, a TABLE and not JSON inside the
 *    video, because two writers touch it: the job runner attaches a finished
 *    clip (generation_id, status) while the editor autosaves trims and order.
 *    Separate columns, separate owners, no lost update.
 *
 * `generation_id` / `agent_job_id` are plain indexed ids, not foreign keys:
 * generations are never deleted, but a pruned chat's job is, and a scene must
 * outlive it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creative_videos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->nullable()->constrained('creative_plans')->nullOnDelete();
            $table->foreignId('style_id')->nullable()->constrained('creative_styles')->nullOnDelete();
            $table->string('title', 160);
            $table->string('platform', 8)->comment('x | tiktok');
            $table->string('aspect', 5)->comment('9:16 | 16:9 | 1:1 — every scene is made in this shape');
            $table->unsignedTinyInteger('max_seconds')->default(30);
            $table->unsignedTinyInteger('fps')->default(30);
            $table->json('timeline')->comment('Client-owned TimelineDoc: captions, look, music, voiceover, sfx');
            $table->string('status', 12)->default('draft')->comment('draft | exported');
            $table->dateTime('exported_at')->nullable();
            $table->string('exported_by_name', 120)->nullable();
            $table->decimal('export_seconds', 5, 2)->nullable();
            $table->unsignedInteger('export_bytes')->nullable()->comment('Metadata only — the MP4 is never stored');
            $table->string('created_by_uni_id', 64);
            $table->string('created_by_name', 120);
            $table->string('updated_by_name', 120)->nullable();
            $table->timestamps();
        });

        Schema::create('creative_video_scenes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_id')->constrained('creative_videos')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('kind', 10)->default('clip')->comment('clip | template');
            $table->string('label', 80);
            $table->text('prompt')->nullable();
            $table->string('engine', 10)->nullable()->comment('kling | seedance');
            $table->boolean('with_character')->default(false);
            $table->unsignedTinyInteger('seconds');
            $table->json('template')->nullable()->comment('{key, title, value, subtitle} — template scenes');
            // Server-owned: written by the job runner, never by PUT.
            $table->unsignedBigInteger('generation_id')->nullable()->index('idx_creative_video_scenes_generation');
            $table->unsignedTinyInteger('output_index')->default(0);
            $table->unsignedBigInteger('agent_job_id')->nullable()->index('idx_creative_video_scenes_job');
            $table->unsignedTinyInteger('step_index')->nullable();
            // Client-owned.
            $table->unsignedInteger('trim_in_ms')->nullable();
            $table->unsignedInteger('trim_out_ms')->nullable();
            // Server-owned.
            $table->string('status', 12)->default('empty')->comment('empty | planned | generating | ready | failed | expired');
            $table->string('error', 500)->nullable();
            $table->timestamps();

            $table->index(['video_id', 'position'], 'idx_creative_video_scenes_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creative_video_scenes');
        Schema::dropIfExists('creative_videos');
    }
};
