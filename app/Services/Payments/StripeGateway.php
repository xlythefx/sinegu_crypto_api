<?php

namespace App\Services\Payments;

use App\Models\Invoice;
use App\Models\StripeCustomer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Stripe\Event;
use Stripe\StripeClient;

/**
 * Everything that touches the Stripe SDK. Kept behind one class so tests can
 * bind a double and the controllers never import \Stripe\* directly.
 *
 * Checkout is hosted: we create a session and hand back its URL. No publishable
 * key ever reaches the browser, and the invoice is settled by the webhook, not
 * by the trader's return.
 */
class StripeGateway
{
    public function __construct(private PaymentEnvironment $env) {}

    public function mode(): string
    {
        return $this->env->stripe()['mode'];
    }

    public function isConfigured(): bool
    {
        $s = $this->env->stripe();

        return $s['secret'] !== '' && $s['webhook_secret'] !== '';
    }

    public function webhookSecret(): string
    {
        return $this->env->stripe()['webhook_secret'];
    }

    private function client(): StripeClient
    {
        $secret = $this->env->stripe()['secret'];
        if ($secret === '') {
            throw new RuntimeException('STRIPE_NOT_CONFIGURED');
        }

        return new StripeClient($secret);
    }

    /**
     * Verify and parse a webhook delivery.
     *
     * @throws \Stripe\Exception\SignatureVerificationException
     * @throws \UnexpectedValueException
     */
    public function constructEvent(string $payload, string $signatureHeader): Event
    {
        return \Stripe\Webhook::constructEvent($payload, $signatureHeader, $this->webhookSecret());
    }

    /**
     * The user's Stripe Customer for the current key mode, created on demand.
     *
     * A PaymentMethod used in a Checkout session WITHOUT a Customer is
     * permanently single-use — every later off-session charge fails with
     * "previously used without Customer attachment". Saved cards are a later
     * phase, but the Customer has to exist from the first payment or early
     * payers would have to re-enter their card when it lands.
     */
    public function customerIdFor(string $uniId, ?string $email, ?string $name): ?string
    {
        $mode = $this->mode();
        $row = StripeCustomer::where('uni_id', $uniId)->where('mode', $mode)->first();

        if ($row) {
            try {
                $existing = $this->client()->customers->retrieve($row->stripe_customer_id, []);
                if (! ($existing->deleted ?? false)) {
                    return $row->stripe_customer_id;
                }
            } catch (\Throwable $e) {
                // Stale id (deleted at Stripe, or from another account) — fall
                // through and mint a new one rather than failing the payment.
                Log::info('Stripe customer id was stale; creating a new one.', [
                    'uni_id' => $uniId,
                    'mode' => $mode,
                ]);
            }
        }

        try {
            $customer = $this->client()->customers->create(array_filter([
                'email' => $email,
                'name' => $name,
                'metadata' => ['uni_id' => $uniId],
            ]));
        } catch (\Throwable $e) {
            // A missing Customer only costs us future auto-charge, so never let
            // it block the payment the trader is trying to make right now.
            Log::warning('Stripe customer creation failed; continuing without one.', [
                'uni_id' => $uniId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        StripeCustomer::updateOrCreate(
            ['uni_id' => $uniId, 'mode' => $mode],
            ['stripe_customer_id' => $customer->id],
        );

        return $customer->id;
    }

    /**
     * Create a hosted Checkout session for an invoice.
     *
     * @return array{id: string, url: string}
     *
     * @throws RuntimeException
     */
    public function createCheckoutSession(
        Invoice $invoice,
        string $accountName,
        ?string $customerId
    ): array {
        $cents = $invoice->feeCents();
        $currency = (string) config('payments.stripe.currency', 'usd');
        $invoiceId = (int) $invoice->id;

        $monthLabel = $invoice->month_year;
        try {
            $monthLabel = Carbon::createFromFormat('!Y-m', (string) $invoice->month_year)->format('F Y');
        } catch (\Throwable $e) {
            // Keep the raw YYYY-MM — a label is not worth failing a payment over.
        }

        $params = [
            'mode' => 'payment',
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => $currency,
                    'product_data' => [
                        'name' => 'Invoice Payment — '.$accountName,
                        'description' => 'Billing period: '.$monthLabel,
                    ],
                    'unit_amount' => $cents,
                ],
                'quantity' => 1,
            ]],
            // {CHECKOUT_SESSION_ID} is a Stripe-side template — it must reach
            // Stripe literally, so nothing may url-encode or interpolate it.
            'success_url' => $this->env->successUrl($invoiceId, 'session_id', '{CHECKOUT_SESSION_ID}'),
            'cancel_url' => $this->env->cancelUrl($invoiceId),
            'client_reference_id' => (string) $invoiceId,
            'metadata' => [
                'invoice_id' => (string) $invoiceId,
                'user_id' => (string) $invoice->user_id,
                'account_id' => (string) $invoice->account_id,
                'exchange' => (string) $invoice->exchange,
                'month_year' => (string) $invoice->month_year,
            ],
        ];

        if ($customerId !== null) {
            $params['customer'] = $customerId;
            $params['payment_intent_data'] = ['setup_future_usage' => 'off_session'];
        }

        try {
            $session = $this->client()->checkout->sessions->create($params, [
                // A double-click reuses one session instead of minting two.
                // The Customer is part of the key because it is part of the
                // params: Stripe keeps a key for 24h and REFUSES its reuse with
                // different params, so a Customer recreated after going stale
                // (or one that failed to create) would otherwise block card
                // payment on this invoice for a day.
                'idempotency_key' => "checkout_{$invoiceId}_{$cents}_".($customerId ?? 'none'),
            ]);
        } catch (\Throwable $e) {
            Log::error('Stripe checkout session creation failed.', [
                'invoice_id' => $invoiceId,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('STRIPE_ERROR');
        }

        if (empty($session->id) || empty($session->url)) {
            throw new RuntimeException('STRIPE_ERROR');
        }

        return ['id' => $session->id, 'url' => $session->url];
    }
}
