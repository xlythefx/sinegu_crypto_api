<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * referral_tracking — the network edge: one row per referred user.
 *
 * Two unique keys on purpose:
 *  - (referral_code, referred_user_uni_id): the mother-spec key — re-binding
 *    with the same code is a silent no-op at registration.
 *  - (referred_user_uni_id) alone: hardening beyond the spec. Without it a user
 *    could join two networks via two different codes, and BOTH referrers could
 *    release commission on the same paid invoice — the payout-items unique key
 *    includes the referrer, so it would not stop that double-pay. One network
 *    per user, ever.
 *
 * Removing a referral deletes this row only; payout history stays untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_tracking', function (Blueprint $table) {
            $table->id();
            $table->string('referral_code', 50)->comment('Code used at registration');
            $table->string('referrer_uni_id', 36)->index('idx_referral_tracking_referrer');
            $table->string('referred_user_uni_id', 36);
            $table->timestamp('created_at')->useCurrent()->comment('Referred at');

            $table->unique(['referral_code', 'referred_user_uni_id'], 'uq_referral_tracking_code_user');
            $table->unique('referred_user_uni_id', 'uq_referral_tracking_referred');

            $table->foreign('referrer_uni_id', 'fk_referral_tracking_referrer')
                ->references('uni_id')->on('user_credentials')
                ->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreign('referred_user_uni_id', 'fk_referral_tracking_referred')
                ->references('uni_id')->on('user_credentials')
                ->cascadeOnDelete()->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_tracking');
    }
};
