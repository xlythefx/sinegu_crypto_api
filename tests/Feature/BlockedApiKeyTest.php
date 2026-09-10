<?php

namespace Tests\Feature;

use App\Models\BinanceAccount;
use Illuminate\Support\Facades\Http;

/**
 * An API key the exchange refuses from OUR server (Binance -2015: key invalid,
 * IP not allow-listed, or a permission missing).
 *
 * The whole feature exists because that failure is silent: the account still
 * reads "connected", its balance quietly stops moving, and no trades arrive.
 * These tests pin the three things that make it recoverable — the engine can
 * still SEE the account (or it could never learn the key was fixed), the
 * deadline does not drift, and a success clears everything.
 */
class BlockedApiKeyTest extends PaymentTestCase
{
    private const ENGINE = 'http://127.0.0.1:5010';

    private function keyStatusUrl(): string
    {
        return '/api/engine/binance/key-status';
    }

    private function report(array $body)
    {
        return $this->withHeaders($this->engineHeaders())->postJson($this->keyStatusUrl(), $body);
    }

    // ---- recording the verdict -------------------------------------------

    public function test_the_engine_can_flag_a_refused_key(): void
    {
        $uniId = $this->makeUser();
        $id = $this->makeAccount($uniId, ['api_key' => 'blocked-key']);

        $this->report([
            'api_key' => 'blocked-key',
            'status' => 'blocked',
            'code' => '-2015',
            'reason' => 'IP_OR_PERMISSION',
            'message' => 'Invalid API-key, IP, or permissions for action.',
        ])->assertOk()->assertJson(['updated' => true, 'status' => 'blocked']);

        $account = BinanceAccount::find($id);
        $this->assertSame('blocked', $account->key_status);
        $this->assertSame('-2015', $account->key_error_code);
        $this->assertNotNull($account->key_blocked_at);
        $this->assertNotNull($account->keyGraceEndsAt());
    }

    /**
     * The deadline must not drift. A poller finds the same broken key every
     * five minutes; if each report reset the clock the account would never
     * reach the disconnect deadline and the user would never get their slot
     * back.
     */
    public function test_repeating_the_same_verdict_does_not_extend_the_deadline(): void
    {
        $uniId = $this->makeUser();
        $id = $this->makeAccount($uniId, ['api_key' => 'blocked-key']);

        $this->report(['api_key' => 'blocked-key', 'status' => 'blocked', 'code' => '-2015']);
        $first = BinanceAccount::find($id)->key_blocked_at;

        $this->travel(2)->hours();
        $this->report(['api_key' => 'blocked-key', 'status' => 'blocked', 'code' => '-2015'])->assertOk();

        $this->assertEquals($first, BinanceAccount::find($id)->key_blocked_at);
    }

    public function test_a_working_key_clears_the_flag_and_the_deadline(): void
    {
        $uniId = $this->makeUser();
        $id = $this->makeAccount($uniId, ['api_key' => 'blocked-key']);

        $this->report(['api_key' => 'blocked-key', 'status' => 'blocked', 'code' => '-2015']);
        $this->report(['api_key' => 'blocked-key', 'status' => 'ok'])->assertOk();

        $account = BinanceAccount::find($id);
        $this->assertSame('ok', $account->key_status);
        $this->assertNull($account->key_blocked_at);
        $this->assertNull($account->key_error_code);
        $this->assertNull($account->keyGraceEndsAt());
    }

    public function test_an_unknown_api_key_is_accepted_and_ignored(): void
    {
        $this->report(['api_key' => 'not-ours', 'status' => 'blocked', 'code' => '-2015'])
            ->assertOk()
            ->assertJson(['updated' => false]);
    }

    public function test_the_endpoint_needs_the_engine_secret(): void
    {
        $this->postJson($this->keyStatusUrl(), ['api_key' => 'x', 'status' => 'ok'])
            ->assertStatus(401);
    }

    // ---- what the engine sees --------------------------------------------

    /**
     * A blocked account must STAY in the engine's list, flagged. Dropping it
     * would stop the pollers touching it, and a poll is the only thing that
     * can ever discover the key works again — the account would be frozen as
     * broken and then disconnected even though the user had fixed it.
     */
    public function test_a_blocked_account_is_still_listed_for_the_engine_but_flagged(): void
    {
        $uniId = $this->makeUser();
        $this->makeAccount($uniId, ['api_key' => 'blocked-key']);
        $this->makeAccount($uniId, ['api_key' => 'fine-key']);

        $this->report(['api_key' => 'blocked-key', 'status' => 'blocked', 'code' => '-2015']);

        $accounts = $this->withHeaders($this->engineHeaders())
            ->getJson('/api/engine/binance/accounts')
            ->assertOk()
            ->json('accounts');

        $byKey = collect($accounts)->keyBy('api_key');
        $this->assertCount(2, $byKey, 'the blocked account must not disappear');
        $this->assertTrue($byKey['blocked-key']['key_blocked']);
        $this->assertFalse($byKey['fine-key']['key_blocked']);
    }

