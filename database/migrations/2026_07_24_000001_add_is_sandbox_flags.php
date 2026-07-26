<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tag rows created by the admin "sandbox" testing endpoints so they can be
 * cleaned up safely without touching real user data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_credentials', function (Blueprint $table) {
            $table->boolean('is_sandbox')->default(false)->index();
        });

        Schema::table('binance_accounts', function (Blueprint $table) {
            $table->boolean('is_sandbox')->default(false)->index();
        });

        Schema::table('binance_pastpositions', function (Blueprint $table) {
            $table->boolean('is_sandbox')->default(false)->index();
        });
    }

    public function down(): void
    {
        Schema::table('user_credentials', function (Blueprint $table) {
            $table->dropColumn('is_sandbox');
        });

        Schema::table('binance_accounts', function (Blueprint $table) {
            $table->dropColumn('is_sandbox');
        });

        Schema::table('binance_pastpositions', function (Blueprint $table) {
            $table->dropColumn('is_sandbox');
        });
    }
};
