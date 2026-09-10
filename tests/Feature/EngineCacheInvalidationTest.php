<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\UserCredential;
use Illuminate\Support\Facades\Http;

/**
 * The engine caches its account and asset lists, so a change made here is
 * invisible to it until the TTL expires. Every write that changes WHO it
 * trades or HOW BIG must therefore invalidate — these tests pin that down at
 * each write site, because the failure mode is silent: everything returns 200
 * and the bot simply keeps using the old numbers.
 */
class EngineCacheInvalidationTest extends PaymentTestCase
{
    private const ENGINE = 'http://127.0.0.1:5010';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.engine.targets.local' => self::ENGINE,
            'services.engine.webhook_secrets.binance' => 'engine-hook-secret',
        ]);
    }

    /** @return int how many times the engine was told to drop that list */
    private function pings(string $path): int
    {
        $count = 0;
        Http::assertSent(function ($request) use ($path, &$count) {
            if ($request->url() === self::ENGINE."/admin/{$path}") {
                $count++;
                $this->assertSame('engine-hook-secret', $request->header('X-Admin-Secret')[0]);
            }

            return true;
        });

        return $count;
    }

    private function traderHeaders(): array
    {
        $uniId = $this->makeUser();

        return [$uniId, $this->userHeaders($uniId)];
    }

    private function adminHeaders(): array
    {
        return $this->userHeaders($this->makeUser(['type' => 'admin']));
    }

    // ---- accounts --------------------------------------------------------

    public function test_connecting_an_account_makes_it_tradeable_without_waiting_for_the_ttl(): void
    {
        Http::fake([self::ENGINE.'/*' => Http::response(['success' => true], 200)]);
        [, $headers] = $this->traderHeaders();

        $this->withHeaders($headers)->postJson('/api/exchange/binance', [
            'name' => 'Fresh Account',
            'api_key' => 'connect-key-'.uniqid(),
            'secret_key' => 'connect-secret',
        ])->assertStatus(201);

        $this->assertSame(1, $this->pings('refresh-accounts'));
    }

    /** A disconnect that lingers is trading with keys the user thinks are gone. */
    public function test_disconnecting_an_account_drops_it_from_the_engine_at_once(): void
    {
        Http::fake([self::ENGINE.'/*' => Http::response(['success' => true], 200)]);
        [$uniId, $headers] = $this->traderHeaders();
        $accountId = $this->makeAccount($uniId);

        $this->withHeaders($headers)->deleteJson("/api/exchange/accounts/{$accountId}")->assertOk();

        $this->assertSame(1, $this->pings('refresh-accounts'));
    }

    /** Paying re-enables the account — trading must resume on the next signal. */
    public function test_settling_an_invoice_refreshes_the_account_list(): void
    {
        Http::fake([self::ENGINE.'/*' => Http::response(['success' => true], 200)]);
        $uniId = $this->makeUser();
        $invoice = $this->makeInvoice($this->makeAccount($uniId, ['enabled' => 0]), $uniId);

        $this->assertTrue(app(\App\Services\InvoiceService::class)->settle($invoice, 'manual'));

        $this->assertSame(1, $this->pings('refresh-accounts'));
        $this->assertSame(1, (int) \App\Models\BinanceAccount::find($invoice->account_id)->enabled);
    }

    /** A replayed settlement changes nothing, so it must not ping either. */
    public function test_a_second_settlement_is_a_no_op_and_pings_nothing(): void
    {
        Http::fake([self::ENGINE.'/*' => Http::response(['success' => true], 200)]);
        $uniId = $this->makeUser();
        $invoice = $this->makeInvoice($this->makeAccount($uniId), $uniId, ['status' => 'paid']);

        $this->assertFalse(app(\App\Services\InvoiceService::class)->settle($invoice, 'coinsbuy'));

        Http::assertNothingSent();
    }

    // ---- assets ----------------------------------------------------------

    public function test_editing_a_base_size_applies_without_an_engine_restart(): void
    {
        Http::fake([self::ENGINE.'/*' => Http::response(['success' => true], 200)]);
        $asset = Asset::create([
            'ticker' => 'BTCUSDT', 'broker' => 'Binance', 'side' => 'ALL',
            'base_size' => 0.004, 'max_increments' => 3, 'enabled' => 1,
        ]);

        $this->withHeaders($this->adminHeaders())
            ->putJson("/api/admin/assets/{$asset->asset_id}", [
                'ticker' => 'BTCUSDT', 'broker' => 'Binance', 'side' => 'ALL',
                'base_size' => 0.008, 'max_increments' => 3, 'enabled' => true,
            ])
            ->assertOk();

        $this->assertSame(1, $this->pings('refresh-assets'));
        $this->assertSame('0.008', (string) round(Asset::find($asset->asset_id)->base_size, 3));
    }

    public function test_creating_and_deleting_an_asset_both_refresh(): void
    {
        Http::fake([self::ENGINE.'/*' => Http::response(['success' => true], 200)]);
        $headers = $this->adminHeaders();

        $created = $this->withHeaders($headers)->postJson('/api/admin/assets', [
            'ticker' => 'ETHUSDT', 'broker' => 'Binance', 'side' => 'ALL',
            'base_size' => 0.05, 'max_increments' => 3, 'enabled' => true,
        ])->assertStatus(201)->json('asset.asset_id');

        $this->withHeaders($headers)->deleteJson("/api/admin/assets/{$created}")->assertOk();

        $this->assertSame(2, $this->pings('refresh-assets'));
    }

    // ---- manual balance refresh -----------------------------------------

    public function test_refresh_balance_syncs_only_this_account(): void
    {
        Http::fake([self::ENGINE.'/*' => Http::response(['success' => true], 200)]);
        [$uniId, $headers] = $this->traderHeaders();
        $mine = $this->makeAccount($uniId, ['api_key' => 'mine-key']);
        $this->makeAccount($this->makeUser(), ['api_key' => 'someone-elses-key']);

        $this->withHeaders($headers)
            ->postJson("/api/exchange/accounts/{$mine}/refresh-balance")
            ->assertOk()
            ->assertJson(['success' => true, 'retry_after' => 60]);

        // One trader pressing the button must not cost one Binance call per
        // account on the platform.
        Http::assertSent(function ($request) {
            if ($request->url() !== self::ENGINE.'/admin/refresh-balances') {
                return true;
            }
            $this->assertSame(['mine-key'], $request->data()['api_keys']);

            return true;
        });
    }

    public function test_a_second_press_inside_the_cooldown_is_refused_with_the_time_left(): void
    {
        Http::fake([self::ENGINE.'/*' => Http::response(['success' => true], 200)]);
        [$uniId, $headers] = $this->traderHeaders();
        $accountId = $this->makeAccount($uniId);

        $this->withHeaders($headers)->postJson("/api/exchange/accounts/{$accountId}/refresh-balance")->assertOk();

        $second = $this->withHeaders($headers)
            ->postJson("/api/exchange/accounts/{$accountId}/refresh-balance")
            ->assertStatus(429)
            ->assertJson(['error_code' => 'COOLDOWN']);

        $retryAfter = $second->json('retry_after');
        $this->assertGreaterThan(0, $retryAfter);
        $this->assertLessThanOrEqual(60, $retryAfter);

        // The refused press must not have reached the exchange at all.
        $this->assertSame(1, $this->pings('refresh-balances'));
    }

    /** The cooldown is keyed per account, not per user or globally. */
    public function test_the_cooldown_is_per_account(): void
    {
        Http::fake([self::ENGINE.'/*' => Http::response(['success' => true], 200)]);
        [$uniId, $headers] = $this->traderHeaders();
        $first = $this->makeAccount($uniId);
        $second = $this->makeAccount($uniId);

        $this->withHeaders($headers)->postJson("/api/exchange/accounts/{$first}/refresh-balance")->assertOk();
        // A different account is not blocked by the first one's cooldown.
        $this->withHeaders($headers)->postJson("/api/exchange/accounts/{$second}/refresh-balance")->assertOk();
        // ...but the first one still is.
        $this->withHeaders($headers)
            ->postJson("/api/exchange/accounts/{$first}/refresh-balance")
            ->assertStatus(429);

        $this->assertSame(2, $this->pings('refresh-balances'));
    }

    public function test_another_users_account_cannot_be_refreshed(): void
    {
        Http::fake();
        [, $headers] = $this->traderHeaders();
        $theirs = $this->makeAccount($this->makeUser());

        $this->withHeaders($headers)
            ->postJson("/api/exchange/accounts/{$theirs}/refresh-balance")
            ->assertStatus(404);

        Http::assertNothingSent();
    }

    /** A dead engine reports honestly instead of pretending the balance is live. */
    public function test_a_failed_sync_reports_503_and_still_holds_the_cooldown(): void
    {
        Http::fake([self::ENGINE.'/*' => Http::response(['error' => 'nope'], 500)]);
        [$uniId, $headers] = $this->traderHeaders();
        $accountId = $this->makeAccount($uniId);

        $this->withHeaders($headers)
            ->postJson("/api/exchange/accounts/{$accountId}/refresh-balance")
            ->assertStatus(503)
            ->assertJson(['success' => false]);

        $this->withHeaders($headers)
            ->postJson("/api/exchange/accounts/{$accountId}/refresh-balance")
            ->assertStatus(429);
    }

    // ---- failure is never the user's problem -----------------------------

    /**
     * The TTL is the safety net, so a dead engine must cost the trader
     * nothing: the account is still connected, the response is still 201.
     */
    public function test_a_dead_engine_does_not_break_the_users_request(): void
    {
        Http::fake([self::ENGINE.'/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('connection refused')]);
        [, $headers] = $this->traderHeaders();

        $this->withHeaders($headers)->postJson('/api/exchange/binance', [
            'name' => 'Engine Down Account',
            'api_key' => 'down-key-'.uniqid(),
            'secret_key' => 'down-secret',
        ])->assertStatus(201);

        $this->assertDatabaseHas('binance_accounts', ['name' => 'Engine Down Account']);
    }

    /** No secret configured (a box without the engine) = no call attempted. */
    public function test_without_an_engine_secret_nothing_is_called(): void
    {
        config(['services.engine.webhook_secrets.binance' => '']);
        Http::fake();
        [, $headers] = $this->traderHeaders();

        $this->withHeaders($headers)->postJson('/api/exchange/binance', [
            'name' => 'No Secret Account',
            'api_key' => 'nosecret-key-'.uniqid(),
            'secret_key' => 'nosecret-secret',
        ])->assertStatus(201);

        Http::assertNothingSent();
    }

    /** Guard against a stray import of the UserCredential model breaking. */
    public function test_admin_role_is_what_the_asset_routes_require(): void
    {
        Http::fake();
        [, $headers] = $this->traderHeaders();

        $this->withHeaders($headers)->postJson('/api/admin/assets', [
            'ticker' => 'LTCUSDT', 'broker' => 'Binance', 'side' => 'ALL',
            'base_size' => 14, 'max_increments' => 3, 'enabled' => true,
        ])->assertStatus(403);

        Http::assertNothingSent();
        $this->assertSame(0, UserCredential::where('type', 'admin')->count());
    }
}
