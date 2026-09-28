<?php

namespace Tests\Feature;

use App\Models\UserCredential;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * Covers `daily_capital` on GET /api/analytics — the per-day denominator the
 * Performance card chains into its period return.
 *
 * The bug it exists for: the card divided a window's P&L by ONE all-time
 * baseline, so a deposit made in September changed the percentage August had
 * already reported. A finished month must not move. These tests pin the
 * property at the data level — the capital of the days inside a window is
 * unaffected by anything that happens after it, so the figure chained from
 * them cannot move either.
 *
 * Trades are seeded with `exchange_fee = 0` so gross == net and no estimated
 * commission enters the walk; the fee correction has its own test below.
 */
class AnalyticsPeriodReturnTest extends EngineTestCase
{
    private string $uniId;

    private const KEY = 'period-return-key';

    protected function setUp(): void
    {
        parent::setUp();

        $this->uniId = $this->makeUser();
        // initial_deposit 0 so the walk is driven purely by the transfers
        // seeded here and the arithmetic below stays checkable by hand.
        $this->makeAccount($this->uniId, [
            'api_key' => self::KEY,
            'balance' => 11000,
            'initial_deposit' => 0,
        ]);
        Sanctum::actingAs(UserCredential::query()->find($this->uniId));
    }

    private function trade(string $closedAt, float $pnl, ?float $fee = 0.0): void
    {
        DB::table('binance_pastpositions')->insert([
            'uni_id' => $this->uniId,
            'api_key' => self::KEY,
            'symbol' => 'BTCUSDT',
            'position_side' => 'LONG',
            'position_amt' => 1,
            'entry_price' => 100,
            'exit_price' => 110,
            'realized_pnl' => $pnl,
            'exchange_fee' => $fee,
            'side' => 'BUY',
            'strategy' => 'ABCD-v1',
            'closed_at' => $closedAt,
        ]);
    }

    private function flow(string $at, float $amount, string $type = 'DEPOSIT'): void
    {
        DB::table('binance_transactions')->insert([
            'uni_id' => $this->uniId,
            'api_key' => self::KEY,
            'type' => $type,
            'amount' => $amount,
            'currency' => 'USDT',
            'created_at' => $at,
        ]);
    }

    private function analytics(array $query = []): array
    {
        return $this->getJson('/api/analytics?'.http_build_query($query))
            ->assertOk()
            ->json('analytics');
    }

    /** $10,000 in on 1 Aug, then +$500 on the 10th and +$500 on the 20th. */
    private function seedAugust(): void
    {
        $this->flow('2026-08-01 09:00:00', 10000);
        $this->trade('2026-08-10 12:00:00', 500);
        $this->trade('2026-08-20 12:00:00', 500);
    }

    public function test_capital_is_the_money_at_work_that_day(): void
    {
        $this->seedAugust();

        $capital = $this->analytics()['daily_capital'];

        // The deposit day itself is present even though it has no trade — the
        // card needs it to place the flow, and a missing key reads as no data.
        $this->assertEquals(10000, $capital['2026-08-01']);
        // First trade divides by the 10,000 that was there…
        $this->assertEquals(10000, $capital['2026-08-10']);
        // …the second by 10,000 + the first trade's profit.
        $this->assertEquals(10500, $capital['2026-08-20']);
    }

    public function test_a_later_deposit_does_not_move_an_earlier_day(): void
    {
        $this->seedAugust();
        $before = $this->analytics()['daily_capital'];

        // The boss funds the account in September.
        $this->flow('2026-09-05 09:00:00', 50000);
        $after = $this->analytics()['daily_capital'];

        // August is closed. Every day inside it divides by exactly what it
        // divided by yesterday, so the percentage chained from them is the
        // same percentage it was before the deposit landed.
        $this->assertEquals($before['2026-08-01'], $after['2026-08-01']);
        $this->assertEquals($before['2026-08-10'], $after['2026-08-10']);
        $this->assertEquals($before['2026-08-20'], $after['2026-08-20']);

        // …and the deposit does show up, on its own day.
        $this->assertEquals(61000, $after['2026-09-05']);
    }

    public function test_a_withdrawal_lowers_capital_from_its_own_day_on(): void
    {
        $this->seedAugust();
        $this->flow('2026-08-15 09:00:00', 2000, 'WITHDRAWAL');

        $capital = $this->analytics()['daily_capital'];

        $this->assertEquals(10000, $capital['2026-08-10']);   // before it
        $this->assertEquals(8500, $capital['2026-08-15']);    // 10,500 − 2,000
        $this->assertEquals(8500, $capital['2026-08-20']);    // after it
    }

