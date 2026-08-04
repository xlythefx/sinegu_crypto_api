<?php

namespace Tests\Feature;

use App\Services\Payments\PaymentEnvironment;
use Tests\TestCase;

/**
 * The live-vs-test switch. This is the safety-critical piece: getting it wrong
 * either takes real money in development or silently runs production against a
 * sandbox.
 */
class PaymentEnvironmentTest extends TestCase
{
    private function env(array $signals = []): PaymentEnvironment
    {
        return new PaymentEnvironment($signals);
    }

    private function configureHttps(): void
    {
        config([
            'payments.environments.production.api_url' => 'https://sinegualerts.test/api',
            'payments.environments.production.public_api_url' => null,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'payments.force' => null,
            'payments.environments.production.server_ips' => ['2.24.139.176'],
            'payments.environments.production.hosts' => ['2.24.139.176'],
            'payments.environments.production.hostnames' => ['srv1860230'],
            'payments.environments.production.api_url' => 'http://2.24.139.176/api',
            'payments.environments.production.frontend_url' => 'http://2.24.139.176',
            'payments.stripe.live.secret' => 'sk_live_x',
            'payments.stripe.test.secret' => 'sk_test_x',
        ]);
    }

    public function test_force_overrides_every_machine_signal(): void
    {
        config(['payments.force' => 'production']);
        $env = $this->env(['server_addr' => '10.0.0.1', 'host' => 'localhost']);

        $this->assertSame('production', $env->name());
        $this->assertSame('override', $env->reason());
    }

    public function test_matching_server_ip_is_production(): void
    {
        $env = $this->env(['server_addr' => '2.24.139.176', 'host' => 'anything']);

        $this->assertTrue($env->isProduction());
        $this->assertSame('server_ip:2.24.139.176', $env->reason());
    }

    public function test_matching_host_is_production(): void
    {
        $env = $this->env(['server_addr' => '', 'host' => '2.24.139.176']);

        $this->assertTrue($env->isProduction());
        $this->assertStringStartsWith('host:', $env->reason());
    }

    /** The CLI path: SERVER_ADDR does not exist under `php artisan`. */
    public function test_matching_hostname_is_production_without_a_request(): void
    {
        $env = $this->env(['server_addr' => '', 'host' => '', 'hostname' => 'SRV1860230']);

        $this->assertTrue($env->isProduction());
        $this->assertSame('hostname:srv1860230', $env->reason());
    }

    public function test_unknown_machine_falls_back_to_sandbox(): void
    {
        $env = $this->env(['server_addr' => '192.168.1.5', 'host' => 'localhost', 'hostname' => 'dev-box']);

        $this->assertSame('sandbox', $env->name());
        $this->assertSame('default', $env->reason());
        $this->assertSame('test', $env->stripe()['mode']);
        $this->assertSame('sandbox', $env->coinsbuy()['mode']);
        $this->assertSame('BTC', $env->coinsbuy()['default_crypto']);
    }

    /**
     * The rule that makes today's answer correct and tomorrow's automatic:
     * production over plain HTTP still resolves to the TEST key set.
     */
    public function test_production_without_https_is_downgraded_to_test_keys(): void
    {
        $env = $this->env(['server_addr' => '2.24.139.176']);

        $this->assertTrue($env->isProduction());
        $this->assertFalse($env->callbacksAreSecure());

        $stripe = $env->stripe();
        $this->assertSame('test', $stripe['mode']);
        $this->assertTrue($stripe['downgraded']);
        $this->assertSame('sk_test_x', $stripe['secret']);
        $this->assertSame('sandbox', $env->coinsbuy()['mode']);
    }

    public function test_production_over_https_uses_live_keys(): void
    {
        $this->configureHttps();
        $env = $this->env(['server_addr' => '2.24.139.176']);

        $this->assertTrue($env->callbacksAreSecure());

        $stripe = $env->stripe();
        $this->assertSame('live', $stripe['mode']);
        $this->assertFalse($stripe['downgraded']);
        $this->assertSame('sk_live_x', $stripe['secret']);
        $this->assertSame('production', $env->coinsbuy()['mode']);
        $this->assertSame('USDT', $env->coinsbuy()['default_crypto']);
    }

    public function test_success_url_keeps_the_stripe_placeholder_literal(): void
    {
        $this->configureHttps();
        $env = $this->env(['server_addr' => '2.24.139.176']);

        $this->assertSame(
            'https://sinegualerts.test/dashboard/invoices/42?payment=success&session_id={CHECKOUT_SESSION_ID}',
            str_replace('http://2.24.139.176', 'https://sinegualerts.test',
                $env->successUrl(42, 'session_id', '{CHECKOUT_SESSION_ID}'))
        );
        $this->assertStringContainsString('payment=cancelled', $env->cancelUrl(42));
    }

    /** In dev the browser and the providers reach the API at different URLs. */
    public function test_public_api_url_is_used_for_callbacks_only(): void
    {
        config([
            'payments.force' => 'sandbox',
            'payments.environments.sandbox.api_url' => 'http://127.0.0.1:8000/api',
            'payments.environments.sandbox.public_api_url' => 'https://tunnel.test/api',
        ]);
        $env = $this->env();

        $this->assertSame('http://127.0.0.1:8000/api', $env->apiBaseUrl());
        $this->assertSame('https://tunnel.test/api', $env->publicApiBaseUrl());
        $this->assertSame('https://tunnel.test/api/payments/coinsbuy/webhook', $env->coinsbuyCallbackUrl());
        $this->assertSame('https://tunnel.test/api/payments/stripe/webhook', $env->stripeWebhookUrl());
    }
}
