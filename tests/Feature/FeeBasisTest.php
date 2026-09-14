<?php

namespace Tests\Feature;

use App\Models\UserCredential;
use App\Services\UserStatsService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * The trading dashboard and Performance Analytics lead with P&L BEFORE
 * exchange fees ("how did the strategy do"); every money figure carries its
 * after-fees twin so the UI can show before / fees / after on hover. The
 * calendar is the exception: its cells stay after fees, with before on hover.
 *
 * Rows before TradingFee::NET_SINCE carry no fee (by decision), so for them
 * before == after and the fee block must count them as "without fee" rather
 * than pretend they cost nothing.
 *
 * Seeded figures: three closes on one account —
 *   A  2026-09-12  net 24.50  fee 0.50   → gross 25.00
 *   B  2026-09-13  net −9.30  fee 0.70   → gross −8.60
 *   C  2026-09-01  net 30.00  fee NULL   → gross 30.00 (pre-cutoff, no fee)
 * Totals: gross 46.40, net 45.20, fees 1.20 (2 with fee, 1 without).
 */
class FeeBasisTest extends EngineTestCase
{
    private string $uniId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->uniId = $this->makeUser();
        $this->makeAccount($this->uniId, [
            'balance' => 1000,
            'unrealized_pnl' => 10,
            'initial_deposit' => 900,
            'api_key' => 'fee-key',
        ]);
        Sanctum::actingAs(UserCredential::query()->find($this->uniId));

