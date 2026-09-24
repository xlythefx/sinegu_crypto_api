<?php

namespace Tests\Feature;

use App\Models\UserCredential;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * Covers GET /api/analytics — specifically that each query filter actually
 * narrows the closed-trade set feeding every metric.
 *
 * These filters used to be accepted and silently ignored, so most assertions
 * here are simply "the number moved when it should have".
 *
 * Money and percentages are compared with assertEquals, not assertSame: PHP
 * serializes round(140.0) as the JSON integer 140, so a strict float compare
 * would fail on values that are numerically correct.
 */
class AnalyticsFiltersTest extends EngineTestCase
{
    private string $uniId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->uniId = $this->makeUser();
        // api_key pinned to the one trade()/transaction() below write, so the
        // seeded rows actually belong to this account. Analytics scopes trades
        // by the user's connected accounts, not by uni_id alone.
        $this->makeAccount($this->uniId, [
            'balance' => 1000,
            'unrealized_pnl' => 50,
            'api_key' => 'test-api-key',
        ]);
        Sanctum::actingAs(UserCredential::query()->find($this->uniId));
    }

    private function trade(string $symbol, ?string $strategy, string $closedAt, float $pnl): void
    {
        DB::table('binance_pastpositions')->insert([
            'uni_id' => $this->uniId,
            'api_key' => 'test-api-key',
            'symbol' => $symbol,
            'position_side' => 'LONG',
            'position_amt' => 1,
            'entry_price' => 100,
            'exit_price' => 110,
            'realized_pnl' => $pnl,
            'side' => 'BUY',
            'strategy' => $strategy,
            'closed_at' => $closedAt,
        ]);
    }

    private function transaction(float $amount, string $type = 'DEPOSIT'): void
    {
        DB::table('binance_transactions')->insert([
            'uni_id' => $this->uniId,
            'api_key' => 'test-api-key',
            'type' => $type,
            'amount' => $amount,
            'currency' => 'USDT',
            'created_at' => '2026-01-01 09:00:00',
        ]);
    }

    /** $1,000 deposited, four closed trades netting +$140 across four months. */
    private function seedTrades(): void
    {
        $this->transaction(1000);
        $this->trade('BTCUSDT', 'ABCD-v1', '2026-01-10 12:00:00', 100);
        $this->trade('ETHUSDT', 'ABCD-v1', '2026-02-10 12:00:00', -40);
        $this->trade('BTCUSDT', 'Scalper', '2026-03-10 12:00:00', 60);
        $this->trade('SOLUSDT', null, '2026-04-10 12:00:00', 20);
    }

    private function analytics(array $query = []): array
    {
        return $this->getJson('/api/analytics?'.http_build_query($query))
            ->assertOk()
            ->json('analytics');
    }

    /* ============ baseline ============ */

    public function test_unfiltered_totals_include_unrealized(): void
    {
        $this->seedTrades();

        $a = $this->analytics();

        $this->assertEquals(140, $a['total_realized']);
        // realized 140 + unrealized 50, over 1000 deposited
        $this->assertEquals(190, $a['total_return_abs']);
        $this->assertEquals(19, $a['total_return_pct']);
        $this->assertFalse($a['filters']['filtered']);
        $this->assertSame(4, $a['trading_days']);
    }

    public function test_chip_lists_cover_every_trade_including_untagged(): void
    {
        $this->seedTrades();

        $a = $this->analytics();

        $this->assertSame(['BTCUSDT', 'ETHUSDT', 'SOLUSDT'], $a['filters']['available_symbols']);
        $this->assertSame(['ABCD-v1', 'Scalper', 'Untagged'], $a['filters']['available_strategies']);
    }

    /* ============ date range ============ */

    public function test_date_range_narrows_every_metric(): void
    {
        $this->seedTrades();

        $a = $this->analytics(['from' => '2026-02-01', 'to' => '2026-03-31']);

        // -40 (Feb) + 60 (Mar); January and April are out of range
        $this->assertEquals(20, $a['total_realized']);
        $this->assertSame(2, $a['trading_days']);
        $this->assertSame(2, $a['quality']['wins'] + $a['quality']['losses']);
    }

    public function test_date_bounds_are_inclusive(): void
    {
        $this->seedTrades();

        $a = $this->analytics(['from' => '2026-01-10', 'to' => '2026-01-10']);

        $this->assertEquals(100, $a['total_realized']);
        $this->assertSame(1, $a['trading_days']);
    }

    public function test_malformed_date_is_ignored_rather_than_rejected(): void
    {
        $this->seedTrades();

        $a = $this->analytics(['from' => 'last-tuesday']);

        $this->assertEquals(140, $a['total_realized']);
    }

    /* ============ symbol chips ============ */

    public function test_symbols_default_to_exclude(): void
    {
        $this->seedTrades();

        $a = $this->analytics(['symbols' => ['ETHUSDT']]);

        // everything except the -40 ETH trade
        $this->assertEquals(180, $a['total_realized']);
        $this->assertTrue($a['filters']['filtered']);
    }

    public function test_symbols_include_mode_keeps_only_the_selection(): void
    {
        $this->seedTrades();

        $a = $this->analytics(['symbols' => ['BTCUSDT'], 'symbol_mode' => 'include']);

        $this->assertEquals(160, $a['total_realized']);
        $this->assertSame(2, $a['quality']['wins']);
    }

    public function test_excluded_symbol_still_appears_in_the_chip_list(): void
    {
        $this->seedTrades();

        $a = $this->analytics(['symbols' => ['ETHUSDT']]);

        // else the chip would vanish the moment it was clicked
        $this->assertContains('ETHUSDT', $a['filters']['available_symbols']);
    }

    /* ============ strategy chips ============ */

    public function test_strategy_include_mode(): void
    {
        $this->seedTrades();

        $a = $this->analytics(['strategies' => ['ABCD-v1'], 'strategy_mode' => 'include']);

        $this->assertEquals(60, $a['total_realized']);
    }

    public function test_untagged_trades_are_filterable(): void
    {
        $this->seedTrades();

        $a = $this->analytics(['strategies' => ['Untagged']]);

        // excludes the null-strategy +20 trade
        $this->assertEquals(120, $a['total_realized']);
    }

    public function test_symbol_and_strategy_chips_compose(): void
    {
        $this->seedTrades();

        $a = $this->analytics([
            'symbols' => ['BTCUSDT'],
            'symbol_mode' => 'include',
            'strategies' => ['Scalper'],
            'strategy_mode' => 'include',
        ]);

        $this->assertEquals(60, $a['total_realized']);
    }

    /* ============ filtered headline ============ */

    public function test_chip_filter_drops_unrealized_from_the_headline(): void
    {
        $this->seedTrades();

        $a = $this->analytics(['symbols' => ['ETHUSDT']]);

        // open positions carry no strategy tag and cannot be attributed to a
        // chip selection, so the headline becomes filtered realized only
        $this->assertEquals(180, $a['total_return_abs']);
        $this->assertEquals(18, $a['total_return_pct']);
    }

    /* ============ return on deposit ============ */

    public function test_return_on_deposit_is_realized_over_deposits(): void
    {
        $this->seedTrades();

        $rod = $this->analytics()['return_on_deposit'];

        $this->assertEquals(14, $rod['pct']);
        $this->assertEquals(140, $rod['realized']);
        $this->assertEquals(1000, $rod['deposits']);
        $this->assertSame(4, $rod['trades']);
    }

    public function test_return_on_deposit_ignores_withdrawals(): void
    {
        $this->seedTrades();
        $this->transaction(400, 'WITHDRAWAL');

        $rod = $this->analytics()['return_on_deposit'];

        // denominator stays the 1000 paid in, not the 600 net
        $this->assertEquals(1000, $rod['deposits']);
        $this->assertEquals(14, $rod['pct']);
    }

    public function test_return_on_deposit_ignores_the_date_range(): void
    {
        $this->seedTrades();

        $a = $this->analytics(['from' => '2026-02-01', 'to' => '2026-03-31']);

        // a deposit is a lifetime concept, so this card stays all-time even
        // though every other metric on the page narrowed
        $this->assertEquals(20, $a['total_realized']);
        $this->assertEquals(14, $a['return_on_deposit']['pct']);
        $this->assertSame(4, $a['return_on_deposit']['trades']);
    }

    public function test_return_on_deposit_honors_the_chip_filters(): void
    {
        $this->seedTrades();

        $rod = $this->analytics(['symbols' => ['ETHUSDT']])['return_on_deposit'];

        $this->assertEquals(18, $rod['pct']);
        $this->assertEquals(180, $rod['realized']);
        $this->assertSame(3, $rod['trades']);
    }

    public function test_return_on_deposit_is_null_without_deposits(): void
    {
        $this->trade('BTCUSDT', 'ABCD-v1', '2026-01-10 12:00:00', 100);

        $rod = $this->analytics()['return_on_deposit'];

        $this->assertNull($rod['pct']);
        $this->assertEquals(0, $rod['deposits']);
    }

    /* ============ exchange ============ */

    public function test_a_venue_with_no_account_returns_an_empty_dataset(): void
    {
        $this->seedTrades();

        // This user has no MEXC account — the MEXC view is empty, never
        // Binance's numbers under a MEXC label.
        $a = $this->analytics(['exchange' => 'mexc']);

        $this->assertEquals(0, $a['total_realized']);
        $this->assertSame(0, $a['trading_days']);
        $this->assertSame([], $a['filters']['available_symbols']);
        $this->assertNull($a['return_on_deposit']['pct']);
    }

    public function test_a_venue_without_tables_is_refused(): void
    {
        // Bybit gained its tables on 2026-09-24, so the refusal now needs a
        // name that really has none.
        $this->getJson('/api/analytics?exchange=kraken')
            ->assertStatus(400)
            ->assertJsonPath('error', 'EXCHANGE_NOT_SUPPORTED');

        $this->getJson('/api/analytics?exchange=bybit')->assertOk();
    }

    public function test_mexc_trades_are_read_from_the_mexc_tables(): void
    {
        $this->seedTrades();
        $this->makeAccount($this->uniId, ['api_key' => 'mexc-key', 'balance' => 500], 'mexc');
        DB::table('mexc_pastpositions')->insert([
            'uni_id' => $this->uniId,
            'api_key' => 'mexc-key',
            'symbol' => 'BTCUSDT',
            'position_side' => 'LONG',
            'position_amt' => 0.01,
            'entry_price' => 100,
            'exit_price' => 110,
            'realized_pnl' => 7,
            'side' => 'SELL',
            'strategy' => 'ABCD-v1',
            'closed_at' => '2026-05-10 12:00:00',
        ]);

        $mexc = $this->analytics(['exchange' => 'mexc']);
        $this->assertEquals(7, $mexc['total_realized']);
        $this->assertSame(1, $mexc['trading_days']);
        $this->assertSame(['MEXC'], array_column($mexc['by_exchange'], 'exchange'));
        $this->assertEquals(500, $mexc['by_exchange'][0]['balance']);

        // "All" pools both venues: Binance's +140 plus MEXC's +7.
        $all = $this->analytics();
        $this->assertEquals(147, $all['total_realized']);
        $this->assertSame(['Binance', 'Bybit', 'MEXC'], array_column($all['by_exchange'], 'exchange'));
    }

    /**
     * Narrowing to a venue the user never connected is a legitimate page of
     * zeros — indistinguishable from "this venue made nothing" unless the
     * payload says how many accounts are in scope. Without it the filter reads
     * as broken.
     */
    public function test_by_exchange_reports_how_many_accounts_are_in_scope(): void
    {
        $this->seedTrades();

        $bybit = $this->analytics(['exchange' => 'bybit']);
        $this->assertSame(['Bybit'], array_column($bybit['by_exchange'], 'exchange'));
        $this->assertSame(0, $bybit['by_exchange'][0]['accounts']);
        $this->assertEquals(0, $bybit['by_exchange'][0]['balance']);

        $binance = $this->analytics(['exchange' => 'binance']);
        $this->assertGreaterThan(0, $binance['by_exchange'][0]['accounts']);

        $all = $this->analytics();
        $counts = array_column($all['by_exchange'], 'accounts', 'exchange');
        $this->assertGreaterThan(0, $counts['Binance']);
        $this->assertSame(0, $counts['Bybit']);
    }

    public function test_binance_exchange_matches_all(): void
    {
        $this->seedTrades();

        $this->assertEquals(
            $this->analytics()['total_realized'],
            $this->analytics(['exchange' => 'binance'])['total_realized'],
        );
    }

    /* ============ empty ============ */

    public function test_no_trades_does_not_error(): void
    {
        $a = $this->analytics();

        $this->assertEquals(0, $a['total_realized']);
        $this->assertSame(0, $a['trading_days']);
        $this->assertNull($a['best_day']);
        $this->assertSame([], $a['filters']['available_strategies']);
    }
}
