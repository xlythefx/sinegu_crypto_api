<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where an invoice's total_fee came from: 'pnl' (the fee math in
 * InvoiceService::computeForAccount) or 'manual' (an admin typed the amount,
 * InvoiceService::generateManual). Every existing row was computed, so the
 * default is 'pnl'. The screens read it to print one "set manually" fee line
 * instead of a realized/unrealized split that would not add up to the total.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('fee_source', 10)->default('pnl')->after('total_fee');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('fee_source');
        });
    }
};
