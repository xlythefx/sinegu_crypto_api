<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Covers GET /api/public/track-record — the unauthenticated landing-page feed.
 *
 * The load-bearing assertion is the privacy one: this endpoint must never leak
 * a balance, a USD amount, an account name or a uni_id, because anyone on the
 * internet can read it.
 */
class PublicTrackRecordTest extends EngineTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();  // the endpoint caches for 5 minutes
    }

    private function makeTrade(string $uniId, string $closedAt, float $pnl): void
    {
        DB::table('binance_pastpositions')->insert([
            'uni_id' => $uniId,
            'api_key' => 'test-api-key',
            'symbol' => 'BTCUSDT',
            'position_side' => 'LONG',
            'position_amt' => 0.01,
            'entry_price' => 100000,
            'exit_price' => 101000,
            'realized_pnl' => $pnl,
            'side' => 'BUY',
            'closed_at' => $closedAt,
        ]);
    }

    private function makeDeposit(string $uniId, string $at, float $amount, string $type = 'DEPOSIT'): void
    {
        DB::table('binance_transactions')->insert([
            'uni_id' => $uniId,
            'api_key' => 'test-api-key',
            'type' => $type,
            'amount' => $amount,
            'currency' => 'USDT',
            'created_at' => $at,
        ]);
    }

    /* ============ availability ============ */

    public function test_no_master_account_reports_unavailable_not_an_error(): void
    {
        $this->getJson('/api/public/track-record')
            ->assertOk()
            ->assertJson(['success' => true, 'available' => false, 'stats' => null, 'series' => []]);
    }

    public function test_master_without_closed_trades_reports_unavailable(): void
    {
        $uniId = $this->makeUser(['type' => 'master']);
        $this->makeDeposit($uniId, '2026-01-01 09:00:00', 1000);

        $this->getJson('/api/public/track-record')
            ->assertOk()
            ->assertJson(['available' => false]);
    }

    public function test_trades_before_any_deposit_are_skipped(): void
    {
        $uniId = $this->makeUser(['type' => 'master']);
        $this->makeTrade($uniId, '2026-01-05 12:00:00', 50);  // no capital yet

        $this->getJson('/api/public/track-record')
            ->assertOk()
            ->assertJson(['available' => false]);
    }

    /* ============ the maths ============ */

    public function test_daily_percentages_are_measured_against_that_day_capital(): void
    {
        $uniId = $this->makeUser(['type' => 'master']);
        $this->makeDeposit($uniId, '2026-01-01 09:00:00', 1000);
        $this->makeTrade($uniId, '2026-01-02 12:00:00', 100);   // +10% on 1000
        $this->makeTrade($uniId, '2026-01-03 12:00:00', -110);  // -10% on 1100
        $this->makeTrade($uniId, '2026-01-03 15:00:00', 55);    //  +5% on 1100 (same day)

        $body = $this->getJson('/api/public/track-record')->assertOk()->json();

        $this->assertTrue($body['available']);
        $this->assertEquals(
            [['date' => '2026-01-02', 'pct' => 10.0, 'cumulative' => 10.0, 'trades' => 1],
                ['date' => '2026-01-03', 'pct' => -5.0, 'cumulative' => 5.0, 'trades' => 2]],
            $body['series'],
        );
        $this->assertEquals(5.0, $body['stats']['total_pnl_pct']);
        $this->assertSame(3, $body['stats']['trades']);
        $this->assertSame(2, $body['stats']['trading_days']);
        $this->assertEquals(50.0, $body['stats']['win_rate']);      // 1 of 2 days
        $this->assertEquals(2.5, $body['stats']['avg_daily_pct']);  // (10 - 5) / 2
        $this->assertEquals(10.0, $body['stats']['avg_win_pct']);
        $this->assertEquals(-5.0, $body['stats']['avg_loss_pct']);
    }

    public function test_a_mid_history_deposit_does_not_rewrite_earlier_days(): void
    {
        $uniId = $this->makeUser(['type' => 'master']);
        $this->makeDeposit($uniId, '2026-01-01 09:00:00', 1000);
        $this->makeTrade($uniId, '2026-01-02 12:00:00', 100);   // +10% on 1000
        $this->makeDeposit($uniId, '2026-01-03 09:00:00', 8900);
        $this->makeTrade($uniId, '2026-01-04 12:00:00', 1000);  // +10% on 10,000

        $body = $this->getJson('/api/public/track-record')->assertOk()->json();

        $this->assertEquals(10.0, $body['series'][0]['pct']);   // unchanged by the top-up
        $this->assertEquals(10.0, $body['series'][1]['pct']);
        $this->assertEquals(20.0, $body['stats']['total_pnl_pct']);
    }

    public function test_withdrawals_shrink_the_base_for_later_days(): void
    {
        $uniId = $this->makeUser(['type' => 'master']);
        $this->makeDeposit($uniId, '2026-01-01 09:00:00', 1000);
        $this->makeDeposit($uniId, '2026-01-02 09:00:00', 500, 'WITHDRAWAL');
        $this->makeTrade($uniId, '2026-01-02 12:00:00', 50);  // +10% on 500

        $body = $this->getJson('/api/public/track-record')->assertOk()->json();

        $this->assertEquals(10.0, $body['series'][0]['pct']);
    }

    public function test_losing_only_history_reports_null_average_win(): void
    {
        $uniId = $this->makeUser(['type' => 'master']);
        $this->makeDeposit($uniId, '2026-01-01 09:00:00', 1000);
        $this->makeTrade($uniId, '2026-01-02 12:00:00', -100);

        $stats = $this->getJson('/api/public/track-record')->assertOk()->json('stats');

        $this->assertNull($stats['avg_win_pct']);
        $this->assertEquals(-10.0, $stats['avg_loss_pct']);
        $this->assertEquals(0.0, $stats['win_rate']);
    }

    /* ============ privacy ============ */

    public function test_needs_no_token_and_leaks_no_amounts_or_identity(): void
    {
        $uniId = $this->makeUser(['type' => 'master', 'name' => 'Master Trader', 'email' => 'master@example.com']);
        $this->makeAccount($uniId, ['name' => 'Master Live', 'balance' => 12345.67]);
        $this->makeDeposit($uniId, '2026-01-01 09:00:00', 1000);
        $this->makeTrade($uniId, '2026-01-02 12:00:00', 100);

        $raw = $this->getJson('/api/public/track-record')->assertOk()->content();

        foreach (['12345', 'Master Live', 'Master Trader', 'master@example.com', $uniId,
            'balance', 'realized_pnl', 'api_key', 'equity'] as $secret) {
            $this->assertStringNotContainsString($secret, $raw, "leaked: {$secret}");
        }
    }

    public function test_other_users_trades_are_not_counted(): void
    {
        $master = $this->makeUser(['type' => 'master']);
        $this->makeDeposit($master, '2026-01-01 09:00:00', 1000);
        $this->makeTrade($master, '2026-01-02 12:00:00', 100);

        $other = $this->makeUser();
        $this->makeDeposit($other, '2026-01-01 09:00:00', 1000);
        $this->makeTrade($other, '2026-01-02 12:00:00', 900);

        $body = $this->getJson('/api/public/track-record')->assertOk()->json();

        $this->assertSame(1, $body['stats']['trades']);
        $this->assertEquals(10.0, $body['stats']['total_pnl_pct']);
    }
}
