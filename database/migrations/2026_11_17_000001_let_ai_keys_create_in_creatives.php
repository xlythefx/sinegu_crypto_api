<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grok may make things in Creatives (owner, 2026-11-17): images and clips on
 * Higgsfield and Idea plans — only on a key a full admin switched on
 * (`can_create`, off for every key until then), and every generation it
 * starts names its key, so a key's spend per day can be capped
 * (`creative_generations.ai_access_key_id`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_access_keys', function (Blueprint $table) {
            $table->boolean('can_create')->default(false)->after('revoked_by_name')
                ->comment('May make images/clips and Idea plans in Admin → Creatives (off until a full admin switches it on)');
            $table->string('can_create_changed_by_name', 120)->nullable()->after('can_create');
            $table->dateTime('can_create_changed_at')->nullable()->after('can_create_changed_by_name');
        });
        Schema::table('creative_generations', function (Blueprint $table) {
            $table->unsignedBigInteger('ai_access_key_id')->nullable()->after('created_by_name')
                ->comment('Started by the AI connector with this key (labelled "Grok"); null = a person');
            $table->index('ai_access_key_id', 'idx_creative_generations_ai_key');
        });
    }

    public function down(): void
    {
        Schema::table('creative_generations', function (Blueprint $table) {
            $table->dropIndex('idx_creative_generations_ai_key');
            $table->dropColumn('ai_access_key_id');
        });
        Schema::table('ai_access_keys', function (Blueprint $table) {
            $table->dropColumn(['can_create', 'can_create_changed_by_name', 'can_create_changed_at']);
        });
    }
};
