<?php

namespace Tests\Feature;

use App\Models\PaymentEvent;
use App\Services\Payments\CoinsbuyGateway;
use App\Services\Payments\StripeGateway;
use Illuminate\Support\Facades\Http;

/**
 * The two payment-initiation endpoints. The guard matrix is shared, and its
 * most important line is ownership: the mother looked invoices up by id alone,
 * behind `Access-Control-Allow-Origin: *`, so anyone could enumerate ids and
 * read back account names, periods and fees.
 */
class PaymentCheckoutTest extends PaymentTestCase
{
    private const STRIPE_URL = '/api/payments/stripe/checkout-session';

    private const COINSBUY_URL = '/api/payments/coinsbuy/deposit';

    /** Stand-in for the Stripe SDK; asserts on what it was handed. */
    private function fakeStripe(array &$captured): void
    {
        $this->app->bind(StripeGateway::class, function () use (&$captured) {
            return new class($captured) extends StripeGateway
            {
                public function __construct(private &$captured)
                {
                    // Deliberately skips the parent constructor: no env needed.
                }

                public function mode(): string
                {
                    return 'test';
                }

                public function isConfigured(): bool
                {
                    return true;
                }

                public function customerIdFor(string $uniId, ?string $email, ?string $name): ?string
                {
                    return 'cus_test_fake';
                }

                public function createCheckoutSession($invoice, string $accountName, ?string $customerId): array
                {
                    $this->captured = [
                        'cents' => $invoice->feeCents(),
                        'invoice_id' => $invoice->id,
                        'account_name' => $accountName,
                        'customer_id' => $customerId,
                    ];

                    return ['id' => 'cs_test_fake', 'url' => 'https://checkout.stripe.test/cs_test_fake'];
                }
            };
        });
    }

