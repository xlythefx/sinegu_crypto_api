<?php

namespace Tests\Feature;

use App\Models\UserCredential;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Admin manual-trade console: recipient list + signed webhook proxy. */
class AdminManualTradeTest extends EngineTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.engine.webhook_secrets.binance' => 'engine-hook-secret',
            'services.engine.targets.local' => 'http://engine.test:5010',
            'services.engine.targets.prod' => 'http://prod.engine.test:5010',
        ]);
    }

    /** Admin/master bearer token for the `admin` middleware. */
    private function admin(): array
    {
        $uniId = $this->makeUser(['type' => 'admin']);
        $user = UserCredential::find($uniId);

        return ['Authorization' => 'Bearer '.$user->createToken('spa')->plainTextToken];
    }

    public function test_targets_requires_admin(): void
    {
        $this->getJson('/api/admin/manual-trade/targets')->assertStatus(401);

        $plainUniId = $this->makeUser();
        $plain = UserCredential::find($plainUniId);
        $this->getJson('/api/admin/manual-trade/targets', [
            'Authorization' => 'Bearer '.$plain->createToken('spa')->plainTextToken,
        ])->assertStatus(403)->assertJson(['error_code' => 'FORBIDDEN']);
    }

    public function test_targets_group_accounts_per_user_and_apply_engine_filters(): void
    {
        $headers = $this->admin();

        $active = $this->makeUser(['name' => 'Alice']);
        $this->makeAccount($active);                          // live
        $this->makeAccount($active, ['demo' => 1]);           // demo
        $this->makeAccount($active, ['enabled' => 0]);        // excluded

        $suspended = $this->makeUser(['name' => 'Bob', 'status' => 'suspended']);
        $this->makeAccount($suspended);                       // excluded

        $sandboxOwner = $this->makeUser(['name' => 'Carol']);
        $this->makeAccount($sandboxOwner, ['is_sandbox' => 1]); // excluded

        $targets = $this->getJson('/api/admin/manual-trade/targets?exchange=binance', $headers)
            ->assertOk()
            ->json('targets');

        $this->assertCount(1, $targets);
        $this->assertSame('Alice', $targets[0]['display_name']);
        $this->assertSame(2, $targets[0]['account_count']);
        $this->assertSame(1, $targets[0]['live_count']);
        $this->assertSame(1, $targets[0]['demo_count']);
    }

    public function test_unsupported_exchange_is_rejected(): void
    {
        // All three venues have a webhook path as of 2026-09-24, so only a name
        // the engine has never heard of is refused here.
        $this->getJson('/api/admin/manual-trade/targets?exchange=kraken', $this->admin())
            ->assertStatus(400)
            ->assertJson(['error_code' => 'EXCHANGE_NOT_SUPPORTED']);
    }

    public function test_send_signs_payload_with_server_side_secret(): void
    {
        Http::fake(['engine.test:5010/*' => Http::response(['accepted' => true, 'queued' => true], 200)]);

        $this->postJson('/api/admin/manual-trade/send', [
            'exchange' => 'binance',
            'target' => 'local',
            'action' => 'BUY',
            'symbol' => 'btcusdt',
            'leverage' => 20,
            'strategy' => 'Manual',
            'target_uni_ids' => ['uni-1', 'uni-2'],
        ], $this->admin())->assertOk()->assertJson(['success' => true, 'sent' => 1]);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->url() === 'http://engine.test:5010/binance_abcd_webhook'
                && $body['secret'] === 'engine-hook-secret'   // supplied by the server
                && $body['action'] === 'BUY'
                && $body['symbol'] === 'BTCUSDT'              // normalized
                && $body['leverage'] === 20
                && $body['target_uni_ids'] === ['uni-1', 'uni-2'];
        });
    }

    public function test_increments_send_one_signal_each_for_entries_only(): void
    {
        Http::fake(['*' => Http::response(['accepted' => true], 200)]);

        $this->postJson('/api/admin/manual-trade/send', [
            'exchange' => 'binance', 'target' => 'local',
            'action' => 'BUY', 'symbol' => 'BTCUSDT', 'increments' => 3,
        ], $this->admin())->assertOk()->assertJson(['sent' => 3, 'increments' => 3]);
        Http::assertSentCount(3);

        // Exits ignore increments — one close empties the position.
        Http::fake(['*' => Http::response(['accepted' => true], 200)]);
        $this->postJson('/api/admin/manual-trade/send', [
            'exchange' => 'binance', 'target' => 'local',
            'action' => 'EXIT_LONG', 'symbol' => 'BTCUSDT', 'increments' => 5,
        ], $this->admin())->assertOk()->assertJson(['sent' => 1, 'increments' => 1]);
        Http::assertSentCount(1);
    }

    public function test_engine_rejection_surfaces_as_502(): void
    {
        Http::fake(['*' => Http::response(['error' => 'Unauthorized'], 403)]);

        $this->postJson('/api/admin/manual-trade/send', [
            'exchange' => 'binance', 'target' => 'local',
            'action' => 'BUY', 'symbol' => 'BTCUSDT',
        ], $this->admin())->assertStatus(502)->assertJson(['success' => false, 'failed' => 1]);
    }

    public function test_missing_webhook_secret_fails_closed(): void
    {
        config(['services.engine.webhook_secrets.binance' => null]);
        Http::fake();

        $this->postJson('/api/admin/manual-trade/send', [
            'exchange' => 'binance', 'target' => 'local',
            'action' => 'BUY', 'symbol' => 'BTCUSDT',
        ], $this->admin())->assertStatus(503)
            ->assertJson(['error_code' => 'ENGINE_WEBHOOK_SECRET_MISSING']);

        Http::assertNothingSent();
    }

    public function test_arbitrary_urls_cannot_be_targeted(): void
    {
        Http::fake();

        $this->postJson('/api/admin/manual-trade/send', [
            'exchange' => 'binance',
            'target' => 'http://evil.example.com',   // not a configured key
            'action' => 'BUY', 'symbol' => 'BTCUSDT',
        ], $this->admin())->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_engine_status_reports_reachability(): void
    {
        Http::fake(['engine.test:5010/health' => Http::response(['status' => 'ok', 'service' => 'binance-abcd'], 200)]);

        $this->getJson('/api/admin/manual-trade/engine?target=local', $this->admin())
            ->assertOk()
            ->assertJson(['engine' => ['reachable' => true, 'health' => ['service' => 'binance-abcd']]]);
    }

    public function test_engine_status_handles_unreachable_engine(): void
    {
        Http::fake(fn () => throw new ConnectionException('connection refused'));

        $this->getJson('/api/admin/manual-trade/engine?target=local', $this->admin())
            ->assertOk()
            ->assertJson(['success' => true, 'engine' => ['reachable' => false]]);
    }
}
