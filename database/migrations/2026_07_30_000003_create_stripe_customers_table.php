<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One Stripe Customer per (user, mode).
 *
 * A PaymentMethod used in a Checkout session WITHOUT a Customer is permanently
 * single-use — every later off-session charge fails with "previously used
 * without Customer attachment". Saved cards and auto-charge are a later phase,
 * but the Customer has to exist from the very first payment or early payers
 * would have to re-enter their card when that phase lands.
 *
 * Keyed by (uni_id, mode), not uni_id alone: a test-mode cus_… is invalid
 * against live keys, and this deployment runs test keys first and flips to live
 * once TLS exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stripe_customers', function (Blueprint $table) {
            $table->id();
            $table->string('uni_id', 36)->index();
            $table->string('mode', 8)->comment('live | test');
            $table->string('stripe_customer_id', 64);
            $table->timestamps();

            $table->unique(['uni_id', 'mode'], 'uq_stripe_customers_user_mode');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_customers');
    }
};
