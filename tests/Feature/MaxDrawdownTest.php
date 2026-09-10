<?php

namespace Tests\Feature;

use App\Services\UserStatsService;
use Tests\TestCase;

/**
 * UserStatsService::maxDrawdownPct — the dashboard's Max Drawdown cell.
 *
 * The property under test is what the metric is measured ON: cumulative
 * realized P&L over a real capital base, never the equity curve. Measuring it
 * on the equity curve published −3392.46% for the live master, because that
 * curve starts at zero and its running peak was the first trade's own $2.52
 * profit when an −82.30 trade closed two days later.
 *
 * Expected figures are derived by hand from the rules, not read back out of
 * the implementation.
 */
class MaxDrawdownTest extends TestCase
{
    public function test_the_loss_is_measured_against_capital_not_against_the_first_trade(): void
    {
        // The live master's opening days: +2.52 on 2026-05-11, then −82.30 on
        // 2026-05-13, with no funding recorded until July. On $1,000 of capital
        // that is a $82.30 dip from a $1,002.52 peak = 8.21%, not 3392%.
        $this->assertSame(
            -8.21,
            UserStatsService::maxDrawdownPct([2.52, -82.30], 1000.0),
        );
    }

    public function test_a_withdrawal_cannot_register_as_a_drawdown(): void
    {
        // Funding is not part of the input at all, which is the point: the
        // master withdrew 8,162.73 on 2026-08-28 and that must not read as a
        // loss. Winning days only => no drawdown.
        $this->assertSame(
            0.0,
            UserStatsService::maxDrawdownPct([100.0, 200.0, 14.5], 5915.78),
        );
    }

    public function test_the_percentage_uses_the_peak_at_the_trough_not_the_final_peak(): void
    {
        // 1000 -> 1100 (peak) -> 1050 (trough, −50) -> 1550.
        // 50 / 1100 = 4.55%. Dividing by the eventual 1550 peak would understate
        // a drawdown that had already happened.
        $this->assertSame(
            -4.55,
            UserStatsService::maxDrawdownPct([100.0, -50.0, 500.0], 1000.0),
        );
    }

    public function test_the_deepest_dip_wins_not_the_first_one(): void
    {
        // 1000 -> 990 (−10) -> 1000 -> 900. The second dip is 100 off a peak of
        // 1000 = 10%.
        $this->assertSame(
            -10.0,
            UserStatsService::maxDrawdownPct([-10.0, 10.0, -100.0], 1000.0),
        );
    }

    public function test_no_capital_base_yields_no_drawdown_rather_than_a_division(): void
    {
        // pct_base falls back to max(equity, 1), so this is the degenerate
        // account: nothing funded, nothing to measure against.
        $this->assertSame(0.0, UserStatsService::maxDrawdownPct([-50.0], 0.0));
        $this->assertSame(0.0, UserStatsService::maxDrawdownPct([], 1000.0));
    }
}
