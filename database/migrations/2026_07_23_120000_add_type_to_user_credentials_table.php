<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Role column: user (default on register) / admin / master.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_credentials', function (Blueprint $table) {
            $table->enum('type', ['user', 'admin', 'master'])
                ->default('user')
                ->after('status')
                ->comment('Account role');
        });
    }

    public function down(): void
    {
        Schema::table('user_credentials', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
