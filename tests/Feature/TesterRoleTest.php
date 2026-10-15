<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminUserController;
use App\Models\UserCredential;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

/**
 * The `tester` role — an internal test account on the TEST signal feed.
 *
 * Its accounts are listed to the engine with `feed: test` (every other owner
 * `main`), so they trade only the test webhooks' signals. Moving a user into
 * or out of the role moves their accounts to the other feed, which is why it
 * waits until they are flat and pings the engine to drop its cached list. A
 * tester may connect the staff-only venues, opens no admin page, and is never
 * counted as a customer.
 */
class TesterRoleTest extends EngineTestCase
{
    private const ADMIN_SECRET = 'test-admin-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.engine.admin_secret' => self::ADMIN_SECRET,
            'services.engine.targets.local' => 'http://engine.test:5010',
        ]);
        // Role changes and connects ping the engine; nothing leaves the process.
        Http::fake(['engine.test:5010/*' => Http::response(['success' => true], 200)]);
    }

    /** Sanctum's guard keeps the first user it resolved — reset per actor. */
    private function actor(string $uniId): static
    {
        $this->app['auth']->forgetGuards();
        $token = UserCredential::find($uniId)->createToken('spa')->plainTextToken;

        return $this->withHeaders(['Authorization' => 'Bearer '.$token]);
    }

    private function setRole(string $actor, string $target, string $role)
    {
        return $this->actor($actor)->putJson("/api/admin/users/{$target}", ['type' => $role]);
    }

    /** An open (or, with amount 0, flat) position row on one of the account's symbols. */
    private function position(string $uniId, int $accountId, float $amount = 14, string $exchange = 'binance'): void
    {
        $apiKey = DB::table("{$exchange}_accounts")->where('id', $accountId)->value('api_key');

        DB::table("{$exchange}_positions")->insert([
            'api_key' => $apiKey,
            'uni_id' => $uniId,
            'symbol' => 'LTCUSDT',
            'position_side' => $amount < 0 ? 'SHORT' : 'LONG',
            'position_amt' => $amount,
            'entry_price' => 100,
            'mark_price' => 101,
            'unrealized_profit' => 14,
            'created_at' => now(),
        ]);
    }

    // ---- the engine's account list -------------------------------------------

    public function test_the_role_is_assignable(): void
    {
        $this->assertContains(UserCredential::TESTER, AdminUserController::ROLES);
        $this->assertSame('tester', UserCredential::TESTER);
    }

    public function test_the_engine_lists_tester_accounts_on_the_test_feed_and_everyone_else_on_main(): void
    {
        $tester = $this->makeUser(['type' => 'tester']);
        $owners = [
            'tester' => $tester,
            'user' => $this->makeUser(),
            'master' => $this->makeUser(['type' => 'master']),
            'developer' => $this->makeUser(['type' => 'developer']),
        ];
        foreach ($owners as $uniId) {
            $this->makeAccount($uniId);
        }
        // A demo tester account follows the test feed exactly like a live one.
        $this->makeAccount($tester, ['demo' => 1], 'mexc');

        $binance = collect($this->getJson('/api/engine/binance/accounts', $this->engineHeaders())
            ->assertOk()->json('accounts'))->keyBy('uni_id');

        $this->assertSame('test', $binance[$owners['tester']]['feed']);
        $this->assertSame('main', $binance[$owners['user']]['feed']);
        $this->assertSame('main', $binance[$owners['master']]['feed']);
        $this->assertSame('main', $binance[$owners['developer']]['feed']);
        $this->assertTrue($binance[$owners['master']]['is_master']);
        $this->assertFalse($binance[$owners['tester']]['is_master']);

        $mexc = $this->getJson('/api/engine/mexc/accounts', $this->engineHeaders())->assertOk()->json('accounts');
        $this->assertCount(1, $mexc);
        $this->assertSame('test', $mexc[0]['feed']);
        $this->assertTrue($mexc[0]['demo']);
    }

    // ---- moving between feeds ------------------------------------------------

    public function test_becoming_a_tester_is_refused_while_a_position_is_open(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);
        $target = $this->makeUser(['name' => 'Open Olga']);
        $this->position($target, $this->makeAccount($target));

        $response = $this->setRole($admin, $target, 'tester')
            ->assertStatus(409)
            ->assertJson(['success' => false, 'error_code' => 'OPEN_POSITIONS'])
            ->assertJsonPath('exchanges', ['binance']);

        $this->assertStringStartsWith('Open Olga still holds open positions — close them first.', $response->json('message'));
        $this->assertDatabaseHas('user_credentials', ['uni_id' => $target, 'type' => 'user']);
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), '/admin/refresh-accounts'));
    }

    public function test_leaving_the_tester_role_is_refused_while_a_position_is_open_on_any_venue(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);
        $tester = $this->makeUser(['type' => 'tester']);
        $this->makeAccount($tester);                                       // flat on Binance
        $this->position($tester, $this->makeAccount($tester, [], 'bybit'), -3, 'bybit');

        foreach (['user', 'developer', 'admin'] as $role) {
            $this->setRole($admin, $tester, $role)
                ->assertStatus(409)
                ->assertJsonPath('error_code', 'OPEN_POSITIONS')
                ->assertJsonPath('exchanges', ['bybit']);
        }
        $this->assertDatabaseHas('user_credentials', ['uni_id' => $tester, 'type' => 'tester']);
    }

    public function test_a_flat_user_moves_feeds_and_the_engine_is_told(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);
        $target = $this->makeUser();
        $account = $this->makeAccount($target);
        $this->position($target, $account, 0);                              // a flat row is not a position
        // A disconnected account's leftover row cannot be closed or re-synced.
        $gone = $this->makeAccount($target, ['deleted_at' => now()], 'mexc');
        $this->position($target, $gone, 5, 'mexc');

        $this->setRole($admin, $target, 'tester')
            ->assertOk()
            ->assertJsonPath('user.type', 'tester');

        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'http://engine.test:5010/admin/refresh-accounts'
            && $r->header('X-Admin-Secret') === [self::ADMIN_SECRET]);

        $feed = $this->getJson('/api/engine/binance/accounts', $this->engineHeaders())->json('accounts.0.feed');
        $this->assertSame('test', $feed);

        // …and back again.
        $this->setRole($admin, $target, 'user')->assertOk()->assertJsonPath('user.type', 'user');
        $this->assertSame('main', $this->getJson('/api/engine/binance/accounts', $this->engineHeaders())->json('accounts.0.feed'));
    }

    public function test_a_role_change_that_keeps_the_feed_is_not_blocked_by_positions(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);
        $target = $this->makeUser();
        $this->position($target, $this->makeAccount($target));

        $this->setRole($admin, $target, 'developer')->assertOk()->assertJsonPath('user.type', 'developer');
        $this->setRole($admin, $target, 'collaborator')->assertOk()->assertJsonPath('user.type', 'collaborator');
        // is_master is derived from the role too, so any role change refreshes.
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/admin/refresh-accounts'));
    }

    public function test_an_unchanged_role_or_a_fee_edit_does_not_ping_the_engine(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);
        $tester = $this->makeUser(['type' => 'tester']);
        $this->position($tester, $this->makeAccount($tester));

        // Echoing the current role is not a change, so open positions do not matter.
        $this->setRole($admin, $tester, 'tester')->assertOk();
        $this->actor($admin)->putJson("/api/admin/users/{$tester}", ['realized_percentage' => 10])->assertOk();

        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), '/admin/refresh-accounts'));
    }

    // ---- what a tester may and may not do ------------------------------------

    public function test_a_tester_may_connect_the_staff_only_venues_and_a_customer_still_may_not(): void
    {
        config(['exchanges.staff_only' => ['mexc', 'bybit']]);

        $tester = $this->makeUser(['type' => 'tester']);
        Sanctum::actingAs(UserCredential::find($tester));
        foreach (['mexc', 'bybit'] as $exchange) {
            $this->postJson("/api/exchange/{$exchange}", [
                'name' => "Tester {$exchange}",
                'api_key' => "{$exchange}-key-".uniqid(),
                'secret_key' => "{$exchange}-secret",
            ])->assertStatus(201)->assertJsonPath('account.exchange', $exchange);
        }

        $customer = $this->makeUser();
        Sanctum::actingAs(UserCredential::find($customer));
        $this->postJson('/api/exchange/mexc', [
            'name' => 'Customer MEXC',
            'api_key' => 'mexc-key-'.uniqid(),
            'secret_key' => 'mexc-secret',
        ])->assertStatus(403)->assertJsonPath('error_code', 'EXCHANGE_RESTRICTED');

        $this->assertDatabaseCount('mexc_accounts', 1);
        $this->assertDatabaseCount('bybit_accounts', 1);
    }

    public function test_a_tester_opens_no_admin_page(): void
    {
        $tester = $this->makeUser(['type' => 'tester']);

        foreach ([
            '/api/admin/insights/overview',       // staff group
            '/api/admin/users',                   // staff group
            '/api/admin/trade-logs',              // admin group
            '/api/admin/manual-trade/targets',
            '/api/admin/webhooks',
        ] as $url) {
            $this->actor($tester)->getJson($url)->assertForbidden();
        }
    }

    public function test_a_tester_is_never_counted_as_a_customer(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);
        $customer = $this->makeUser(['name' => 'Customer']);
        $this->makeAccount($customer);
        // A tester trading real money on its own account.
        $this->makeAccount($this->makeUser(['type' => 'tester']), ['balance' => 5000, 'initial_deposit' => 5000]);

        $customers = $this->actor($admin)->getJson('/api/admin/insights/customers')->assertOk()->json();
        $this->assertSame(1, collect($customers['funnel'])->firstWhere('key', 'signed_up')['count']);

        foreach (['all', 'customers'] as $scope) {
            $platform = $this->actor($admin)->getJson("/api/admin/insights/platform?scope={$scope}")->assertOk()->json();
            $this->assertSame(1, $platform['accounts_live'], $scope);
            $this->assertSame(1, $platform['under_management']['customer_accounts'], $scope);
            $this->assertEquals(1000.0, $platform['under_management']['balance'], $scope);
        }
    }
}
