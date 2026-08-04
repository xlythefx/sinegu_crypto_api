<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\InvoiceService;
use App\Services\Payments\PaymentEventRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * POST /api/payments/stripe/webhook — the only thing that marks a card payment
 * settled. The signature was already verified by VerifyStripeSignature.
 *
 * CARDINAL RULE: answer 200 to everything we understand, even when we choose
 * not to act. Stripe retries non-2xx for three days and then disables the
 * endpoint, so a permanent condition (unknown invoice, wrong amount) must never
 * be reported as an error — it gets a 200, an audit row and an error-level log.
 * Only a bad signature or an unconfigured secret is a non-2xx, and both of
 * those come from the middleware.
 */
class StripeWebhookController extends Controller
{
    public function __construct(
        private InvoiceService $invoices,
        private PaymentEventRecorder $events,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        /** @var \Stripe\Event $event */
        $event = $request->attributes->get('stripe_event');
        $eventId = (string) $event->id;

        if ($this->events->wasProcessed('stripe', $eventId)) {
            return response()->json(['success' => true, 'duplicate' => true]);
        }

        if ($event->type !== 'checkout.session.completed') {
            $this->events->record([
                'provider' => 'stripe', 'event_id' => $eventId,
                'outcome' => 'ignored', 'message' => $event->type,
            ]);

            return response()->json(['success' => true, 'received' => true, 'event_type' => $event->type]);
        }

        $session = $event->data->object;
        $sessionId = (string) ($session->id ?? '');
        $paymentStatus = (string) ($session->payment_status ?? '');

        if (! in_array($paymentStatus, ['paid', 'no_payment_required'], true)) {
            $this->events->record([
                'provider' => 'stripe', 'event_id' => $eventId, 'external_id' => $sessionId,
                'outcome' => 'not_processed', 'provider_status' => $paymentStatus,
            ]);

            return response()->json(['success' => true, 'received' => true]);
        }

        // `stripe trigger checkout.session.completed` sends a session with no
        // metadata at all. Acknowledge it and do nothing.
        // metadata is a \Stripe\StripeObject, not an array — read it through
        // ArrayAccess so a cast never turns it into the object's internals.
        $invoiceId = $session->metadata['invoice_id'] ?? $session->client_reference_id ?? null;
        if ($invoiceId === null || $invoiceId === '') {
            $this->events->record([
                'provider' => 'stripe', 'event_id' => $eventId, 'external_id' => $sessionId,
                'outcome' => 'tracking_unknown', 'provider_status' => $paymentStatus,
                'message' => 'Session carried no invoice reference (test event?).',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Test webhook (no invoice reference).',
            ]);
        }

        $invoice = Invoice::find((int) $invoiceId);
        if (! $invoice) {
            Log::error('Stripe webhook: paid session for an unknown invoice.', [
                'invoice_id' => $invoiceId, 'session_id' => $sessionId,
            ]);
            $this->events->record([
                'provider' => 'stripe', 'event_id' => $eventId, 'external_id' => $sessionId,
                'invoice_id' => (int) $invoiceId, 'outcome' => 'invoice_not_found',
                'provider_status' => $paymentStatus,
            ]);

            return response()->json(['success' => true, 'received' => true]);
        }

        if ($invoice->isPaid()) {
            $this->events->record([
                'provider' => 'stripe', 'event_id' => $eventId, 'external_id' => $sessionId,
                'invoice_id' => $invoice->id, 'user_id' => $invoice->user_id,
                'account_id' => $invoice->account_id, 'outcome' => 'already_paid',
                'provider_status' => $paymentStatus,
            ]);

            return response()->json(['success' => true, 'already_paid' => true]);
        }

        // Re-verify at the gateway boundary: the amount now comes from outside.
        $expectedCents = $invoice->feeCents();
        $paidCents = (int) ($session->amount_total ?? 0);
        $currency = strtolower((string) ($session->currency ?? ''));
        $expectedCurrency = strtolower((string) config('payments.stripe.currency', 'usd'));

        if ($paidCents !== $expectedCents || $currency !== $expectedCurrency) {
            Log::error('Stripe webhook: amount or currency did not match the invoice.', [
                'invoice_id' => $invoice->id, 'session_id' => $sessionId,
                'expected_cents' => $expectedCents, 'paid_cents' => $paidCents,
                'expected_currency' => $expectedCurrency, 'currency' => $currency,
            ]);
            $this->events->record([
                'provider' => 'stripe', 'event_id' => $eventId, 'external_id' => $sessionId,
                'invoice_id' => $invoice->id, 'user_id' => $invoice->user_id,
                'account_id' => $invoice->account_id, 'outcome' => 'amount_mismatch',
                'provider_status' => $paymentStatus,
                'amount' => $paidCents / 100, 'amount_currency' => strtoupper($currency),
                'expected_amount' => $expectedCents / 100,
                'message' => "Expected {$expectedCents} {$expectedCurrency}, received {$paidCents} {$currency}.",
            ]);

            return response()->json(['success' => true, 'received' => true]);
        }

        $settled = $this->invoices->settle($invoice, 'stripe', $sessionId, $paidCents / 100);

        $this->events->record([
            'provider' => 'stripe', 'event_id' => $eventId, 'external_id' => $sessionId,
            'invoice_id' => $invoice->id, 'user_id' => $invoice->user_id,
            'account_id' => $invoice->account_id,
            'outcome' => $settled ? 'paid' : 'already_paid',
            'provider_status' => $paymentStatus,
            'amount' => $paidCents / 100, 'amount_currency' => strtoupper($currency),
            'expected_amount' => $expectedCents / 100,
        ]);

        return response()->json([
            'success' => true,
            'invoice_id' => $invoice->id,
            'status' => 'paid',
            'provider' => 'stripe',
            'reference' => $sessionId,
        ]);
    }
}
