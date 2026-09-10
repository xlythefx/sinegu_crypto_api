<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A reservation of one exact on-chain amount for one invoice.
 *
 * Direct USDT-TRC20 payments arrive at ONE shared receiving address with no
 * memo field and, for anyone paying from an exchange withdrawal, a `from`
 * address belonging to the exchange rather than the customer. The amount is
 * therefore the only payer-controlled signal that survives the trip, and this
 * table is what makes it unambiguous: while an intent is open it holds the
 * figure in `open_units`, and UNIQUE(network, address, open_units) makes it
 * impossible for a second open intent to claim the same one. Closing an intent
 * sets that column NULL, and NULLs are distinct in a UNIQUE index on BOTH
 * MySQL and SQLite (the test database) — which is the whole reason this works
 * without the partial index MySQL does not have.
 *
 * Three deliberate choices worth keeping:
 *
 *  - `address`, `contract_address` and `decimals` are SNAPSHOTS. Rotating the
 *    receiving address, or the day a contract is re-pointed, must not orphan
 *    the intents already in flight — each one settles against the values it
 *    was issued with. `decimals` in particular is never read back from the
 *    chain: a malicious token reporting `decimals: 0` would make one base unit
 *    look like a whole dollar.
 *  - `expected_units` SURVIVES the close, unlike `open_units`. That is what
 *    lets an expired intent still be found by amount, so a payment that
 *    arrives an hour late can be suggested to an admin instead of being lost.
 *  - No foreign key on `invoice_id`, same rationale as `payment_events`: a
 *    record of money changing hands must outlive the row it refers to.
 *
 * `network` carries the environment on the DATA rather than in the process.
 * PaymentEnvironment::forceSandbox() is request-scoped credential pinning and
 * cannot reach the watcher, which is a scheduled command with no request and
 * no user — so the network an intent belongs to has to travel with the intent.
 * Same pattern as `binance_accounts.is_sandbox`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_intents', function (Blueprint $table) {
            $table->id();

            $table->string('provider', 20)->default('tron');
            $table->string('network', 20)->comment('mainnet | nile — the environment, carried on the row');

            $table->unsignedInteger('invoice_id');
            $table->string('user_id', 36)->comment('user_credentials.uni_id');
            $table->unsignedInteger('account_id')->nullable();

            // Snapshots — see the class docblock.
            $table->string('address', 64)->comment('receiving address AT ISSUE; rotation must not orphan this row');
            $table->string('contract_address', 64);
            $table->string('asset', 16)->default('USDT');
            $table->unsignedTinyInteger('decimals')->default(6)->comment('never read from the chain');

            // Base units (6 dp for USDT), never a float. See TronUnits.
            $table->unsignedBigInteger('expected_units')->comment('survives the close, so a late payment is still findable');
            $table->decimal('expected_usd', 20, 8);
            $table->unsignedBigInteger('shortfall_units')->default(0)->comment('accepted underpayment — exchanges deduct their fee from the amount sent');
            $table->unsignedBigInteger('overpay_units')->default(0);

            // = expected_units while open, NULL once closed. Purely the
            // concurrency reservation; the unique index below is the guarantee.
            $table->unsignedBigInteger('open_units')->nullable();

            $table->string('status', 16)->default('open')
                ->comment('open | settled | expired | cancelled | superseded');
            $table->timestamp('expires_at')->nullable();

            $table->string('tx_hash', 191)->nullable();
            $table->unsignedBigInteger('received_units')->nullable();
            $table->timestamp('settled_at')->nullable();

            $table->timestamps();

            $table->unique(['network', 'address', 'open_units'], 'uq_payment_intents_open_amount');
            $table->index(['invoice_id', 'status'], 'idx_payment_intents_invoice_status');
            $table->index(['network', 'status', 'expires_at'], 'idx_payment_intents_network_status');
            $table->index('tx_hash', 'idx_payment_intents_tx_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_intents');
    }
};
