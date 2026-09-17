<?php

namespace Tests\Feature;

use App\Models\UserCredential;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * The dashboard's top-bar exchange pills: `?exchange=` on every trader read.
 *
 * One user, one Binance account and one MEXC account, each with its own
 * closed trade, open position and deposit in its own `{exchange}_*` tables.
 * "All" must pool both; a venue must show that venue alone; and a venue the
 * user has nothing on must read as "nothing connected", never as the other
 * venue's money under the wrong label.
 */
class DashboardExchangeFilterTest extends EngineTestCase
{
    private string $uniId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->uniId = $this->makeUser();
        $this->makeAccount($this->uniId, [
            'api_key' => 'binance-key', 'balance' => 1000, 'unrealized_pnl' => 10, 'initial_deposit' => 1000,
        ]);
        $this->makeAccount($this->uniId, [
            'api_key' => 'mexc-key', 'balance' => 500, 'unrealized_pnl' => 5, 'initial_deposit' => 500,
        ], 'mexc');

        $this->close('binance', 'binance-key', 'ETHUSDT', 40, '2026-06-01 10:00:00');
        $this->close('mexc', 'mexc-key', 'BTCUSDT', 7, '2026-06-02 10:00:00');
        $this->open('binance', 'binance-key', 'ETHUSDT');
        $this->open('mexc', 'mexc-key', 'BTCUSDT');
        $this->deposit('binance', 'binance-key', 100);
        $this->deposit('mexc', 'mexc-key', 50);