    // ---- what the user sees ----------------------------------------------

    public function test_the_owner_sees_the_status_the_ip_and_the_deadline(): void
    {
        config(['services.engine.public_ip' => '2.24.139.176']);
        $uniId = $this->makeUser();
        $this->makeAccount($uniId, ['api_key' => 'blocked-key']);
        $this->report([
            'api_key' => 'blocked-key',
            'status' => 'blocked',
            'code' => '-2015',
            'reason' => 'IP_OR_PERMISSION',
            'message' => 'Invalid API-key, IP, or permissions for action.',
        ]);

        $this->app['auth']->forgetGuards();
        $response = $this->withHeaders($this->userHeaders($uniId))
            ->getJson('/api/exchange/accounts')
            ->assertOk()
            ->assertJson(['server_ip' => '2.24.139.176']);

        $account = $response->json('accounts.0');
        $this->assertSame('blocked', $account['key_status']);
        $this->assertSame('IP_OR_PERMISSION', $account['key_error_reason']);
        $this->assertNotNull($account['key_grace_ends_at']);
        // The secret must not ride along on a payload built for this modal.
        $this->assertArrayNotHasKey('secret_key', $account);
    }

    // ---- the 3-day disconnect --------------------------------------------

    public function test_a_key_blocked_past_the_grace_period_is_disconnected(): void
    {
        Http::fake();
        $uniId = $this->makeUser();
        $id = $this->makeAccount($uniId, ['api_key' => 'blocked-key']);
        $this->report(['api_key' => 'blocked-key', 'status' => 'blocked', 'code' => '-2015']);

        $this->travel(BinanceAccount::KEY_GRACE_DAYS + 1)->days();
        $this->artisan('exchange:disconnect-blocked-keys')->assertExitCode(0);

        $this->assertSoftDeleted('binance_accounts', ['id' => $id]);
    }

    /** Inside the window the user still has time — nothing is touched. */
    public function test_a_key_blocked_for_less_than_the_grace_period_survives(): void
    {
        Http::fake();
        $uniId = $this->makeUser();
        $id = $this->makeAccount($uniId, ['api_key' => 'blocked-key']);
        $this->report(['api_key' => 'blocked-key', 'status' => 'blocked', 'code' => '-2015']);

        $this->travel(BinanceAccount::KEY_GRACE_DAYS - 1)->days();
        $this->artisan('exchange:disconnect-blocked-keys')->assertExitCode(0);

        $this->assertNotSoftDeleted('binance_accounts', ['id' => $id]);
    }

    /** A user who fixed their key must never be disconnected afterwards. */
    public function test_a_recovered_key_is_never_disconnected(): void
    {
        Http::fake();
        $uniId = $this->makeUser();
        $id = $this->makeAccount($uniId, ['api_key' => 'blocked-key']);
        $this->report(['api_key' => 'blocked-key', 'status' => 'blocked', 'code' => '-2015']);

        $this->travel(BinanceAccount::KEY_GRACE_DAYS + 5)->days();
        $this->report(['api_key' => 'blocked-key', 'status' => 'ok']);
        $this->artisan('exchange:disconnect-blocked-keys')->assertExitCode(0);

        $this->assertNotSoftDeleted('binance_accounts', ['id' => $id]);
    }

    public function test_dry_run_changes_nothing(): void
    {
        Http::fake();
        $uniId = $this->makeUser();
        $id = $this->makeAccount($uniId, ['api_key' => 'blocked-key']);
        $this->report(['api_key' => 'blocked-key', 'status' => 'blocked', 'code' => '-2015']);

        $this->travel(BinanceAccount::KEY_GRACE_DAYS + 1)->days();
        $this->artisan('exchange:disconnect-blocked-keys --dry-run')->assertExitCode(0);

        $this->assertNotSoftDeleted('binance_accounts', ['id' => $id]);
        Http::assertNothingSent();
    }

