<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit of everything a payment provider ever told us — one row per
 * webhook delivery (plus one when we create a Coinsbuy deposit).
 *
 * Provider-discriminated rather than one table per provider, the same collapse
 * this project already made when binance_invoices/ig_invoices became `invoices`.
 *
 * UNIQUE(provider, event_id) is the replay defence: a duplicate delivery loses
 * the insert race, so idempotency holds under concurrency and not merely under
 * sequential retries. There is deliberately no FK on invoice_id — the audit
 * trail has to survive an invoice being deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20)->index()->comment('stripe | coinsbuy');
            // Stripe: evt_… ; Coinsbuy has no event id, so we hash the
            // (deposit, transfer, statuses) tuple that identifies a delivery.
            $table->string('event_id', 191);
            $table->string('external_id', 191)->nullable()->index()
                ->comment('Stripe session id / Coinsbuy deposit id');
            $table->string('transfer_id', 191)->nullable();
            $table->string('tracking_id', 191)->nullable()->index();
            $table->unsignedInteger('invoice_id')->nullable()->index();
            $table->string('user_id', 36)->nullable()->index();
            $table->unsignedInteger('account_id')->nullable();
            // created | paid | already_paid | overpaid | amount_mismatch |
            // invoice_not_found | tracking_unknown | canceled | failed |
            // blocked | not_processed | ignored | error
            $table->string('outcome', 32)->index();
            $table->string('provider_status', 32)->nullable()
                ->comment('Stripe payment_status / Coinsbuy deposit status');
            $table->string('secondary_status', 32)->nullable()
                ->comment('Coinsbuy transfer status');
            $table->decimal('amount', 20, 8)->nullable();
            $table->string('amount_currency', 10)->nullable();
            $table->decimal('expected_amount', 20, 8)->nullable();
            $table->string('crypto_currency', 16)->nullable();
            $table->decimal('crypto_amount', 32, 16)->nullable();
            $table->string('tx_hash', 191)->nullable();
            $table->string('message', 512)->nullable();
            $table->text('payload')->nullable()->comment('Raw provider body, truncated');
            // Append-only: created_at only, no updated_at.
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['provider', 'event_id'], 'uq_payment_events_provider_event');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');
    }
};
