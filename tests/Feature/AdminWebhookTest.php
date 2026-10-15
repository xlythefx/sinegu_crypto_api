<?php

namespace Tests\Feature;

use App\Models\UserCredential;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * GET /api/admin/webhooks — every engine webhook (venue × signal feed), who a
 * signal posted to it would reach, and the engine's own verdict on it.
 *
 * Receivers use the engine accounts endpoint's filters and feed rule, so the
 * page says exactly what the fan-out hits. Engine facts come from /health and
 * read null — unknown — whenever the engine does not answer. Secrets are
 * presence booleans only: no value, masked or not, ever leaves the server.
 */
class AdminWebhookTest extends EngineTestCase
{
    private const MAIN_SECRET = 'main-webhook-secret-VALUE-123';

    private const TEST_SECRET = 'test-webhook-secret-VALUE-456';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.engine.webhook_secrets.binance' => self::MAIN_SECRET,
            'services.engine.webhook_secrets.bybit' => self::MAIN_SECRET,
            'services.engine.webhook_secrets.mexc' => self::MAIN_SECRET,
            'services.engine.test_webhook_secret' => self::TEST_SECRET,
            'services.engine.targets.local' => 'http://engine.test:5010',
            'services.engine.public_webhook_base' => 'https://pixel-alpha.com',
        ]);
        Cache::flush();
    }

    private function as(string $type = 'admin'): array
    {
        $this->app['auth']->forgetGuards();
        $user = UserCredential::find($this->makeUser(['type' => $type]));

        return ['Authorization' => 'Bearer '.$user->createToken('spa')->plainTextToken];
    }

    /** The /health the engine will carry once the test feed ships. */
    private function health(array $overrides = []): array
    {
        return array_merge([
            'status' => 'ok',
            'exchanges' => ['binance' => ['accounts' => 4], 'mexc' => ['accounts' => 1]],
            'test_feed' => ['enabled' => true],
            'webhooks' => [
                ['exchange' => 'binance', 'feed' => 'main', 'path' => '/binance_abcd_webhook', 'enabled' => true],
                ['exchange' => 'mexc', 'feed' => 'main', 'path' => '/mexc_abcd_webhook', 'enabled' => true],
                ['exchange' => 'bybit', 'feed' => 'main', 'path' => '/bybit_abcd_webhook', 'enabled' => false],
                ['exchange' => 'binance', 'feed' => 'test', 'path' => '/binance_test_webhook', 'enabled' => true],
                ['exchange' => 'mexc', 'feed' => 'test', 'path' => '/mexc_test_webhook', 'enabled' => true],
                // bybit/test deliberately not listed: an engine that does not serve it.
            ],
        ], $overrides);
    }

    private function hook(array $body, string $exchange, string $feed): array
    {
        return collect($body['webhooks'])->first(fn ($w) => $w['exchange'] === $exchange && $w['feed'] === $feed);
    }

    public function test_it_lists_every_venue_and_feed_with_the_engines_verdict(): void
    {
        Http::fake(['engine.test:5010/health' => Http::response($this->health(), 200)]);

        $body = $this->getJson('/api/admin/webhooks', $this->as())->assertOk()->json();

        $this->assertTrue($body['success']);
        $this->assertSame(['reachable' => true, 'test_feed_enabled' => true], $body['engine']);
        $this->assertSame(['prod' => 'https://pixel-alpha.com', 'local' => 'http://engine.test:5010'], $body['bases']);
        $this->assertSame(['main_set' => true, 'test_set' => true], $body['secrets']);

        // Main feed first, then test; venues in one consistent order.
        $this->assertSame([
            ['binance', 'main', '/binance_abcd_webhook'],
            ['bybit', 'main', '/bybit_abcd_webhook'],
            ['mexc', 'main', '/mexc_abcd_webhook'],
            ['binance', 'test', '/binance_test_webhook'],
            ['bybit', 'test', '/bybit_test_webhook'],
            ['mexc', 'test', '/mexc_test_webhook'],
        ], array_map(fn ($w) => [$w['exchange'], $w['feed'], $w['path']], $body['webhooks']));
        $this->assertSame(['exchange', 'feed', 'path', 'venue_live', 'enabled', 'receivers'], array_keys($body['webhooks'][0]));

        $this->assertTrue($this->hook($body, 'binance', 'main')['venue_live']);
        $this->assertFalse($this->hook($body, 'bybit', 'main')['venue_live']);
        $this->assertTrue($this->hook($body, 'mexc', 'test')['venue_live']);
        $this->assertTrue($this->hook($body, 'binance', 'test')['enabled']);
        $this->assertFalse($this->hook($body, 'bybit', 'main')['enabled']);
        $this->assertFalse($this->hook($body, 'bybit', 'test')['enabled']);   // not listed = not served
    }

    public function test_receivers_are_what_each_path_would_fan_out_to(): void
    {
        Http::fake(['engine.test:5010/health' => Http::response($this->health(), 200)]);

        $customer = $this->makeUser();
        $this->makeAccount($customer);                                      // binance main live
        $this->makeAccount($customer, ['demo' => 1]);                       // binance main demo
        $this->makeAccount($customer, ['enabled' => 0]);                    // disabled: excluded
        $this->makeAccount($customer, ['is_sandbox' => 1]);                 // sandbox: excluded
        $this->makeAccount($customer, ['deleted_at' => now()]);             // disconnected: excluded
        $this->makeAccount($this->makeUser(['status' => 'suspended']));     // suspended owner: excluded
        $this->makeAccount($this->makeUser(['type' => 'master']));          // binance main live
        $this->makeAccount($this->makeUser(['type' => 'developer']), ['key_status' => 'blocked']); // still listed by the engine

        $tester = $this->makeUser(['type' => 'tester']);
        $this->makeAccount($tester);                                        // binance test live
        $this->makeAccount($tester, ['demo' => 1], 'mexc');                 // mexc test demo
        $this->makeAccount($this->makeUser(['type' => 'tester', 'status' => 'suspended']), [], 'mexc'); // excluded

        $body = $this->getJson('/api/admin/webhooks', $this->as())->assertOk()->json();

        $this->assertSame(['live' => 3, 'demo' => 1], $this->hook($body, 'binance', 'main')['receivers']);
        $this->assertSame(['live' => 1, 'demo' => 0], $this->hook($body, 'binance', 'test')['receivers']);
        $this->assertSame(['live' => 0, 'demo' => 0], $this->hook($body, 'mexc', 'main')['receivers']);
        $this->assertSame(['live' => 0, 'demo' => 1], $this->hook($body, 'mexc', 'test')['receivers']);
        $this->assertSame(['live' => 0, 'demo' => 0], $this->hook($body, 'bybit', 'test')['receivers']);
    }

    public function test_an_engine_that_does_not_answer_reads_as_unknown(): void
    {
        Http::fake(['engine.test:5010/health' => Http::response('Bad Gateway', 502)]);
        $this->makeAccount($this->makeUser(['type' => 'tester']));

        $body = $this->getJson('/api/admin/webhooks', $this->as())->assertOk()->json();

        $this->assertSame(['reachable' => false, 'test_feed_enabled' => null], $body['engine']);
        foreach ($body['webhooks'] as $w) {
            $this->assertNull($w['venue_live']);
            $this->assertNull($w['enabled']);
        }
        // The DB half still answers.
        $this->assertSame(['live' => 1, 'demo' => 0], $this->hook($body, 'binance', 'test')['receivers']);
    }

    public function test_a_refused_connection_reads_as_unknown(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            // A faked ConnectionException crashes PHP natively on the WAMP box
            // (see AdminManualTradeTest); the 502 case above covers the path.
            $this->markTestSkipped('Faked ConnectionException crashes PHP on Windows.');
        }
        Http::fake(fn () => throw new ConnectionException('connection refused'));

        $this->getJson('/api/admin/webhooks', $this->as())
            ->assertOk()
            ->assertJsonPath('engine.reachable', false)
            ->assertJsonPath('webhooks.0.enabled', null);
    }

    public function test_an_engine_that_predates_the_test_feed_leaves_those_fields_unknown(): void
    {
        Http::fake(['engine.test:5010/health' => Http::response(['status' => 'ok', 'exchanges' => ['binance' => []]], 200)]);

        $body = $this->getJson('/api/admin/webhooks', $this->as())->assertOk()->json();

        $this->assertSame(['reachable' => true, 'test_feed_enabled' => null], $body['engine']);
        $this->assertTrue($this->hook($body, 'binance', 'main')['venue_live']);
        $this->assertFalse($this->hook($body, 'mexc', 'main')['venue_live']);
        $this->assertNull($this->hook($body, 'binance', 'main')['enabled']);
    }

    public function test_the_health_answer_is_reused_for_thirty_seconds(): void
    {
        Http::fake(['engine.test:5010/health' => Http::response($this->health(), 200)]);
        $headers = $this->as();

        $this->getJson('/api/admin/webhooks', $headers)->assertOk();
        $this->getJson('/api/admin/webhooks', $headers)->assertOk();
        Http::assertSentCount(1);

        $this->travel(31)->seconds();
        $this->getJson('/api/admin/webhooks', $headers)->assertOk();
        Http::assertSentCount(2);
    }

    public function test_secrets_are_presence_only_and_never_appear_in_the_body(): void
    {
        Http::fake(['engine.test:5010/health' => Http::response($this->health(), 200)]);

        $raw = $this->getJson('/api/admin/webhooks', $this->as())->assertOk()->getContent();

        foreach ([self::MAIN_SECRET, self::TEST_SECRET] as $secret) {
            $this->assertStringNotContainsString($secret, $raw);
            // Nor a masked copy's recognisable ends.
            $this->assertStringNotContainsString(substr($secret, 0, 6), $raw);
            $this->assertStringNotContainsString(substr($secret, -4), $raw);
        }

        config(['services.engine.test_webhook_secret' => null, 'services.engine.webhook_secrets.binance' => '']);
        $this->getJson('/api/admin/webhooks', $this->as())
            ->assertOk()
            ->assertJsonPath('secrets', ['main_set' => false, 'test_set' => false]);
    }

    public function test_it_is_full_admin_only(): void
    {
        Http::fake();

        $this->getJson('/api/admin/webhooks')->assertUnauthorized();
        foreach (['collaborator', 'tester', 'user'] as $type) {
            $this->getJson('/api/admin/webhooks', $this->as($type))
                ->assertForbidden()
                ->assertJsonPath('error_code', 'FORBIDDEN');
        }
        foreach (['admin', 'master', 'developer'] as $type) {
            $this->getJson('/api/admin/webhooks', $this->as($type))->assertOk();
        }
    }
}