    /** Disconnecting frees the one-account-per-user slot. */
    public function test_after_the_disconnect_a_new_key_can_be_connected(): void
    {
        Http::fake();
        $uniId = $this->makeUser();
        $this->makeAccount($uniId, ['api_key' => 'blocked-key']);
        $this->report(['api_key' => 'blocked-key', 'status' => 'blocked', 'code' => '-2015']);

        $this->travel(BinanceAccount::KEY_GRACE_DAYS + 1)->days();
        $this->artisan('exchange:disconnect-blocked-keys');

        $this->app['auth']->forgetGuards();
        $this->withHeaders($this->userHeaders($uniId))
            ->postJson('/api/exchange/binance', [
                'name' => 'Reconnected Account',
                'api_key' => 'fresh-key',
                'secret_key' => 'fresh-secret',
            ])
            ->assertStatus(201);
    }

    // ---- the admin view --------------------------------------------------

    public function test_admin_sees_every_blocked_account_with_owner_and_deadline(): void
    {
        config(['services.engine.public_ip' => '2.24.139.176']);
        $owner = $this->makeUser(['name' => 'Blocked Owner', 'email' => 'blocked@test.local']);
        $this->makeAccount($owner, ['api_key' => 'blocked-key-abcdef1234', 'name' => 'Their Account']);
        $this->makeAccount($this->makeUser(), ['api_key' => 'fine-key']);
        $this->report([
            'api_key' => 'blocked-key-abcdef1234',
            'status' => 'blocked',
            'code' => '-2015',
            'reason' => 'IP_OR_PERMISSION',
            'message' => 'Invalid API-key, IP, or permissions for action.',
        ]);

        $this->app['auth']->forgetGuards();
        $response = $this->withHeaders($this->userHeaders($this->makeUser(['type' => 'admin'])))
            ->getJson('/api/admin/engine/key-issues')
            ->assertOk()
            ->assertJson(['grace_days' => BinanceAccount::KEY_GRACE_DAYS, 'server_ip' => '2.24.139.176']);

        $accounts = $response->json('accounts');
        $this->assertCount(1, $accounts, 'only refused accounts belong on this screen');
        $row = $accounts[0];
        $this->assertSame('Blocked Owner', $row['owner_name']);
        $this->assertSame('blocked@test.local', $row['owner_email']);
        $this->assertSame('IP_OR_PERMISSION', $row['error_reason']);
        $this->assertSame(BinanceAccount::KEY_GRACE_DAYS, $row['days_left']);
        $this->assertNotNull($row['grace_ends_at']);
    }

    /** A support screen must not become a way to read out credentials. */
    public function test_the_admin_view_never_returns_a_whole_key_or_any_secret(): void
    {
        $owner = $this->makeUser();
        $this->makeAccount($owner, ['api_key' => 'blocked-key-abcdef1234', 'secret_key' => 'super-secret-value']);
        $this->report(['api_key' => 'blocked-key-abcdef1234', 'status' => 'blocked', 'code' => '-2015']);

        $this->app['auth']->forgetGuards();
        $body = $this->withHeaders($this->userHeaders($this->makeUser(['type' => 'admin'])))
            ->getJson('/api/admin/engine/key-issues')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('super-secret-value', $body);
        $this->assertStringNotContainsString('blocked-key-abcdef1234', $body);
        $this->assertStringContainsString('blocke', $body);   // the hint only
    }

    public function test_a_plain_trader_cannot_open_the_admin_view(): void
    {
        $this->withHeaders($this->userHeaders($this->makeUser()))
            ->getJson('/api/admin/engine/key-issues')
            ->assertStatus(403);
    }

    public function test_admin_recheck_clears_a_key_the_engine_now_accepts(): void
    {
        $owner = $this->makeUser();
        $id = $this->makeAccount($owner, ['api_key' => 'blocked-key']);
        $this->report(['api_key' => 'blocked-key', 'status' => 'blocked', 'code' => '-2015']);

        // The engine answers the sync AND reports the key working, exactly as
        // the real round trip does.
        Http::fake(function () {
            $this->report(['api_key' => 'blocked-key', 'status' => 'ok']);

            return Http::response(['success' => true], 200);
        });

        $this->app['auth']->forgetGuards();
        $this->withHeaders($this->userHeaders($this->makeUser(['type' => 'admin'])))
            ->postJson("/api/admin/engine/key-issues/{$id}/recheck")
            ->assertOk()
            ->assertJson(['cleared' => true, 'status' => 'ok']);

        $this->assertSame('ok', BinanceAccount::find($id)->key_status);
    }
}