    public function test_capital_ignores_the_date_and_chip_filters(): void
    {
        $this->seedAugust();

        $unfiltered = $this->analytics()['daily_capital'];
        $narrowed = $this->analytics([
            'from' => '2026-08-15',
            'to' => '2026-08-31',
            'symbols' => ['ETHUSDT'],
            'symbol_mode' => 'include',
        ])['daily_capital'];

        // The filters narrow what is MEASURED, never the money that was at
        // work behind it — otherwise excluding a trade would silently change
        // the denominator of every other trade's day.
        $this->assertEquals($unfiltered, $narrowed);
    }

    public function test_a_gross_rows_commission_comes_off_the_capital(): void
    {
        $this->flow('2026-08-01 09:00:00', 10000);
        // exchange_fee NULL = a pre-cutoff row whose P&L is still gross. The
        // exchange took the commission anyway, so the walk must not compound
        // money the account never had.
        $this->trade('2026-08-10 12:00:00', 500, null);
        $this->trade('2026-08-20 12:00:00', 500, 0.0);

        $capital = $this->analytics()['daily_capital'];

        // 1 unit closed at 110 = 110 notional, 0.05% taker per side, both
        // sides = 0.11 — charged to the capital, never to realized_pnl.
        $this->assertEquals(10000, $capital['2026-08-10']);
        $this->assertEquals(10499.89, $capital['2026-08-20']);
    }

    public function test_the_estimated_fee_on_gross_rows_is_broken_out_per_day(): void
    {
        $this->flow('2026-08-01 09:00:00', 10000);
        $this->trade('2026-08-10 12:00:00', 500, null);
        $this->trade('2026-08-20 12:00:00', 500, 0.0);

        $a = $this->analytics();

        // Only the gross row's day carries one: a recorded fee (even 0) is
        // already in its P&L and must not be counted twice.
        $this->assertEquals(['2026-08-10' => 0.11], $a['daily_unrecorded_fees']);
        // And it is the exact gap between the balance and the P&L on that day.
        $this->assertEquals(10000 + 500 - 0.11, $a['daily_balance']['2026-08-10']);
    }

    /* ======= daily_balance — what the Date Range card's end date held ======= */

    public function test_balance_on_a_date_ignores_transfers_made_after_it(): void
    {
        $this->seedAugust();
        $this->flow('2026-08-15 09:00:00', 2000, 'WITHDRAWAL');
        // A September deposit and withdrawal must not appear in any August
        // balance — the card used to add every transfer ever made.
        $this->flow('2026-09-05 09:00:00', 50000);
        $this->flow('2026-09-06 09:00:00', 7000, 'WITHDRAWAL');

        $balance = $this->analytics()['daily_balance'];

        $this->assertEquals(10000, $balance['2026-08-01']);
        $this->assertEquals(10500, $balance['2026-08-10']);   // + that day's trade
        $this->assertEquals(8500, $balance['2026-08-15']);    // − the withdrawal
        $this->assertEquals(9000, $balance['2026-08-20']);    // + the second trade
        $this->assertEquals(59000, $balance['2026-09-05']);
        $this->assertEquals(52000, $balance['2026-09-06']);
    }

    public function test_balance_is_seeded_from_initial_deposit(): void
    {
        DB::table('binance_accounts')->where('api_key', self::KEY)->update(['initial_deposit' => 1000]);
        $this->seedAugust();

        $a = $this->analytics();

        // Money that was there before any transfer we recorded is still money.
        $this->assertEquals(1000, $a['initial_deposit']);
        $this->assertEquals(11000, $a['daily_balance']['2026-08-01']);
        $this->assertEquals(12000, $a['daily_balance']['2026-08-20']);
        // …and it is not in `baseline`, which stays net flows alone.
        $this->assertEquals(10000, $a['baseline']);
    }

    public function test_no_trades_and_no_flows_is_an_empty_series(): void
    {
        $this->assertSame([], $this->analytics()['daily_balance']);
        $this->assertSame([], $this->analytics()['daily_capital']);
        $this->assertSame([], $this->analytics()['daily_flows']);
    }

    /* ============ daily_flows — the capital chart's input ============ */

    public function test_flows_are_signed_and_netted_per_day(): void
    {
        $this->flow('2026-08-01 09:00:00', 10000);
        // Two transfers on one day net to one step on the chart.
        $this->flow('2026-08-15 09:00:00', 2000, 'WITHDRAWAL');
        $this->flow('2026-08-15 14:00:00', 500);

        $flows = $this->analytics()['daily_flows'];

        $this->assertEquals(10000, $flows['2026-08-01']);
        $this->assertEquals(-1500, $flows['2026-08-15']);
        // Only days that moved money appear — the chart steps, it does not
        // draw a point per calendar day.
        $this->assertSame(['2026-08-01', '2026-08-15'], array_keys($flows));
    }

    public function test_flows_ignore_the_date_and_chip_filters(): void
    {
        $this->seedAugust();

        $this->assertEquals(
            $this->analytics()['daily_flows'],
            $this->analytics(['from' => '2026-09-01', 'to' => '2026-09-30'])['daily_flows'],
        );
    }
}
