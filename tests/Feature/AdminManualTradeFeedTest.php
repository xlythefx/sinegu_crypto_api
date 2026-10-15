<?php

namespace Tests\Feature;

use App\Models\UserCredential;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

/**
 * The manual-trade console's `feed` choice: `main` posts to TradingView's
 * path with the main secret and reaches every non-tester account; `test`
 * posts to the venue's TEST path with the test feed's own secret and reaches
 * tester-owned accounts only. Kept apart from AdminManualTradeTest, whose
 * faked ConnectionException is the case that crashes PHP on the WAMP box —
 * every engine answer here is an ordinary faked response.
 */
class AdminManualTradeFeedTest extends EngineTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.engine.webhook_secrets.binance' => 'main-hook-secret',
            'services.engine.webhook_secrets.mexc' => 'main-hook-secret',
            'services.engine.test_webhook_secret' => 'test-hook-secret-xyz',
            'services.engine.targets.local' => 'http://engine.test:5010',
            'services.engine.targets.prod' => 'http://prod.engine.test:5010',
        ]);
    }

    private function admin(): array
    {
        $this->app['auth']->forgetGuards();
        $user = UserCredential::find($this->makeUser(['type' => 'admin']));

        return ['Authorization' => 'Bearer '.$user->createToken('spa')->plainTextToken];
    }

    // ---- targets --------------------------------------------------------------

    public function test_targets_follow_the_feed_the_engine_would_fan_out_to(): void
    {
        $headers = $this->admin();
        $alice = $this->makeUser(['name' => 'Alice']);
        $this->makeAccount($alice);
        $tess = $this->makeUser(['name' => 'Tess', 'type' => 'tester']);
        $this->makeAccount($tess);
        $this->makeAccount($tess, ['demo' => 1]);
        $suspendedTester = $this->makeUser(['name' => 'Gone', 'type' => 'tester', 'status' => 'suspended']);
        $this->makeAccount($suspendedTester);                              // the engine's filters still apply

        $main = $this->getJson('/api/admin/manual-trade/targets?exchange=binance', $headers)->assertOk()->json('targets');
        $this->assertSame(['Alice'], array_column($main, 'display_name'));
        $this->assertSame(['main'], array_column($main, 'feed'));
        $this->assertSame($main, $this->getJson('/api/admin/manual-trade/targets?exchange=binance&feed=main', $headers)->json('targets'));

        $test = $this->getJson('/api/admin/manual-trade/targets?exchange=binance&feed=test', $headers)->assertOk()->json('targets');
        $this->assertCount(1, $test);
        $this->assertSame('Tess', $test[0]['display_name']);
        $this->assertSame('test', $test[0]['feed']);
        $this->assertSame(1, $test[0]['live_count']);
        $this->assertSame(1, $test[0]['demo_count']);

        $this->getJson('/api/admin/manual-trade/targets?exchange=binance&feed=staging', $headers)
            ->assertStatus(400)
            ->assertJsonPath('error_code', 'FEED_NOT_SUPPORTED');
    }

    // ---- send -----------------------------------------------------------------

    public function test_a_test_signal_goes_to_the_test_path_with_the_test_secret(): void
    {
        Http::fake(['engine.test:5010/*' => Http::response(['accepted' => true], 200)]);

        foreach (['binance' => '/binance_test_webhook', 'mexc' => '/mexc_test_webhook', 'bybit' => '/bybit_test_webhook'] as $exchange => $path) {
            $this->postJson('/api/admin/manual-trade/send', [
                'exchange' => $exchange, 'feed' => 'test', 'target' => 'local',
                'action' => 'BUY', 'symbol' => 'ltcusdt', 'target_uni_ids' => ['uni-t'],
            ], $this->admin())
                ->assertOk()
                ->assertJson(['success' => true, 'sent' => 1, 'feed' => 'test', 'url' => "http://engine.test:5010{$path}"]);

            Http::assertSent(fn (HttpRequest $r) => $r->url() === "http://engine.test:5010{$path}"
                && $r->data()['secret'] === 'test-hook-secret-xyz'
                && $r->data()['symbol'] === 'LTCUSDT'
                && $r->data()['target_uni_ids'] === ['uni-t']);
        }
        // The main secret never travels on the test feed.
        Http::assertNotSent(fn (HttpRequest $r) => ($r->data()['secret'] ?? null) === 'main-hook-secret');
    }

    public function test_the_main_feed_is_still_the_default(): void
    {
        Http::fake(['prod.engine.test:5010/*' => Http::response(['accepted' => true], 200)]);

        $this->postJson('/api/admin/manual-trade/send', [
            'exchange' => 'binance', 'target' => 'prod', 'action' => 'EXIT_LONG', 'symbol' => 'LTCUSDT',
        ], $this->admin())->assertOk()->assertJsonPath('feed', 'main');

        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'http://prod.engine.test:5010/binance_abcd_webhook'
            && $r->data()['secret'] === 'main-hook-secret');
    }

    public function test_a_missing_test_secret_fails_closed_without_falling_back_to_the_main_one(): void
    {
        config(['services.engine.test_webhook_secret' => null]);
        Http::fake();

        $this->postJson('/api/admin/manual-trade/send', [
            'exchange' => 'binance', 'feed' => 'test', 'target' => 'local',
            'action' => 'BUY', 'symbol' => 'LTCUSDT',
        ], $this->admin())
            ->assertStatus(503)
            ->assertJson([
                'error_code' => 'ENGINE_TEST_WEBHOOK_SECRET_MISSING',
                'message' => 'ENGINE_TEST_WEBHOOK_SECRET is not configured on the API.',
            ]);

        Http::assertNothingSent();
    }

    public function test_an_unknown_feed_is_refused(): void
    {
        Http::fake();

        $this->postJson('/api/admin/manual-trade/send', [
            'exchange' => 'binance', 'feed' => 'staging', 'target' => 'local',
            'action' => 'BUY', 'symbol' => 'LTCUSDT',
        ], $this->admin())->assertStatus(422);

        Http::assertNothingSent();
    }

    // ---- engine status ------------------------------------------------------------

    public function test_engine_status_masks_the_feeds_secret_and_reports_the_test_feed(): void
    {
        Http::fake(['engine.test:5010/health' => Http::response(['status' => 'ok', 'test_feed' => ['enabled' => true]], 200)]);

        $engine = $this->getJson('/api/admin/manual-trade/engine?target=local&exchange=binance&feed=test', $this->admin())
            ->assertOk()->json('engine');
        $this->assertTrue($engine['reachable']);
        $this->assertSame('test', $engine['feed']);
        $this->assertTrue($engine['test_feed_enabled']);
        $this->assertSame('test-h••••••••••-xyz', $engine['webhook_secret_masked']);

        $main = $this->getJson('/api/admin/manual-trade/engine?target=local&exchange=binance', $this->admin())->json('engine');
        $this->assertSame('main', $main['feed']);
        $this->assertSame('main-h••••••••••cret', $main['webhook_secret_masked']);
    }

    public function test_an_engine_without_the_test_feed_reports_it_as_unknown(): void
    {
        Http::fake(['engine.test:5010/health' => Http::response(['status' => 'ok'], 200)]);

        $this->getJson('/api/admin/manual-trade/engine?target=local&feed=test', $this->admin())
            ->assertOk()
            ->assertJsonPath('engine.test_feed_enabled', null);

        config(['services.engine.test_webhook_secret' => '']);
        $this->getJson('/api/admin/manual-trade/engine?target=local&feed=test', $this->admin())
            ->assertOk()
            ->assertJsonPath('engine.webhook_secret_masked', null);
    }
}
