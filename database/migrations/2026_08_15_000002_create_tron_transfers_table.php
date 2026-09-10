<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Our mirror of every TRC-20 transfer that has arrived at a receiving address.
 *
 * IMPORTANT — WE STORE EVERY TRANSFER, INCLUDING THE ONES WE REJECT. The scan
 * cursor is MAX(block_timestamp) over this table, so anyone who later adds an
 * early `continue` before the insert ("why record junk?") silently freezes the
 * cursor and stops the watcher seeing anything new. The junk is the cursor.
 *
 * Three jobs, which is why it is its own table rather than more columns on
 * `payment_events`:
 *   1. the data source for Admin → Crypto Transfers,
 *   2. the scan cursor,
 *   3. the dedupe, via UNIQUE(network, event_key).
 * `payment_events` cannot do any of them: it is append-only (created_at, no
 * updated_at) and its writer swallows every throwable by design, so it can
 * neither hold mutable state (unmatched → settled) nor be the authoritative
 * record that a transfer was consumed. It stays what it is — the settlement
 * audit, keyed to invoices.
 *
 * `event_key` is a sha256 of (tx_hash|from|to|contract|value|block_timestamp),
 * NOT the bare transaction id: ONE TRON TRANSACTION CAN CARRY SEVERAL TRC-20
 * TRANSFERS, TronGrid returns one row per transfer, and the endpoint exposes no
 * log index. Keying on tx_hash alone would silently swallow the second transfer
 * — money received, never recorded. Same shape as
 * PaymentEventRecorder::coinsbuyEventId(), which hashes an identifying tuple
 * for a provider that sends no event id.
 *
 * `value_raw` is the chain's own decimal string of integer base units, stored
 * verbatim as the lossless record. `value_units` is populated only when that
 * string fits in a signed 64-bit integer; a hostile token can emit a 256-bit
 * value that would overflow PHP's int and degrade to a float, so out-of-range
 * values are recorded and then never arithmetic'd (reject_reason
 * 'value_out_of_range').
 *
 * `token_symbol` and `token_decimals` are stored AS REPORTED BY THE CHAIN and
 * are never used for a decision — anyone can deploy a token called "USDT" with
 * whatever decimals they like. Only `contract_address`, compared to the
 * configured one, decides whether a transfer is money.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tron_transfers', function (Blueprint $table) {
            $table->id();

            $table->string('network', 20);
            $table->string('event_key', 64)->comment('sha256 of the identifying tuple — NOT the bare txid');

            $table->string('tx_hash', 191)->comment('one transaction may produce several rows');
            $table->string('contract_address', 64)->comment('the ONLY trusted identity of the token');
            $table->string('token_symbol', 32)->nullable()->comment('as reported; never used for a decision');
            $table->unsignedTinyInteger('token_decimals')->nullable()->comment('as reported; never used for a decision');

            $table->string('from_address', 64);
            $table->string('to_address', 64);

            $table->string('value_raw', 80)->comment('verbatim base-10 base units from the chain');
            $table->unsignedBigInteger('value_units')->nullable()->comment('NULL ⇒ out of int64 range ⇒ never arithmetic\'d');

            $table->unsignedBigInteger('block_timestamp')->comment('ms since epoch — THE SCAN CURSOR');
            $table->boolean('confirmed')->default(true);

            $table->string('status', 16)->default('unmatched')
                ->comment('unmatched | settled | ignored | rejected');
            $table->string('reject_reason', 32)->nullable()
                ->comment('wrong_contract | wrong_recipient | not_transfer | self_transfer | value_out_of_range | dust');

            $table->unsignedBigInteger('intent_id')->nullable();
            $table->unsignedInteger('invoice_id')->nullable();
            $table->string('settled_by', 48)->nullable()->comment('watcher | admin:{uni_id}');
            $table->timestamp('settled_at')->nullable();
            $table->string('note', 255)->nullable()->comment("an admin's reason when ignored");
            $table->text('payload')->nullable()->comment('raw item, truncated');

            $table->timestamps();

            $table->unique(['network', 'event_key'], 'uq_tron_transfers_network_event');
            $table->index(['network', 'to_address', 'block_timestamp'], 'idx_tron_transfers_scan');
            $table->index(['network', 'status', 'block_timestamp'], 'idx_tron_transfers_status');
            $table->index('tx_hash', 'idx_tron_transfers_tx_hash');
            $table->index('invoice_id', 'idx_tron_transfers_invoice');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tron_transfers');
    }
};
