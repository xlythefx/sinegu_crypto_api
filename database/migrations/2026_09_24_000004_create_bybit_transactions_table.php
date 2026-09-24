<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * bybit_transactions — capital in and out of the Bybit trading wallet. Same
 * shape as binance_transactions / mexc_transactions.
 *
 * On a Bybit UNIFIED account there is no separate futures wallet to transfer
 * into, so the analogue of Binance's futures transfers is the account's own
 * ledger: GET /v5/account/transaction-log, types TRANSFER_IN / TRANSFER_OUT.
 * `tran_id` is that row's `id` and `info` its `tradeId`/reference where one
 * exists.
 *
 * This figure is not cosmetic: `initial_deposit + (deposits - withdrawals)` is
 * `total_deposit` on GET /engine/bybit/accounts, which is what the engine's
 * deposit gate tests and what BinancePnlSource-style invoicing bills against.
 * An unknown deposit fails the gate closed, so a missing row means an account
 * quietly stops opening positions — never a wrong size.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bybit_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('api_key', 128)->index('idx_bybit_transactions_api_key')->comment('Bybit API key (FK bybit_accounts)');
            $table->string('uni_id', 36)->index('idx_bybit_transactions_uni_id')->comment('User ID (FK user_credentials)');
            $table->string('type', 16)->index('idx_bybit_transactions_type')->comment('DEPOSIT or WITHDRAWAL');
            $table->decimal('amount', 20, 8)->comment('Transaction amount (stored as absolute)');
            $table->decimal('balance_after', 20, 8)->nullable()->comment('cashBalance after the entry');
            $table->bigInteger('tran_id')->nullable()->index('idx_bybit_transactions_tran_id')->comment('Bybit transaction-log row id');
            $table->string('currency', 16)->default('USDT');
            $table->bigInteger('transaction_time')->nullable()->index('idx_bybit_transactions_transaction_time')->comment('Bybit transactionTime (ms)');
            $table->string('info', 64)->nullable()->comment('Bybit tradeId / reference');
            $table->dateTime('created_at')->useCurrent();

            $table->unique(['api_key', 'tran_id'], 'uq_bybit_transactions_api_key_tran_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bybit_transactions');
    }
};
