<?php

namespace App\Services\Payments;

use App\Models\PaymentEvent;
use Illuminate\Support\Facades\Log;

/**
 * Writes the append-only payment_events audit trail.
 *
 * Two jobs: never silently drop a webhook we could not act on, and give both
 * webhooks event-level idempotency on top of the per-invoice idempotency
 * InvoiceService::settle already has. UNIQUE(provider, event_id) does the real
 * work — wasProcessed() is the fast path, the index is the guarantee under
 * concurrent deliveries.
 */
class PaymentEventRecorder
{
    /** Coinsbuy sends no event id, so identify a delivery by what it carries. */
    public static function coinsbuyEventId(
        ?string $depositId,
        ?string $transferId,
        $depositStatus,
        $transferStatus
    ): string {
        return hash('sha256', implode('|', [
            $depositId ?? '', $transferId ?? '',
            $depositStatus === null ? '' : (string) $depositStatus,
            $transferStatus === null ? '' : (string) $transferStatus,
        ]));
    }

    public function wasProcessed(string $provider, string $eventId): bool
    {
        return PaymentEvent::where('provider', $provider)
            ->where('event_id', $eventId)
            ->exists();
    }

    /**
     * Append one row. Deliberately swallows its own failure: a duplicate-key
     * race or an audit problem must never turn into a non-2xx that makes the
     * provider retry a delivery we already handled.
     *
     * @return bool  false when the row was not written (duplicate or error)
     */
    public function record(array $attributes): bool
    {
        try {
            if (isset($attributes['payload']) && is_string($attributes['payload'])) {
                $attributes['payload'] = mb_substr($attributes['payload'], 0, 16000);
            }
            if (isset($attributes['message'])) {
                $attributes['message'] = mb_substr((string) $attributes['message'], 0, 512);
            }
            $attributes['created_at'] ??= now();

            PaymentEvent::create($attributes);

            return true;
        } catch (\Throwable $e) {
            Log::warning('payment_events insert failed (webhook flow unaffected).', [
                'provider' => $attributes['provider'] ?? null,
                'event_id' => $attributes['event_id'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * The invoice a Coinsbuy deposit belongs to, from the row written when the
     * deposit was created. A second correlation path so a mangled or missing
     * tracking_id cannot orphan a payment.
     */
    public function invoiceIdForDeposit(string $depositId): ?int
    {
        $id = PaymentEvent::where('provider', 'coinsbuy')
            ->where('external_id', $depositId)
            ->whereNotNull('invoice_id')
            ->orderBy('id')
            ->value('invoice_id');

        return $id !== null ? (int) $id : null;
    }
}
