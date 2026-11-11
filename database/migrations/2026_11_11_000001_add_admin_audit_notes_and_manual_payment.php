<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The admin audit (2026-11-11): "we don't know how it was paid" — an invoice
 * marked paid by hand said only "Marked paid by an admin", with no name, no
 * way the money came and no note; and nothing recorded who approved,
 * suspended, re-priced or deleted a customer.
 *
 *  - admin_audit_logs: one row per admin action on a customer or an invoice
 *    (who, on whom, what, a plain sentence). Append-only; it feeds the
 *    customer's Timeline. Starts today — nothing earlier exists.
 *  - user_notes: free-text notes on a customer ("paid by bank, ref 1234",
 *    "promised to pay Friday"), shown on the Timeline. Full admins only.
 *  - invoices.paid_by / manual_method / payment_note: for a payment marked by
 *    hand — the admin's uni_id, how the money came (bank_transfer |
 *    crypto_wallet | cash | other) and the note they typed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_audit_logs', function (Blueprint $table) {
            $table->id();
            // NULL = the system (a scheduled job), never a missing admin.
            $table->string('actor_uni_id', 64)->nullable();
            $table->string('subject_uni_id', 64)->nullable()->index();
            $table->string('action', 40);
            $table->string('summary', 255);
            $table->json('details')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('user_notes', function (Blueprint $table) {
            $table->id();
            $table->string('uni_id', 64)->index();
            $table->string('author_uni_id', 64)->nullable();
            $table->text('body');
            $table->timestamps();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('paid_by', 64)->nullable()->after('paid_amount');
            $table->string('manual_method', 20)->nullable()->after('paid_by');
            $table->string('payment_note', 500)->nullable()->after('manual_method');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['paid_by', 'manual_method', 'payment_note']);
        });
        Schema::dropIfExists('user_notes');
        Schema::dropIfExists('admin_audit_logs');
    }
};