        Sanctum::actingAs(UserCredential::query()->find($this->uniId));
    }

    private function close(string $exchange, string $apiKey, string $symbol, float $pnl, string $closedAt): void
    {
        DB::table("{$exchange}_pastpositions")->insert([
            'uni_id' => $this->uniId,
            'api_key' => $apiKey,
            'symbol' => $symbol,
            'position_side' => 'LONG',
            'position_amt' => 1,
            'entry_price' => 100,
            'exit_price' => 110,
            'realized_pnl' => $pnl,
            'side' => 'SELL',
            'strategy' => 'ABCD-v1',
            'closed_at' => $closedAt,
        ]);
    }

    private function open(string $exchange, string $apiKey, string $symbol): void
    {
        DB::table("{$exchange}_positions")->insert([
            'uni_id' => $this->uniId,
            'api_key' => $apiKey,
            'symbol' => $symbol,
            'position_side' => 'LONG',
            'position_amt' => 1,
            'entry_price' => 100,
            'mark_price' => 101,
            'unrealized_profit' => 1,
            'notional' => 101,
            'update_time' => 1,
        ]);
    }

    private function deposit(string $exchange, string $apiKey, float $amount): void
    {
        DB::table("{$exchange}_transactions")->insert([
            'uni_id' => $this->uniId,
            'api_key' => $apiKey,
            'type' => 'DEPOSIT',
            'amount' => $amount,
            'currency' => 'USDT',
            'created_at' => '2026-05-01 09:00:00',
        ]);
    }

    private function summary(string $query = ''): array
    {
        return $this->getJson('/api/dashboard/summary'.$query)->assertOk()->json('summary');
    }

    /* ============ summary ============ */

    public function test_all_pools_every_exchange(): void
    {
        $s = $this->summary();

        $this->assertSame('all', $s['exchange']);
        $this->assertSame(['binance', 'mexc'], $s['exchanges']);
        $this->assertSame(2, $s['accounts']);
        $this->assertEquals(1515, $s['equity']);            // 1000+10 + 500+5
        $this->assertEquals(47, $s['realized_pnl']);         // 40 + 7
        $this->assertEquals(150, $s['net_deposits']);        // 100 + 50
        $this->assertEquals(1650, $s['pct_base']);           // 1500 initial + 150
        $this->assertSame(['Binance', 'MEXC'], array_column($s['commissions']['rows'], 'exchange'));
        $this->assertSame(['BTCUSDT', 'ETHUSDT'], collect($s['by_asset'])->pluck('id')->sort()->values()->all());
    }

    public function test_a_venue_narrows_every_figure_to_that_venue(): void
    {
        $s = $this->summary('?exchange=mexc');

        $this->assertSame('mexc', $s['exchange']);
        $this->assertSame(['mexc'], $s['exchanges']);
        $this->assertSame(1, $s['accounts']);
        $this->assertEquals(505, $s['equity']);
        $this->assertEquals(7, $s['realized_pnl']);
        $this->assertEquals(50, $s['net_deposits']);
        $this->assertEquals(550, $s['pct_base']);
        $this->assertSame(1, $s['metrics']['trades']);
        $this->assertSame(['MEXC'], array_column($s['commissions']['rows'], 'exchange'));
        $this->assertSame(['BTCUSDT'], array_column($s['by_asset'], 'id'));

        $b = $this->summary('?exchange=binance');
        $this->assertEquals(1010, $b['equity']);
        $this->assertEquals(40, $b['realized_pnl']);
        $this->assertSame(['ETHUSDT'], array_column($b['by_asset'], 'id'));
    }

    public function test_a_venue_the_user_has_nothing_on_reads_as_unconnected(): void
    {
        DB::table('mexc_accounts')->where('api_key', 'mexc-key')->update(['deleted_at' => now()]);

        $s = $this->summary('?exchange=mexc');

        $this->assertSame(0, $s['accounts']);
        $this->assertEquals(0, $s['equity']);
        $this->assertEquals(0, $s['realized_pnl']);  // the disconnected key's trade goes with it
        $this->assertSame([], $s['by_asset']);
    }

    public function test_the_filter_is_case_insensitive_and_bybit_is_refused(): void
    {
        $this->assertSame('mexc', $this->summary('?exchange=MEXC')['exchange']);
        $this->getJson('/api/dashboard/summary?exchange=bybit')
            ->assertStatus(400)
            ->assertJsonPath('error', 'EXCHANGE_NOT_SUPPORTED');
        $this->getJson('/api/dashboard/daily-pnl?exchange=kraken')->assertStatus(400);
        $this->getJson('/api/binance/positions?exchange=bybit')->assertStatus(400);
    }

    /* ============ the other dashboard reads ============ */

    public function test_daily_pnl_follows_the_filter_and_names_each_trades_exchange(): void
    {
        $all = $this->getJson('/api/dashboard/daily-pnl')->assertOk()->json('days');
        $this->assertSame(['2026-06-02', '2026-06-01'], array_keys($all)); // newest first, as before
        $this->assertSame('binance', $all['2026-06-01']['trades'][0]['exchange']);
        $this->assertSame('mexc', $all['2026-06-02']['trades'][0]['exchange']);

        $mexc = $this->getJson('/api/dashboard/daily-pnl?exchange=mexc')->assertOk()->json('days');
        $this->assertSame(['2026-06-02'], array_keys($mexc));
        $this->assertEquals(7, $mexc['2026-06-02']['total']);
    }

    public function test_asset_performance_follows_the_filter(): void
    {
        $all = $this->getJson('/api/dashboard/asset-performance')->assertOk()->json();
        $this->assertEquals(1515, $all['balance']);
        $this->assertSame(['ETHUSDT', 'BTCUSDT'], array_column($all['assets'], 'ticker')); // ranked by P&L

        $mexc = $this->getJson('/api/dashboard/asset-performance?exchange=mexc')->assertOk()->json();
        $this->assertEquals(505, $mexc['balance']);
        $this->assertSame(['BTCUSDT'], array_column($mexc['assets'], 'ticker'));
    }

    public function test_positions_merge_both_tables_and_stamp_the_exchange(): void
    {
        $open = $this->getJson('/api/binance/positions')->assertOk()->json('positions');
        $this->assertSame(['mexc', 'binance'], array_column($open, 'exchange')); // ordered by symbol: BTC, ETH

        $closed = $this->getJson('/api/binance/past-positions')->assertOk()->json('positions');
        $this->assertSame(['mexc', 'binance'], array_column($closed, 'exchange')); // newest first

        $onlyMexc = $this->getJson('/api/binance/past-positions?exchange=mexc')->assertOk()->json('positions');
        $this->assertCount(1, $onlyMexc);
        $this->assertSame('BTCUSDT', $onlyMexc[0]['symbol']);
    }

    public function test_a_binance_key_is_never_looked_up_in_the_mexc_tables(): void
    {
        // Same api_key string on both venues (cannot happen — keys are venue
        // specific — but it is the exact failure a flat whereIn would allow):
        // the MEXC row must only ever pair with MEXC accounts.
        DB::table('mexc_pastpositions')->insert([
            'uni_id' => $this->uniId,
            'api_key' => 'binance-key',
            'symbol' => 'SOLUSDT',
            'position_side' => 'LONG',
            'position_amt' => 1,
            'entry_price' => 1,
            'exit_price' => 2,
            'realized_pnl' => 999,
            'side' => 'SELL',
            'closed_at' => '2026-06-03 10:00:00',
        ]);

        $this->assertEquals(40, $this->summary('?exchange=binance')['realized_pnl']);
        $this->assertEquals(47, $this->summary()['realized_pnl']);
    }
}
