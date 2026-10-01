<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Email verification goes live: from now on `email_verified = 0` locks an
 * account out of every route but /auth/me, /auth/logout and /auth/email/*.
 *
 * Until now the flag meant nothing — register() set it true unchecked, and a
 * Discord sign-up stored Discord's own word (possibly 0) — so everyone already
 * in the system is marked verified rather than locked out by a flag nobody
 * was ever asked to satisfy.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('user_credentials')
            ->where('email_verified', '!=', 1)
            ->update(['email_verified' => 1]);
    }

    /**
     * No-op: the previous values cannot be recovered, and they were never
     * enforced, so there is nothing meaningful to restore.
     */
    public function down(): void
    {
    }
};
