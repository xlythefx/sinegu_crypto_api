<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sign-up details (owner, 2026-10-14): who a new user is and how they found
 * us, shown to every staff role on admin user detail.
 *
 *  - user_access_events — one row per registration and per successful login:
 *    the IP, the country Cloudflare resolved it to, and the device parsed from
 *    the User-Agent (App\Services\Auth\AccessLog). The browser's own timezone,
 *    language and screen size are kept ONLY when the visitor pressed "Accept
 *    all" on the cookie banner (`consent = 'all'`). Append-only.
 *  - user_signup_sources — "Where did you hear about us?", one row per ticked
 *    option so a later statistics page is a GROUP BY. Slugs are
 *    App\Services\Signup\SignupSources::SLUGS; `detail` is the free text of
 *    `other` and nothing else.
 *  - user_contact_handles — the optional Telegram / Discord usernames, typed
 *    by the user and editable in Settings. Unverified, so never unique: a
 *    uniqueness rule would let one person block another by typing their name.
 *
 * All three hang off user_credentials by uni_id and CASCADE on delete, like
 * daily_returns and crypto_wallets — deleting a user (AdminUserController::destroy,
 * SandboxController::deleteUser) takes these rows with it. One migration, so
 * `migrate:rollback --step=1` removes the feature's tables together.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_access_events', function (Blueprint $table) {
            $table->id();
            $table->string('uni_id', 36);
            $table->string('event', 16)->comment('register | login');
            $table->string('method', 16)->comment('password | discord');
            $table->string('ip', 45)->nullable();
            $table->char('country', 2)->nullable()->comment('CF-IPCountry; null off Cloudflare or when unknown');
            $table->string('device_type', 16)->comment('desktop | mobile | tablet | bot | unknown');
            $table->string('browser', 64)->nullable()->comment('e.g. "Chrome 141" — major version only');
            $table->string('os', 64)->nullable()->comment('e.g. "Windows", "iOS 18"');
            $table->string('user_agent', 512)->nullable();
            // Browser-reported, kept only with consent = 'all'.
            $table->string('timezone', 64)->nullable();
            $table->string('language', 35)->nullable();
            $table->string('screen', 16)->nullable()->comment('e.g. "1920x1080"');
            $table->string('consent', 16)->nullable()->comment("Cookie banner: 'all' | 'essential' | null (not answered)");
            $table->timestamp('created_at')->useCurrent();

            $table->index(['uni_id', 'created_at'], 'idx_user_access_events_user_time');
            $table->index('ip', 'idx_user_access_events_ip');
            $table->foreign('uni_id', 'fk_user_access_events_uni_id')
                ->references('uni_id')->on('user_credentials')
                ->cascadeOnDelete()->cascadeOnUpdate();
        });

        Schema::create('user_signup_sources', function (Blueprint $table) {
            $table->id();
            $table->string('uni_id', 36);
            $table->string('source', 32)->comment('SignupSources::SLUGS — stable, never renamed');
            $table->string('detail', 120)->nullable()->comment("Free text, only on source = 'other'");
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['uni_id', 'source'], 'uq_user_signup_sources_user_source');
            $table->foreign('uni_id', 'fk_user_signup_sources_uni_id')
                ->references('uni_id')->on('user_credentials')
                ->cascadeOnDelete()->cascadeOnUpdate();
        });

        Schema::create('user_contact_handles', function (Blueprint $table) {
            $table->string('uni_id', 36)->primary();
            $table->string('telegram', 32)->nullable()->comment('Without the @, case kept');
            $table->string('discord', 32)->nullable()->comment('Lowercase, without the @');
            $table->timestamps();

            $table->foreign('uni_id', 'fk_user_contact_handles_uni_id')
                ->references('uni_id')->on('user_credentials')
                ->cascadeOnDelete()->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_contact_handles');
        Schema::dropIfExists('user_signup_sources');
        Schema::dropIfExists('user_access_events');
    }
};
