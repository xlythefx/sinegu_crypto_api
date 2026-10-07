<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the current password-reset code was MAILED, for the 60 s resend
 * cooldown.
 *
 * The cooldown used to be derived from `reset_code_expires_at` (sent = expiry
 * − TTL), the way email verification still does it. That stopped being true
 * the moment a reissue inside a live window stopped moving the expiry
 * (PasswordResetController::forgot, 2026-10-07 — the change that caps
 * guesses at 5 per 15-minute window per account instead of letting every
 * reissue restart the window): derived from an expiry that no longer moves,
 * every reissue after the first reads as "cooldown already past", and the
 * only brake on mailing a victim would have been the 2/min per-email
 * throttle. Nullable: a row whose code predates this column falls back to
 * the old derivation until that code is replaced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_credentials', function (Blueprint $table) {
            $table->dateTime('reset_code_sent_at')->nullable()->after('reset_code_attempts')
                ->comment('When the current reset code was mailed; the 60 s resend cooldown counts from here');
        });
    }

    public function down(): void
    {
        Schema::table('user_credentials', function (Blueprint $table) {
            $table->dropColumn('reset_code_sent_at');
        });
    }
};
