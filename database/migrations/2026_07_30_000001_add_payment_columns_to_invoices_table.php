<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment provenance on the invoice row.
 *
 * Until now `status = 'paid'` was the whole record of a payment: the paid date
 * was inferred from `updated_at` (so editing a paid invoice silently rewrote it)
 * and nothing recorded which provider took the money or under what reference.
 * `InvoiceService::settle()` fills these in for every path — manual, Stripe and
 * Coinsbuy alike.
 *
 * `payment_provider` is a varchar, not an enum: adding a provider later must not
 * need an ALTER ... MODIFY ENUM (which is also the one schema operation that
 * behaves differently between MySQL and the sqlite test database).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('paid_at')->nullable()->after('status');
            $table->string('payment_provider', 20)->nullable()->after('paid_at')
                ->comment('manual | stripe | coinsbuy');
            $table->string('payment_reference', 191)->nullable()->after('payment_provider')
                ->comment('Stripe checkout session id / Coinsbuy deposit id');
            $table->decimal('paid_amount', 20, 8)->nullable()->after('payment_reference');

            $table->index('payment_reference', 'idx_invoices_payment_reference');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('idx_invoices_payment_reference');
            $table->dropColumn(['paid_at', 'payment_provider', 'payment_reference', 'paid_amount']);
        });
    }
};
