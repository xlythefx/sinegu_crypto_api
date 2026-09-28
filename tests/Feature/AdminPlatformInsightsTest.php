<?php

namespace Tests\Feature;

use App\Models\UserCredential;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/admin/insights/platform[/daily-pnl]?scope= — the admin Overview's
 * Platform view: every real account pooled into one portfolio.
 *
 * Counted (live, real money), figures hand-derived:
 *   customer A  binance  initial 1000  balance 1100  unrealized 5
 *               2026-09-14 close net 49.50 fee 0.50 · today close net 10 fee 0
 *   customer B  mexc     initial 3000  balance 3000
 *               2026-09-14 close net −19.50 fee 0.50
 *   master      binance  initial 6000  balance 6500
 *               2026-09-14 close net 100.00 fee 1.50
 * Never counted, each closing +999 on 2026-09-14: a staff account, a demo
 * customer account, an SBXINV- scenario account and a disconnected account.
 */
class AdminPlatformInsightsTest extends EngineTestCase
{
    private string $admin;

    private string $customerA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->makeUser(['type' => 'admin', 'name' => 'Staff']);
        $this->customerA = $this->makeUser(['name' => 'Alice']);
        $customerB = $this->makeUser(['name' => 'Bob']);
        $master = $this->makeUser(['type' => 'master', 'name' => 'Master']);

        $a = $this->makeAccount($this->customerA, ['initial_deposit' => 1000, 'balance' => 1100, 'unrealized_pnl' => 5]);
        $b = $this->makeAccount($customerB, ['initial_deposit' => 3000, 'balance' => 3000], 'mexc');
        $m = $this->makeAccount($master, ['initial_deposit' => 6000, 'balance' => 6500]);

        $this->close('binance', $a, '2026-09-14 10:00:00', 49.5, 0.5);
        $this->close('binance', $a, now()->subMinutes(5)->toDateTimeString(), 10, 0);
        $this->close('mexc', $b, '2026-09-14 11:00:00', -19.5, 0.5);
        $this->close('binance', $m, '2026-09-14 12:00:00', 100, 1.5);

        $excluded = [
            $this->makeAccount($this->admin),
            $this->makeAccount($this->customerA, ['demo' => 1]),
            $this->makeAccount($customerB, ['api_key' => 'SBXINV-platform-test']),
            $this->makeAccount($customerB, ['deleted_at' => now()]),
        ];
        foreach ($excluded as $id) {
            $this->close('binance', $id, '2026-09-14 13:00:00', 999, 1);
        }
    }

    private function close(string $exchange, int $accountId, string $closedAt, float $net, float $fee): void
    {
        static $order = 900000;
        $account = DB::table("{$exchange}_accounts")->find($accountId);

        DB::table("{$exchange}_pastpositions")->insert([
            'api_key' => $account->api_key,
            'uni_id' => $account->uni_id,
            'symbol' => 'LTCUSDT',
            'position_side' => 'LONG',
            'position_amt' => 10,
            'exit_price' => 52,
            'realized_pnl' => $net,
            'exchange_fee' => $fee,
            'fee_source' => 'actual',
            'side' => 'SELL',
            'order_id' => ++$order,
            'closed_at' => $closedAt,
            'strategy' => 'ABCD-v1',
            'is_sandbox' => 0,
            'created_at' => now(),
        ]);
    }

    private function headersFor(string $uniId): array
    {
        return ['Authorization' => 'Bearer '.UserCredential::find($uniId)->createToken('spa')->plainTextToken];
    }

    private function platform(string $scope = 'all'): array
    {
        return $this->getJson("/api/admin/insights/platform?scope={$scope}", $this->headersFor($this->admin))
            ->assertOk()->json();
    }

    private function days(string $scope = 'all'): array
    {
        return $this->getJson("/api/admin/insights/platform/daily-pnl?scope={$scope}", $this->headersFor($this->admin))
            ->assertOk()->json('days');
    }

    public function test_it_is_admin_only_and_refuses_an_unknown_scope(): void
    {
        $paths = ['platform', 'platform/daily-pnl'];
        foreach ($paths as $path) {
            $this->getJson("/api/admin/insights/{$path}?scope=everyone", $this->headersFor($this->admin))->assertStatus(400);
        }

        // The guard keeps the first user it resolved for the whole test.
        $this->app['auth']->forgetGuards();
        foreach ($paths as $path) {
            $this->getJson("/api/admin/insights/{$path}", $this->headersFor($this->customerA))->assertForbidden();
        }
    }

    public function test_the_platform_pools_only_live_real_customer_and_master_money(): void
    {
        $p = $this->platform();

        $this->assertEquals(4100.0, $p['under_management']['customers']);
        $this->assertEquals(2, $p['under_management']['customer_accounts']);
        $this->assertEquals(6500.0, $p['under_management']['master']);
        $this->assertEquals(10600.0, $p['under_management']['balance']);
        $this->assertEquals(10605.0, $p['under_management']['equity']);

        $this->assertEquals(['net' => 140.0, 'gross' => 142.5, 'fees' => 2.5, 'trades' => 4], $p['pnl']['all']);
        $this->assertEquals(['net' => 10.0, 'gross' => 10.0, 'fees' => 0.0, 'trades' => 1], $p['pnl']['today']);
        $this->assertEquals(3, $p['accounts_live']);
        $this->assertEquals(3, $p['owners']);
        $this->assertEquals(1, $p['traders_today']);
        $this->assertEquals(75.0, $p['win_rate']); // 3 of 4 closes in profit
    }

    public function test_the_scope_chip_narrows_every_figure(): void
    {
        $customers = $this->platform('customers');
        $this->assertEquals(0.0, $customers['under_management']['master']);
        $this->assertEquals(40.0, $customers['pnl']['all']['net']); // 49.5 + 10 − 19.5

        $master = $this->platform('master');
        $this->assertEquals(0.0, $master['under_management']['customers']);
        $this->assertEquals(6500.0, $master['under_management']['master']);
        $this->assertEquals(100.0, $master['pnl']['all']['net']);
    }

    public function test_a_pooled_day_is_measured_on_the_pooled_starting_balance(): void
    {
        $day = $this->days()['2026-09-14'];

        $this->assertEquals(130.0, $day['total']);         // 49.5 − 19.5 + 100
        $this->assertEquals(132.5, $day['total_gross']);
        $this->assertEquals(10000.0, $day['start_balance']); // 1000 + 3000 + 6000
        $this->assertEquals(1.3, $day['pct']);
        $this->assertCount(3, $day['trades']);
        $this->assertEqualsCanonicalizing(['Alice', 'Bob', 'Master'], array_column($day['trades'], 'name'));

        $customers = $this->days('customers')['2026-09-14'];
        $this->assertEquals(4000.0, $customers['start_balance']);
        $this->assertEquals(0.75, $customers['pct']); // 30 / 4000

        $master = $this->days('master')['2026-09-14'];
        $this->assertEquals(1.67, $master['pct']); // 100 / 6000
    }

    public function test_a_per_user_calendar_does_not_name_owners(): void
    {
        $days = $this->getJson('/api/dashboard/daily-pnl', $this->headersFor($this->customerA))
            ->assertOk()->json('days');

        $this->assertArrayNotHasKey('name', $days['2026-09-14']['trades'][0]);
    }
}
