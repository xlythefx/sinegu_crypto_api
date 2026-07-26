<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * binance_invoices — monthly billing periods for Binance broker accounts.
 * Ported from sinegu_db; account_id can now hold binance_accounts.id
 * (this DB's accounts table has an integer PK, unlike the mother's).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('binance_invoices', function (Blueprint $table) {
            $table->increments('id');
            $table->string('user_id', 36)->comment('FK user_credentials.uni_id');
            $table->integer('account_id')->nullable()->comment('binance_accounts.id');
            $table->string('api_key', 128)->comment('FK binance_accounts.api_key');
            $table->string('month_year', 7)->comment('Billing month in YYYY-MM format');
            $table->decimal('equity_start', 20, 8)->nullable()->default(0)->comment('HWM at start of period');
            $table->decimal('equity_end', 20, 8)->nullable()->default(0)->comment('Equity at end of period (balance + unrealized)');
            $table->decimal('realized_pnl', 20, 8)->nullable()->default(0)->comment('Realized PnL in billing month');
            $table->decimal('unrealized_pnl', 20, 8)->nullable()->default(0)->comment('Unrealized PnL snapshot at billing time');
            $table->decimal('deposit_amount', 20, 8)->nullable()->default(0)->comment('Adjusted deposit (initial + net transfers)');
            $table->decimal('adjusted_equity', 20, 8)->nullable()->default(0)->comment('Equity after adjustments');
            $table->decimal('capital_flow', 20, 8)->nullable()->default(0)->comment('Net deposits/withdrawals in billing month');
            $table->decimal('performance_equity', 20, 8)->nullable()->default(0)->comment('Previous equity + billing month trading profit');
            $table->decimal('hwm_before', 20, 8)->nullable()->default(0);
            $table->decimal('hwm_after', 20, 8)->nullable()->default(0);
            $table->decimal('new_realized_profit', 20, 8)->nullable()->default(0);
            $table->decimal('new_unrealized_profit', 20, 8)->nullable()->default(0);
            $table->decimal('fee_realized', 20, 8)->nullable()->default(0);
            $table->decimal('fee_unrealized', 20, 8)->nullable()->default(0);
            $table->decimal('total_fee', 20, 8)->nullable()->default(0);
            $table->enum('status', ['pending', 'paid', 'failed', 'overdue'])->default('pending')->index('idx_binance_invoices_status');
            $table->date('due_date')->nullable()->comment('Payment due date (1st of following month + 7 days)');
            $table->unsignedTinyInteger('reminder_count')->default(0);
            $table->dateTime('last_reminder_sent_at')->nullable();
            $table->dateTime('invoice_sent_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable()->useCurrentOnUpdate();

            $table->unique(['api_key', 'month_year'], 'uq_binance_invoices_apikey_month');
            $table->index(['user_id', 'month_year'], 'idx_binance_invoices_user_month');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('binance_invoices');
    }
};
