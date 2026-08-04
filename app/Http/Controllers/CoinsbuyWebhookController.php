<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\InvoiceService;
use App\Services\Payments\CoinsbuyGateway;
use App\Services\Payments\PaymentEventRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * POST /api/payments/coinsbuy/webhook — settles crypto payments. The HMAC was
 * already checked by VerifyCoinsbuySignature.
 *
 * Same cardinal rule as the Stripe webhook: 200 for everything understood,
 * with an audit row. Coinsbuy retries too.
 *
 * Coinsbuy status vocabulary:
 *   deposit   2 Created  3 Paid  4 Canceled  5 Unresolved
 *   transfer -3 Canceled -2 Blocked -1 Failed  0 Created  1 Unconfirmed  2 Confirmed
 */
class CoinsbuyWebhookController extends Controller
{
    public function __construct(
        private InvoiceService $invoices,
        private PaymentEventRecorder $events,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $payload = (array) $request->attributes->get('coinsbuy_payload', []);
        $transfer = $request->attributes->get('coinsbuy_transfer');

        $deposit = (array) ($payload['data'] ?? []);
        $attributes = (array) ($deposit['attributes'] ?? []);
        $depositId = isset($deposit['id']) ? (string) $deposit['id'] : null;
        $transferId = isset($transfer['id']) ? (string) $transfer['id'] : null;
        $depositStatus = $attributes['status'] ?? null;
        $transferStatus = isset($transfer['attributes']['status'])
            ? (int) $transfer['attributes']['status']
            : null;
        $trackingId = isset($attributes['tracking_id']) ? (string) $attributes['tracking_id'] : null;

        $eventId = PaymentEventRecorder::coinsbuyEventId(
            $depositId, $transferId, $depositStatus, $transferStatus
        );
        if ($this->events->wasProcessed('coinsbuy', $eventId)) {
            return response()->json(['success' => true, 'duplicate' => true]);
        }

        // Fiat when Coinsbuy converted for us (Merchant wallet), crypto otherwise.
        $targetPaid = isset($attributes['target_paid']) && (float) $attributes['target_paid'] > 0
            ? (float) $attributes['target_paid']
            : null;
        $cryptoAmount = isset($transfer['attributes']['amount']) && (float) $transfer['attributes']['amount'] > 0
            ? (float) $transfer['attributes']['amount']
            : null;
        $cryptoCurrency = $this->resolveCurrencyAlpha($payload, $deposit, $transfer);
        $txHash = $transfer['attributes']['txid'] ?? null;

        $base = [
            'provider' => 'coinsbuy',
            'event_id' => $eventId,
            'external_id' => $depositId,
            'transfer_id' => $transferId,
            'tracking_id' => $trackingId,
            'provider_status' => $depositStatus,
            'secondary_status' => $transferStatus,
            'crypto_currency' => $cryptoCurrency,
            'crypto_amount' => $cryptoAmount,
            'tx_hash' => is_string($txHash) ? $txHash : null,
        ];

        // Two independent correlation paths: the row we wrote when the deposit
        // was created, and the tracking id Coinsbuy echoes back.
        $invoiceId = ($depositId !== null ? $this->events->invoiceIdForDeposit($depositId) : null)
            ?? CoinsbuyGateway::parseTrackingId($trackingId);

        if ($invoiceId === null) {
            $this->events->record($base + ['outcome' => 'tracking_unknown']);

            return response()->json([
                'success' => true,
                'message' => 'Callback did not correspond to one of our deposits.',
            ]);
        }

        $invoice = Invoice::find($invoiceId);
        if (! $invoice) {
            Log::error('Coinsbuy webhook: callback for an unknown invoice.', [
                'invoice_id' => $invoiceId, 'deposit_id' => $depositId,
            ]);
            $this->events->record($base + ['invoice_id' => $invoiceId, 'outcome' => 'invoice_not_found']);

            return response()->json(['success' => true, 'received' => true]);
        }

        $base['invoice_id'] = $invoice->id;
        $base['user_id'] = $invoice->user_id;
        $base['account_id'] = $invoice->account_id;
        $base['expected_amount'] = $invoice->feeCents() / 100;

        // A Confirmed transfer counts even on an Unresolved deposit — that
        // combination is what an overpayment looks like, and it is a real
        // payment. Carried over from the mother verbatim.
        $shouldProcess = (int) $depositStatus === 3 || $transferStatus === 2;

        if (! $shouldProcess) {
            $this->events->record($base + ['outcome' => $this->outcomeFor($depositStatus, $transferStatus)]);

            return response()->json(['success' => true, 'received' => true]);
        }

        if ($invoice->isPaid()) {
            $this->events->record($base + ['outcome' => 'already_paid']);

            return response()->json(['success' => true, 'already_paid' => true]);
        }

        $expectedCents = $invoice->feeCents();

        // Only the fiat figure is comparable to the invoice. When Coinsbuy sends
        // only a crypto amount (Enterprise wallet) there is nothing to compare
        // against, so we trust their processing and say so in the audit row.
        if ($targetPaid === null) {
            $settled = $this->invoices->settle($invoice, 'coinsbuy', $depositId);
            $this->events->record($base + [
                'outcome' => $settled ? 'paid' : 'already_paid',
                'amount' => $cryptoAmount,
                'amount_currency' => $cryptoCurrency,
                'message' => 'amount_unverified: callback carried no fiat target_paid.',
            ]);

            return response()->json([
                'success' => true, 'invoice_id' => $invoice->id, 'status' => 'paid',
                'provider' => 'coinsbuy', 'reference' => $depositId,
            ]);
        }

        $paidCents = (int) round($targetPaid * 100);
        $fiat = (string) config('payments.coinsbuy.fiat_currency', 'USD');

        // The tolerance MUST be at least the `inaccuracy` we asked Coinsbuy for
        // when creating the deposit — both come from one config value. The
        // mother requested 1% and then rejected anything over $0.01 off, which
        // silently refused legitimate payments on any invoice above $1: money
        // taken, invoice left unpaid, account left disabled.
        $tolerance = (int) ceil($expectedCents * (float) config('payments.coinsbuy.inaccuracy_pct', 1.0) / 100);

        if ($paidCents < $expectedCents - $tolerance) {
            Log::error('Coinsbuy webhook: underpaid beyond tolerance.', [
                'invoice_id' => $invoice->id, 'deposit_id' => $depositId,
                'expected_cents' => $expectedCents, 'paid_cents' => $paidCents,
                'tolerance_cents' => $tolerance,
            ]);
            $this->events->record($base + [
                'outcome' => 'amount_mismatch',
                'amount' => $targetPaid, 'amount_currency' => $fiat,
                'message' => "Expected {$expectedCents} cents (±{$tolerance}), received {$paidCents}.",
            ]);

            return response()->json(['success' => true, 'received' => true]);
        }

        $settled = $this->invoices->settle($invoice, 'coinsbuy', $depositId, $targetPaid);

        $outcome = ! $settled
            ? 'already_paid'
            : ($paidCents > $expectedCents ? 'overpaid' : 'paid');

        $this->events->record($base + [
            'outcome' => $outcome,
            'amount' => $targetPaid,
            'amount_currency' => $fiat,
        ]);

        return response()->json([
            'success' => true,
            'invoice_id' => $invoice->id,
            'status' => 'paid',
            'provider' => 'coinsbuy',
            'reference' => $depositId,
        ]);
    }

    /** Why a callback we understood was not acted on. */
    private function outcomeFor($depositStatus, ?int $transferStatus): string
    {
        return match (true) {
            (int) $depositStatus === 5 => 'overpaid',
            (int) $depositStatus === 4 => 'canceled',
            $transferStatus === -1 => 'failed',
            $transferStatus === -2 => 'blocked',
            $transferStatus === -3 => 'canceled',
            default => 'not_processed',
        };
    }

    /** Currency objects ride along in `included[]`; resolve the id to its code. */
    private function resolveCurrencyAlpha(array $payload, array $deposit, $transfer): ?string
    {
        $id = $deposit['relationships']['currency']['data']['id']
            ?? $transfer['relationships']['currency']['data']['id']
            ?? null;

        if ($id === null) {
            return null;
        }

        foreach ((array) ($payload['included'] ?? []) as $item) {
            if (($item['type'] ?? null) === 'currency' && (string) ($item['id'] ?? '') === (string) $id) {
                $alpha = $item['attributes']['alpha'] ?? null;

                return is_string($alpha) ? $alpha : null;
            }
        }

        return null;
    }
}
