<?php

namespace Tests\Feature;

use App\Models\UserCredential;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * The admin user-detail page's exchange pill: `?exchange=` on every read
 * /admin/users/{uniId}/* makes, and the users list carrying every venue's
 * accounts.
 *
 * Same fixture shape as DashboardExchangeFilterTest — one user, one Binance
 * and one MEXC account, each with its own trade, open position, deposit and
 * invoice in its own tables — because the admin's "MEXC" view must be the
 * user's own MEXC pill, figure for figure.
 */
class AdminUserExchangeFilterTest extends EngineTestCase
{
    private string $uniId;

    private int $binanceId;

    private int $mexcId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->uniId = $this->makeUser(['name' => 'Two Venue Trader']);
        $this->binanceId = $this->makeAccount($this->uniId, [
            'api_key' => 'binance-key', 'name' => 'Binance Main',
            'balance' => 1000, 'unrealized_pnl' => 10, 'initial_deposit' => 1000,
        ]);
        // Same id on purpose: the per-exchange tables number independently.
        $this->mexcId = $this->makeAccount($this->uniId, [
            'id' => $this->binanceId, 'api_key' => 'mexc-key', 'name' => 'MEXC Main',
            'balance' => 500, 'unrealized_pnl' => 5, 'initial_deposit' => 500,
        ], 'mexc');

