<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * community_details — optional public profile, strictly 1:1 with a referral code.
 *
 * profile_banner / profile_image columns exist for spec parity but are
 * deliberately NOT surfaced (no upload endpoints, no UI): nothing consumes them
 * today — the invite link lands on /auth, which renders no community page.
 * Keeping the columns costs nothing and spares a migration if a public invite
 * landing page ever gets built.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referral_id')->unique('uq_community_details_referral');
            $table->string('community_name', 255);
            $table->text('bio');
            $table->string('profile_banner', 500)->nullable();
            $table->string('profile_image', 500)->nullable();
            $table->timestamps();

            $table->foreign('referral_id', 'fk_community_details_referral')
                ->references('id')->on('referral_codes')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_details');
    }
};
