<?php

namespace Tests\Feature;

use App\Models\UserCredential;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/** Admin Dashboard → Open positions: list, forced refresh, manual close. */
class AdminOpenPositionsTest extends EngineTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.engine.webhook_secrets.binance' => 'engine-hook-secret',
            'services.engine.targets.local' => 'http://engine.test:5010',
        ]);
        Cache::flush();
    }

    private function admin(): array
    {
        $user = UserCredential::find($this->makeUser(['type' => 'admin']));

        return ['Authorization' => 'Bearer '.$user->createToken('spa')->plainTextToken];
    }

    private function position(string $uniId, int $accountId, array $overrides = [], string $exchange = 'binance'): int
    {
        $table = $exchange.'_accounts';
        $apiKey = DB::table($table)->where('id', $accountId)->value('api_key');

        return DB::table($exchange.'_positions')->insertGetId(array_merge([
            'api_key' => $apiKey,
            'uni_id' => $uniId,
            'symbol' => 'LTCUSDT',
            'position_side' => 'LONG',
            'position_amt' => 14,
            'entry_price' => 100,
            'mark_price' => 101,
            'unrealized_profit' => 14,
            'created_at' => now(),
        ], $overrides));
    }

    public function test_requires_admin(): void
    {
        $plain = UserCredential::find($this->makeUser());
        $this->getJson('/api/admin/open-positions', [
            'Authorization' => 'Bearer '.$plain->createToken('spa')->plainTextToken,
        ])->assertStatus(403);
    }

    public function test_lists_every_venue_with_a_closable_verdict_and_no_api_key(): void
    {
        $headers = $this->admin();
        $alice = $this->makeUser(['name' => 'Alice']);
        $live = $this->position($alice, $this->makeAccount($alice));
        $disabled = $this->position($alice, $this->makeAccount($alice, ['enabled' => 0]), ['symbol' => 'BTCUSDT', 'position_side' => 'BOTH', 'position_amt' => -0.5]);
        $mexc = $this->position($alice, $this->makeAccount($alice, [], 'mexc'), [], 'mexc');
        $this->position($alice, $this->makeAccount($alice), ['symbol' => 'ETHUSDT', 'position_amt' => 0]); // flat row: hidden

        $rows = collect($this->getJson('/api/admin/open-positions', $headers)->assertOk()->json('positions'));

        $this->assertCount(3, $rows);
        $this->assertTrue($rows->firstWhere('id', $live)['closable']);
        $short = $rows->first(fn ($r) => $r['id'] === $disabled && $r['exchange'] === 'binance');
        $this->assertFalse($short['closable']);
        $this->assertSame('SHORT', $short['side']);   // BOTH falls back to the sign
        $this->assertSame(0.5, $short['size']);
        $this->assertNotNull($rows->first(fn ($r) => $r['exchange'] === 'mexc' && $r['id'] === $mexc));
        $rows->each(fn ($r) => $this->assertArrayNotHasKey('api_key', $r));
    }

    public function test_names_the_venues_the_master_has_an_account_on(): void
    {
        $headers = $this->admin();
        $master = $this->makeUser(['type' => 'master']);
        $this->makeAccount($master);
        $this->makeAccount($master, ['is_sandbox' => 1], 'mexc'); // a sandbox is no reference

        $this->getJson('/api/admin/open-positions', $headers)
            ->assertOk()
            ->assertJsonPath('master_exchanges', ['binance']);
    }

    public function test_lists_traded_accounts_even_with_no_position_and_never_the_master(): void
    {
        $headers = $this->admin();
        $master = $this->makeUser(['type' => 'master']);
        $this->makeAccount($master);
        $flat = $this->makeUser(['name' => 'Flat Fred']);
        $this->makeAccount($flat);
        $off = $this->makeUser(['name' => 'Off']);
        $this->makeAccount($off, ['enabled' => 0]);

        $accounts = collect($this->getJson('/api/admin/open-positions', $headers)->assertOk()->json('accounts'));

        $this->assertSame([$flat], $accounts->pluck('uni_id')->all());
    }

    public function test_refresh_has_a_thirty_second_platform_wide_cooldown(): void
    {
        Http::fake(['engine.test:5010/*' => Http::response(['success' => true], 200)]);
        $headers = $this->admin();

        $this->postJson('/api/admin/open-positions/refresh', [], $headers)
            ->assertOk()->assertJsonPath('refresh.cooldown_seconds', 30);
        $this->postJson('/api/admin/open-positions/refresh', [], $this->admin())
            ->assertStatus(429)->assertJson(['error_code' => 'REFRESH_COOLDOWN']);

        Http::assertSentCount(1);
        $this->travel(31)->seconds();
        $this->postJson('/api/admin/open-positions/refresh', [], $headers)->assertOk();
    }

    public function test_close_resolves_rows_server_side_and_echoes_the_engine_request(): void
    {
        Http::fake([
            'engine.test:5010/admin/close-positions' => Http::response(['success' => true, 'filled' => 1, 'failed' => 0, 'skipped' => 0, 'running' => 0, 'jobs' => []], 200),
            'engine.test:5010/*' => Http::response(['success' => true], 200),
        ]);
        $headers = $this->admin();
        $alice = $this->makeUser(['name' => 'Alice']);
        $id = $this->position($alice, $this->makeAccount($alice));

        $res = $this->postJson('/api/admin/open-positions/close', [
            'positions' => [['exchange' => 'binance', 'id' => $id]],
        ], $headers)->assertOk();

        $expected = ['exchange' => 'binance', 'uni_id' => $alice, 'symbol' => 'LTCUSDT', 'side' => 'LONG'];
        $res->assertJsonPath('engine_request.positions.0', $expected);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/admin/close-positions')
            && $r->hasHeader('X-Admin-Secret', 'engine-hook-secret')
            && $r['positions'] === [$expected]);
        // the closed account is re-read afterwards
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/admin/refresh-positions'));
    }

    public function test_close_refuses_an_account_the_engine_does_not_trade(): void
    {
        Http::fake();
        $headers = $this->admin();
        $alice = $this->makeUser();
        $id = $this->position($alice, $this->makeAccount($alice, ['enabled' => 0]));

        $this->postJson('/api/admin/open-positions/close', [
            'positions' => [['exchange' => 'binance', 'id' => $id]],
        ], $headers)->assertStatus(422)->assertJson(['error_code' => 'NOT_CLOSABLE']);
        $this->assertNoEngineAction();
    }

    public function test_close_refuses_a_row_that_is_gone(): void
    {
        Http::fake();
        $this->postJson('/api/admin/open-positions/close', [
            'positions' => [['exchange' => 'binance', 'id' => 999999]],
        ], $this->admin())->assertStatus(409)->assertJson(['error_code' => 'POSITION_GONE']);
        $this->assertNoEngineAction();
    }

    /**
     * The engine refuses a whole close request naming a venue it is not
     * trading, so such a row must not be closable — or one Bybit row inside a
     * "close everyone" would stop every other close in the batch.
     */
    public function test_a_venue_the_engine_does_not_trade_is_not_closable(): void
    {
        Http::fake(['engine.test:5010/health' => Http::response(['status' => 'ok', 'exchanges' => ['binance' => []]], 200)]);
        $headers = $this->admin();
        $alice = $this->makeUser();
        $binance = $this->position($alice, $this->makeAccount($alice));
        $bybit = $this->position($alice, $this->makeAccount($alice, [], 'bybit'), [], 'bybit');

        $rows = collect($this->getJson('/api/admin/open-positions', $headers)->assertOk()->json('positions'));
        $this->assertTrue($rows->first(fn ($r) => $r['exchange'] === 'binance' && $r['id'] === $binance)['closable']);
        $off = $rows->first(fn ($r) => $r['exchange'] === 'bybit' && $r['id'] === $bybit);
        $this->assertFalse($off['closable']);
        $this->assertStringContainsString('not trading Bybit', $off['blocked_reason']);

        $this->postJson('/api/admin/open-positions/close', [
            'positions' => [['exchange' => 'bybit', 'id' => $bybit]],
        ], $headers)->assertStatus(422)->assertJson(['error_code' => 'NOT_CLOSABLE']);
        $this->assertNoEngineAction();
    }

    public function test_an_engine_that_does_not_answer_blocks_no_venue(): void
    {
        Http::fake(['engine.test:5010/health' => Http::response('', 503)]);
        $headers = $this->admin();
        $alice = $this->makeUser();
        $bybit = $this->position($alice, $this->makeAccount($alice, [], 'bybit'), [], 'bybit');

        $rows = collect($this->getJson('/api/admin/open-positions', $headers)->assertOk()->json('positions'));
        $this->assertTrue($rows->first(fn ($r) => $r['exchange'] === 'bybit' && $r['id'] === $bybit)['closable']);
    }

    /** Reading /health is fine; nothing may be closed or re-synced. */
    private function assertNoEngineAction(): void
    {
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), '/admin/'));
    }
}
