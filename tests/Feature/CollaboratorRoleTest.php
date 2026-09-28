<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureStaff;
use App\Models\UserCredential;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

/**
 * The `collaborator` role — READ-ONLY staff.
 *
 * It reaches exactly the `staff` route group (dashboard Overview, User
 * Management list + detail, Strategies) and nothing behind `admin`. It is NOT
 * in EnsureAdmin::ROLES, so it cannot connect a staff-only exchange either,
 * and the shared user-detail payloads drop fee settings and account / key
 * details for it. It is never counted as a customer.
 */
class CollaboratorRoleTest extends EngineTestCase
{
    private string $admin;

    private string $collaborator;

    private string $customer;

    private int $customerAccount;

    protected function setUp(): void
    {
        parent::setUp();
        // Connect / disconnect ping the engine on 127.0.0.1:5010.
        Http::fake();

        $this->admin = $this->makeUser(['type' => 'admin', 'name' => 'Admin']);
        $this->collaborator = $this->makeUser(['type' => 'collaborator', 'name' => 'Collab']);
        $this->customer = $this->makeUser(['name' => 'Customer']);
        $this->customerAccount = $this->makeAccount($this->customer, [
            'api_key' => 'CUSTOMERKEY1234567890',
            'secret_key' => 'CUSTOMERSECRET0987654321',
        ]);

        DB::table('binance_pastpositions')->insert([
            'api_key' => 'CUSTOMERKEY1234567890',
            'uni_id' => $this->customer,
            'symbol' => 'LTCUSDT',
            'position_side' => 'LONG',
            'position_amt' => 10,
            'exit_price' => 52,
            'realized_pnl' => 20,
            'exchange_fee' => 0.5,
            'fee_source' => 'actual',
            'side' => 'SELL',
            'order_id' => 777001,
            'closed_at' => '2026-09-14 10:00:00',
            'strategy' => 'ABCD-v1',
            'is_sandbox' => 0,
            'created_at' => now(),
        ]);
    }

    /** Sanctum's guard keeps the first user it resolved — reset per actor. */
    private function actor(string $uniId): static
    {
        $this->app['auth']->forgetGuards();
        $token = UserCredential::find($uniId)->createToken('spa')->plainTextToken;

        return $this->withHeaders(['Authorization' => 'Bearer '.$token]);
    }

    /** @return string[] every GET a collaborator may read */
    private function staffReads(): array
    {
        return [
            '/api/admin/insights/overview',
            '/api/admin/insights/platform',
            '/api/admin/insights/platform/daily-pnl',
            '/api/admin/users',
            "/api/admin/users/{$this->customer}",
            "/api/admin/users/{$this->customer}/summary",
            "/api/admin/users/{$this->customer}/daily-pnl",
            "/api/admin/users/{$this->customer}/analytics",
            '/api/admin/strategies',
            '/api/admin/strategies?scope=master',
        ];
    }

    public function test_the_role_lists_are_built_from_the_admin_list(): void
    {
        $this->assertNotContains('collaborator', EnsureAdmin::ROLES);
        $this->assertSame([...EnsureAdmin::ROLES, 'collaborator'], EnsureStaff::ROLES);
    }

    public function test_an_admin_can_make_someone_a_collaborator(): void
    {
        $target = $this->makeUser();

        $this->actor($this->admin)
            ->putJson("/api/admin/users/{$target}", ['type' => 'collaborator'])
            ->assertOk()
            ->assertJsonPath('user.type', 'collaborator');

        $this->assertDatabaseHas('user_credentials', ['uni_id' => $target, 'type' => 'collaborator']);
    }

    public function test_an_admin_can_create_a_collaborator(): void
    {
        $this->actor($this->admin)
            ->postJson('/api/admin/users', [
                'name' => 'New Collab',
                'email' => 'new-collab@test.local',
                'password' => 'password123',
                'type' => 'collaborator',
            ])
            ->assertStatus(201)
            ->assertJsonPath('user.type', 'collaborator');
    }

    public function test_a_collaborator_reads_every_staff_route(): void
    {
        foreach ($this->staffReads() as $url) {
            $this->actor($this->collaborator)->getJson($url)->assertOk();
        }
    }

    public function test_a_plain_user_reads_none_of_the_staff_routes(): void
    {
        foreach ($this->staffReads() as $url) {
            $this->actor($this->customer)->getJson($url)
                ->assertForbidden()
                ->assertJsonPath('error_code', 'FORBIDDEN');
        }
    }

