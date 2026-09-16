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

    /** The master's real account key — what the record is scoped to. */
    private const KEY = 'test-api-key';

    /**
     * A master user plus one REAL binance_accounts row keyed {@see self::KEY}.
     * `initial_deposit` is 0 by default so these fixtures keep measuring
     * against their explicit deposit rows; the seeding of capital from
     * `initial_deposit` gets its own test below.
     */
    private function makeMaster(array $accountOverrides = []): string
    {
        $uniId = $this->makeUser(['type' => 'master']);
        $this->makeAccount($uniId, array_merge([
            'api_key' => self::KEY,
            'initial_deposit' => 0,
        ], $accountOverrides));

        return $uniId;
    }

    private function makeTrade(string $uniId, string $closedAt, float $pnl, string $apiKey = self::KEY, string $symbol = 'BTCUSDT', ?int $increments = null): void
    {
        DB::table('binance_pastpositions')->insert([
            'uni_id' => $uniId,
            'api_key' => $apiKey,
            'symbol' => $symbol,
            'position_side' => 'LONG',
            'position_amt' => 0.01,
            'increments_closed' => $increments,
            'entry_price' => 100000,
            'exit_price' => 101000,
            'realized_pnl' => $pnl,
            'side' => 'BUY',
            'closed_at' => $closedAt,
        ]);
    }

    private function makeDeposit(string $uniId, string $at, float $amount, string $type = 'DEPOSIT', string $apiKey = self::KEY): void
    {
        DB::table('binance_transactions')->insert([
            'uni_id' => $uniId,
            'api_key' => $apiKey,
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
        $uniId = $this->makeMaster();
        $this->makeDeposit($uniId, '2026-01-01 09:00:00', 1000);

        $this->getJson('/api/public/track-record')
            ->assertOk()
            ->assertJson(['available' => false]);
    }

    public function test_trades_before_any_deposit_are_skipped(): void
    {
        $uniId = $this->makeMaster();
        $this->makeTrade($uniId, '2026-01-05 12:00:00', 50);  // no capital yet

        $this->getJson('/api/public/track-record')
            ->assertOk()
            ->assertJson(['available' => false]);
    }

    /* ============ the maths ============ */

    public function test_daily_percentages_are_measured_against_that_day_capital(): void
    {
        $uniId = $this->makeMaster();
        $this->makeDeposit($uniId, '2026-01-01 09:00:00', 1000);
        $this->makeTrade($uniId, '2026-01-02 12:00:00', 100);   // +10% on 1000
        $this->makeTrade($uniId, '2026-01-03 12:00:00', -110);  // -10% on 1100
        $this->makeTrade($uniId, '2026-01-03 15:00:00', 55);    //  +5% on 1100 (same day)

        $body = $this->getJson('/api/public/track-record')->assertOk()->json();

        $this->assertTrue($body['available']);
        $this->assertEquals(
            // cumulative is CHAINED: 1.10 × 0.95 = 1.045, not 10 − 5.
            // roc is realized P&L over the 1000 ever contributed: +100, then
            // +45. It coincides with cumulative ONLY because all the capital
            // arrived before the first trade — see the mid-history test below.
            [['date' => '2026-01-02', 'pct' => 10.0, 'cumulative' => 10.0, 'roc' => 10.0, 'trades' => 1,
                'assets' => [['symbol' => 'BTCUSDT', 'pct' => 10.0, 'trades' => 1]]],
                ['date' => '2026-01-03', 'pct' => -5.0, 'cumulative' => 4.5, 'roc' => 4.5, 'trades' => 2,
                    'assets' => [['symbol' => 'BTCUSDT', 'pct' => -5.0, 'trades' => 2]]]],
            $body['series'],
        );
        $this->assertEquals(4.5, $body['stats']['total_pnl_pct']);
        $this->assertEquals(4.5, $body['stats']['return_on_capital_pct']);
        $this->assertSame(3, $body['stats']['trades']);
        $this->assertSame(2, $body['stats']['trading_days']);
        $this->assertEquals(50.0, $body['stats']['win_rate']);      // 1 of 2 days
        $this->assertEquals(2.5, $body['stats']['avg_daily_pct']);  // (10 - 5) / 2
        $this->assertEquals(10.0, $body['stats']['avg_win_pct']);
        $this->assertEquals(-5.0, $body['stats']['avg_loss_pct']);
    }

    /**
     * Each day carries its own leaderboard: every symbol's share of that day's
     * return, best first, measured on the day's capital so the shares add up
     * to the day's `pct`. The order is per DAY — yesterday's winner is not
     * today's — which is what the Telegram daily recap ranks with medals.
     */
    public function test_each_day_ranks_its_assets_by_their_share_of_the_return(): void
    {
        $uniId = $this->makeMaster();
        $this->makeDeposit($uniId, '2026-01-01 09:00:00', 1000);
        $this->makeTrade($uniId, '2026-01-02 10:00:00', 50, self::KEY, 'LTCUSDT');   // +5% on 1000
        $this->makeTrade($uniId, '2026-01-02 11:00:00', -30, self::KEY, 'BTCUSDT');  // -3%
        $this->makeTrade($uniId, '2026-01-02 12:00:00', 30, self::KEY, 'LTCUSDT');   // +3% (LTC 8% in 2)
        $this->makeTrade($uniId, '2026-01-03 10:00:00', 105, self::KEY, 'BTCUSDT');  // +10% on 1050
        $this->makeTrade($uniId, '2026-01-03 11:00:00', -10.5, self::KEY, 'LTCUSDT'); // -1%

        $series = $this->getJson('/api/public/track-record')->assertOk()->json('series');

        $this->assertEquals(5.0, $series[0]['pct']);
        $this->assertEquals(
            [['symbol' => 'LTCUSDT', 'pct' => 8.0, 'trades' => 2],
                ['symbol' => 'BTCUSDT', 'pct' => -3.0, 'trades' => 1]],
            $series[0]['assets'],
        );
        $this->assertEquals(9.0, $series[1]['pct']);
        $this->assertEquals(
            [['symbol' => 'BTCUSDT', 'pct' => 10.0, 'trades' => 1],
                ['symbol' => 'LTCUSDT', 'pct' => -1.0, 'trades' => 1]],
            $series[1]['assets'],
        );
    }

    /**
     * Days are Manila calendar days, not UTC ones (`services.track_record.timezone`).
     * The Telegram daily recap reads "today" off this series, and a UTC day
     * ends at 08:00 in Manila — so before this, a close at 20:00 UTC (04:00
     * next morning locally) counted toward a day the reader had already seen
     * reported. The zone is published so the recap slices on the same calendar.
     */
    public function test_days_are_bucketed_in_the_reporting_timezone_not_utc(): void
    {
        config(['services.track_record.timezone' => 'Asia/Manila']);
        $uniId = $this->makeMaster(['initial_deposit' => 1000]);
        $this->makeTrade($uniId, '2026-01-02 12:00:00', 100);  // 20:00 Manila, Jan 2
        $this->makeTrade($uniId, '2026-01-02 20:00:00', 110);  // 04:00 Manila, Jan 3

        $body = $this->getJson('/api/public/track-record')->assertOk()->json();

        $this->assertSame('Asia/Manila', $body['timezone']);
        $this->assertSame(['2026-01-02', '2026-01-03'], array_column($body['series'], 'date'));
        $this->assertEquals(10.0, $body['series'][0]['pct']);  // 100 on 1000
        $this->assertEquals(10.0, $body['series'][1]['pct']);  // 110 on 1100 — its own day, its own capital
    }

    /**
     * A row is one close ORDER, and a stacked position closes in one order —
     * so the channel's `Increment (1/3)…(3/3)` entries end as a single row.
     * The published trade count is increments, not rows, or the daily recap
     * says "4 trades" under six announced closes. A row without the figure
     * (history, poller rows) is at least one.
     */
    public function test_trade_counts_are_increments_closed_not_rows(): void
    {
        $uniId = $this->makeMaster(['initial_deposit' => 1000]);
        $this->makeTrade($uniId, '2026-01-02 10:00:00', 30, self::KEY, 'LTCUSDT', 3);
        $this->makeTrade($uniId, '2026-01-02 11:00:00', 10, self::KEY, 'LTCUSDT', 1);
        $this->makeTrade($uniId, '2026-01-02 12:00:00', 10, self::KEY, 'BTCUSDT', null);

        $body = $this->getJson('/api/public/track-record')->assertOk()->json();

        $this->assertSame(5, $body['series'][0]['trades']);
        $this->assertSame(5, $body['stats']['trades']);
        $this->assertEquals(
            [['symbol' => 'LTCUSDT', 'pct' => 4.0, 'trades' => 4],
                ['symbol' => 'BTCUSDT', 'pct' => 1.0, 'trades' => 1]],
            $body['series'][0]['assets'],
        );
    }

    public function test_a_mid_history_deposit_does_not_rewrite_earlier_days(): void
    {
        $uniId = $this->makeMaster();
        $this->makeDeposit($uniId, '2026-01-01 09:00:00', 1000);
        $this->makeTrade($uniId, '2026-01-02 12:00:00', 100);   // +10% on 1000
        $this->makeDeposit($uniId, '2026-01-03 09:00:00', 8900);
        $this->makeTrade($uniId, '2026-01-04 12:00:00', 1000);  // +10% on 10,000

        $body = $this->getJson('/api/public/track-record')->assertOk()->json();

        $this->assertEquals(10.0, $body['series'][0]['pct']);   // unchanged by the top-up
        $this->assertEquals(10.0, $body['series'][1]['pct']);
        $this->assertEquals(21.0, $body['stats']['total_pnl_pct']);  // 1.1 × 1.1

        // The two measures answer different questions, and this is the shape
        // that separates them: 1,100 of profit on 9,900 ever committed is 11.1%,
        // against a compounded 21% earned mostly while the account was small.
        // Publishing either alone is fine; publishing one unlabelled beside the
        // other is what makes them look like a contradiction.
        $this->assertEquals(11.11, $body['stats']['return_on_capital_pct']);
    }

    public function test_return_on_capital_counts_withdrawn_money_as_no_longer_committed(): void
    {
        $uniId = $this->makeMaster();
        $this->makeDeposit($uniId, '2026-01-01 09:00:00', 1000);
        $this->makeTrade($uniId, '2026-01-02 12:00:00', 100);
        $this->makeDeposit($uniId, '2026-01-03 09:00:00', 500, 'WITHDRAWAL');

        $body = $this->getJson('/api/public/track-record')->assertOk()->json();

        // Base is 1000 − 500 = 500 committed, so 100 of profit reads as +20%.
        // Net, not gross: money taken back out is not still at work, and the
        // same figure the rest of the system sizes and bills on.
        $this->assertEquals(20.0, $body['stats']['return_on_capital_pct']);
    }

    public function test_withdrawals_shrink_the_base_for_later_days(): void
    {
        $uniId = $this->makeMaster();
        $this->makeDeposit($uniId, '2026-01-01 09:00:00', 1000);
        $this->makeDeposit($uniId, '2026-01-02 09:00:00', 500, 'WITHDRAWAL');
        $this->makeTrade($uniId, '2026-01-02 12:00:00', 50);  // +10% on 500

        $body = $this->getJson('/api/public/track-record')->assertOk()->json();

        $this->assertEquals(10.0, $body['series'][0]['pct']);
    }

    public function test_losing_only_history_reports_null_average_win(): void
    {
        $uniId = $this->makeMaster();
        $this->makeDeposit($uniId, '2026-01-01 09:00:00', 1000);
        $this->makeTrade($uniId, '2026-01-02 12:00:00', -100);

        $stats = $this->getJson('/api/public/track-record')->assertOk()->json('stats');

        $this->assertNull($stats['avg_win_pct']);
        $this->assertEquals(-10.0, $stats['avg_loss_pct']);
        $this->assertEquals(0.0, $stats['win_rate']);
    }

    /**
     * The live-master regression: an account funded BEFORE it was connected has
     * no transaction rows at all, so `initial_deposit` is the only record of its
     * capital. Starting the walk at zero divided day one's P&L by whatever
     * pennies preceded it and published a −980% track record against a real
     * +46%.
     */
    public function test_initial_deposit_is_the_capital_base_when_no_transfers_were_polled(): void
    {
        $uniId = $this->makeMaster(['initial_deposit' => 1000]);
        $this->makeTrade($uniId, '2026-01-02 12:00:00', 0.5);   // +0.05% on 1000
        $this->makeTrade($uniId, '2026-01-03 12:00:00', 100);   // +10% on 1000.50

        $body = $this->getJson('/api/public/track-record')->assertOk()->json();

        $this->assertEquals(0.05, $body['series'][0]['pct']);
        $this->assertEquals(9.995, $body['series'][1]['pct']);  // 100 / 1000.50
        $this->assertEquals(10.05, $body['stats']['total_pnl_pct']);
    }

    /**
     * The total is the growth the account actually delivered, so with no
     * deposits it must equal end capital ÷ start capital. Summing the daily
     * percentages would report 30% for the same three days.
     */
    public function test_total_is_the_real_compounded_return_not_the_sum_of_days(): void
    {
        $uniId = $this->makeMaster(['initial_deposit' => 1000]);
        $this->makeTrade($uniId, '2026-01-02 12:00:00', 100);  // +10% on 1000
        $this->makeTrade($uniId, '2026-01-03 12:00:00', 110);  // +10% on 1100
        $this->makeTrade($uniId, '2026-01-04 12:00:00', 121);  // +10% on 1210

        $body = $this->getJson('/api/public/track-record')->assertOk()->json();

        // 1331 / 1000 − 1
        $this->assertEquals(33.1, $body['stats']['total_pnl_pct']);
        $this->assertEquals(33.1, $body['series'][2]['cumulative']);
        // The daily average stays an average of the days, and therefore no
        // longer multiplies out to the total. That divergence is the point.
        $this->assertEquals(10.0, $body['stats']['avg_daily_pct']);
    }

    /**
     * A testnet account trades play money and a sandbox account trades figures
     * an admin invented. Both can live under the master's uni_id, which is why
     * the record is scoped by API KEY rather than by user.
     */
    public function test_testnet_and_sandbox_accounts_are_not_published(): void
    {
        $uniId = $this->makeMaster(['initial_deposit' => 1000]);
        $this->makeTrade($uniId, '2026-01-02 12:00:00', 100);   // +10%, real

        $this->makeAccount($uniId, ['api_key' => 'demo-key', 'demo' => 1, 'initial_deposit' => 5000]);
        $this->makeDeposit($uniId, '2026-01-01 09:00:00', 5000, 'DEPOSIT', 'demo-key');
        $this->makeTrade($uniId, '2026-01-02 13:00:00', -900, 'demo-key');

        $this->makeAccount($uniId, ['api_key' => 'SBXINV-key', 'is_sandbox' => 1, 'initial_deposit' => 200]);
        $this->makeTrade($uniId, '2026-01-02 14:00:00', 400, 'SBXINV-key');

        $body = $this->getJson('/api/public/track-record')->assertOk()->json();

        $this->assertSame(1, $body['stats']['trades']);
        $this->assertEquals(10.0, $body['stats']['total_pnl_pct']);
    }

    /** A rotated key still traded real money — its history stays in the record. */
    public function test_a_disconnected_real_account_is_still_counted(): void
    {
        $uniId = $this->makeMaster(['initial_deposit' => 1000, 'deleted_at' => '2026-02-01 00:00:00']);
        $this->makeTrade($uniId, '2026-01-02 12:00:00', 100);

        $body = $this->getJson('/api/public/track-record')->assertOk()->json();

        $this->assertEquals(10.0, $body['stats']['total_pnl_pct']);
    }

    /**
     * Two real accounts are one portfolio: their opening capital adds up and a
     * day's return is the pair's P&L over the pair's capital.
     */
    public function test_multiple_real_accounts_are_measured_as_one_portfolio(): void
    {
        $uniId = $this->makeMaster(['initial_deposit' => 600]);
        $this->makeAccount($uniId, ['api_key' => 'second-key', 'initial_deposit' => 400]);
        $this->makeTrade($uniId, '2026-01-02 12:00:00', 60);
        $this->makeTrade($uniId, '2026-01-02 13:00:00', 40, 'second-key');

        $body = $this->getJson('/api/public/track-record')->assertOk()->json();

        $this->assertEquals(10.0, $body['series'][0]['pct']);  // 100 / 1000
        $this->assertSame(2, $body['series'][0]['trades']);
    }

    /* ============ privacy ============ */

    public function test_needs_no_token_and_leaks_no_amounts_or_identity(): void
    {
        $uniId = $this->makeMaster(['name' => 'Master Live', 'balance' => 12345.67]);
        DB::table('user_credentials')->where('uni_id', $uniId)
            ->update(['name' => 'Master Trader', 'email' => 'master@example.com']);
        $this->makeDeposit($uniId, '2026-01-01 09:00:00', 1000);
        $this->makeTrade($uniId, '2026-01-02 12:00:00', 100);

        $response = $this->getJson('/api/public/track-record')->assertOk();
        // A vacuous pass would be the worst outcome here — an empty payload
        // leaks nothing either. Assert there IS a record before frisking it.
        $response->assertJson(['available' => true]);
        $raw = $response->content();

        foreach (['12345', 'Master Live', 'Master Trader', 'master@example.com', $uniId,
            'balance', 'realized_pnl', 'api_key', 'equity'] as $secret) {
            $this->assertStringNotContainsString($secret, $raw, "leaked: {$secret}");
        }
    }

    public function test_other_users_trades_are_not_counted(): void
    {
        $master = $this->makeMaster();
        $this->makeDeposit($master, '2026-01-01 09:00:00', 1000);
        $this->makeTrade($master, '2026-01-02 12:00:00', 100);

        $other = $this->makeUser();
        $otherKey = 'other-user-key';
        $this->makeAccount($other, ['api_key' => $otherKey, 'initial_deposit' => 0]);
        $this->makeDeposit($other, '2026-01-01 09:00:00', 1000, 'DEPOSIT', $otherKey);
        $this->makeTrade($other, '2026-01-02 12:00:00', 900, $otherKey);

        $body = $this->getJson('/api/public/track-record')->assertOk()->json();

        $this->assertSame(1, $body['stats']['trades']);
        $this->assertEquals(10.0, $body['stats']['total_pnl_pct']);
    }
}
