<?php

namespace Tests\Unit;

use App\Services\Pnl\FeeAttribution;
use PHPUnit\Framework\TestCase;

/**
 * FeeAttribution replays one symbol's receipts into a fee per closing order.
 * Pure, so these are plain PHPUnit: no app, no DB. Figures are hand-derived
 * from the rules, never read off the code.
 *
 * Receipt shapes mirror `exchange_fee_receipts` rows; `id` stands in for the
 * insertion order the query sorts on.
 */
class FeeAttributionTest extends TestCase
{
    private int $nextId = 1;

    private function fill(
        int $orderId,
        string $side,
        string $positionSide,
        float $qty,
        float $commission,
        int $time,
        float $realizedPnl = 0.0,
        string $asset = 'USDT',
        ?int $ref = null,
    ): array {
        $id = $this->nextId++;

        return [
            'id' => $id,
            'kind' => 'fill',
            'ref' => $ref ?? (1000 + $id),
            'order_id' => $orderId,
            'side' => $side,
            'position_side' => $positionSide,
            'qty' => $qty,
            'price' => 50,
            'realized_pnl' => $realizedPnl,
            'amount' => $commission,
            'asset' => $asset,
            'charged_at' => $time,
        ];
    }

    private function funding(float $amount, int $time, string $asset = 'USDT'): array
    {
        $id = $this->nextId++;

        return [
            'id' => $id,
            'kind' => 'funding',
            'ref' => 5000 + $id,
            'order_id' => null,
            'side' => null,
            'position_side' => null,
            'qty' => null,
            'price' => null,
            'realized_pnl' => null,
            'amount' => $amount,
            'asset' => $asset,
            'charged_at' => $time,
        ];
    }

    public function test_simple_entry_and_exit_sums_both_commissions(): void
    {
        $r = FeeAttribution::attribute([
            $this->fill(1, 'BUY', 'LONG', 10, 0.25, 1000),
            $this->fill(2, 'SELL', 'LONG', 10, 0.26, 2000, 25.0),
        ]);

        $this->assertArrayHasKey(2, $r);
        $this->assertArrayNotHasKey(1, $r);
        $this->assertSame(0.26, $r[2]['exit_commission']);
        $this->assertSame(0.25, $r[2]['entry_commission']);
        $this->assertSame(0.0, $r[2]['funding']);
        $this->assertSame(0.51, $r[2]['total']);
        $this->assertTrue($r[2]['confirmable']);
        $this->assertNull($r[2]['reason']);
        $this->assertSame('LONG', $r[2]['position_side']);
        $this->assertSame(10.0, $r[2]['close_qty']);
        $this->assertSame(2000, $r[2]['closed_at_ms']);
        $this->assertSame(['USDT'], $r[2]['assets']);
    }

    public function test_three_stacked_entries_and_one_exit_sum_every_entry_commission(): void
    {
        $r = FeeAttribution::attribute([
            $this->fill(1, 'BUY', 'LONG', 10, 0.25, 1000),
            $this->fill(2, 'BUY', 'LONG', 10, 0.24, 1500),
            $this->fill(3, 'BUY', 'LONG', 10, 0.23, 2000),
            $this->fill(4, 'SELL', 'LONG', 30, 0.75, 3000, 60.0),
        ]);

        $this->assertSame(0.72, $r[4]['entry_commission']);
        $this->assertSame(0.75, $r[4]['exit_commission']);
        $this->assertSame(1.47, $r[4]['total']);
        $this->assertTrue($r[4]['confirmable']);
    }

    public function test_a_multi_fill_order_is_one_event_dated_at_its_last_fill(): void
    {
        $r = FeeAttribution::attribute([
            $this->fill(1, 'BUY', 'LONG', 6, 0.15, 1000),
            $this->fill(1, 'BUY', 'LONG', 4, 0.10, 1001),
            $this->fill(2, 'SELL', 'LONG', 7, 0.18, 2000, 10.0),
            $this->fill(2, 'SELL', 'LONG', 3, 0.08, 2003, 5.0),
        ]);

        $this->assertCount(1, $r);
        $this->assertSame(10.0, $r[2]['close_qty']);
        $this->assertSame(2003, $r[2]['closed_at_ms']);
        $this->assertSame(0.26, $r[2]['exit_commission']);
        $this->assertSame(0.25, $r[2]['entry_commission']);
    }