    public function test_an_admin_still_reads_every_staff_route(): void
    {
        foreach ($this->staffReads() as $url) {
            $this->actor($this->admin)->getJson($url)->assertOk();
        }
    }

    public function test_a_collaborator_is_refused_everything_behind_admin(): void
    {
        $forbidden = [
            ['GET', '/api/admin/invoices'],
            ['GET', '/api/admin/positions'],
            ['GET', '/api/admin/api-keys'],
            ['GET', '/api/admin/insights/money'],
            ['GET', '/api/admin/insights/system'],
            ['GET', '/api/admin/insights/customers'],
            ['GET', '/api/admin/insights/strategies'],
            ['GET', "/api/admin/users/{$this->customer}/invoices"],
            ['GET', "/api/admin/users/{$this->customer}/positions"],
            ['GET', '/api/admin/affiliate/overview'],
            ['GET', '/api/admin/master-stats'],
            ['GET', '/api/admin/trade-logs'],
            ['GET', '/api/admin/todos'],
            ['PUT', "/api/admin/users/{$this->customer}"],
            ['POST', '/api/admin/users'],
            ['POST', "/api/admin/users/{$this->customer}/accept"],
            ['PUT', '/api/admin/strategies/ABCD-v1'],
            ['DELETE', "/api/admin/api-keys/binance/{$this->customerAccount}"],
        ];

        foreach ($forbidden as [$method, $url]) {
            $this->actor($this->collaborator)
                ->json($method, $url, ['enabled' => false, 'realized_percentage' => 0, 'status' => 'suspended'])
                ->assertForbidden();
        }

        // Nothing moved.
        $this->assertDatabaseMissing('strategies', ['strategy_key' => 'ABCD-v1', 'enabled' => 0]);
        $this->assertDatabaseHas('user_credentials', ['uni_id' => $this->customer, 'status' => 'active']);
        $this->assertNull(DB::table('binance_accounts')->where('id', $this->customerAccount)->value('deleted_at'));
    }

    public function test_a_collaborator_cannot_change_roles_of_anyone(): void
    {
        foreach ([$this->collaborator, $this->customer, $this->admin] as $target) {
            $this->actor($this->collaborator)
                ->putJson("/api/admin/users/{$target}", ['type' => 'admin'])
                ->assertForbidden();
        }

        $this->assertDatabaseHas('user_credentials', ['uni_id' => $this->collaborator, 'type' => 'collaborator']);
        $this->assertDatabaseHas('user_credentials', ['uni_id' => $this->customer, 'type' => 'user']);
    }

    public function test_an_admin_cannot_demote_themselves_to_collaborator(): void
    {
        $this->actor($this->admin)
            ->putJson("/api/admin/users/{$this->admin}", ['type' => 'collaborator'])
            ->assertStatus(422)
            ->assertJson(['success' => false]);

        $this->assertDatabaseHas('user_credentials', ['uni_id' => $this->admin, 'type' => 'admin']);
    }

    public function test_user_detail_hides_fees_and_accounts_from_a_collaborator(): void
    {
        $admin = $this->actor($this->admin)->getJson("/api/admin/users/{$this->customer}")->assertOk()->json('user');
        foreach (['realized_percentage', 'unrealized_percentage', 'affiliate_percentage', 'referrals_count', 'accounts'] as $key) {
            $this->assertArrayHasKey($key, $admin);
        }
        $this->assertNotEmpty($admin['accounts']);

        $response = $this->actor($this->collaborator)->getJson("/api/admin/users/{$this->customer}")->assertOk();
        $collab = $response->json('user');
        foreach (['realized_percentage', 'unrealized_percentage', 'affiliate_percentage', 'referrals_count', 'accounts'] as $key) {
            $this->assertArrayNotHasKey($key, $collab);
        }
        foreach (['uni_id', 'name', 'email', 'status', 'type', 'created_at', 'last_activity'] as $key) {
            $this->assertArrayHasKey($key, $collab);
        }
        $this->assertStringNotContainsString('CUSTOMERK', $response->getContent());
        $this->assertStringNotContainsString('CUSTOMERSECRET', $response->getContent());
    }

