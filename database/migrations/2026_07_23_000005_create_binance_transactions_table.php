<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * binance_transactions — Futures wallet deposits/withdrawals.
 * Ported 1:1 from sinegu_db.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('binance_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('api_key', 128)->index('idx_binance_transactions_api_key')->comment('Binance API key (FK binance_accounts)');
            $table->string('uni_id', 36)->index('idx_binance_transactions_uni_id')->comment('User ID (FK user_credentials)');
            $table->string('type', 16)->index('idx_binance_transactions_type')->comment('DEPOSIT or WITHDRAWAL');
            $table->decimal('amount', 20, 8)->comment('Transaction amount (stored as absolute)');
            $table->decimal('balance_after', 20, 8)->nullable();
            $table->bigInteger('tran_id')->nullable()->index('idx_binance_transactions_tran_id')->comment('Binance tranId');
            $table->string('currency', 16)->default('USDT');
            $table->bigInteger('transaction_time')->nullable()->index('idx_binance_transactions_transaction_time')->comment('Binance time (ms)');
            $table->string('info', 64)->nullable()->comment('e.g. TRANSFER');
            $table->dateTime('created_at')->useCurrent();

            $table->unique(['api_key', 'tran_id'], 'uq_binance_transactions_api_key_tran_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('binance_transactions');
    }
};
