<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * referrer_payout_items — the released commission lines inside an envelope.
 * THIS TABLE IS THE RELEASE-STATE MECHANISM.
 *
 * Line identity is (referrer, referred user, exchange, month) — deliberately NOT
 * an invoice id (spec §6.10): invoice tables get cleared and regenerated during
 * resets, and payout history must survive that without enabling double-pays.
 * The uq_payout_items_line unique key is the guard; preserve it under any future
 * schema change. commission is FROZEN here at release time — later invoice edits
 * or percentage changes never alter released history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referrer_payout_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payout_id')->constrained('referrer_payouts')->cascadeOnDelete();
            $table->string('referrer_uni_id', 36);
            $table->string('referred_user_uni_id', 36);
            $table->enum('exchange', ['binance', 'bybit', 'mexc']);
            $table->char('month_year', 7)->comment('YYYY-MM — lexical order == chronological order');
            $table->decimal('fee_paid', 20, 8)->comment('The invoice total_fee behind this line');
            $table->decimal('commission', 20, 8)->comment('fee_paid × pct/100, frozen at release');
            $table->dateTime('released_at');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(
                ['referrer_uni_id', 'referred_user_uni_id', 'exchange', 'month_year'],
                'uq_payout_items_line'
            );
            $table->index('referred_user_uni_id', 'idx_payout_items_referred');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referrer_payout_items');
    }
};