    public function test_partial_close_takes_its_share_and_the_remainder_closes_with_the_rest(): void
    {
        $r = FeeAttribution::attribute([
            $this->fill(1, 'BUY', 'LONG', 10, 0.40, 1000),
            $this->funding(0.10, 1500),
            $this->fill(2, 'SELL', 'LONG', 4, 0.16, 2000, 8.0),   // 40% of the bucket
            $this->fill(3, 'SELL', 'LONG', 6, 0.24, 3000, 12.0),  // the remaining 60%
        ]);

        $this->assertSame(0.16, $r[2]['entry_commission']);
        $this->assertSame(0.04, $r[2]['funding']);
        $this->assertSame(0.36, $r[2]['total']);
        $this->assertTrue($r[2]['confirmable']);

        $this->assertSame(0.24, $r[3]['entry_commission']);
        $this->assertSame(0.06, $r[3]['funding']);
        $this->assertSame(0.54, $r[3]['total']);
        $this->assertTrue($r[3]['confirmable']);
    }

    public function test_funding_inside_the_window_attaches_and_outside_is_ignored(): void
    {
        $r = FeeAttribution::attribute([
            $this->funding(0.50, 500),                             // before the entry: flat, orphan
            $this->fill(1, 'BUY', 'LONG', 10, 0.25, 1000),
            $this->funding(0.30, 1500),                            // held: ours
            $this->funding(0.20, 1800),                            // held: ours
            $this->fill(2, 'SELL', 'LONG', 10, 0.26, 2000, 25.0),
            $this->funding(0.40, 2500),                            // after the close: flat, orphan
        ]);

        $this->assertSame(0.5, $r[2]['funding']);
        $this->assertSame(1.01, $r[2]['total']);
    }

    public function test_a_funding_credit_lowers_the_total_and_can_make_it_negative(): void
    {
        $r = FeeAttribution::attribute([
            $this->fill(1, 'BUY', 'LONG', 10, 0.05, 1000),
            $this->funding(-0.80, 1500),                           // the exchange paid us
            $this->fill(2, 'SELL', 'LONG', 10, 0.05, 2000, 25.0),
        ]);

        $this->assertSame(-0.8, $r[2]['funding']);
        $this->assertSame(-0.7, $r[2]['total']);
        $this->assertTrue($r[2]['confirmable']);
    }

    public function test_a_close_with_no_entry_in_the_ledger_is_entry_missing(): void
    {
        $r = FeeAttribution::attribute([
            $this->fill(9, 'SELL', 'LONG', 10, 0.26, 2000, 25.0),
        ]);

        $this->assertFalse($r[9]['confirmable']);
        $this->assertSame(FeeAttribution::REASON_ENTRY_MISSING, $r[9]['reason']);
        $this->assertSame(0.26, $r[9]['exit_commission']);
        $this->assertSame(0.0, $r[9]['entry_commission']);
    }

    public function test_one_way_flat_fill_with_realized_pnl_is_a_pre_ledger_close(): void
    {
        // BOTH mode, nothing held, a SELL that realized P&L: it closed a LONG
        // the ledger never saw open. Must not open a phantom SHORT.
        $r = FeeAttribution::attribute([
            $this->fill(9, 'SELL', 'BOTH', 10, 0.26, 2000, 25.0),
            $this->fill(10, 'BUY', 'BOTH', 5, 0.12, 3000),          // a real entry afterwards
            $this->fill(11, 'SELL', 'BOTH', 5, 0.13, 4000, 3.0),    // and its close
        ]);

        $this->assertFalse($r[9]['confirmable']);
        $this->assertSame(FeeAttribution::REASON_ENTRY_MISSING, $r[9]['reason']);
        $this->assertSame('LONG', $r[9]['position_side']);

        $this->assertTrue($r[11]['confirmable']);
        $this->assertSame(0.12, $r[11]['entry_commission']);
        $this->assertSame(0.25, $r[11]['total']);
    }

    public function test_one_way_direction_follows_the_sign_of_the_net_position(): void
    {
        $r = FeeAttribution::attribute([
            $this->fill(1, 'SELL', 'BOTH', 10, 0.25, 1000),         // flat + SELL, no pnl: opens SHORT
            $this->fill(2, 'BUY', 'BOTH', 10, 0.26, 2000, -4.0),    // net < 0 + BUY: closes SHORT
            $this->fill(3, 'BUY', 'BOTH', 10, 0.27, 3000),          // flat + BUY: opens LONG
            $this->fill(4, 'SELL', 'BOTH', 10, 0.28, 4000, 6.0),    // net > 0 + SELL: closes LONG
        ]);

        $this->assertSame('SHORT', $r[2]['position_side']);
        $this->assertSame(0.51, $r[2]['total']);
        $this->assertSame('LONG', $r[4]['position_side']);
        $this->assertSame(0.55, $r[4]['total']);
    }

