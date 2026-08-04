<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Services\Payments\PaymentEnvironment;
use Illuminate\Support\Facades\DB;

/**
 * Shared plumbing for the payment tests: invoice seeding, a trader token, an
 * environment forced into a known state, and REAL signature builders for both
 * providers (the verifiers run their actual crypto — nothing is mocked).
 */
abstract class PaymentTestCase extends EngineTestCase
{
    protected string $stripeWebhookSecret = 'whsec_test_secret';

    protected string $coinsbuyWebhookSecret = 'coinsbuy-test-secret';

    protected function setUp(): void
    {
        parent::setUp();
        $this->usePayments(PaymentEnvironment::SANDBOX);
    }

    /**
     * Pin the environment and give both providers usable fake credentials.
     * Re-resolves the singleton so a later call takes effect mid-test.
     */
    protected function usePayments(string $environment, array $overrides = []): void
    {
        config(array_merge([
            'payments.force' => $environment,
            'payments.stripe.test.secret' => 'sk_test_fake',
            'payments.stripe.test.webhook_secret' => $this->stripeWebhookSecret,
            'payments.stripe.live.secret' => 'sk_live_fake',
            'payments.stripe.live.webhook_secret' => $this->stripeWebhookSecret,
            'payments.coinsbuy.sandbox.client_id' => 'cb-client',
            'payments.coinsbuy.sandbox.client_secret' => 'cb-secret',
            'payments.coinsbuy.sandbox.webhook_secret' => $this->coinsbuyWebhookSecret,
            'payments.coinsbuy.sandbox.base_url' => 'https://v3.api-sandbox.coinsbuy.test',
            'payments.coinsbuy.sandbox.wallet_id' => '873',
            'payments.coinsbuy.production.client_id' => 'cb-live-client',
            'payments.coinsbuy.production.client_secret' => 'cb-live-secret',
            'payments.coinsbuy.production.webhook_secret' => $this->coinsbuyWebhookSecret,
            'payments.coinsbuy.production.base_url' => 'https://v3.api.coinsbuy.test',
        ], $overrides));

        $this->app->forgetInstance(PaymentEnvironment::class);
        $this->app->singleton(PaymentEnvironment::class, fn () => new PaymentEnvironment);
    }

    /** Insert an invoice row; returns the model. */
    protected function makeInvoice(int $accountId, string $userId, array $overrides = []): Invoice
    {
        $apiKey = DB::table('binance_accounts')->find($accountId)->api_key;

        return Invoice::create(array_merge([
            'user_id' => $userId,
            'account_id' => $accountId,
            'exchange' => 'binance',
            'api_key' => $apiKey,
            'month_year' => '2026-06',
            'total_fee' => 12.34,
            'status' => 'pending',
            'due_date' => now()->addDays(7)->toDateString(),
        ], $overrides));
    }

    /** Sanctum bearer header for a seeded user. */
    protected function userHeaders(string $uniId): array
    {
        $user = \App\Models\UserCredential::where('uni_id', $uniId)->firstOrFail();

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    // ---- provider signatures --------------------------------------------

    /** A real Stripe-Signature header, so Webhook::constructEvent accepts it. */
    protected function stripeSignature(string $payload, ?string $secret = null, ?int $timestamp = null): string
    {
        $timestamp ??= time();
        $secret ??= $this->stripeWebhookSecret;
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        return "t={$timestamp},v1={$signature}";
    }

    /**
     * A Stripe checkout.session.completed body.
     *
     * @param  array  $session  overrides merged into the session object
     */
    protected function stripeEvent(array $session = [], string $type = 'checkout.session.completed', ?string $eventId = null): array
    {
        return [
            'id' => $eventId ?? 'evt_test_'.bin2hex(random_bytes(6)),
            'object' => 'event',
            'type' => $type,
            'data' => ['object' => array_merge([
                'id' => 'cs_test_'.bin2hex(random_bytes(6)),
                'object' => 'checkout.session',
                'payment_status' => 'paid',
                'amount_total' => 1234,
                'currency' => 'usd',
                'client_reference_id' => null,
                'metadata' => [],
            ], $session)],
        ];
    }

    /** A Coinsbuy callback body, signed the way Coinsbuy signs it. */
    protected function coinsbuyPayload(array $options = []): array
    {
        $depositStatus = $options['deposit_status'] ?? 3;
        $transferStatus = $options['transfer_status'] ?? 2;
        $trackingId = $options['tracking_id'] ?? 'inv_1_1785312000';
        $time = (string) ($options['time'] ?? '1785312345');

        $depositAttributes = [
            'status' => $depositStatus,
            'tracking_id' => $trackingId,
        ];
        if (array_key_exists('target_paid', $options)) {
            if ($options['target_paid'] !== null) {
                $depositAttributes['target_paid'] = $options['target_paid'];
            }
        } else {
            $depositAttributes['target_paid'] = '12.34';
        }

        $payload = [
            'data' => [
                'id' => $options['deposit_id'] ?? '9001',
                'type' => 'deposit',
                'attributes' => $depositAttributes,
                'relationships' => ['currency' => ['data' => ['type' => 'currency', 'id' => '10']]],
            ],
            'included' => [
                [
                    'id' => $options['transfer_id'] ?? '5001',
                    'type' => 'transfer',
                    'attributes' => [
                        'status' => $transferStatus,
                        // Coinsbuy sends amounts as strings; the HMAC is over the
                        // characters, so this must not become a float anywhere.
                        'amount' => $options['transfer_amount'] ?? '0.00042100',
                        'txid' => $options['txid'] ?? 'abc123',
                    ],
                    'relationships' => ['currency' => ['data' => ['type' => 'currency', 'id' => '10']]],
                ],
                ['id' => '10', 'type' => 'currency', 'attributes' => ['alpha' => 'BTC']],
            ],
            'meta' => ['time' => $time],
        ];

        $message = (string) $transferStatus
            .(string) ($options['transfer_amount'] ?? '0.00042100')
            .$trackingId
            .$time;
        $payload['meta']['sign'] = hash_hmac(
            'sha256', $message, $options['secret'] ?? $this->coinsbuyWebhookSecret
        );

        return $payload;
    }
}
