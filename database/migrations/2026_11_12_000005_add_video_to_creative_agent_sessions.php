<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creatives v2.1: the video editor has its own chat. A session with a
 * `video_id` is that video's EDITOR CHAT for its person (a plain index, like
 * the jobs' and scenes' video ids — a deleted video leaves the chat to be
 * pruned with the others). The workspace chat (video_id NULL) never picks one
 * as "the newest".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creative_agent_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('video_id')->nullable()->after('user_name')
                ->comment('The video this chat edits (the editor chat); NULL = the workspace chat');
            $table->index('video_id', 'idx_creative_agent_sessions_video');
        });
    }

    public function down(): void
    {
        Schema::table('creative_agent_sessions', function (Blueprint $table) {
            $table->dropIndex('idx_creative_agent_sessions_video');
            $table->dropColumn('video_id');
        });
    }
};