    public function test_close_larger_than_the_ledger_saw_enter_is_entry_qty_short_and_resets(): void
    {
        $r = FeeAttribution::attribute([
            $this->fill(1, 'BUY', 'LONG', 4, 0.10, 1000),           // only part of the position is ours
            $this->fill(2, 'SELL', 'LONG', 10, 0.26, 2000, 25.0),   // the close is the whole thing
            $this->fill(3, 'BUY', 'LONG', 2, 0.05, 3000),           // a clean position afterwards
            $this->fill(4, 'SELL', 'LONG', 2, 0.05, 4000, 1.0),
        ]);

        $this->assertFalse($r[2]['confirmable']);
        $this->assertSame(FeeAttribution::REASON_ENTRY_QTY_SHORT, $r[2]['reason']);
        $this->assertSame(0.10, $r[2]['entry_commission']);        // what was seen, reported not trusted

        // The bucket was reset, so the next position is not polluted by the shortfall.
        $this->assertTrue($r[4]['confirmable']);
        $this->assertSame(0.05, $r[4]['entry_commission']);
        $this->assertSame(0.1, $r[4]['total']);
    }

    public function test_quantity_within_rounding_tolerance_is_a_whole_close(): void
    {
        $r = FeeAttribution::attribute([
            $this->fill(1, 'BUY', 'LONG', 0.123, 0.10, 1000),
            $this->fill(2, 'SELL', 'LONG', 0.12300001, 0.10, 2000, 1.0),
            $this->fill(3, 'BUY', 'LONG', 1, 0.05, 3000),
            $this->fill(4, 'SELL', 'LONG', 1, 0.05, 4000, 1.0),
        ]);

        $this->assertTrue($r[2]['confirmable']);
        $this->assertSame(0.2, $r[2]['total']);
        // ...and it emptied the bucket: the next close sees only its own entry.
        $this->assertSame(0.05, $r[4]['entry_commission']);
    }

    public function test_bnb_commission_anywhere_on_the_position_refuses_the_figure(): void
    {
        $r = FeeAttribution::attribute([
            $this->fill(1, 'BUY', 'LONG', 10, 0.0004, 1000, 0.0, 'BNB'),
            $this->fill(2, 'SELL', 'LONG', 10, 0.26, 2000, 25.0),
        ]);

        $this->assertFalse($r[2]['confirmable']);
        $this->assertSame(FeeAttribution::REASON_NON_USDT, $r[2]['reason']);
        $this->assertSame(['BNB', 'USDT'], $r[2]['assets']);
    }

    public function test_a_zero_amount_fill_in_another_asset_does_not_poison(): void
    {
        $r = FeeAttribution::attribute([
            $this->fill(1, 'BUY', 'LONG', 10, 0.0, 1000, 0.0, 'BNB'),  // free fill, asset irrelevant
            $this->fill(2, 'SELL', 'LONG', 10, 0.26, 2000, 25.0),
        ]);

        $this->assertTrue($r[2]['confirmable']);
        $this->assertSame(['USDT'], $r[2]['assets']);
        $this->assertSame(0.26, $r[2]['total']);
    }

    public function test_hedge_mode_keeps_long_and_short_buckets_apart(): void
    {
        $r = FeeAttribution::attribute([
            $this->fill(1, 'BUY', 'LONG', 10, 0.20, 1000),
            $this->fill(2, 'SELL', 'SHORT', 4, 0.08, 1100),
            $this->funding(0.14, 1500),                             // split 10:4 → 0.10 / 0.04
            $this->fill(3, 'BUY', 'SHORT', 4, 0.08, 2000, -1.0),    // closes SHORT
            $this->fill(4, 'SELL', 'LONG', 10, 0.20, 3000, 5.0),    // closes LONG
        ]);

        $this->assertSame('SHORT', $r[3]['position_side']);
        $this->assertSame(0.08, $r[3]['entry_commission']);
        $this->assertSame(0.04, $r[3]['funding']);
        $this->assertSame(0.2, $r[3]['total']);

        $this->assertSame('LONG', $r[4]['position_side']);
        $this->assertSame(0.2, $r[4]['entry_commission']);
        $this->assertSame(0.1, $r[4]['funding']);
        $this->assertSame(0.5, $r[4]['total']);
    }

    public function test_funding_at_the_same_instant_as_the_close_still_belongs_to_it(): void
    {
        $r = FeeAttribution::attribute([
            $this->fill(1, 'BUY', 'LONG', 10, 0.25, 1000),
            $this->fill(2, 'SELL', 'LONG', 10, 0.26, 2000, 25.0),
            $this->funding(0.30, 2000),
        ]);

        $this->assertSame(0.3, $r[2]['funding']);
    }

    public function test_receipts_are_accepted_as_objects_in_any_order(): void
    {
        $rows = [
            (object) $this->fill(2, 'SELL', 'LONG', 10, 0.26, 2000, 25.0),
            (object) $this->fill(1, 'BUY', 'LONG', 10, 0.25, 1000),
        ];

        $r = FeeAttribution::attribute($rows);

        $this->assertTrue($r[2]['confirmable']);
        $this->assertSame(0.51, $r[2]['total']);
    }
}
