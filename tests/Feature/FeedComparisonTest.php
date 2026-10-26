<?php

namespace Tests\Feature;

use App\Models\TradeLog;
use App\Models\UserCredential;

/**
 * Signal Log → "TradingView vs QuantLab": the same signal from the main feed
 * (TradingView) and the test feed (QuantLab), paired by exchange + action +
 * coin within two minutes, with which arrived first and the master's vs the
 * tester's fill. Expected figures are worked out by hand below.
 */
class FeedComparisonTest extends EngineTestCase
{
    private string $admin;

    private string $masterAccount = 'Master Binance';

    private string $testerAccount = 'QL Tester';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-25 00:00:00');
        $this->admin = $this->makeUser(['type' => 'admin']);
        $this->makeAccount($this->makeUser(['type' => 'master']), ['name' => $this->masterAccount]);
        $this->makeAccount($this->makeUser(['type' => 'tester']), ['name' => $this->testerAccount]);
    }

    private function headers(): array
    {
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.UserCredential::find($this->admin)->createToken('t')->plainTextToken];
    }

    /** One signal through the engine's own endpoint, as the engine posts it. */
    private function signal(string $feed, string $receivedAt, array $overrides = [], ?float $fill = null): int
    {
        $detail = $feed === 'main'
            ? ['account' => $this->masterAccount, 'is_master' => true, 'status' => 'filled', 'fill_price' => $fill]
            : ['account' => $this->testerAccount, 'is_master' => false, 'status' => 'filled', 'fill_price' => $fill];

        return $this->postJson('/api/engine/binance/trade-logs', array_merge([
            'action' => 'BUY', 'ticker' => 'LTCUSDT', 'success' => true, 'category' => 'signal',
            'target_count' => 1, 'filled' => 1, 'failed' => 0, 'skipped' => 0,
            'details' => [$detail],
            'ts' => substr(str_replace('T', ' ', $receivedAt), 0, 19),
            'feed' => $feed,
            'received_at' => $receivedAt,
            'strategy' => $feed === 'main' ? 'ABCD-v1' : 'vwma_reversion',
        ], $overrides), $this->engineHeaders())->assertStatus(201)->json('trade_log_id');
    }

    private function compare(string $query = '')
    {
        return $this->getJson('/api/admin/trade-logs/compare'.$query, $this->headers())->assertOk();
    }

    public function test_milliseconds_survive_the_round_trip(): void
    {
        $id = $this->signal('main', '2026-10-24T14:15:09.204Z');

        $this->assertSame('2026-10-24 14:15:09.204', TradeLog::find($id)->received_at->format('Y-m-d H:i:s.v'));
        $row = $this->getJson('/api/admin/trade-logs', $this->headers())->json('logs.0');
        $this->assertSame('2026-10-24T14:15:09.204Z', $row['delivery']['received_at']);
    }

    public function test_the_same_signal_from_both_feeds_pairs_and_says_who_was_first(): void
    {
        $this->signal('main', '2026-10-24T14:15:09.204Z', [], 92.41);
        $this->signal('test', '2026-10-24T14:15:02.812Z', [], 92.38);

        $res = $this->compare();
        $row = $res->json('rows.0');

        $this->assertSame('pair', $row['kind']);
        // 02.812 − 09.204 = −6.392 s: QuantLab arrived first.
        $this->assertSame(-6392, $row['gap_ms']);
        $this->assertSame('test', $row['first']);
        $this->assertSame('2026-10-24T14:15:02.812Z', $row['at']);
        $this->assertSame($this->masterAccount, $row['main']['account']);
        $this->assertSame($this->testerAccount, $row['test']['account']);
        $this->assertSame('vwma_reversion', $row['test']['strategy']);
        // A BUY: the lower price is the better fill. (92.38 − 92.41) / 92.41 = −0.0325 %.
        $this->assertSame('test', $row['better_price']);
        $this->assertEqualsWithDelta(-0.0325, $row['price_diff_pct'], 0.00001);
        $this->assertTrue($row['prices_comparable']);

        $summary = $res->json('summary');
        $this->assertSame(1, $summary['pairs']);
        $this->assertSame(1, $summary['test_first']);
        $this->assertSame(-6392, $summary['avg_gap_ms']);
        $this->assertSame(1, $summary['prices']['test_better']);
        $this->assertSame(120, $res->json('match_seconds'));
    }

    public function test_on_a_sell_the_higher_price_is_the_better_fill(): void
    {
        $this->signal('main', '2026-10-24T16:30:01.100Z', ['action' => 'EXIT_LONG'], 1.842);
        $this->signal('test', '2026-10-24T16:30:03.000Z', ['action' => 'EXIT_LONG'], 1.843);

        $row = $this->compare()->json('rows.0');
        $this->assertSame(1900, $row['gap_ms']);
        $this->assertSame('main', $row['first']);
        $this->assertSame('test', $row['better_price']);
        $this->assertSame('exit', $row['type']);
    }

    public function test_more_than_two_minutes_apart_is_two_signals_not_one(): void
    {
        $this->signal('main', '2026-10-24T14:15:00.000Z');
        $this->signal('test', '2026-10-24T14:17:00.001Z');

        $res = $this->compare();
        $this->assertSame(['test_only', 'main_only'], array_column($res->json('rows'), 'kind'));
        $this->assertSame(0, $res->json('summary.pairs'));
        $this->assertSame(1, $res->json('summary.main_only'));
        $this->assertSame(1, $res->json('summary.test_only'));
    }

    public function test_the_closest_arrival_pairs_and_the_other_stays_alone(): void
    {
        $early = $this->signal('main', '2026-10-24T14:15:00.000Z');
        $late = $this->signal('main', '2026-10-24T14:15:50.000Z');
        $this->signal('test', '2026-10-24T14:15:45.000Z');

        $rows = collect($this->compare()->json('rows'));
        $pair = $rows->firstWhere('kind', 'pair');
        $this->assertSame($late, $pair['main']['id']);
        $this->assertSame(-5000, $pair['gap_ms']);
        $this->assertSame($early, $rows->firstWhere('kind', 'main_only')['main']['id']);
    }

    public function test_a_different_action_never_pairs(): void
    {
        $this->signal('main', '2026-10-24T14:15:00.000Z', ['action' => 'BUY']);
        $this->signal('test', '2026-10-24T14:15:01.000Z', ['action' => 'SELL']);

        $this->assertSame(0, $this->compare()->json('summary.pairs'));
    }

    public function test_a_coin_only_one_feed_sends_is_counted_not_listed(): void
    {
        $this->signal('main', '2026-10-24T14:15:00.000Z');
        $this->signal('test', '2026-10-24T14:15:01.000Z');
        // QuantLab never sends BTC: TradingView's BTC signal is not "missed".
        $this->signal('main', '2026-10-24T14:20:00.000Z', ['ticker' => 'BTCUSDT']);

        $res = $this->compare();
        $this->assertCount(1, $res->json('rows'));
        $this->assertSame(['main' => 1, 'test' => 0], $res->json('summary.not_compared'));
    }

    public function test_retries_and_rows_without_an_arrival_are_left_out(): void
    {
        $this->signal('main', '2026-10-24T14:15:00.000Z');
        $this->signal('test', '2026-10-24T14:15:01.000Z');
        $this->signal('test', '2026-10-24T14:16:00.000Z', ['category' => 'retry']);
        $this->postJson('/api/engine/binance/trade-logs', [
            'action' => 'BUY', 'ticker' => 'LTCUSDT', 'success' => true, 'category' => 'signal',
            'details' => [], 'ts' => '2026-10-24 14:15:30', 'feed' => 'main',
        ], $this->engineHeaders())->assertStatus(201);

        $res = $this->compare();
        $this->assertSame(['pair'], array_column($res->json('rows'), 'kind'));
    }

    public function test_a_demo_tester_fill_is_shown_but_never_compared(): void
    {
        $this->makeAccount($this->makeUser(['type' => 'tester']), ['name' => 'QL Demo', 'demo' => 1]);
        $this->signal('main', '2026-10-24T14:15:00.000Z', [], 92.41);
        $this->signal('test', '2026-10-24T14:15:01.000Z', [
            'details' => [['account' => 'QL Demo', 'status' => 'filled', 'fill_price' => 92.10]],
        ]);

        $res = $this->compare();
        $row = $res->json('rows.0');
        $this->assertTrue($row['test']['demo']);
        $this->assertFalse($row['main']['demo']);
        $this->assertFalse($row['prices_comparable']);
        $this->assertSame(0, $res->json('summary.prices.compared'));
        $this->assertSame(1, $res->json('summary.prices.demo'));
    }

    public function test_the_master_not_trading_reads_as_its_outcome_with_no_price(): void
    {
        $this->signal('main', '2026-10-24T14:15:00.000Z', [
            'details' => [['account' => $this->masterAccount, 'is_master' => true, 'status' => 'skipped', 'reason' => 'maxed sizing']],
        ]);
        $this->signal('test', '2026-10-24T14:15:01.000Z', [], 92.38);

        $row = $this->compare()->json('rows.0');
        $this->assertSame('skipped', $row['main']['outcome']);
        $this->assertSame('maxed sizing', $row['main']['reason']);
        $this->assertNull($row['main']['fill_price']);
        $this->assertNull($row['better_price']);
    }

    public function test_filters_and_the_default_seven_day_window(): void
    {
        $this->signal('main', '2026-10-10T14:15:00.000Z');
        $this->signal('test', '2026-10-10T14:15:01.000Z');
        $this->signal('main', '2026-10-24T14:15:00.000Z');
        $this->signal('test', '2026-10-24T14:15:01.000Z');

        $this->assertSame(1, $this->compare()->json('summary.pairs'));
        $this->assertSame(2, $this->compare('?from=2026-10-01')->json('summary.pairs'));
        $this->assertSame(0, $this->compare('?ticker=BTCUSDT')->json('summary.pairs'));
        // The feed filter is ignored: both feeds ARE the comparison.
        $this->assertSame(1, $this->compare('?feed=main')->json('summary.pairs'));
    }
}
