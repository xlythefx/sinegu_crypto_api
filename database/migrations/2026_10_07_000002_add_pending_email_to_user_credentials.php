<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Changing an account's email now goes through the mailed 6-digit code, and
 * the address being proven waits HERE until the code is accepted.
 *
 * Until this, PUT /user/profile rewrote `email` in place with no password and
 * no re-verification: a stolen bearer token could point the account at an
 * attacker's inbox and the next forgot-password handed them the account. With
 * a pending column the live `email` — the one every login, reset code and
 * guarded route keys on — never moves until the NEW inbox has typed the code,
 * so a typo cannot lock the owner out and a thief cannot cut them off.
 *
 * Deliberately NOT unique: two users may both be mid-change to the same
 * address, and only the one who proves it first gets it (EmailVerification
 * re-checks `email` at verify time). A unique index here would let anyone
 * reserve an address they do not own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_credentials', function (Blueprint $table) {
            $table->string('pending_email', 255)->nullable()->after('email')
                ->comment('New address awaiting its verification code; NULL = no change in flight');
        });
    }

    public function down(): void
    {
        Schema::table('user_credentials', function (Blueprint $table) {
            $table->dropColumn('pending_email');
        });
    }
};
