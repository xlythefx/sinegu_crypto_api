<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records WHEN a user accepted the Terms and WHICH version they saw.
 *
 * A checkbox on the register form is worth nothing without the timestamp
 * behind it — the day a customer disputes a performance fee, "they ticked the
 * box" has to be backed by a row. The version is the "Last updated" date of
 * the Terms at the moment of acceptance, so a later revision of the text can
 * be told apart from what this user actually agreed to.
 *
 * Existing rows stay NULL: they registered before the checkbox existed, and
 * pretending otherwise would be a fabricated record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_credentials', function (Blueprint $table) {
            $table->dateTime('terms_accepted_at')->nullable()->after('email_verified');
            $table->string('terms_version', 32)->nullable()->after('terms_accepted_at')
                ->comment('The Terms "Last updated" date the user accepted');
        });
    }

    public function down(): void
    {
        Schema::table('user_credentials', function (Blueprint $table) {
            $table->dropColumn(['terms_accepted_at', 'terms_version']);
        });
    }
};
