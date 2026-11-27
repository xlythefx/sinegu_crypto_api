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

    /**
     * Customer profit ranks by RETURN on the capital each day started with,
     * per calendar month, real money only, with the master beside it — so a
     * withdrawal after a loss cannot make it look bigger (Dima, 2026-11-11).
     */
    public function test_customer_profit_ranks_by_return_per_month_real_money_only(): void
    {
        \Illuminate\Support\Carbon::setTestNow('2026-11-11 12:00:00');
        $admin = $this->makeUser(['type' => 'admin']);
        $fee = ['exchange_fee' => 0, 'fee_source' => 'actual'];

        // Lost 500 on 10,000, then took 5,000 out: −5%, not −10% of what is left.
        $big = $this->makeUser(['name' => 'Big Loser']);
        $bigAcc = $this->makeAccount($big, ['initial_deposit' => 10000, 'balance' => 4500]);
        $this->closeTrade('binance', $bigAcc, ['realized_pnl' => -500, 'closed_at' => '2026-11-03 10:00:00'] + $fee);
        DB::table('binance_transactions')->insert([
            'uni_id' => $big, 'api_key' => DB::table('binance_accounts')->find($bigAcc)->api_key,
            'type' => 'WITHDRAWAL', 'amount' => 5000, 'currency' => 'USDT', 'created_at' => '2026-11-05 09:00:00',
        ]);

        // +10 in October on 1,000, then +20 in November on 1,010.
        $small = $this->makeUser(['name' => 'Small Winner']);
        $smallAcc = $this->makeAccount($small, ['initial_deposit' => 1000, 'balance' => 1030]);
        $this->closeTrade('binance', $smallAcc, ['realized_pnl' => 10, 'closed_at' => '2026-10-20 10:00:00'] + $fee);
        $this->closeTrade('binance', $smallAcc, ['realized_pnl' => 20, 'closed_at' => '2026-11-04 10:00:00'] + $fee);
        // Play money never counts.
        $demo = $this->makeAccount($small, ['demo' => 1], 'mexc');
        $this->closeTrade('mexc', $demo, ['realized_pnl' => -900, 'closed_at' => '2026-11-04 11:00:00'] + $fee);

        $master = $this->makeUser(['type' => 'master']);
        $masterAcc = $this->makeAccount($master, ['initial_deposit' => 20000]);
        $this->closeTrade('binance', $masterAcc, ['realized_pnl' => -400, 'closed_at' => '2026-11-03 10:00:00'] + $fee);

        $profit = $this->getJson('/api/admin/insights/customers', $this->headersFor($admin))
            ->assertOk()->json('profit');
        \Illuminate\Support\Carbon::setTestNow();

        $now = $profit['this_month'];
        $this->assertSame(['2026-11-01', '2026-11-11'], [$now['from'], $now['to']]);
        $this->assertSame(['Small Winner', 'Big Loser'], array_column($now['customers'], 'name'));
        $this->assertEquals(1.98, $now['customers'][0]['pct']);
        $this->assertEquals(20, $now['customers'][0]['pnl']);
        $this->assertEquals(-5.0, $now['customers'][1]['pct']);
        $this->assertEquals(-500, $now['customers'][1]['pnl']);
        $this->assertEquals(-2.0, $now['master']['pct']);

        $last = $profit['last_month'];
        $this->assertSame(['2026-10-01', '2026-10-31'], [$last['from'], $last['to']]);
        $this->assertSame(['Small Winner'], array_column($last['customers'], 'name'));
        $this->assertEquals(1.0, $last['customers'][0]['pct']);
        $this->assertNull($last['master']);
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

    public function test_test_feed_signals_never_count_on_the_dashboard(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);
        $tester = $this->makeUser(['type' => 'tester']);
        $row = fn (string $feed) => TradeLog::create([
            'exchange' => 'binance', 'feed' => $feed, 'action' => 'BUY', 'ticker' => 'LTCUSDT',
            'success' => false, 'strategy' => 'Provider-X', 'category' => 'signal', 'target_count' => 1,
            'filled' => 0, 'skipped' => 0, 'failed' => 1, 'ts' => now(),
            'details' => [['uni_id' => $tester, 'status' => 'failed', 'error' => 'boom']],
        ]);
        $row('test');
        $row('test');

        $overview = $this->getJson('/api/admin/insights/overview', $this->headersFor($admin))->assertOk();
        $this->assertSame(0, $overview->json('attention.signals_today.signals'));

        $system = $this->getJson('/api/admin/insights/system', $this->headersFor($admin))->assertOk();
        $binance = collect($system->json('venues'))->firstWhere('exchange', 'binance');
        $this->assertSame(0, $binance['signals_today']);
        $this->assertSame(0, $binance['failed_today']);
        $this->assertNull($binance['last_signal_at']);

        // The same rows on the main feed do count — the filter is the feed.
        \Illuminate\Support\Facades\Cache::flush();
        $row('main');
        $overview = $this->getJson('/api/admin/insights/overview', $this->headersFor($admin))->assertOk();
        $this->assertSame(1, $overview->json('attention.signals_today.signals'));
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

    /**
     * The Money tab's "Future invoice" runs the invoice's own math on the
     * running month without writing — so it must equal what generating the
     * invoice would store, and leave the invoices table untouched.
     */
    public function test_money_forecast_is_the_realized_fee_the_invoice_would_bill(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);
        $now = ['closed_at' => now()->toDateTimeString()];

        $winner = $this->makeUser(['name' => 'Winner', 'realized_percentage' => 25]);
        $winnerAcc = $this->makeAccount($winner, ['balance' => 1100, 'initial_deposit' => 1000]);
        $this->closeTrade('binance', $winnerAcc, ['realized_pnl' => 100] + $now);
        $winnerDemo = $this->makeAccount($winner, ['demo' => 1]);
        $this->closeTrade('binance', $winnerDemo, ['realized_pnl' => 999] + $now); // testnet: never billed

        // Profitable month, but equity still under the starting mark: no fee.
        $under = $this->makeUser(['name' => 'Under']);
        $underAcc = $this->makeAccount($under, ['balance' => 900, 'initial_deposit' => 1000]);
        $this->closeTrade('binance', $underAcc, ['realized_pnl' => 50] + $now);

        $master = $this->makeUser(['type' => 'master']);
        $this->closeTrade('binance', $this->makeAccount($master, ['balance' => 5000]), ['realized_pnl' => 500] + $now);

        $mexc = $this->makeUser();
        $this->makeAccount($mexc, [], 'mexc'); // no P&L source yet: counted, not guessed

        $f = $this->getJson('/api/admin/insights/money', $this->headersFor($admin))->assertOk()->json('forecast');

        $this->assertSame(0, DB::table('invoices')->count(), 'a forecast never writes an invoice');
        $this->assertSame(now()->format('Y-m'), $f['month']);
        $this->assertEquals(25, $f['total']);
        $this->assertSame(1, $f['billable_accounts']);
        $this->assertSame(['Winner', 'Under'], array_column($f['accounts'], 'name'));
        $this->assertSame('billable', $f['accounts'][0]['status']);
        $this->assertSame('below_hwm', $f['accounts'][1]['status']);
        $this->assertEquals(0, $f['accounts'][1]['fee']);
        $this->assertSame([['exchange' => 'mexc', 'accounts' => 1]], $f['not_supported']);

        $invoice = app(\App\Services\InvoiceService::class)->generateForAccount(
            \App\Models\BinanceAccount::find($winnerAcc), now()->format('Y-m'), ['realized' => 25, 'unrealized' => 6],
        );
        $this->assertEquals($f['accounts'][0]['fee'], round((float) $invoice->fee_realized, 2));
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

        $all = $this->getJson('/api/admin/strategies', $h)->json('trades');
        $this->assertCount(2, $all);

        // Each row names its owner and role, so the page can show whose trade
        // it was and narrow to the master (2026-11-19).
        $byRole = collect($all)->keyBy('owner_role');
        $this->assertSame(30.0, (float) $byRole['master']['realized_pnl']);
        $this->assertSame(UserCredential::find($master)->name, $byRole['master']['owner_name']);
        $this->assertSame(UserCredential::find($customer)->name, $byRole['user']['owner_name']);

        $compare = $this->getJson('/api/admin/insights/strategies', $h)->assertOk()->json('compare.ABCD-v1');
        $this->assertSame(1, $compare['master']['trades']);
        $this->assertSame(100.0, (float) $compare['master']['win_rate']);
        $this->assertSame(100.0, (float) $compare['customers'][0]['participation']);
        $this->assertSame(0.0, (float) $compare['customers'][0]['win_rate']);
    }

    public function test_all_scope_shows_tester_test_feed_trades_but_not_their_main_feed_history(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);
        $tester = $this->makeUser(['type' => 'tester']);
        $testerDemo = $this->makeAccount($tester, ['demo' => 1]);

        // Before the test feed: this account still followed the main feed.
        $this->closeTrade('binance', $testerDemo, ['strategy' => 'VWMA-Reversion', 'closed_at' => '2026-10-15 08:00:00']);
        // After: a test-feed strategy, demo money — still its own card.
        $this->closeTrade('binance', $testerDemo, ['strategy' => 'QuantLab', 'realized_pnl' => -3, 'closed_at' => '2026-10-16 09:00:00']);

        $h = $this->headersFor($admin);
        $all = $this->getJson('/api/admin/strategies', $h)->assertOk()->json('trades');
        $this->assertSame(['QuantLab'], array_column($all, 'strategy'));

        // The master / customers scopes stay real money only.
        $this->assertCount(0, $this->getJson('/api/admin/strategies?scope=master', $h)->json('trades'));
        $this->assertCount(0, $this->getJson('/api/admin/strategies?scope=customers', $h)->json('trades'));
        $this->assertCount(0, $this->getJson('/api/admin/strategies?exchange=mexc', $h)->json('trades'));
    }

    public function test_all_scope_shows_the_submasters_quantlab_trades_from_when_quantlab_started(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);
        $master = $this->makeUser(['type' => 'master']);
        $this->closeTrade('binance', $this->makeAccount($master), ['strategy' => 'VWMA-Reversion']);
        $sub = $this->makeUser(['type' => 'submaster']);
        $subLive = $this->makeAccount($sub);
        $subDemo = $this->makeAccount($sub, ['demo' => 1]);

        // 19 Nov: TradingView alerts under the main strategy's name — before QuantLab.
        $this->closeTrade('binance', $subLive, ['strategy' => 'VWMA-Reversion', 'closed_at' => '2026-11-19 20:00:00']);
        // QuantLab's, real money: its own card.
        $this->closeTrade('binance', $subLive, ['strategy' => 'VWMA-Reversion - QuantLab', 'realized_pnl' => -4, 'closed_at' => '2026-11-21 09:00:00']);
        // Play money never counts.
        $this->closeTrade('binance', $subDemo, ['strategy' => 'VWMA-Reversion - QuantLab', 'closed_at' => '2026-11-21 10:00:00']);

        $h = $this->headersFor($admin);
        $all = collect($this->getJson('/api/admin/strategies', $h)->assertOk()->json('trades'));
        $this->assertSame(['VWMA-Reversion', 'VWMA-Reversion - QuantLab'], $all->pluck('strategy')->sort()->values()->all());
        $quantlab = $all->firstWhere('strategy', 'VWMA-Reversion - QuantLab');
        $this->assertSame('submaster', $quantlab['owner_role']);
        $this->assertSame(-4.0, (float) $quantlab['realized_pnl']);

        // The master / customers scopes never carry it.
        $this->assertSame(['VWMA-Reversion'], array_column($this->getJson('/api/admin/strategies?scope=master', $h)->json('trades'), 'strategy'));
        $this->assertCount(0, $this->getJson('/api/admin/strategies?scope=customers', $h)->json('trades'));
    }

    public function test_the_submasters_earlier_quantlab_trades_are_renamed_and_nobody_elses(): void
    {
        $master = $this->makeUser(['type' => 'master']);
        $masterAcc = $this->makeAccount($master);
        $sub = $this->makeUser(['type' => 'submaster']);
        $subLive = $this->makeAccount($sub);

        $this->closeTrade('binance', $masterAcc, ['strategy' => 'VWMA-Reversion', 'closed_at' => '2026-11-22 09:00:00']);
        $this->closeTrade('binance', $subLive, ['strategy' => 'VWMA-Reversion', 'closed_at' => '2026-11-19 20:00:00']);
        $this->closeTrade('binance', $subLive, ['strategy' => 'VWMA-Reversion', 'closed_at' => '2026-11-22 09:00:00']);
        $subKey = DB::table('binance_accounts')->where('id', $subLive)->value('api_key');
        $masterKey = DB::table('binance_accounts')->where('id', $masterAcc)->value('api_key');
        foreach ([$subKey, $masterKey] as $key) {
            DB::table('open_strategies')->insert([
                'exchange' => 'binance', 'api_key' => $key, 'symbol' => 'SANTOSUSDT',
                'position_side' => 'LONG', 'strategy' => 'VWMA-Reversion',
            ]);
        }

        $migration = require database_path('migrations/2026_11_27_000001_name_quantlab_submaster_trades.php');
        $migration->up();

        $labels = fn (string $key) => DB::table('binance_pastpositions')->where('api_key', $key)
            ->orderBy('closed_at')->pluck('strategy')->all();
        $this->assertSame(['VWMA-Reversion', 'VWMA-Reversion - QuantLab'], $labels($subKey));
        $this->assertSame(['VWMA-Reversion'], $labels($masterKey));
        $this->assertSame('VWMA-Reversion - QuantLab', DB::table('open_strategies')->where('api_key', $subKey)->value('strategy'));
        $this->assertSame('VWMA-Reversion', DB::table('open_strategies')->where('api_key', $masterKey)->value('strategy'));

        $migration->down();
        $this->assertSame(['VWMA-Reversion', 'VWMA-Reversion'], $labels($subKey));
        $this->assertSame('VWMA-Reversion', DB::table('open_strategies')->where('api_key', $subKey)->value('strategy'));
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
