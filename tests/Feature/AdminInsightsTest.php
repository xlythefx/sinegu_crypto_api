<?php

namespace Tests\Feature;

use App\Models\TradeLog;
use App\Models\UserCredential;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/admin/insights/* and /admin/strategies?scope= — the admin
 * dashboard's tabs. The rules pinned here are the scoping ones: play money
 * (demo, sandbox, SBXINV-) is never business, staff are never customers,
 * and every exchange is counted.
 */
class AdminInsightsTest extends EngineTestCase
{
    private function headersFor(string $uniId): array
    {
        $user = UserCredential::find($uniId);

        return ['Authorization' => 'Bearer '.$user->createToken('spa')->plainTextToken];
    }

    private function closeTrade(string $exchange, int $accountId, array $overrides = []): void
    {
        $account = DB::table("{$exchange}_accounts")->find($accountId);
        static $order = 700000;

        DB::table("{$exchange}_pastpositions")->insert(array_merge([
            'api_key' => $account->api_key,
            'uni_id' => $account->uni_id,
            'symbol' => 'LTCUSDT',
            'position_side' => 'LONG',
            'position_amt' => 10,
            'entry_price' => 50,
            'exit_price' => 52,
            'realized_pnl' => 20,
            'side' => 'SELL',
            'order_id' => ++$order,
            'closed_at' => now()->subHour()->toDateTimeString(),
            'strategy' => 'ABCD-v1',
            'is_sandbox' => 0,
            'created_at' => now(),
        ], $overrides));
    }

    public function test_every_tab_is_admin_only(): void
    {
        $user = $this->makeUser();

        foreach (['overview', 'customers', 'money', 'system', 'strategies'] as $tab) {
            $this->getJson("/api/admin/insights/{$tab}", $this->headersFor($user))->assertForbidden();
        }
    }

    public function test_every_tab_answers_for_an_admin(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);

