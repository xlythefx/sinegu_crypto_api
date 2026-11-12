<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creatives v2, part 4: the shared AUDIO library — music the team holds the
 * rights to and recorded voiceovers. The one exception to "nothing on our
 * disk" besides the character's reference images (owner's decision,
 * 2026-11-12): small files only (≤ 15 MB each, ≤ 40 in all), on the private
 * local disk (`creatives/audio/<random>.<ext>`), served through a signed link.
 * Never a video.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creative_audio_assets', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 10)->comment('music | voiceover');
            $table->string('label', 80);
            $table->string('mime', 60);
            $table->unsignedInteger('bytes');
            $table->unsignedInteger('duration_ms')->nullable()->comment('As measured by the browser that uploaded it');
            $table->string('path', 255);
            $table->string('created_by_name', 120);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creative_audio_assets');
    }
};
