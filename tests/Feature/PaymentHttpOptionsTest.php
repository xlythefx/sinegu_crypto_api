<?php

namespace Tests\Feature;

use App\Services\Payments\CoinsbuyGateway;
use App\Services\Payments\TronGateway;
use Illuminate\Http\Client\PendingRequest;
use Tests\TestCase;

/**
 * The IPv4 pin must use Guzzle's own `force_ip_resolve`. Guzzle 8 refuses a
 * raw CURLOPT_IPRESOLVE inside 'curl' with an InvalidArgumentException BEFORE
 * any request is sent — and Http::fake() never reaches that check, so every
 * gateway test passed while payments:watch-tron failed every minute on prod
 * from 2026-10-07 to 2026-11-08. This pins the option on the real client.
 */
class PaymentHttpOptionsTest extends TestCase
{
    private function client(object $gateway, array $args): PendingRequest
    {
        $m = new \ReflectionMethod($gateway, 'http');
        $m->setAccessible(true);

        return $m->invoke($gateway, ...$args);
    }

    private function assertPinnedToIpv4(PendingRequest $request): void
    {
        $options = $request->getOptions();
        $this->assertSame('v4', $options['force_ip_resolve'] ?? null);
        $this->assertArrayNotHasKey(CURLOPT_IPRESOLVE, (array) ($options['curl'] ?? []));
    }

    public function test_tron_pins_ipv4_with_an_option_guzzle_accepts(): void
    {
        config(['payments.tron.force_ipv4' => true]);

        $this->assertPinnedToIpv4($this->client(app(TronGateway::class), ['mainnet']));
    }

    public function test_coinsbuy_pins_ipv4_with_an_option_guzzle_accepts(): void
    {
        config(['payments.coinsbuy.force_ipv4' => true]);

        $this->assertPinnedToIpv4($this->client(app(CoinsbuyGateway::class), []));
    }
}
