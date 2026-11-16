<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The WhatsApp number (owner, 2026-11-16): asked at sign-up beside Telegram,
 * both required, and required before a customer may connect an exchange.
 * Stored in E.164 (`+66812345678`) by App\Services\Signup\ContactHandles.
 * Typed by the user and never verified, so never unique — same reason as the
 * usernames beside it. Personal data: the AI connector's redactor drops it
 * by name and the Privacy Policy lists it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_contact_handles', function (Blueprint $table) {
            $table->string('whatsapp', 20)->nullable()->after('discord')
                ->comment('E.164, e.g. +66812345678; typed, unverified');
        });
    }

    public function down(): void
    {
        Schema::table('user_contact_handles', function (Blueprint $table) {
            $table->dropColumn('whatsapp');
        });
    }
};