    // ---- shared guards ---------------------------------------------------

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->postJson(self::STRIPE_URL, ['invoice_id' => 1])->assertStatus(401);
        $this->postJson(self::COINSBUY_URL, ['invoice_id' => 1])->assertStatus(401);
    }

    public function test_another_users_invoice_is_not_found(): void
    {
        $owner = $this->makeUser();
        $invoice = $this->makeInvoice($this->makeAccount($owner), $owner);
        $intruder = $this->makeUser();

        $this->withHeaders($this->userHeaders($intruder))
            ->postJson(self::STRIPE_URL, ['invoice_id' => $invoice->id])
            ->assertStatus(404)
            ->assertJson(['error_code' => 'INVOICE_NOT_FOUND']);
    }

    public function test_already_paid_invoice_is_rejected(): void
    {
        $user = $this->makeUser();
        $invoice = $this->makeInvoice($this->makeAccount($user), $user, ['status' => 'paid']);

        $this->withHeaders($this->userHeaders($user))
            ->postJson(self::STRIPE_URL, ['invoice_id' => $invoice->id])
            ->assertStatus(409)
            ->assertJson(['error_code' => 'INVOICE_ALREADY_PAID']);
    }

    public function test_zero_fee_invoice_has_nothing_to_pay(): void
    {
        $user = $this->makeUser();
        $invoice = $this->makeInvoice($this->makeAccount($user), $user, [
            'total_fee' => 0, 'status' => 'pending',
        ]);

        $this->withHeaders($this->userHeaders($user))
            ->postJson(self::COINSBUY_URL, ['invoice_id' => $invoice->id])
            ->assertStatus(422)
            ->assertJson(['error_code' => 'NOTHING_TO_PAY']);
    }

    /** A tab left open across an invoice regeneration. */
    public function test_stale_client_amount_is_rejected_with_the_current_figure(): void
    {
        $user = $this->makeUser();
        $invoice = $this->makeInvoice($this->makeAccount($user), $user);

        $this->withHeaders($this->userHeaders($user))
            ->postJson(self::STRIPE_URL, ['invoice_id' => $invoice->id, 'amount' => 12.00])
            ->assertStatus(409)
            ->assertJson(['error_code' => 'AMOUNT_MISMATCH', 'expected' => 12.34]);
    }

    // ---- Stripe ----------------------------------------------------------

    public function test_stripe_without_keys_fails_closed(): void
    {
        $this->usePayments('sandbox', ['payments.stripe.test.secret' => '']);
        $user = $this->makeUser();
        $invoice = $this->makeInvoice($this->makeAccount($user), $user);

        $this->withHeaders($this->userHeaders($user))
            ->postJson(self::STRIPE_URL, ['invoice_id' => $invoice->id])
            ->assertStatus(503)
            ->assertJson(['error_code' => 'STRIPE_NOT_CONFIGURED']);
    }

    public function test_stripe_checkout_charges_the_invoice_amount(): void
    {
        $captured = [];
        $this->fakeStripe($captured);

        $user = $this->makeUser();
        $invoice = $this->makeInvoice($this->makeAccount($user), $user);

        $this->withHeaders($this->userHeaders($user))
            ->postJson(self::STRIPE_URL, ['invoice_id' => $invoice->id, 'amount' => 12.34])
            ->assertOk()
            ->assertJson([
                'provider' => 'stripe',
                'mode' => 'test',
                'session_id' => 'cs_test_fake',
                'checkout_url' => 'https://checkout.stripe.test/cs_test_fake',
            ]);

        $this->assertSame(1234, $captured['cents']);
        $this->assertSame($invoice->id, $captured['invoice_id']);
        $this->assertSame('cus_test_fake', $captured['customer_id']);
    }

    /**
     * The float-rounding guard: (int) round((float) '12.34500000' * 100) is
     * 1234, because the double is 12.34499999…. feeCents() must say 1235.
     */
    public function test_half_cent_fee_rounds_up_not_down(): void
    {
        $captured = [];
        $this->fakeStripe($captured);

        $user = $this->makeUser();
        $invoice = $this->makeInvoice($this->makeAccount($user), $user, ['total_fee' => '12.34500000']);

        $this->withHeaders($this->userHeaders($user))
            ->postJson(self::STRIPE_URL, ['invoice_id' => $invoice->id])
            ->assertOk();

        $this->assertSame(1235, $captured['cents']);
    }

    /** Live keys must never meet a callback URL the webhook cannot arrive on. */
    public function test_live_mode_over_plain_http_refuses_to_charge(): void
    {
        $captured = [];
        $this->app->bind(StripeGateway::class, fn () => new class extends StripeGateway
        {
            public function __construct() {}

            public function mode(): string
            {
                return 'live';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function createCheckoutSession($invoice, string $accountName, ?string $customerId): array
            {
                throw new \LogicException('Stripe must not be called in this scenario.');
            }
        });

        $this->usePayments('production', [
            'payments.environments.production.api_url' => 'http://2.24.139.176/api',
            'payments.environments.production.public_api_url' => null,
        ]);

        $user = $this->makeUser();
        $invoice = $this->makeInvoice($this->makeAccount($user), $user);

        $this->withHeaders($this->userHeaders($user))
            ->postJson(self::STRIPE_URL, ['invoice_id' => $invoice->id])
            ->assertStatus(503)
            ->assertJson(['error_code' => 'STRIPE_INSECURE_CALLBACK']);
    }

    // ---- Coinsbuy --------------------------------------------------------

    private function fakeCoinsbuyApi(array $walletPages = null): void
    {
        $walletPages ??= [[
            'data' => [[
                'id' => '873',
                'relationships' => ['currency' => ['data' => ['id' => 'USD']]],
                'attributes' => ['status' => 3],
            ]],
            'meta' => ['total_pages' => 1],
        ]];

        Http::fake([
            '*/token/' => Http::response(['data' => ['attributes' => [
                'access' => 'fake-token', 'expires_in' => 3600,
            ]]], 200),
            '*/currency/*' => Http::response(['data' => [['id' => '10']]], 200),
            // When no fiat wallet matches, the gateway scans again for an
            // Enterprise wallet in the requested coin. That second scan is real
            // behaviour, so the sequence must answer it instead of running dry:
            // an empty page means "no wallet here either".
            '*/wallet/*' => Http::sequence(array_map(
                fn ($page) => Http::response($page, 200), $walletPages
            ))->whenEmpty(Http::response(['data' => [], 'meta' => ['total_pages' => 1]], 200)),
            '*/deposit/' => Http::response(['data' => [
                'id' => '9001',
                'attributes' => [
                    'status' => 2,
                    'payment_page' => 'https://pay.coinsbuy.test/9001',
                ],
            ]], 200),
        ]);
    }

    public function test_coinsbuy_without_credentials_fails_closed(): void
    {
        $this->usePayments('sandbox', ['payments.coinsbuy.sandbox.client_id' => '']);
        $user = $this->makeUser();
        $invoice = $this->makeInvoice($this->makeAccount($user), $user);

        $this->withHeaders($this->userHeaders($user))
            ->postJson(self::COINSBUY_URL, ['invoice_id' => $invoice->id])
            ->assertStatus(503)
            ->assertJson(['error_code' => 'COINSBUY_NOT_CONFIGURED']);
    }

    public function test_unsupported_cryptocurrency_is_rejected(): void
    {
        $user = $this->makeUser();
        $invoice = $this->makeInvoice($this->makeAccount($user), $user);

        $this->withHeaders($this->userHeaders($user))
            ->postJson(self::COINSBUY_URL, ['invoice_id' => $invoice->id, 'cryptocurrency' => 'DOGE'])
            ->assertStatus(422)
            ->assertJson(['error_code' => 'CRYPTOCURRENCY_UNSUPPORTED']);
    }

    public function test_coinsbuy_deposit_sends_a_well_formed_json_api_body(): void
    {
        $this->fakeCoinsbuyApi();
        $user = $this->makeUser();
        $accountId = $this->makeAccount($user, ['name' => 'Main-Account #1']);
        $invoice = $this->makeInvoice($accountId, $user);

        $this->withHeaders($this->userHeaders($user))
            ->postJson(self::COINSBUY_URL, ['invoice_id' => $invoice->id, 'cryptocurrency' => 'USDT'])
            ->assertOk()
            ->assertJson([
                'provider' => 'coinsbuy',
                'payment_type' => 'payment_page',
                'payment_url' => 'https://pay.coinsbuy.test/9001',
                'deposit_id' => '9001',
                'amount' => 12.34,
            ]);

        Http::assertSent(function ($request) use ($invoice) {
            if (! str_ends_with($request->url(), '/deposit/')) {
                return false;
            }

            $attributes = $request->data()['data']['attributes'];
            $this->assertSame('deposit', $request->data()['data']['type']);
            $this->assertSame('application/vnd.api+json', $request->header('Content-Type')[0]);
            $this->assertMatchesRegularExpression('/^inv_'.$invoice->id.'_\d+$/', $attributes['tracking_id']);
            // Labels are capped at 32 chars and reject special characters.
            $this->assertLessThanOrEqual(32, strlen($attributes['label']));
            $this->assertStringNotContainsString('-', $attributes['label']);
            $this->assertStringNotContainsString('#', $attributes['label']);
            // Strings, not floats — Coinsbuy is strict about this.
            $this->assertSame('12.34', $attributes['target_amount_requested']);
            $this->assertSame('0.12', $attributes['inaccuracy']);
            $this->assertStringEndsWith('/payments/coinsbuy/webhook', $attributes['callback_url']);
            $this->assertStringContainsString('payment=success&transaction_id=inv_', $attributes['payment_page_redirect_url']);

            return true;
        });
    }

    /** The webhook's second correlation path is written here. */
    public function test_creating_a_deposit_records_an_audit_row(): void
    {
        $this->fakeCoinsbuyApi();
        $user = $this->makeUser();
        $invoice = $this->makeInvoice($this->makeAccount($user), $user);

        $this->withHeaders($this->userHeaders($user))
            ->postJson(self::COINSBUY_URL, ['invoice_id' => $invoice->id])
            ->assertOk();

        $event = PaymentEvent::where('provider', 'coinsbuy')->firstOrFail();
        $this->assertSame('created', $event->outcome);
        $this->assertSame('9001', $event->external_id);
        $this->assertSame($invoice->id, (int) $event->invoice_id);
    }

    /**
     * The mother's wallet loop stopped as soon as a page came back short, so a
     * wallet on a later page was invisible.
     */
    public function test_wallet_on_a_later_page_is_still_found(): void
    {
        $this->usePayments('sandbox', ['payments.coinsbuy.sandbox.wallet_id' => '']);
        $this->fakeCoinsbuyApi([
            [   // page 1: fewer rows than the page size, and no USD wallet
                'data' => [[
                    'id' => '100',
                    'relationships' => ['currency' => ['data' => ['id' => 'BTC']]],
                    'attributes' => ['status' => 3],
                ]],
                'meta' => ['total_pages' => 2],
            ],
            [   // page 2: the Active USD wallet
                'data' => [[
                    'id' => '873',
                    'relationships' => ['currency' => ['data' => ['id' => 'USD']]],
                    'attributes' => ['status' => 3],
                ]],
                'meta' => ['total_pages' => 2],
            ],
        ]);

        $user = $this->makeUser();
        $invoice = $this->makeInvoice($this->makeAccount($user), $user);

        $this->withHeaders($this->userHeaders($user))
            ->postJson(self::COINSBUY_URL, ['invoice_id' => $invoice->id])
            ->assertOk();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/deposit/')
            && $request->data()['data']['relationships']['wallet']['data']['id'] === '873');
    }

    public function test_no_active_wallet_is_reported_as_unavailable(): void
    {
        $this->usePayments('sandbox', ['payments.coinsbuy.sandbox.wallet_id' => '']);
        $this->fakeCoinsbuyApi([[
            'data' => [[
                'id' => '873',
                'relationships' => ['currency' => ['data' => ['id' => 'USD']]],
                'attributes' => ['status' => 1],   // not Active
            ]],
            'meta' => ['total_pages' => 1],
        ]]);

        $user = $this->makeUser();
        $invoice = $this->makeInvoice($this->makeAccount($user), $user);

        $this->withHeaders($this->userHeaders($user))
            ->postJson(self::COINSBUY_URL, ['invoice_id' => $invoice->id])
            ->assertStatus(503)
            ->assertJson(['error_code' => 'COINSBUY_NO_WALLET']);
    }

    public function test_the_access_token_is_fetched_once_and_cached(): void
    {
        $this->fakeCoinsbuyApi();
        $user = $this->makeUser();
        $first = $this->makeInvoice($this->makeAccount($user), $user);
        $second = $this->makeInvoice($this->makeAccount($user), $user, ['month_year' => '2026-05']);

        $headers = $this->userHeaders($user);
        $this->withHeaders($headers)->postJson(self::COINSBUY_URL, ['invoice_id' => $first->id])->assertOk();
        $this->withHeaders($headers)->postJson(self::COINSBUY_URL, ['invoice_id' => $second->id])->assertOk();

        $tokenCalls = 0;
        Http::assertSent(function ($request) use (&$tokenCalls) {
            if (str_ends_with($request->url(), '/token/')) {
                $tokenCalls++;
            }

            return true;
        });
        $this->assertSame(1, $tokenCalls);
    }

    // ---- GET /payments/methods ------------------------------------------

    public function test_methods_reports_the_resolved_mode_and_default_coin(): void
    {
        $user = $this->makeUser();

        $this->withHeaders($this->userHeaders($user))
            ->getJson('/api/payments/methods')
            ->assertOk()
            ->assertJson([
                'environment' => 'sandbox',
                'stripe' => ['enabled' => true, 'mode' => 'test'],
                'coinsbuy' => [
                    'enabled' => true,
                    'mode' => 'sandbox',
                    'default_cryptocurrency' => 'BTC',
                    'cryptocurrencies' => ['BTC', 'ETH', 'USDT', 'USDC'],
                ],
            ]);
    }

    /** The frontend's "test mode" badge, and the proof the guard fired. */
    public function test_methods_explains_a_production_box_still_on_test_keys(): void
    {
        $this->usePayments('production', [
            'payments.environments.production.api_url' => 'http://2.24.139.176/api',
            'payments.environments.production.public_api_url' => null,
        ]);
        $user = $this->makeUser();

        $response = $this->withHeaders($this->userHeaders($user))
            ->getJson('/api/payments/methods')
            ->assertOk()
            ->assertJson(['environment' => 'production', 'stripe' => ['mode' => 'test']]);

        $this->assertStringContainsString('HTTPS', $response->json('stripe.reason'));
    }

    /* ============ developer accounts ============ */

    /** A live box with https callbacks — everything a real production run has. */
    private function useLiveProduction(): void
    {
        $this->usePayments('production', [
            'payments.environments.production.api_url' => 'https://api.sinegualerts.test/api',
            'payments.environments.production.frontend_url' => 'https://sinegualerts.test',
            'payments.environments.production.public_api_url' => null,
        ]);
    }

    public function test_a_developer_pays_with_sandbox_credentials_on_a_live_box(): void
    {
        $this->useLiveProduction();
        $this->fakeCoinsbuyApi();

        $developer = $this->makeUser(['type' => 'developer']);
        $invoice = $this->makeInvoice($this->makeAccount($developer), $developer);

        $this->withHeaders($this->userHeaders($developer))
            ->postJson(self::COINSBUY_URL, ['invoice_id' => $invoice->id])
            ->assertOk()
            ->assertJson(['mode' => 'sandbox', 'test_account' => true]);

        // The keys are what matter, and the base URL is what proves which set
        // was used: a live-credentialled call would have gone to v3.api.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'v3.api-sandbox.coinsbuy.test'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'https://v3.api.coinsbuy.test'));
    }

    public function test_a_plain_user_on_the_same_box_pays_for_real(): void
    {
        $this->useLiveProduction();
        $this->fakeCoinsbuyApi();

        $user = $this->makeUser();
        $invoice = $this->makeInvoice($this->makeAccount($user), $user);

        $this->withHeaders($this->userHeaders($user))
            ->postJson(self::COINSBUY_URL, ['invoice_id' => $invoice->id])
            ->assertOk()
            ->assertJson(['mode' => 'production', 'test_account' => false]);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'https://v3.api.coinsbuy.test'));
    }

    public function test_methods_flags_the_developer_test_account(): void
    {
        $this->useLiveProduction();

        $developer = $this->makeUser(['type' => 'developer']);
        $this->withHeaders($this->userHeaders($developer))
            ->getJson('/api/payments/methods')
            ->assertOk()
            ->assertJson([
                // The machine verdict is unchanged — only the keys are pinned.
                'environment' => 'production',
                'test_account' => true,
                'coinsbuy' => ['mode' => 'sandbox'],
                'stripe' => ['mode' => 'test'],
            ]);

        // The guard caches the user it resolved, so a second token in the same
        // test authenticates as the first one unless the guards are dropped.
        $this->app['auth']->forgetGuards();

        $user = $this->makeUser();
        $this->withHeaders($this->userHeaders($user))
            ->getJson('/api/payments/methods')
            ->assertOk()
            ->assertJson([
                'test_account' => false,
                'coinsbuy' => ['mode' => 'production'],
                'stripe' => ['mode' => 'live'],
            ]);
    }

    /* ---- developer diagnostics ---- */

    /** Coinsbuy answers, but refuses the credentials. */
    private function fakeCoinsbuyRejectingCredentials(): void
    {
        Http::fake([
            '*/token/' => Http::response([
                'errors' => [['code' => '1001', 'detail' => 'Invalid client credentials']],
            ], 400),
            '*' => Http::response([], 200),
        ]);
    }

    /**
     * Bad keys used to surface as COINSBUY_NO_WALLET, which sends the next
     * person hunting through the wallet dashboard for a credentials problem.
     */
    public function test_refused_credentials_are_reported_as_auth_not_as_a_missing_wallet(): void
    {
        $this->fakeCoinsbuyRejectingCredentials();
        $user = $this->makeUser();
        $invoice = $this->makeInvoice($this->makeAccount($user), $user);

        $this->withHeaders($this->userHeaders($user))
            ->postJson(self::COINSBUY_URL, ['invoice_id' => $invoice->id])
            ->assertStatus(503)
            ->assertJson(['error_code' => 'COINSBUY_AUTH_FAILED']);
    }

    public function test_a_developer_gets_the_whole_failure_trace(): void
    {
        $this->fakeCoinsbuyRejectingCredentials();
        $developer = $this->makeUser(['type' => 'developer']);
        $invoice = $this->makeInvoice($this->makeAccount($developer), $developer);

        $response = $this->withHeaders($this->userHeaders($developer))
            ->postJson(self::COINSBUY_URL, ['invoice_id' => $invoice->id])
            ->assertStatus(503)
            ->assertJson([
                // The trader-facing sentence is unchanged — debug is additive.
                'error_code' => 'COINSBUY_AUTH_FAILED',
                'debug' => [
                    'error_code' => 'COINSBUY_AUTH_FAILED',
                    'environment' => 'sandbox',
                    'provider' => 'coinsbuy',
                    'invoice_id' => $invoice->id,
                    'coinsbuy' => [
                        'mode' => 'sandbox',
                        'client_id_set' => true,
                        'auth_failed' => true,
                    ],
                ],
            ]);

        $this->assertNotEmpty($response->json('debug.hint'));

        // The trace must name the step that actually broke, with what Coinsbuy
        // said — that is the whole point of the block.
        $trace = $response->json('debug.coinsbuy.trace');
        $steps = array_column($trace, 'step');
        $this->assertContains('token.rejected', $steps);
        $this->assertSame('Invalid client credentials', $trace[array_search('token.rejected', $steps, true)]['detail']);
    }

    public function test_a_plain_trader_hitting_the_same_fault_sees_no_diagnostics(): void
    {
        $this->fakeCoinsbuyRejectingCredentials();
        $user = $this->makeUser();
        $invoice = $this->makeInvoice($this->makeAccount($user), $user);

        $this->withHeaders($this->userHeaders($user))
            ->postJson(self::COINSBUY_URL, ['invoice_id' => $invoice->id])
            ->assertStatus(503)
            ->assertJsonMissingPath('debug');
    }

    /** Presence booleans only — a diagnostic must never become a key leak. */
    public function test_the_diagnostic_block_carries_no_credential_values(): void
    {
        $this->fakeCoinsbuyRejectingCredentials();
        $developer = $this->makeUser(['type' => 'developer']);
        $invoice = $this->makeInvoice($this->makeAccount($developer), $developer);

        $body = $this->withHeaders($this->userHeaders($developer))
            ->postJson(self::COINSBUY_URL, ['invoice_id' => $invoice->id])
            ->assertStatus(503)
            ->getContent();

        foreach (['cb-client', 'cb-secret', 'sk_test_fake', $this->coinsbuyWebhookSecret] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    public function test_methods_hands_a_developer_the_resolved_wiring(): void
    {
        $developer = $this->makeUser(['type' => 'developer']);

        $this->withHeaders($this->userHeaders($developer))
            ->getJson('/api/payments/methods')
            ->assertOk()
            ->assertJson(['debug' => [
                'environment' => 'sandbox',
                'forced_sandbox' => true,
                'coinsbuy' => ['mode' => 'sandbox', 'wallet_id_pinned' => '873'],
                'stripe' => ['mode' => 'test', 'secret_set' => true],
            ]]);

        $this->app['auth']->forgetGuards();

        $this->withHeaders($this->userHeaders($this->makeUser()))
            ->getJson('/api/payments/methods')
            ->assertOk()
            ->assertJsonMissingPath('debug');
    }

    public function test_tracking_id_round_trips(): void
    {
        $this->assertSame(42, CoinsbuyGateway::parseTrackingId('inv_42_1785312000'));
        $this->assertNull(CoinsbuyGateway::parseTrackingId('billing_binance_42_1785312000'));
        $this->assertNull(CoinsbuyGateway::parseTrackingId('inv_42'));
        $this->assertNull(CoinsbuyGateway::parseTrackingId(null));
    }
}
