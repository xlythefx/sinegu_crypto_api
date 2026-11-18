<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * High-risk alerts (2026-11-18, the user's request with the sub-master role).
 *
 * `risk_alerts` = one row per serious failure that needs a person: a trade
 * that reached accounts outside its signal feed, a close nobody is coming to
 * fix, the kill switch, the master's or sub-master's key refused, the engine
 * not answering, a signal refused because the coin list could not load. Each
 * is emailed to TRADE_ALERT_EMAILS (at most once an hour per problem), shown
 * on the Admin Overview until a full admin marks it resolved.
 *
 * `trade_logs.breach` = the accounts that FILLED a signal although their
 * owner's role is not that feed's (FeedAudit) — the proof on the row itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 40)->comment('feed_breach | exit_stuck | kill_switch | key_refused | engine_down | assets_unavailable');
            $table->string('dedupe_key', 191)->comment('Same kind + key within an hour = one alert, counted again');
            $table->string('exchange', 16)->nullable();
            $table->string('title', 255);
            $table->json('lines')->nullable()->comment('Plain sentences, shown on the Overview and in the email');
            $table->unsignedBigInteger('trade_log_id')->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->dateTime('last_seen_at');
            $table->dateTime('emailed_at')->nullable();
            $table->dateTime('telegram_at')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->string('resolved_by_name', 120)->nullable();
            $table->string('resolve_note', 500)->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->index(['kind', 'dedupe_key', 'last_seen_at'], 'idx_risk_alerts_dedupe');
            $table->index('resolved_at', 'idx_risk_alerts_open');
        });

        Schema::table('trade_logs', function (Blueprint $table) {
            $table->json('breach')->nullable()->after('details')
                ->comment('Accounts that filled although their owner is not on this feed (FeedAudit)');
        });
    }

    public function down(): void
    {
        Schema::table('trade_logs', function (Blueprint $table) {
            $table->dropColumn('breach');
        });
        Schema::dropIfExists('risk_alerts');
    }
};
