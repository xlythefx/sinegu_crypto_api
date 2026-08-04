<?php

namespace App\Http\Controllers;

use App\Models\UserCredential;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Unauthenticated endpoints for the marketing site (no Sanctum, no admin).
 *
 * PRIVACY RULE — everything here is world-readable, so it may only ever expose
 * PERCENTAGES and counts derived from the master account. No balances, no USD
 * amounts, no account names, no user data. Same rule as the public Telegram
 * channel: the track record is a percentage, never an amount.
 */
class PublicStatsController extends Controller
{
    /** How long a computed track record is served from cache. */
    private const CACHE_TTL_SECONDS = 300;

    private const CACHE_KEY = 'public.track-record';

    /**
     * GET /api/public/track-record
     * The master account's verified track record: daily percentage returns,
     * their running sum, and the headline stats — feeds the landing page's
     * "See every trade, verified" section.
     *
     * Always 200. `available: false` means there is nothing to publish yet
     * (no master account, or no closed trades) — the landing page renders its
     * empty state rather than inventing numbers.
     */
    public function trackRecord(): JsonResponse
    {
        return response()->json(
            Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, fn () => $this->compute())
        );
    }

    /**
     * Percentage track record of the master account.
     *
     * Each trading day's return is that day's realized P&L over the capital the
     * account started the day with (net deposits + realized P&L to date), so a
     * mid-history deposit doesn't dilute earlier days. The headline figures are
     * simple sums/means of those daily percentages — "+0.34% per trading day"
     * across 126 days reads as "+42.9% total", which is what the page claims.
     * Compounding is deliberately NOT applied: it would make the total diverge
     * from the daily average shown beside it.
     */
    private function compute(): array
    {
        $master = UserCredential::where('type', 'master')->first();

        if (! $master) {
            return $this->unavailable();
        }

        $trades = DB::table('binance_pastpositions')
            ->where('uni_id', $master->uni_id)
            ->orderBy('closed_at')
            ->get(['realized_pnl', 'closed_at']);

        if ($trades->isEmpty()) {
            return $this->unavailable();
        }

        // --- Per-day aggregates ---------------------------------------------
        $pnlByDay = [];
        $tradesByDay = [];
        foreach ($trades as $trade) {
            $day = substr((string) $trade->closed_at, 0, 10);
            $pnlByDay[$day] = ($pnlByDay[$day] ?? 0) + (float) $trade->realized_pnl;
            $tradesByDay[$day] = ($tradesByDay[$day] ?? 0) + 1;
        }

        $flowByDay = [];
        $transactions = DB::table('binance_transactions')
            ->where('uni_id', $master->uni_id)
            ->get(['type', 'amount', 'created_at']);
        foreach ($transactions as $tx) {
            $day = substr((string) $tx->created_at, 0, 10);
            $delta = (float) $tx->amount * ($tx->type === 'WITHDRAWAL' ? -1 : 1);
            $flowByDay[$day] = ($flowByDay[$day] ?? 0) + $delta;
        }

        // --- Walk the timeline day by day ------------------------------------
        $days = array_unique(array_merge(array_keys($pnlByDay), array_keys($flowByDay)));
        sort($days);

        $capital = 0.0;       // net deposits + realized P&L so far
        $cumulative = 0.0;    // running sum of daily percentages
        $series = [];
        $dayPercents = [];

        foreach ($days as $day) {
            // Deposits/withdrawals land before the day's trades, so that day's
            // return is measured against the capital it was actually sized on.
            $capital += $flowByDay[$day] ?? 0.0;

            $pnl = $pnlByDay[$day] ?? null;
            if ($pnl !== null && $capital > 0) {
                $percent = $pnl / $capital * 100;
                $cumulative += $percent;
                $dayPercents[] = $percent;
                $series[] = [
                    'date' => $day,
                    'pct' => round($percent, 3),
                    'cumulative' => round($cumulative, 3),
                    'trades' => $tradesByDay[$day] ?? 0,
                ];
            }

            $capital += $pnl ?? 0.0;
        }

        if (! $series) {  // trades exist but no capital was ever recorded
            return $this->unavailable();
        }

        // --- Headline stats ---------------------------------------------------
        $wins = array_values(array_filter($dayPercents, fn ($p) => $p > 0));
        $losses = array_values(array_filter($dayPercents, fn ($p) => $p < 0));
        $tradingDays = count($dayPercents);

        $mean = fn (array $values) => $values ? array_sum($values) / count($values) : null;
        $round = fn (?float $value, int $precision = 2) => $value === null ? null : round($value, $precision);

        return [
            'success' => true,
            'available' => true,
            'stats' => [
                'total_pnl_pct' => $round($cumulative),
                'win_rate' => $tradingDays ? round(count($wins) / $tradingDays * 100, 1) : null,
                'trades' => $trades->count(),
                'avg_daily_pct' => $round($mean($dayPercents)),
                'avg_win_pct' => $round($mean($wins)),
                'avg_loss_pct' => $round($mean($losses)),
                'trading_days' => $tradingDays,
                'winning_days' => count($wins),
                'losing_days' => count($losses),
                'first_trade_at' => $series[0]['date'],
                'last_trade_at' => $series[count($series) - 1]['date'],
            ],
            'series' => $series,
        ];
    }

    /** Nothing to publish yet — a shape the landing page can render safely. */
    private function unavailable(): array
    {
        return [
            'success' => true,
            'available' => false,
            'stats' => null,
            'series' => [],
        ];
    }
}
