<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creatives v2, part 3: a job can make a VIDEO's clips. The editor proposes
 * one with no chat behind it, so `session_id` becomes nullable (the FK
 * stays) and the job records whose spend it is (`owner_uni_id`) and the video
 * it fills (`video_id`, a plain index like the scenes' ids). A job's status
 * gains `completed_with_failures` — the clips that worked still land.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creative_agent_jobs', function (Blueprint $table) {
            $table->unsignedBigInteger('video_id')->nullable()->after('plan_id')
                ->comment('The video project whose scenes this job makes');
            $table->string('owner_uni_id', 64)->nullable()->after('video_id')
                ->comment('Whose spend later steps are when the job has no chat');
            $table->index('video_id', 'idx_creative_agent_jobs_video');
        });

        Schema::table('creative_agent_jobs', function (Blueprint $table) {
            $table->uuid('session_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // A job with no chat cannot exist in the old shape: drop those (their
        // generations, the month's spend, stay — they only lose the link).
        $orphans = DB::table('creative_agent_jobs')->whereNull('session_id')->pluck('id');
        if ($orphans->isNotEmpty()) {
            DB::table('creative_generations')->whereIn('agent_job_id', $orphans)->update(['agent_job_id' => null]);
            DB::table('creative_agent_jobs')->whereIn('id', $orphans)->delete();
        }
        DB::table('creative_agent_jobs')->where('status', 'completed_with_failures')->update(['status' => 'completed']);

        Schema::table('creative_agent_jobs', function (Blueprint $table) {
            $table->uuid('session_id')->nullable(false)->change();
        });

        Schema::table('creative_agent_jobs', function (Blueprint $table) {
            $table->dropIndex('idx_creative_agent_jobs_video');
            $table->dropColumn(['video_id', 'owner_uni_id']);
        });
    }
};
