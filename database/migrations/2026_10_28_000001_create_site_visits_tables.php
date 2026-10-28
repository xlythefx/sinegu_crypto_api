<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The visitor counter (owner, 2026-10-28): who opens the PUBLIC website,
 * shown on Admin → Visitors to every staff role, IP included.
 *
 *  - site_visits — one row per counted page view (App\Services\Visits\VisitRecorder).
 *    A view the spam rules refuse is never a row; one they only DOUBT is a
 *    row with `suspicious = 1`, left out of every count. `ip` and
 *    `user_agent` are nulled after VisitRecorder::IP_DAYS by `visits:purge-ips`;
 *    `visitor_hash` (a daily HMAC of IP + browser) stays, so the counts
 *    survive the purge and cannot be traced back to an address.
 *  - site_visit_filtered — how many pings each rule threw away per UTC day,
 *    so the page can show that the filter is working without keeping a row
 *    per bot.
 *
 * Neither hangs off user_credentials: a visitor has no account. One
 * migration, so `migrate:rollback --step=1` removes the feature's tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_visits', function (Blueprint $table) {
            $table->id();
            $table->string('path', 64)->comment('A VisitPages path, never a raw URL');
            $table->char('visitor_hash', 64)->comment('HMAC of UTC day + IP + User-Agent: one visitor per day');
            $table->boolean('is_entry')->default(false)->comment('First page of the visit — the one the referrer belongs to');
            $table->string('ip', 45)->nullable()->comment('Nulled after VisitRecorder::IP_DAYS');
            $table->string('user_agent', 512)->nullable()->comment('Nulled with the IP');
            $table->char('country', 2)->nullable()->comment('CF-IPCountry; null off Cloudflare or when unknown');
            $table->string('device_type', 16)->comment('desktop | mobile | tablet');
            $table->string('browser', 64)->nullable();
            $table->string('os', 64)->nullable();
            $table->string('referrer_host', 120)->nullable()->comment('Another site that sent them; entry views only');
            $table->string('ref_code', 50)->nullable()->comment('A real referral_codes.code from ?ref=');
            $table->string('utm_source', 64)->nullable();
            $table->string('utm_campaign', 64)->nullable();
            $table->boolean('signed_in')->default(false)->comment('A customer with a session (staff are never counted)');
            $table->boolean('suspicious')->default(false)->comment('Kept but left out of every count');
            $table->string('suspicious_reason', 16)->nullable()->comment('flood | burst');
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at', 'idx_site_visits_time');
            $table->index(['ip', 'created_at'], 'idx_site_visits_ip_time');
            $table->index(['suspicious', 'created_at'], 'idx_site_visits_suspicious_time');
        });

        Schema::create('site_visit_filtered', function (Blueprint $table) {
            $table->id();
            $table->date('day');
            $table->string('reason', 16)->comment('VisitRecorder::FILTER_* — why the ping was not counted');
            $table->unsignedInteger('hits')->default(0);

            $table->unique(['day', 'reason'], 'uq_site_visit_filtered_day_reason');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_visit_filtered');
        Schema::dropIfExists('site_visits');
    }
};