    public function test_user_list_hides_fees_and_key_hints_from_a_collaborator(): void
    {
        $find = fn (array $users) => collect($users)->firstWhere('uni_id', $this->customer);

        $admin = $find($this->actor($this->admin)->getJson('/api/admin/users')->assertOk()->json('users'));
        $this->assertArrayHasKey('realized_percentage', $admin);
        $this->assertArrayHasKey('api_key', $admin['accounts'][0]);

        $response = $this->actor($this->collaborator)->getJson('/api/admin/users')->assertOk();
        $collab = $find($response->json('users'));
        $this->assertArrayNotHasKey('realized_percentage', $collab);
        $this->assertArrayNotHasKey('unrealized_percentage', $collab);
        $this->assertArrayNotHasKey('api_key', $collab['accounts'][0]);
        // The exchange + state still render (the list's exchange filter).
        $this->assertSame('binance', $collab['accounts'][0]['exchange']);
        $this->assertStringNotContainsString('CUSTOMERK', $response->getContent());
        $this->assertStringNotContainsString('CUSTOMERSECRET', $response->getContent());
    }

    public function test_summary_hides_billing_figures_from_a_collaborator(): void
    {
        $admin = $this->actor($this->admin)->getJson("/api/admin/users/{$this->customer}/summary")->assertOk()->json('summary');
        $this->assertArrayHasKey('hwm', $admin);
        $this->assertArrayHasKey('commissions', $admin);

        $collab = $this->actor($this->collaborator)->getJson("/api/admin/users/{$this->customer}/summary")->assertOk()->json('summary');
        $this->assertArrayNotHasKey('hwm', $collab);
        $this->assertArrayNotHasKey('commissions', $collab);
        $this->assertEquals($admin['realized_pnl'], $collab['realized_pnl']);
        $this->assertEquals($admin['metrics'], $collab['metrics']);
    }

    public function test_calendar_and_analytics_carry_no_key_material(): void
    {
        foreach (['daily-pnl', 'analytics'] as $path) {
            $body = $this->actor($this->collaborator)->getJson("/api/admin/users/{$this->customer}/{$path}")
                ->assertOk()->getContent();
            $this->assertStringNotContainsString('CUSTOMERK', $body);
            $this->assertStringNotContainsString('CUSTOMERSECRET', $body);
        }
    }

    public function test_overview_hides_billing_and_key_sections_from_a_collaborator(): void
    {
        $admin = $this->actor($this->admin)->getJson('/api/admin/insights/overview')->assertOk()->json();
        $this->assertArrayHasKey('blocked_keys', $admin['attention']);
        $this->assertArrayHasKey('unpaid_invoices', $admin['attention']);
        $this->assertArrayHasKey('collected_this_month', $admin['headline']);

        // Served from the same cache entry — the redaction is per request.
        $collab = $this->actor($this->collaborator)->getJson('/api/admin/insights/overview')->assertOk()->json();
        foreach (['blocked_keys', 'blocked_keys_count', 'overdue_invoices', 'unpaid_invoices', 'unmatched_transfers', 'paused_for_payment'] as $key) {
            $this->assertArrayNotHasKey($key, $collab['attention']);
        }
        $this->assertArrayNotHasKey('collected_this_month', $collab['headline']);
        $this->assertArrayHasKey('pending_users', $collab['attention']);
        $this->assertArrayHasKey('master_balance', $collab['headline']);

        // …and the admin's next read is still whole.
        $again = $this->actor($this->admin)->getJson('/api/admin/insights/overview')->assertOk()->json();
        $this->assertArrayHasKey('blocked_keys', $again['attention']);
    }

    public function test_a_collaborator_cannot_connect_a_staff_only_exchange(): void
    {
        config(['exchanges.staff_only' => ['mexc']]);
        Sanctum::actingAs(UserCredential::find($this->collaborator));

        $this->postJson('/api/exchange/mexc', [
            'name' => 'Collab MEXC',
            'api_key' => 'mx0-key-'.uniqid(),
            'secret_key' => 'mx0-secret',
        ])
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'EXCHANGE_RESTRICTED');

        $this->assertDatabaseCount('mexc_accounts', 0);
    }

    public function test_a_collaborator_is_never_counted_as_a_customer(): void
    {
        // A collaborator who trades real money on their own account.
        $this->makeAccount($this->collaborator, ['balance' => 5000, 'initial_deposit' => 5000]);

        $customers = $this->actor($this->admin)->getJson('/api/admin/insights/customers')->assertOk()->json();
        $signedUp = collect($customers['funnel'])->firstWhere('key', 'signed_up')['count'];
        $this->assertSame(1, $signedUp); // the customer alone

        $platform = $this->actor($this->admin)->getJson('/api/admin/insights/platform')->assertOk()->json();
        $this->assertSame(1, $platform['under_management']['customer_accounts']);
        $this->assertEquals(1000.0, $platform['under_management']['customers']);
        $this->assertEquals(0.0, $platform['under_management']['master']);
    }
}
