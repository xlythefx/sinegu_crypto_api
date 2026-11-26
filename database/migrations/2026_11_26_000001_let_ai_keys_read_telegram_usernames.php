<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dima's Grok could not find the Telegram usernames of people who register
 * (2026-11-26): AI Access drops every contact field on purpose. A full admin
 * may now allow ONE key to read them (`can_see_contacts`, off for every key
 * until then). Telegram usernames only — WhatsApp numbers, emails and phone
 * numbers stay hidden from every key. Who switched it and when stays on the key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_access_keys', function (Blueprint $table) {
            $table->boolean('can_see_contacts')->default(false)->after('can_create_changed_at')
                ->comment('May read the Telegram usernames people gave at sign-up (off until a full admin switches it on)');
            $table->string('can_see_contacts_changed_by_name', 120)->nullable()->after('can_see_contacts');
            $table->dateTime('can_see_contacts_changed_at')->nullable()->after('can_see_contacts_changed_by_name');
        });
    }

    public function down(): void
    {
        Schema::table('ai_access_keys', function (Blueprint $table) {
            $table->dropColumn(['can_see_contacts', 'can_see_contacts_changed_by_name', 'can_see_contacts_changed_at']);
        });
    }
};
