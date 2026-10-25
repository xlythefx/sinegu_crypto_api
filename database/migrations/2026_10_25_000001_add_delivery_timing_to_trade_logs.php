<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * trade_logs delivery timing — the proof that a signal TradingView marked as
 * "failed / timed out" still arrived and traded (24 Oct 2026, 14:15 UTC: the
 * alert log said timed out, the engine had filled five accounts).
 *
 * - sent_at     when TradingView fired the alert: its `{{timenow}}`, sent as
 *               the payload's `sent_at`. NULL for an alert without the field,
 *               a retry, a manual trade, and every row before this column.
 * - received_at when the engine's webhook handler got the request.
 * - transit_ms  received_at − sent_at in milliseconds, computed on ingest from
 *               the engine's millisecond values (the datetime columns keep
 *               whole seconds). Signed: TradingView's clock is not ours.
 * - ack_ms      how long the engine took to answer TradingView.
 *
 * App\Services\Admin\SignalDelivery reads them; every column is nullable
 * because an engine deployed before this one sends none of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trade_logs', function (Blueprint $table) {
            $table->dateTime('sent_at')->nullable()->after('ts')
                ->comment("TradingView's {{timenow}} — when the alert fired (UTC)");
            $table->dateTime('received_at')->nullable()->after('sent_at')
                ->comment('When the engine received the webhook (UTC)');
            $table->integer('transit_ms')->nullable()->after('received_at')
                ->comment('received_at - sent_at, milliseconds (signed)');
            $table->decimal('ack_ms', 10, 2)->nullable()->after('transit_ms')
                ->comment('How long the engine took to answer the webhook, milliseconds');
        });
    }

    public function down(): void
    {
        Schema::table('trade_logs', function (Blueprint $table) {
            $table->dropColumn(['sent_at', 'received_at', 'transit_ms', 'ack_ms']);
        });
    }
};
