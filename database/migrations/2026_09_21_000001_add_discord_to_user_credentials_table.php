<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Sign in with Discord": the Discord identity linked to an account, and a
 * password column that may now be empty.
 *
 * `discord_id` is Discord's snowflake — a 64-bit integer that JavaScript
 * cannot hold exactly, so it is a STRING here and everywhere it travels.
 * UNIQUE because one Discord account is one person: the same id on two rows
 * would let either row's owner log into the other, and the index is also the
 * race backstop when two sign-ups complete at once.
 *
 * `password` becomes NULLABLE: a user who registered through Discord has no
 * password until they set one (Settings → "Set a password"), and NULL says so
 * explicitly — a random unusable hash would look like a real credential in a
 * dump and need a second flag to say it is not. AuthController::login answers
 * DISCORD_ONLY for such a row before it ever compares a hash.
 *
 * `change()` re-states the comment on purpose: since Laravel 11 a change keeps
 * only the modifiers it is given, so an unmentioned comment is dropped.
 *
 * down() drops the Discord columns but LEAVES password nullable — putting NOT
 * NULL back would fail on every Discord-only row, and there is no safe value
 * to fill in for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_credentials', function (Blueprint $table) {
            $table->string('discord_id', 32)->nullable()->unique('user_credentials_discord_id_unique')
                ->after('terms_version')->comment('Discord user snowflake, as a string; NULL = not linked');
            $table->string('discord_username', 64)->nullable()->after('discord_id');
            $table->dateTime('discord_linked_at')->nullable()->after('discord_username');

            $table->string('password')->nullable()
                ->comment('Hashed password; NULL = Discord-only account')->change();
        });
    }

    public function down(): void
    {
        Schema::table('user_credentials', function (Blueprint $table) {
            $table->dropUnique('user_credentials_discord_id_unique');
            $table->dropColumn(['discord_id', 'discord_username', 'discord_linked_at']);
        });
    }
};
