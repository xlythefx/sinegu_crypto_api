<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\PaymentIntent;
use App\Services\Payments\PaymentEnvironment;
use App\Services\Payments\TronUnits;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Shared plumbing for the payment tests: invoice seeding, a trader token, an
 * environment forced into a known state, and REAL signature builders for both
 * providers (the verifiers run their actual crypto — nothing is mocked).
 */
abstract class PaymentTestCase extends EngineTestCase
{
    protected string $stripeWebhookSecret = 'whsec_test_secret';

    protected string $coinsbuyWebhookSecret = 'coinsbuy-test-secret';

    /*
    | TRON fixtures. Every one of these carries a REAL base58check checksum —
    | TronAddress validates rather than pattern-matches, so a made-up string
    | would be rejected before any test logic ran. Generated from 0x41 + a
    | counter, so they are obviously not anyone's wallet.
    */
    protected string $tronMainnetAddress = 'T9yD14Nj9j7xAB4dbGeiX9h8unkKLxmGkn';

    protected string $tronNileAddress = 'T9yD14Nj9j7xAB4dbGeiX9h8unkKT76qbH';

    protected string $tronNileContract = 'T9yD14Nj9j7xAB4dbGeiX9h8unkKawPyGg';

    /** A different contract with the same reported symbol — the "fake USDT" case. */
    protected string $tronLookalikeContract = 'T9yD14Nj9j7xAB4dbGeiX9h8unkKi6mJHp';

    protected string $tronPayer = 'T9yD14Nj9j7xAB4dbGeiX9h8unkKsN8FyA';

    protected function setUp(): void
    {
        parent::setUp();
        // TronGateway keeps its scan-freshness stamp in the cache, which
        // RefreshDatabase does not touch — without this a "stale scan" assertion
        // would depend on which test ran before it.
        Cache::flush();
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
            'payments.tron.networks.mainnet.base_url' => 'https://api.trongrid.test',
            'payments.tron.networks.mainnet.address' => $this->tronMainnetAddress,
            'payments.tron.networks.nile.base_url' => 'https://nile.trongrid.test',
            'payments.tron.networks.nile.address' => $this->tronNileAddress,
            'payments.tron.networks.nile.contract' => $this->tronNileContract,
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

    /**
     * Sanctum bearer header for a seeded user.
     *
     * Guards are forgotten first because the AuthManager is a singleton for the
     * whole test, and RequestGuard caches the user it resolved. Without this, a
     * test that makes a second call AS A DIFFERENT USER silently gets the first
     * user back — which does not happen in production, where every request is a
     * fresh container, and so produces a failure that looks like a bug in the
     * code under test.
     */
    protected function userHeaders(string $uniId): array
    {
        $user = \App\Models\UserCredential::where('uni_id', $uniId)->firstOrFail();

        $this->app['auth']->forgetGuards();

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

    // ---- TRON ------------------------------------------------------------

    /** Insert an open intent directly, bypassing TronIntentService. */
    protected function makeIntent(Invoice $invoice, array $overrides = []): PaymentIntent
    {
        $units = TronUnits::centsToUnits($invoice->feeCents(), 6);

        return PaymentIntent::create(array_merge([
            'provider' => 'tron',
            'network' => 'nile',
            'invoice_id' => $invoice->id,
            'user_id' => $invoice->user_id,
            'account_id' => $invoice->account_id,
            'address' => $this->tronNileAddress,
            'contract_address' => $this->tronNileContract,
            'asset' => 'USDT',
            'decimals' => 6,
            'expected_units' => $units,
            'expected_usd' => $invoice->feeCents() / 100,
            'shortfall_units' => TronUnits::centsToUnits(100, 6),
            'overpay_units' => TronUnits::pctUnits($units, 5.0),
            'open_units' => $units,
            'status' => PaymentIntent::STATUS_OPEN,
            'expires_at' => now()->addHour(),
        ], $overrides));
    }

    /**
     * One item in TronGrid's TRC-20 response. Defaults describe a clean,
     * correctly-addressed USDT transfer so a test only states its deviation.
     */
    protected function tronItem(array $overrides = []): array
    {
        return array_merge([
            'transaction_id' => 'tx-'.bin2hex(random_bytes(8)),
            'token_info' => [
                'symbol' => 'USDT',
                'address' => $this->tronNileContract,
                'decimals' => 6,
                'name' => 'Tether USD',
            ],
            'block_timestamp' => now()->getTimestampMs(),
            'from' => $this->tronPayer,
            'to' => $this->tronNileAddress,
            'type' => 'Transfer',
            'value' => '12340000',
        ], $overrides);
    }

    /** A TronGrid page envelope. `$fingerprint` non-null means "more to come". */
    protected function tronPage(array $items, ?string $fingerprint = null): array
    {
        return [
            'success' => true,
            'data' => $items,
            'meta' => array_filter([
                'at' => now()->getTimestampMs(),
                'page_size' => count($items),
                'fingerprint' => $fingerprint,
            ], fn ($v) => $v !== null),
        ];
    }

    /**
     * Fake the TRC-20 endpoint. Several pages become a sequence, so a test can
     * exercise the paginated walk; the last one repeats once the sequence is
     * exhausted rather than erroring.
     *
     * @param  list<array>  $pages
     */
    protected function fakeTron(array $pages, string $host = 'nile.trongrid.test'): void
    {
        if (count($pages) === 1) {
            Http::fake(["{$host}/*" => Http::response($pages[0])]);

            return;
        }

        $sequence = Http::sequence();
        foreach ($pages as $page) {
            $sequence->push($page);
        }
        $sequence->whenEmpty(Http::response($this->tronPage([])));

        Http::fake(["{$host}/*" => $sequence]);
    }
}
