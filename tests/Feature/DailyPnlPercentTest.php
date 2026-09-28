<?php

namespace Tests\Feature;

use App\Models\UserCredential;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * The P&L calendar's percentage is each day's P&L over the balance THAT day
 * started with (after its transfers, before its trades) — never over today's
 * balance, which let a deposit rewrite every day already on screen.
 *
 * Seeded (initial_deposit 1000, both closes after the fee cutoff):
 *   2026-09-14  close net 49.50  fee 0.50  → start 1000.00
 *   2026-09-15  deposit 1000, close net −20.90  fee 0.50
 *               → start 1000 + 49.50 + 1000 = 2049.50
 */
class DailyPnlPercentTest extends EngineTestCase
{
    private string $uniId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->uniId = $this->makeUser();
        Sanctum::actingAs(UserCredential::query()->find($this->uniId));
    }

    private function trade(int $orderId, string $closedAt, float $net, float $fee): void
    {
        DB::table('binance_pastpositions')->insert([
            'uni_id' => $this->uniId,
            'api_key' => 'pct-key',
            'symbol' => 'LTCUSDT',
            'position_side' => 'LONG',
            'position_amt' => 10,
            'exit_price' => 50,
            'realized_pnl' => $net,
            'exchange_fee' => $fee,
            'fee_source' => 'actual',
            'side' => 'SELL',
            'order_id' => $orderId,
            'strategy' => 'ABCD-v1',
            'closed_at' => $closedAt,
            'created_at' => now(),
        ]);
    }

    public function test_each_day_is_measured_on_the_balance_it_started_with(): void
    {
        $this->makeAccount($this->uniId, ['initial_deposit' => 1000, 'balance' => 2028.6, 'api_key' => 'pct-key']);
        $this->trade(1, '2026-09-14 10:00:00', 49.5, 0.5);
        DB::table('binance_transactions')->insert([
            'uni_id' => $this->uniId,
            'api_key' => 'pct-key',
            'type' => 'DEPOSIT',
            'amount' => 1000,
            'currency' => 'USDT',
            'created_at' => '2026-09-15 09:00:00',
        ]);
        $this->trade(2, '2026-09-15 10:00:00', -20.9, 0.5);

        $days = $this->getJson('/api/dashboard/daily-pnl')->assertOk()->json('days');

        // The deposit on the 15th does not touch the 14th.
        $this->assertEquals(1000.0, $days['2026-09-14']['start_balance']);
        $this->assertEquals(4.95, $days['2026-09-14']['pct']);
        $this->assertEquals(5.0, $days['2026-09-14']['pct_gross']);

        $this->assertEquals(2049.5, $days['2026-09-15']['start_balance']);
        $this->assertEquals(-1.02, $days['2026-09-15']['pct']);       // −20.90 / 2049.50
        $this->assertEquals(-1.0, $days['2026-09-15']['pct_gross']);  // −20.40 / 2049.50
    }

    public function test_no_capital_on_record_gives_no_percentage_rather_than_a_huge_one(): void
    {
        $this->makeAccount($this->uniId, ['initial_deposit' => 0, 'balance' => 50, 'api_key' => 'pct-key']);
        $this->trade(1, '2026-09-14 10:00:00', 49.5, 0.5);

        $day = $this->getJson('/api/dashboard/daily-pnl')->assertOk()->json('days.2026-09-14');

        $this->assertEquals(49.5, $day['total']);
        $this->assertNull($day['pct']);
        $this->assertNull($day['pct_gross']);
    }
}
