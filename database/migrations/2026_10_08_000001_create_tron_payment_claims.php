<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two customers may now wait for the same amount at once (2026-10-08).
 *
 * Until now UNIQUE(network, address, open_units) refused the second invoice
 * that wanted a figure already reserved, so a customer could be locked out of
 * paying for up to an hour because a stranger owed the same sum. The index is
 * replaced by a plain one: the watcher already settles only when EXACTLY ONE
 * invoice can own a payment, and a payment two can own is now held and both
 * customers are asked for their transaction ID (`tron_payment_claims`).
 *
 * `tron_transfers.candidate_invoice_ids` records who could own a held payment —
 * the invoices whose pay sheet should ask. It is written once, when the
 * payment arrives, so the question does not change under the customer.
 *
 * `tron_payment_claims` is one row per (transfer, invoice) that pasted the
 * transaction ID. `accepted` settled the invoice; `disputed` means the payment
 * was already on ANOTHER invoice — two people say the same money is theirs,
 * and one of them is wrong. A disputed row is open until an admin resolves it
 * (`resolved_at`), which is what the Admin Overview's caution strip counts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_intents', function (Blueprint $table) {
            $table->dropUnique('uq_payment_intents_open_amount');
            $table->index(['network', 'address', 'open_units'], 'idx_payment_intents_open_amount');
        });

        Schema::table('tron_transfers', function (Blueprint $table) {
            $table->json('candidate_invoice_ids')->nullable()->after('invoice_id')
                ->comment('invoices that could own a HELD payment; their pay sheets ask for the TXID');
        });

        Schema::create('tron_payment_claims', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tron_transfer_id');
            $table->unsignedInteger('invoice_id')->comment('the invoice whose owner pasted the TXID');
            $table->string('user_id', 36)->comment('user_credentials.uni_id of the claimant');
            $table->string('network', 20);
            $table->string('tx_hash', 191);
            $table->string('outcome', 16)->comment('accepted | disputed');
            $table->unsignedInteger('against_invoice_id')->nullable()
                ->comment('disputed: the invoice the payment was already on');
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolved_by', 48)->nullable()->comment('admin:{uni_id}');
            $table->string('resolution_note', 255)->nullable();
            $table->timestamps();

            $table->unique(['tron_transfer_id', 'invoice_id'], 'uq_tron_claims_transfer_invoice');
            $table->index(['outcome', 'resolved_at'], 'idx_tron_claims_open_disputes');
            $table->index('invoice_id', 'idx_tron_claims_invoice');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tron_payment_claims');

        Schema::table('tron_transfers', function (Blueprint $table) {
            $table->dropColumn('candidate_invoice_ids');
        });

        // The unique index cannot come back while two open intents share a
        // figure, so every open reservation is closed first. A customer with the
        // pay sheet open simply gets a fresh one on their next visit.
        DB::table('payment_intents')->where('status', 'open')->update([
            'status' => 'expired',
            'open_units' => null,
            'updated_at' => now(),
        ]);

        Schema::table('payment_intents', function (Blueprint $table) {
            $table->dropIndex('idx_payment_intents_open_amount');
            $table->unique(['network', 'address', 'open_units'], 'uq_payment_intents_open_amount');
        });
    }
};
