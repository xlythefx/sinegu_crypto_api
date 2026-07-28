<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * referrer_payouts — one envelope per admin release action (the payout ledger).
 *
 * Deliberately NO foreign key on referrer_uni_id: this is audit history and must
 * survive user deletion, exactly like the items table survives invoice
 * regeneration. status defaults to 'paid' — an envelope existing means money
 * went out; pending/rejected exist for enum parity with the mother system but
 * the release flow never writes them (spec §6.5). total_amount is the 2-dp audit
 * figure; per-line truth lives in referrer_payout_items at 8 dp.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referrer_payouts', function (Blueprint $table) {
            $table->id();
            $table->string('referrer_uni_id', 36)->index('idx_referrer_payouts_referrer');
            $table->char('month_year', 7)->nullable()->comment('Representative period YYYY-MM; NULL when the envelope spans months');
            $table->decimal('total_amount', 12, 2)->comment('USD released (audit figure)');
            $table->string('payout_address', 255)->comment('Crypto address or bank label');
            $table->string('tx_hash', 255)->nullable()->comment('Blockchain proof (crypto)');
            $table->dateTime('paid_at')->comment('When admin confirmed the release');
            $table->enum('status', ['pending', 'paid', 'rejected'])->default('paid');
            $table->enum('payment_method', ['crypto', 'bank_wire'])->default('crypto');
            $table->string('proof_file', 500)->nullable()->comment('Private-disk path of uploaded proof (bank wire)');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referrer_payouts');
    }
};