        $this->getJson('/api/admin/insights/overview', $this->headersFor($admin))
            ->assertOk()->assertJsonStructure(['attention' => ['blocked_keys', 'signals_today'], 'headline']);
        $this->getJson('/api/admin/insights/customers', $this->headersFor($admin))
            ->assertOk()->assertJsonStructure(['funnel', 'active', 'by_exchange', 'most_missed_7d']);
        $this->getJson('/api/admin/insights/money', $this->headersFor($admin))
            ->assertOk()->assertJsonStructure(['monthly', 'totals', 'overdue', 'under_management']);
        $this->getJson('/api/admin/insights/system', $this->headersFor($admin))
            ->assertOk()->assertJsonStructure(['venues', 'payment_watcher']);
        $this->getJson('/api/admin/insights/strategies', $this->headersFor($admin))
            ->assertOk()->assertJsonStructure(['reliability', 'compare']);
    }

    /**
     * The suite's `array` store never serializes, so it hid the bug: on a
     * store that does (prod uses `database`), with `serializable_classes`
     * false, a cached Collection came back as an incomplete class and every
     * cache HIT served its lists as JSON objects.
     */
    public function test_a_cache_hit_serves_the_same_lists_as_the_miss(): void
    {
        config(['cache.default' => 'file', 'cache.serializable_classes' => false]);
        $admin = $this->makeUser(['type' => 'admin']);
        $customer = $this->makeUser(['name' => 'Blocked Bob']);
        $this->makeAccount($customer, ['key_status' => 'blocked', 'key_blocked_at' => now()->subDay()]);
        $headers = $this->headersFor($admin);
        $keys = ['overview', 'customers', 'money', 'system', 'strategies:all::'];
        $forget = fn () => array_map(
            fn ($k) => \Illuminate\Support\Facades\Cache::forget(\App\Http\Controllers\AdminInsightsController::CACHE_PREFIX.$k),
            $keys,
        );

        $forget();
        try {
            foreach (['overview', 'customers', 'money', 'system', 'strategies'] as $tab) {
                $miss = $this->getJson("/api/admin/insights/{$tab}", $headers)->assertOk()->getContent();
                $hit = $this->getJson("/api/admin/insights/{$tab}", $headers)->assertOk()->getContent();
                $this->assertSame($miss, $hit, "{$tab}: a cache hit must serve what the miss served");
            }
            $overview = json_decode($this->getJson('/api/admin/insights/overview', $headers)->getContent(), true);
            $this->assertTrue(array_is_list($overview['attention']['blocked_keys']));
            $this->assertSame('Blocked Bob', $overview['attention']['blocked_keys'][0]['owner']);
        } finally {
            $forget();
        }
    }

    /**
     * Sign-ups are approved FROM the Overview (its "waiting for approval"
     * card), so resolving one must drop the cached answer — otherwise the
     * approved user sits on the strip for up to a minute and gets clicked twice.
     */
    public function test_approving_or_rejecting_a_sign_up_clears_it_from_the_cached_overview(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);
        $first = $this->makeUser(['status' => 'pending', 'name' => 'Pending Pam']);
        $second = $this->makeUser(['status' => 'pending', 'name' => 'Pending Pete']);
        $headers = $this->headersFor($admin);

        $this->getJson('/api/admin/insights/overview', $headers)
            ->assertOk()->assertJsonPath('attention.pending_users_count', 2);

        $this->postJson("/api/admin/users/{$first}/accept", [], $headers)->assertOk();
        $this->getJson('/api/admin/insights/overview', $headers)
            ->assertOk()
            ->assertJsonPath('attention.pending_users_count', 1)
            ->assertJsonPath('attention.pending_users.0.name', 'Pending Pete');

        $this->postJson("/api/admin/users/{$second}/reject", [], $headers)->assertOk();
        $this->getJson('/api/admin/insights/overview', $headers)
            ->assertOk()->assertJsonPath('attention.pending_users_count', 0);
    }

    public function test_customer_funnel_ignores_staff_demo_and_sandbox_and_counts_every_venue(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);
        $this->makeAccount($admin); // staff: never a customer

        $binance = $this->makeUser();
        $this->makeAccount($binance);
        $mexc = $this->makeUser();
        $this->makeAccount($mexc, [], 'mexc');
        $bybit = $this->makeUser();
        $this->makeAccount($bybit, ['initial_deposit' => 200], 'bybit'); // below the gate

        $demoOnly = $this->makeUser();
        $this->makeAccount($demoOnly, ['demo' => 1]);
        $sandbox = $this->makeUser();
        $this->makeAccount($sandbox, ['is_sandbox' => 1]);
        $this->makeUser(['status' => 'pending']);

        $funnel = collect($this->getJson('/api/admin/insights/customers', $this->headersFor($admin))
            ->assertOk()->json('funnel'))->pluck('count', 'key');

        $this->assertSame(6, $funnel['signed_up']);
        $this->assertSame(5, $funnel['approved']);
        $this->assertSame(3, $funnel['connected']);
        $this->assertSame(2, $funnel['funded']);
    }

    public function test_overview_counts_blocked_keys_and_today_skip_reasons(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);
        $customer = $this->makeUser(['name' => 'Blocked Bob']);
        $this->makeAccount($customer, ['key_status' => 'blocked', 'key_blocked_at' => now()->subDay()]);

        TradeLog::create([
            'exchange' => 'binance', 'action' => 'BUY', 'ticker' => 'LTCUSDT', 'success' => false,
            'strategy' => 'ABCD-v1', 'category' => 'signal', 'target_count' => 3,
            'filled' => 1, 'skipped' => 2, 'failed' => 0, 'ts' => now(),
            'details' => [
                ['uni_id' => $customer, 'status' => 'skipped', 'reason' => 'api key blocked'],
                ['uni_id' => 'x', 'status' => 'skipped', 'reason' => 'deposit below minimum'],
                ['uni_id' => 'y', 'status' => 'filled'],
            ],
        ]);

        $res = $this->getJson('/api/admin/insights/overview', $this->headersFor($admin))->assertOk();

        $this->assertSame(1, $res->json('attention.blocked_keys_count'));
        $this->assertSame('Blocked Bob', $res->json('attention.blocked_keys.0.owner'));
        $this->assertSame(2, $res->json('attention.blocked_keys.0.days_left'));
        $this->assertSame(1, $res->json('attention.signals_today.reasons')['api key blocked']);
        $this->assertSame(1, $res->json('attention.signals_today.reasons')['deposit below minimum']);
        $this->assertSame(1, $res->json('headline.active_traders_today'));
    }

    public function test_money_excludes_scenario_invoices(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);
        $customer = $this->makeUser();
        $month = now()->format('Y-m');

        foreach ([[1, 'real-key', 40], [2, 'SBXINV-scratch', 999]] as [$accountId, $key, $fee]) {
            DB::table('invoices')->insert([
                'exchange' => 'binance', 'user_id' => $customer, 'account_id' => $accountId, 'api_key' => $key,
                'month_year' => $month, 'realized_pnl' => 200, 'unrealized_pnl' => 0,
                'hwm_before' => 0, 'hwm_after' => 200, 'fee_realized' => $fee, 'fee_unrealized' => 0,
                'total_fee' => $fee, 'status' => 'pending', 'due_date' => now()->addDays(7)->toDateString(),
                'created_at' => now(),
            ]);
        }

        $this->getJson('/api/admin/insights/money', $this->headersFor($admin))
            ->assertOk()
            ->assertJsonPath('totals.invoiced', 40)
            ->assertJsonPath('totals.outstanding', 40);
    }

    public function test_strategy_scope_separates_master_from_customers_across_venues(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);
        $master = $this->makeUser(['type' => 'master']);
        $masterAcc = $this->makeAccount($master);
        $masterDemo = $this->makeAccount($master, ['demo' => 1]);
        $customer = $this->makeUser();
        $custMexc = $this->makeAccount($customer, [], 'mexc');

        $this->closeTrade('binance', $masterAcc, ['realized_pnl' => 30]);
        $this->closeTrade('binance', $masterDemo, ['realized_pnl' => 999]); // testnet: never counted
        $this->closeTrade('mexc', $custMexc, ['symbol' => 'LTC_USDT', 'realized_pnl' => -5]);

        $h = $this->headersFor($admin);
        $masterTrades = $this->getJson('/api/admin/strategies?scope=master', $h)->assertOk()->json('trades');
        $this->assertCount(1, $masterTrades);
        $this->assertSame(30.0, (float) $masterTrades[0]['realized_pnl']);

        $customerTrades = $this->getJson('/api/admin/strategies?scope=customers', $h)->assertOk()->json('trades');
        $this->assertCount(1, $customerTrades);
        $this->assertSame('mexc', $customerTrades[0]['exchange']);

        $this->assertCount(2, $this->getJson('/api/admin/strategies', $h)->json('trades'));

        $compare = $this->getJson('/api/admin/insights/strategies', $h)->assertOk()->json('compare.ABCD-v1');
        $this->assertSame(1, $compare['master']['trades']);
        $this->assertSame(100.0, (float) $compare['master']['win_rate']);
        $this->assertSame(100.0, (float) $compare['customers'][0]['participation']);
        $this->assertSame(0.0, (float) $compare['customers'][0]['win_rate']);
    }

    public function test_admin_can_read_any_users_analytics(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);
        $master = $this->makeUser(['type' => 'master']);
        $acc = $this->makeAccount($master);
        $this->closeTrade('binance', $acc);

        $this->getJson("/api/admin/users/{$master}/analytics", $this->headersFor($admin))
            ->assertOk()->assertJsonPath('success', true)->assertJsonStructure(['analytics' => ['risk', 'quality']]);
        $this->getJson('/api/admin/users/nope/analytics', $this->headersFor($admin))->assertNotFound();
    }

    public function test_a_trader_cannot_read_another_users_analytics(): void
    {
        $master = $this->makeUser(['type' => 'master']);

        $this->getJson("/api/admin/users/{$master}/analytics", $this->headersFor($this->makeUser()))
            ->assertForbidden();
    }
}
