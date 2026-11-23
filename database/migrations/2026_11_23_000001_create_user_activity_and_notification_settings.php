<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two requests from Dima (2026-11-23):
 *
 *  - user_activity_logs: what a person did THEMSELVES (changed their password,
 *    connected or renamed an exchange account, opened a payment…), one row per
 *    successful action, written by the `activity:<action>` route middleware.
 *    It joins the admin audit and the sign-in log on the customer's Timeline
 *    and on Admin → Activity. Never a credential or a typed contact value in
 *    `details`.
 *
 *  - notification_settings: per team email, whether it is sent and to whom.
 *    No row = the default list from the server's config (MAIL_ADMIN_ADDRESS
 *    or TRADE_ALERT_EMAILS), so nothing changes until an admin edits it on
 *    Admin → Notifications. `recipients` NULL = the default list too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->string('uni_id', 36);
            $table->string('action', 40)->comment('UserActivity::ACTIONS — stable, never renamed');
            $table->string('summary', 255);
            $table->json('details')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['uni_id', 'created_at'], 'idx_user_activity_user_time');
            $table->index('created_at', 'idx_user_activity_time');
        });

        Schema::create('notification_settings', function (Blueprint $table) {
            $table->string('slug', 64)->primary()->comment('TeamNotices::NOTICES key');
            $table->boolean('enabled')->default(true);
            $table->json('recipients')->nullable()->comment('NULL = the default list from config');
            $table->string('updated_by', 36)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_settings');
        Schema::dropIfExists('user_activity_logs');
    }
};