        $this->close('binance', 'binance-key', 'ETHUSDT', 40, '2026-06-01 10:00:00');
        $this->close('mexc', 'mexc-key', 'BTCUSDT', 7, '2026-06-02 10:00:00');
        $this->open('binance', 'binance-key', 'ETHUSDT');
        $this->open('mexc', 'mexc-key', 'BTCUSDT');
        $this->deposit('binance', 'binance-key', 100);
        $this->deposit('mexc', 'mexc-key', 50);
        $this->invoice('binance', $this->binanceId, 'binance-key', 20, 1100);
        $this->invoice('mexc', $this->mexcId, 'mexc-key', 3, 560);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(UserCredential::query()->find($this->makeUser(['type' => 'admin'])));
    }

    private function close(string $exchange, string $apiKey, string $symbol, float $pnl, string $closedAt): void
    {
        DB::table("{$exchange}_pastpositions")->insert([
            'uni_id' => $this->uniId, 'api_key' => $apiKey, 'symbol' => $symbol,
            'position_side' => 'LONG', 'position_amt' => 1, 'entry_price' => 100,
            'exit_price' => 110, 'realized_pnl' => $pnl, 'side' => 'SELL',
            'strategy' => 'ABCD-v1', 'closed_at' => $closedAt,
        ]);
    }

    private function open(string $exchange, string $apiKey, string $symbol): void
    {
        DB::table("{$exchange}_positions")->insert([
            'uni_id' => $this->uniId, 'api_key' => $apiKey, 'symbol' => $symbol,
            'position_side' => 'LONG', 'position_amt' => 1, 'entry_price' => 100,
            'mark_price' => 101, 'unrealized_profit' => 1, 'notional' => 101, 'update_time' => 1,
        ]);
    }

    private function deposit(string $exchange, string $apiKey, float $amount): void
    {
        DB::table("{$exchange}_transactions")->insert([
            'uni_id' => $this->uniId, 'api_key' => $apiKey, 'type' => 'DEPOSIT',
            'amount' => $amount, 'currency' => 'USDT', 'created_at' => '2026-05-01 09:00:00',
        ]);
    }

    private function invoice(string $exchange, int $accountId, string $apiKey, float $fee, float $hwm): void
    {
        DB::table('invoices')->insert([
            'user_id' => $this->uniId, 'account_id' => $accountId, 'exchange' => $exchange,
            'api_key' => $apiKey, 'month_year' => now()->format('Y-m'),
            'total_fee' => $fee, 'status' => 'pending', 'hwm_after' => $hwm,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function summary(string $query = ''): array
    {
        return $this->getJson("/api/admin/users/{$this->uniId}/summary{$query}")
            ->assertOk()
            ->json('summary');
    }

    /* ============ summary ============ */

    public function test_all_pools_every_exchange(): void
    {
        $s = $this->summary();

        $this->assertSame('all', $s['exchange']);
        $this->assertSame(2, $s['accounts']);
        $this->assertEquals(1500, $s['balance']);
        $this->assertEquals(1515, $s['equity']);
        $this->assertEquals(47, $s['realized_pnl']);
        $this->assertEquals(150, $s['net_deposits']);
        $this->assertEquals(150, $s['capital_flow']['deposits']);
        $this->assertEquals(1650, $s['capital_flow']['capital']);
        $this->assertSame(2, $s['metrics']['total_trades']);
        $this->assertEquals(23, $s['commissions']['this_month']);
        $this->assertEquals(1515, $s['hwm']); // max(1100, 560) < equity 1515
    }

    public function test_a_venue_narrows_every_figure_to_that_venue(): void
    {
        $s = $this->summary('?exchange=mexc');

        $this->assertSame('mexc', $s['exchange']);
        $this->assertSame(1, $s['accounts']);
        $this->assertEquals(500, $s['balance']);
        $this->assertEquals(505, $s['equity']);
        $this->assertEquals(7, $s['realized_pnl']);
        $this->assertEquals(50, $s['net_deposits']);
        $this->assertEquals(550, $s['capital_flow']['capital']);
        $this->assertSame(1, $s['metrics']['total_trades']);
        $this->assertEquals(3, $s['commissions']['this_month']);
        $this->assertEquals(560, $s['hwm']); // MEXC's own invoice HWM, above its equity

        $b = $this->summary('?exchange=binance');
        $this->assertEquals(1010, $b['equity']);
        $this->assertEquals(40, $b['realized_pnl']);
        $this->assertEquals(20, $b['commissions']['this_month']);
        $this->assertEquals(1100, $b['hwm']);
    }

    public function test_the_filter_is_case_insensitive_and_bybit_is_refused(): void
    {
        $this->assertSame('binance', $this->summary('?exchange=Binance')['exchange']);

        foreach (['summary', 'daily-pnl', 'positions', 'invoices'] as $read) {
            $this->getJson("/api/admin/users/{$this->uniId}/{$read}?exchange=bybit")
                ->assertStatus(400)
                ->assertJsonPath('error', 'EXCHANGE_NOT_SUPPORTED');
        }
    }

    /* ============ the other reads ============ */

    public function test_daily_pnl_follows_the_filter(): void
    {
        $all = $this->getJson("/api/admin/users/{$this->uniId}/daily-pnl")->assertOk()->json('days');
        $this->assertSame(['2026-06-02', '2026-06-01'], array_keys($all));
        $this->assertSame('mexc', $all['2026-06-02']['trades'][0]['exchange']);

        $mexc = $this->getJson("/api/admin/users/{$this->uniId}/daily-pnl?exchange=mexc")->assertOk()->json('days');
        $this->assertSame(['2026-06-02'], array_keys($mexc));
        $this->assertEquals(7, $mexc['2026-06-02']['total']);
    }

    public function test_positions_merge_both_tables_and_name_each_rows_exchange(): void
    {
        $all = $this->getJson("/api/admin/users/{$this->uniId}/positions")->assertOk()->json();
        $this->assertSame('all', $all['exchange']);
        $this->assertCount(2, $all['positions']);
        $this->assertCount(2, $all['trades']);
        $this->assertSame(['MEXC', 'Binance'], array_column($all['trades'], 'broker')); // newest first
        $this->assertSame(['mexc', 'binance'], array_column($all['trades'], 'exchange'));
        // Joined to the RIGHT exchange's account: the MEXC trade names the MEXC
        // account, although a Binance account shares its id.
        $this->assertSame('MEXC Main', $all['trades'][0]['account_name']);
        $this->assertEquals(500, $all['trades'][0]['account_balance']);

        $mexc = $this->getJson("/api/admin/users/{$this->uniId}/positions?exchange=mexc")->assertOk()->json();
        $this->assertCount(1, $mexc['positions']);
        $this->assertSame('BTCUSDT', $mexc['positions'][0]['symbol']);
        $this->assertSame('MEXC', $mexc['positions'][0]['broker']);
        $this->assertCount(1, $mexc['trades']);
        $this->assertEquals(7, $mexc['trades'][0]['realized_pnl']);
    }

    public function test_invoices_follow_the_filter(): void
    {
        $all = $this->getJson("/api/admin/users/{$this->uniId}/invoices")->assertOk()->json('invoices');
        $this->assertCount(2, $all);

        $mexc = $this->getJson("/api/admin/users/{$this->uniId}/invoices?exchange=mexc")->assertOk()->json('invoices');
        $this->assertCount(1, $mexc);
        $this->assertSame('mexc', $mexc[0]['exchange']);
    }

    /* ============ the users list ============ */

    public function test_the_users_list_carries_every_exchanges_accounts(): void
    {
        DB::table('mexc_accounts')->where('api_key', 'mexc-key')->update(['deleted_at' => now()]);

        $user = collect($this->getJson('/api/admin/users')->assertOk()->json('users'))
            ->firstWhere('uni_id', $this->uniId);

        $accounts = collect($user['accounts'])->keyBy('exchange');
        $this->assertCount(2, $accounts, 'disconnected accounts are listed too');
        $this->assertSame('MEXC Main', $accounts['mexc']['name']);
        $this->assertNotNull($accounts['mexc']['deleted_at']);
        $this->assertSame('Binance Main', $accounts['binance']['name']);
        $this->assertEquals(1000, $accounts['binance']['balance']);
        $this->assertStringNotContainsString('mexc-key', json_encode($user));
    }
}
