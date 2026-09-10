<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the exchange still accepts this account's API key FROM OUR SERVER.
 *
 * Columns rather than a new table on purpose: this is one current state per
 * account (the engine overwrites it on every verdict), not an event log — a
 * table would mean a join on the hottest read in the system, the engine's
 * account list, to answer a question the row itself can hold. If we later want
 * the history of breakages, that is a separate append-only table and these
 * columns stay as the fast current view.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('binance_accounts', function (Blueprint $table) {
            $table->string('key_status', 16)->default('ok')->after('enabled');
            // Binance's own code (-2015) and message, kept for support: it is
            // the difference between "whitelist our IP" and "your key is wrong".
            $table->string('key_error_code', 16)->nullable()->after('key_status');
            $table->string('key_error_reason', 32)->nullable()->after('key_error_code');
            $table->string('key_error_message', 255)->nullable()->after('key_error_reason');
            // When it FIRST broke — the 3-day disconnect deadline counts from
            // here, so repeated reports of the same fault never extend it.
            $table->timestamp('key_blocked_at')->nullable()->after('key_error_message');
            $table->timestamp('key_checked_at')->nullable()->after('key_blocked_at');

            $table->index(['key_status', 'key_blocked_at'], 'binance_accounts_key_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('binance_accounts', function (Blueprint $table) {
            $table->dropIndex('binance_accounts_key_status_index');
            $table->dropColumn([
                'key_status',
                'key_error_code',
                'key_error_reason',
                'key_error_message',
                'key_blocked_at',
                'key_checked_at',
            ]);
        });
    }
};
