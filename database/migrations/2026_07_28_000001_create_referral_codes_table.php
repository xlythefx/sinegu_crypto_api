<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * referral_codes — one shareable affiliate code per referrer.
 *
 * UNIQUE(user_uni_id) enforces one-code-per-referrer at the DB level (the mother
 * spec implied it but never constrained it), which makes "generate if absent"
 * race-safe. The code itself is globally unique — it is the public identifier
 * embedded in invite links ({origin}/auth?ref=CODE).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_codes', function (Blueprint $table) {
            $table->id();
            $table->string('user_uni_id', 36)->unique('uq_referral_codes_user')->comment('The referrer (user_credentials.uni_id)');
            $table->string('code', 50)->unique('uq_referral_codes_code')->comment('Shareable affiliate code');
            $table->timestamps();

            $table->foreign('user_uni_id', 'fk_referral_codes_user')
                ->references('uni_id')->on('user_credentials')
                ->cascadeOnDelete()->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_codes');
    }
};
