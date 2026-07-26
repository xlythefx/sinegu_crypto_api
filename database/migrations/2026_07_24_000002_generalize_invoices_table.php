<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generalize the per-broker `binance_invoices` table into a single, exchange-
 * agnostic `invoices` table. Instead of one table per exchange (the mother's
 * billing_periods / binance_invoices / ig_invoices split), every exchange's
 * invoices live in one table discriminated by an `exchange` column — so adding
 * Bybit/MEXC needs no new table, model, controller, or webhook.
 *
 * HWM stays on the invoice row (hwm_before/hwm_after), which the binance_accounts
 * table has no columns for anyway — uniform across all exchanges.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('binance_invoices', 'invoices');

        Schema::table('invoices', function (Blueprint $table) {
            $table->enum('exchange', ['binance', 'bybit', 'mexc'])
                ->default('binance')
                ->after('account_id')
                ->comment('Which exchange this invoice belongs to');

            // Uniqueness is now per (exchange, account, month): account ids are
            // only unique within their own exchange's accounts table.
            $table->dropUnique('uq_binance_invoices_apikey_month');
            $table->unique(['exchange', 'account_id', 'month_year'], 'uq_invoices_exchange_account_month');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique('uq_invoices_exchange_account_month');
            $table->unique(['api_key', 'month_year'], 'uq_binance_invoices_apikey_month');
            $table->dropColumn('exchange');
        });

        Schema::rename('invoices', 'binance_invoices');
    }
};