        $this->trade(1, 'LTCUSDT', 'ABCD-v1', '2026-09-12 10:00:00', 24.5, 0.5, 'estimated');
        $this->trade(2, 'LTCUSDT', 'ABCD-v1', '2026-09-13 10:00:00', -9.3, 0.7, 'actual');
        $this->trade(3, 'ETHUSDT', 'Scalper', '2026-09-01 10:00:00', 30.0, null, null);
    }

    private function trade(int $orderId, string $symbol, string $strategy, string $closedAt, float $net, ?float $fee, ?string $source): void
    {
        DB::table('binance_pastpositions')->insert([
            'uni_id' => $this->uniId,
            'api_key' => 'fee-key',
            'symbol' => $symbol,
            'position_side' => 'LONG',
            'position_amt' => 10,
            'exit_price' => 50,
            'realized_pnl' => $net,
            'exchange_fee' => $fee,
            'fee_source' => $source,
            'side' => 'SELL',
            'order_id' => $orderId,
            'strategy' => $strategy,
            'closed_at' => $closedAt,
            'created_at' => now(),
        ]);
    }

    public function test_with_fee_basis_stamps_both_bases_and_whether_a_fee_is_known(): void
    {
        $rows = UserStatsService::withFeeBasis(collect([
            (object) ['realized_pnl' => '24.5', 'exchange_fee' => '0.5'],
            (object) ['realized_pnl' => '30', 'exchange_fee' => null],
            (object) ['realized_pnl' => null, 'exchange_fee' => null],
        ]));

        $this->assertSame([25.0, 24.5, 0.5, true], [$rows[0]->pnl_gross, $rows[0]->pnl_net, $rows[0]->pnl_fee, $rows[0]->fee_known]);
        $this->assertSame([30.0, 30.0, 0.0, false], [$rows[1]->pnl_gross, $rows[1]->pnl_net, $rows[1]->pnl_fee, $rows[1]->fee_known]);
        $this->assertNull($rows[2]->pnl_gross);
        $this->assertNull($rows[2]->pnl_net);

        $fees = UserStatsService::feeSummary($rows);
        $this->assertEquals(['total' => 0.5, 'trades_with_fee' => 1, 'trades_without_fee' => 2, 'since' => '2026-09-11'], $fees);
    }

    public function test_dashboard_summary_leads_before_fees_and_carries_the_after_fees_twin(): void
    {
        $s = $this->getJson('/api/dashboard/summary')->assertOk()->json('summary');

        $this->assertEquals(45.2, $s['realized_pnl']);         // after fees — unchanged meaning
        $this->assertEquals(46.4, $s['realized_pnl_gross']);   // before fees
        $this->assertEquals(55.2, $s['total_pnl']);            // + unrealized 10
        $this->assertEquals(56.4, $s['total_pnl_gross']);
        $this->assertEquals(['total' => 1.2, 'trades_with_fee' => 2, 'trades_without_fee' => 1, 'since' => '2026-09-11'], $s['fees']);

        // The rail: strategy figures before fees, the money figure both ways.
        $this->assertEquals(55.2, $s['metrics']['net_pnl']);
        $this->assertEquals(56.4, $s['metrics']['gross_pnl']);
        $this->assertEquals(1.2, $s['metrics']['fees']);
        $this->assertEquals(66.7, $s['metrics']['win_rate']);     // 2 of 3 on the gross basis
        $this->assertEquals(round(46.4 / 3, 2), $s['metrics']['expectancy']);

        // Equity curve: `equity` ends on the live balance, `equity_gross` on
        // balance + fees since the start; both start at the same level.
        $curve = $s['equity_curve'];
        $last = end($curve);
        $this->assertEquals(1010.0, $last['equity']);
        $this->assertEquals(1011.2, $last['equity_gross']);
        $first = $curve[0];
        $this->assertEquals($first['equity'], $first['equity_gross']); // 2026-09-01: no fee on record

        // Period breakdown: gross headline, net + fees beside it.
        $this->assertEqualsCanonicalizing(['daily', 'weekly', 'monthly'], array_keys($s['pnl_breakdown']));
        $this->assertSame(array_keys($s['pnl_breakdown']), array_keys($s['pnl_breakdown_net']));
        $this->assertSame(array_keys($s['pnl_breakdown']), array_keys($s['pnl_breakdown_fees']));

        // By-asset series: totals both ways, points both ways.
        $ltc = collect($s['by_asset'])->firstWhere('id', 'LTCUSDT');
        $this->assertEquals(16.4, $ltc['total']);       // 25 − 8.6
        $this->assertEquals(15.2, $ltc['total_net']);   // 24.5 − 9.3
        $this->assertEquals(1.2, $ltc['fees']);
        $this->assertEquals([25.0, 24.5], [$ltc['curve'][0]['cum'], $ltc['curve'][0]['cum_net']]);
        $this->assertEquals([16.4, 15.2], [$ltc['curve'][1]['cum'], $ltc['curve'][1]['cum_net']]);
    }

    public function test_calendar_cells_stay_after_fees_with_before_fees_beside_them(): void
    {
        $days = $this->getJson('/api/dashboard/daily-pnl')->assertOk()->json('days');

        $this->assertEquals(24.5, $days['2026-09-12']['total']);
        $this->assertEquals(25.0, $days['2026-09-12']['total_gross']);
        $this->assertEquals(0.5, $days['2026-09-12']['fees']);

        $this->assertEquals(30.0, $days['2026-09-01']['total']);
        $this->assertEquals(30.0, $days['2026-09-01']['total_gross']);
        $this->assertEquals(0.0, $days['2026-09-01']['fees']);
        $this->assertNull($days['2026-09-01']['trades'][0]['fee_source']);
    }

    public function test_analytics_leads_before_fees_with_net_twins_and_a_fee_block(): void
    {
        $a = $this->getJson('/api/analytics')->assertOk()->json('analytics');

        $this->assertEquals(46.4, $a['total_realized']);
        $this->assertEquals(45.2, $a['total_realized_net']);
        $this->assertEquals(['total' => 1.2, 'trades_with_fee' => 2, 'trades_without_fee' => 1, 'since' => '2026-09-11'], $a['fees']);
        $this->assertEquals(56.4, $a['total_return_abs']);
        $this->assertEquals(55.2, $a['total_return_abs_net']);
        $this->assertEquals(46.4, $a['return_on_deposit']['realized']);
        $this->assertEquals(45.2, $a['return_on_deposit']['realized_net']);

        $this->assertEquals(['date' => '2026-09-01', 'pnl' => 30.0, 'pnl_net' => 30.0], $a['best_day']);
        $this->assertEquals(['date' => '2026-09-13', 'pnl' => -8.6, 'pnl_net' => -9.3], $a['worst_day']);
        $this->assertEquals(-8.6, $a['daily_pnl']['2026-09-13']);
        $this->assertEquals(-9.3, $a['daily_pnl_net']['2026-09-13']);

        $sep = collect($a['monthly'])->firstWhere('month', '2026-09');
        $this->assertEquals(['pnl' => 46.4, 'pnl_net' => 45.2, 'fees' => 1.2, 'trades' => 3], collect($sep)->only(['pnl', 'pnl_net', 'fees', 'trades'])->all());

        $ltc = collect($a['by_symbol'])->firstWhere('symbol', 'LTCUSDT');
        $this->assertEquals(['realized_pnl' => 16.4, 'realized_pnl_net' => 15.2, 'fees' => 1.2], collect($ltc)->only(['realized_pnl', 'realized_pnl_net', 'fees'])->all());

        // Trade quality on the gross basis: the loss is −8.6, not −9.3.
        $this->assertEquals(-8.6, $a['quality']['largest_loss']);
        $this->assertEquals(-8.6, $a['quality']['avg_loss']);
    }

    public function test_asset_performance_curves_are_before_fees_with_net_points(): void
    {
        $assets = $this->getJson('/api/dashboard/asset-performance')->assertOk()->json('assets');
        $ltc = collect($assets)->firstWhere('ticker', 'LTCUSDT');

        $this->assertEquals(16.4, $ltc['total_pnl']);
        $this->assertEquals(15.2, $ltc['total_pnl_net']);
        $this->assertEquals(1.2, $ltc['fees']);
        $this->assertEquals(25.0, $ltc['equity_series'][0]['cumulative']);
        $this->assertEquals(24.5, $ltc['equity_series'][0]['cumulative_net']);
    }
}
