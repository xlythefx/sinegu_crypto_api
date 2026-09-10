<?php

namespace Tests\Feature;

use App\Services\UserStatsService;
use Tests\TestCase;

/**
 * UserStatsService::buildDailyEquityCurve — the dashboard's equity curve.
 *
 * Shape and level are separate concerns here, and both are load-bearing: the
 * line's moves come from realized P&L alone (so funding can never draw itself
 * as a crash), and the whole series is shifted by one constant so its last
 * point is the live equity the exchange reports.
 *
 * Expected figures are derived by hand from the rules.
 */
class EquityCurveTest extends TestCase
{
    /** @param array<string, float> $days */
    private function curve(array $days, float $base, float $equity): array
    {
        return UserStatsService::buildDailyEquityCurve(collect($days), $base, $equity);
    }

    public function test_the_last_point_is_the_live_equity_not_the_reconstruction(): void
    {
        // Base 1,000 + 30 of P&L reconstructs to 1,030, but the exchange says
        // the account holds 900 (fees, unseen transfers — the gap is real and
        // unknowable). The anchor wins.
        $curve = $this->curve(
            ['2026-05-01' => 10.0, '2026-05-02' => 20.0],
            1000.0,
            900.0,
        );

        $this->assertSame(900.0, end($curve)['equity']);
    }

    public function test_a_constant_shift_preserves_every_daily_move(): void
    {
        // Whatever the offset, day-to-day deltas must survive it exactly:
        // +10 then +20, still +10 then +20 after anchoring.
        $curve = $this->curve(
            ['2026-05-01' => 10.0, '2026-05-02' => 20.0, '2026-05-03' => -5.0],
            1000.0,
            900.0,
        );

        // Reconstruction runs 1010 -> 1030 -> 1025; anchored to 900 the offset
        // is -125, so every point drops by exactly that and nothing else moves.
        $equities = array_column($curve, 'equity');
        $this->assertSame(885.0, $equities[0]);
        $this->assertSame(905.0, $equities[1]);
        $this->assertSame(900.0, $equities[2]);
        $this->assertSame(20.0, round($equities[1] - $equities[0], 2));
        $this->assertSame(-5.0, round($equities[2] - $equities[1], 2));
    }

    public function test_funding_never_appears_as_a_step_on_the_line(): void
    {
        // The curve is built from P&L days only — deposits and withdrawals
        // reach it solely through $base. The live master's 8,162.73 withdrawal
        // on 2026-08-28 used to draw a full-depth spike; there is now no code
        // path by which a transfer can become a point.
        $curve = $this->curve(['2026-08-28' => 12.0, '2026-08-29' => 8.0], 5000.0, 5020.0);

        $this->assertCount(2, $curve);
        foreach ($curve as $point) {
            $this->assertGreaterThan(4000.0, $point['equity']);
        }
    }

    public function test_an_account_that_has_closed_nothing_is_a_single_live_point(): void
    {
        $curve = $this->curve([], 2500.0, 2500.0);

        $this->assertCount(1, $curve);
        $this->assertSame(2500.0, $curve[0]['equity']);
    }

    public function test_each_point_carries_its_trading_day(): void
    {
        $curve = $this->curve(['2026-05-01' => 10.0, '2026-05-02' => 20.0], 1000.0, 1030.0);

        $this->assertSame('2026-05-01', $curve[0]['date']);
        $this->assertSame('2026-05-02', $curve[1]['date']);
        // `at` is the end of that day, so a point sorts after the trades it sums.
        $this->assertStringStartsWith('2026-05-01T23:59:59', $curve[0]['at']);
    }

    /** @param list<float> $equities */
    private function sharpe(array $equities): ?float
    {
        return UserStatsService::sharpeFromCurve(
            array_map(fn (float $e) => ['equity' => $e], $equities),
        );
    }

    public function test_sharpe_is_measured_on_returns_so_account_size_cancels(): void
    {
        // Daily returns +10% then +20%: mean 0.15, population σ 0.05,
        // 0.15 / 0.05 * sqrt(252) = 3 * 15.8745 = 47.62.
        $this->assertSame(47.62, $this->sharpe([100.0, 110.0, 132.0]));

        // The same two returns on an account 100x larger must score identically
        // — the old dollar-based version scored it 100x differently.
        $this->assertSame(47.62, $this->sharpe([10000.0, 11000.0, 13200.0]));
    }

    public function test_sharpe_is_null_when_there_is_nothing_to_measure(): void
    {
        $this->assertNull($this->sharpe([1000.0]));           // no returns
        $this->assertNull($this->sharpe([1000.0, 1010.0]));   // one return
        $this->assertNull($this->sharpe([100.0, 110.0, 121.0])); // flat +10%, no deviation
    }

    public function test_the_opening_point_reflects_real_capital_not_the_first_trade(): void
    {
        // The regression this model replaced: the live master's first closed
        // trade was +2.52 and its funding history did not start until seven
        // weeks later, so a replay from zero opened the account at $2.52.
        // Anchored to a real 6,429.34 equity with 514.21 of realized P&L, the
        // curve opens near the ~5,915 of capital that actually earned it.
        $curve = $this->curve(
            ['2026-05-11' => 2.52, '2026-09-04' => 511.69],
            6968.37,
            6429.34,
        );

        $this->assertGreaterThan(5000.0, $curve[0]['equity']);
        $this->assertSame(6429.34, end($curve)['equity']);
    }
}
