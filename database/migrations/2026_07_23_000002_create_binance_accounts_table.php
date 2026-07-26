<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * binance_accounts — ported from sinegu_db with one deliberate change:
 * an auto-increment `id` primary key (the mother table used api_key as PK).
 * api_key stays unique so child tables can still reference it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('binance_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('api_key', 128)->unique('uq_binance_accounts_api_key')->comment('Binance API key');
            $table->string('uni_id', 36)->nullable()->index('idx_binance_accounts_uni_id')->comment('User ID from user_credentials (owner)');
            $table->string('secret_key', 128)->comment('Binance API secret');
            $table->string('name', 128)->unique('uq_binance_accounts_name')->comment('Account display name');
            $table->decimal('balance', 20, 8)->nullable()->comment('Current balance');
            $table->decimal('unrealized_pnl', 20, 8)->nullable()->comment('totalUnrealizedProfit from Binance /fapi/v3/account');
            $table->decimal('initial_deposit', 20, 8)->nullable()->comment('Initial deposit amount');
            $table->string('currency_type', 16)->default('USDT')->comment('Account currency (USDT, BUSD, etc.)');
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('deleted_at')->nullable()->index('idx_deleted_at')->comment('Soft delete; NULL = active');
            $table->boolean('demo')->default(false)->comment('1=demo/test, 0=live');
            $table->boolean('enabled')->default(true)->comment('1=enabled, 0=disabled');

            $table->foreign('uni_id', 'fk_binance_accounts_uni_id')
                ->references('uni_id')->on('user_credentials')
                ->cascadeOnDelete()->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('binance_accounts');
    }
};
