<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * mexc_transactions — transfers in and out of the MEXC futures wallet
 * (GET /api/v1/private/account/transfer_record, state SUCCESS). Same shape as
 * binance_transactions; `tran_id` is MEXC's record id, `info` its txid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mexc_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('api_key', 128)->index('idx_mexc_transactions_api_key')->comment('MEXC API key (FK mexc_accounts)');
            $table->string('uni_id', 36)->index('idx_mexc_transactions_uni_id')->comment('User ID (FK user_credentials)');
            $table->string('type', 16)->index('idx_mexc_transactions_type')->comment('DEPOSIT or WITHDRAWAL');
            $table->decimal('amount', 20, 8)->comment('Transaction amount (stored as absolute)');
            $table->decimal('balance_after', 20, 8)->nullable();
            $table->bigInteger('tran_id')->nullable()->index('idx_mexc_transactions_tran_id')->comment('MEXC transfer record id');
            $table->string('currency', 16)->default('USDT');
            $table->bigInteger('transaction_time')->nullable()->index('idx_mexc_transactions_transaction_time')->comment('MEXC createTime (ms)');
            $table->string('info', 64)->nullable()->comment('MEXC txid');
            $table->dateTime('created_at')->useCurrent();

            $table->unique(['api_key', 'tran_id'], 'uq_mexc_transactions_api_key_tran_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mexc_transactions');
    }
};
