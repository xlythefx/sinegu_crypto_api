<?php

namespace Tests\Feature;

use App\Models\UserCredential;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

/**
 * Admin user detail's header actions: Refresh (a real exchange read, scoped
 * to the user's keys), Delete (suspended users with nothing on record only),
 * and Admin → Trading Positions' Refresh.
 */
class AdminUserActionsTest extends EngineTestCase
{
    private const ENGINE = 'http://127.0.0.1:5010';

    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.engine.targets.local' => self::ENGINE,
            'services.engine.webhook_secrets.binance' => 'engine-hook-secret',
        ]);
        $this->admin = $this->makeUser(['type' => 'admin']);
        Sanctum::actingAs(UserCredential::query()->find($this->admin));
    }

    /** @return array<string, list<mixed>> engine path => bodies sent */
    private function sent(): array
    {
        $out = [];
        Http::assertSent(function ($request) use (&$out) {
            $out[substr($request->url(), strlen(self::ENGINE.'/admin/'))][] = $request->data();

            return true;
        });

        return $out;
    }

    public function test_refresh_reads_only_this_users_connected_accounts(): void
    {
        Http::fake([self::ENGINE.'/*' => Http::response(['success' => true])]);
        $user = $this->makeUser();
        $this->makeAccount($user, ['api_key' => 'mine-live']);
        $this->makeAccount($user, ['api_key' => 'mine-gone', 'deleted_at' => now()]);
        $this->makeAccount($this->makeUser(), ['api_key' => 'someone-else']);

        $this->postJson("/api/admin/users/{$user}/refresh")->assertOk()->assertJsonPath('accounts', 1);

        $sent = $this->sent();
        $this->assertSame([['api_keys' => ['mine-live']]], $sent['refresh-balances']);
        $this->assertSame([['api_keys' => ['mine-live']]], $sent['refresh-positions']);
    }

    public function test_refresh_with_no_connected_account_calls_nothing(): void
    {
        Http::fake();
        $user = $this->makeUser();

        $this->postJson("/api/admin/users/{$user}/refresh")->assertOk()->assertJsonPath('accounts', 0);
        Http::assertNothingSent();
    }

    public function test_refresh_says_so_when_the_engine_is_down(): void
    {
        Http::fake([self::ENGINE.'/*' => Http::response('down', 502)]);
        $user = $this->makeUser();
        $this->makeAccount($user);

        $this->postJson("/api/admin/users/{$user}/refresh")->assertStatus(503);
    }

    public function test_positions_refresh_syncs_every_account(): void
    {
        Http::fake([self::ENGINE.'/*' => Http::response(['success' => true])]);

        $this->postJson('/api/admin/positions/refresh')->assertOk();

        $this->assertSame([[]], $this->sent()['refresh-positions']);
    }

    public function test_a_suspended_user_with_nothing_on_record_is_deleted(): void
    {
        $user = $this->makeUser(['status' => 'suspended']);

        $this->getJson("/api/admin/users/{$user}")->assertOk()->assertJsonPath('user.delete_blockers', []);
        $this->deleteJson("/api/admin/users/{$user}")->assertOk();

        $this->assertNull(UserCredential::find($user));
    }

    public function test_an_active_user_is_never_deleted(): void
    {
        $user = $this->makeUser();

        $this->deleteJson("/api/admin/users/{$user}")
            ->assertStatus(422)->assertJsonPath('error_code', 'NOT_DELETABLE');
        $this->assertNotNull(UserCredential::find($user));
    }

    public function test_history_blocks_the_delete_even_when_suspended(): void
    {
        $withAccount = $this->makeUser(['status' => 'suspended']);
        $this->makeAccount($withAccount, ['deleted_at' => now()]); // disconnected still counts

        $withInvoice = $this->makeUser(['status' => 'suspended']);
        DB::table('invoices')->insert([
            'exchange' => 'binance', 'user_id' => $withInvoice, 'account_id' => 1, 'api_key' => 'k',
            'month_year' => '2026-08', 'realized_pnl' => 0, 'unrealized_pnl' => 0, 'hwm_before' => 0,
            'hwm_after' => 0, 'fee_realized' => 0, 'fee_unrealized' => 0, 'total_fee' => 0,
            'status' => 'paid', 'created_at' => now(),
        ]);

        foreach ([$withAccount, $withInvoice] as $uniId) {
            $this->assertNotEmpty($this->getJson("/api/admin/users/{$uniId}")->json('user.delete_blockers'));
            $this->deleteJson("/api/admin/users/{$uniId}")->assertStatus(422);
            $this->assertNotNull(UserCredential::find($uniId));
        }
    }

    public function test_the_master_and_yourself_are_never_deleted(): void
    {
        $master = $this->makeUser(['type' => 'master', 'status' => 'suspended']);
        $this->deleteJson("/api/admin/users/{$master}")->assertStatus(422);

        DB::table('user_credentials')->where('uni_id', $this->admin)->update(['status' => 'suspended']);
        $this->deleteJson("/api/admin/users/{$this->admin}")->assertStatus(422);
    }

    public function test_a_collaborator_can_do_none_of_it(): void
    {
        Sanctum::actingAs(UserCredential::query()->find($this->makeUser(['type' => 'collaborator'])));
        $user = $this->makeUser(['status' => 'suspended']);

        $this->postJson("/api/admin/users/{$user}/refresh")->assertForbidden();
        $this->deleteJson("/api/admin/users/{$user}")->assertForbidden();
        $this->postJson('/api/admin/positions/refresh')->assertForbidden();
    }
}
