<?php

namespace App\Http\Controllers;

use App\Models\UserCredential;
use Carbon\Carbon;
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
     * account started the day with, so a mid-history deposit doesn't dilute
     * earlier days. That capital is the same figure the rest of the system
     * sizes and bills on — `initial_deposit + (deposits − withdrawals)`, per
     * {@see \App\Services\Pnl\BinancePnlSource} — plus realized P&L to date.
     *
     * Seeding it from `initial_deposit` is load-bearing, not a nicety.
     * `binance_transactions` only holds transfers the poller has SEEN; an
     * account funded before it was connected has none, so a walk that started
     * at zero divided day one's P&L by the pennies of profit that happened to
     * come before it. On the live master that published −980% against a real
     * +490 on ~1,053 of capital.
     *
     * The total is those daily returns CHAINED, not summed — a time-weighted
     * return, so it is the growth the account actually delivered. The earlier
     * sum overstated it (+62.9% against a real +46.6% on the live master),
     * because adding a percentage earned on a small base to one earned on a
     * larger base counts the same profit twice. Deposits still cannot inflate
     * it: each day's return is already measured on that day's own capital, and
     * chaining ratios never sees the flows between them.
     *
     * The consequence is deliberate: `avg_daily_pct × trading_days` no longer
     * reconciles with the total. It is an arithmetic mean of daily returns,
     * beside a compounded total — the page labels them as such. Whichever the
     * two disagree about, the total is the one a customer can verify against a
     * balance, and this section's whole claim is that it is verifiable.
     */
    private function compute(): array
    {
        $master = UserCredential::where('type', 'master')->first();

        if (! $master) {
            return $this->unavailable();
        }

        // Scoped by API KEY, not by uni_id: the master's uni_id can also own a
        // TESTNET account (demo=1) and invoice-sandbox scratch accounts, whose
        // trades are play money and must never be published as a verified
        // record. Soft-deleted real accounts are deliberately kept (DB::table
        // applies no soft-delete scope) — a key that has since been
        // disconnected still traded real money, and dropping it would rewrite
        // history every time an account is rotated.
        $accounts = DB::table('binance_accounts')
            ->where('uni_id', $master->uni_id)
            ->where('demo', 0)
            ->where('is_sandbox', 0)
            ->get(['api_key', 'initial_deposit']);

        if ($accounts->isEmpty()) {
            return $this->unavailable();
        }

        $apiKeys = $accounts->pluck('api_key')->all();
        $openingCapital = (float) $accounts->sum(fn ($a) => (float) $a->initial_deposit);
        $cumulativePnl = 0.0;   // running realized P&L, for return-on-capital

        $trades = DB::table('binance_pastpositions')
            ->whereIn('api_key', $apiKeys)
            ->orderBy('closed_at')
            ->get(['realized_pnl', 'closed_at', 'symbol']);

        if ($trades->isEmpty()) {
            return $this->unavailable();
        }

        // --- Per-day aggregates ---------------------------------------------
        // Also split per SYMBOL within the day, for the per-asset ranking each
        // series point carries. A ticker is not private: the public channel
        // already names it on every entry and exit it announces.
        //
        // "A day" is a calendar day in the reporting timezone, not UTC — see
        // `services.track_record.timezone`. Timestamps are stored in UTC, so a
        // trade closed at 20:00 UTC belongs to the NEXT Manila day.
        $timezone = $this->timezone();
        $pnlByDay = [];
        $tradesByDay = [];
        $pnlBySymbol = [];     // [day][symbol] => realized P&L
        $tradesBySymbol = [];  // [day][symbol] => closed trades
        foreach ($trades as $trade) {
            $day = $this->localDay($trade->closed_at, $timezone);
            $symbol = (string) $trade->symbol;
            $pnl = (float) $trade->realized_pnl;
            $pnlByDay[$day] = ($pnlByDay[$day] ?? 0) + $pnl;
            $tradesByDay[$day] = ($tradesByDay[$day] ?? 0) + 1;
            $pnlBySymbol[$day][$symbol] = ($pnlBySymbol[$day][$symbol] ?? 0) + $pnl;
            $tradesBySymbol[$day][$symbol] = ($tradesBySymbol[$day][$symbol] ?? 0) + 1;
        }

        $flowByDay = [];
        $transactions = DB::table('binance_transactions')
            ->whereIn('api_key', $apiKeys)
            ->get(['type', 'amount', 'created_at']);
        foreach ($transactions as $tx) {
            $day = $this->localDay($tx->created_at, $timezone);
            $delta = (float) $tx->amount * ($tx->type === 'WITHDRAWAL' ? -1 : 1);
            $flowByDay[$day] = ($flowByDay[$day] ?? 0) + $delta;
        }

        // --- Walk the timeline day by day ------------------------------------
        $days = array_unique(array_merge(array_keys($pnlByDay), array_keys($flowByDay)));
        sort($days);

        // Every dollar ever committed, net of what was taken back out — the same
        // `initial_deposit + (deposits − withdrawals)` the rest of the system
        // sizes and bills on. It is the denominator of `roc` below, and it is
        // FIXED across the whole series rather than running: a running one would
        // make the line leap upward the day a deposit lands, with no trading
        // behind the jump (on the live master, −25.6% to −3.2% in one July day).
        //
        // The cost of fixing it is that a new deposit rescales every past `roc`.
        // That is why `roc` is a HEADLINE figure only and `cumulative` still
        // draws the chart: the chart's compounded points are never revised,
        // which is the property that makes a published percentage checkable.
        $capitalContributed = $openingCapital + array_sum($flowByDay);

        $capital = $openingCapital;  // + net flows and realized P&L as we walk
        $growth = 1.0;        // compounded factor across the trading days so far
        $series = [];
        $dayPercents = [];

        foreach ($days as $day) {
            // Deposits/withdrawals land before the day's trades, so that day's
            // return is measured against the capital it was actually sized on.
            $capital += $flowByDay[$day] ?? 0.0;

            $pnl = $pnlByDay[$day] ?? null;
            if ($pnl !== null && $capital > 0) {
                $percent = $pnl / $capital * 100;
                // Floored at 0: a day that loses more than the whole account
                // cannot compound into a negative factor, which would flip the
                // sign of every day after it. The `$capital > 0` guard then
                // ends the walk anyway.
                $growth *= max(0.0, 1 + $percent / 100);
                $dayPercents[] = $percent;
                $cumulativePnl += $pnl;
                $series[] = [
                    'date' => $day,
                    'pct' => round($percent, 3),
                    'cumulative' => round(($growth - 1) * 100, 3),
                    // Return on capital as of this day. Carried per-day, not
                    // just as a headline, so a recap can quote the figure as it
                    // stood at the END of the period it reports — a monthly
                    // posted on the 30th must not include the 31st.
                    'roc' => $capitalContributed > 0
                        ? round($cumulativePnl / $capitalContributed * 100, 3)
                        : null,
                    'trades' => $tradesByDay[$day] ?? 0,
                    'assets' => $this->rankAssets(
                        $pnlBySymbol[$day] ?? [], $tradesBySymbol[$day] ?? [], $capital
                    ),
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
            'timezone' => $timezone,
            'stats' => [
                // TWO different questions, deliberately both published:
                //  return_on_capital_pct — what every dollar committed has
                //    returned so far. The headline, and the figure the Telegram
                //    recap quotes as "All-time".
                //  total_pnl_pct — the compounded (time-weighted) return, which
                //    is what the chart's `cumulative` points build to. Kept as
                //    the chart's own total so the curve and its endpoint can
                //    never disagree.
                // They differ whenever capital arrived unevenly — on the live
                // master, most of it landed AFTER the losing early months, so
                // the compounded figure is much the harsher of the two. Label
                // both wherever they appear; presented bare they read as a
                // contradiction rather than as two measures.
                'return_on_capital_pct' => $capitalContributed > 0
                    ? $round($cumulativePnl / $capitalContributed * 100)
                    : null,
                'total_pnl_pct' => $round(($growth - 1) * 100),
                'win_rate' => $tradingDays ? round(count($wins) / $tradingDays * 100, 1) : null,
                // Only trades on PUBLISHED days. The page reads this as
                // "verified from N closed trades over M trading days", so a
                // trade on a day the walk could not price does not belong in it.
                'trades' => array_sum(array_column($series, 'trades')),
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

    /**
     * One day's assets, best first: each symbol's realized P&L over the SAME
     * capital the day's own `pct` is measured on, so the shares add up to the
     * day's return (rounding aside). Percentages and trade counts only — the
     * same privacy rule as everything else in the payload. Ordered on the raw
     * P&L rather than the rounded percent so two symbols never tie on a
     * rounding artefact; ties on the money itself fall back to the symbol.
     *
     * @param  array<string, float>  $pnlBySymbol
     * @param  array<string, int>  $tradesBySymbol
     * @return list<array{symbol: string, pct: float, trades: int}>
     */
    private function rankAssets(array $pnlBySymbol, array $tradesBySymbol, float $capital): array
    {
        $ranked = [];
        foreach ($pnlBySymbol as $symbol => $pnl) {
            $ranked[] = [
                'symbol' => $symbol,
                'pct' => round($pnl / $capital * 100, 3),
                'trades' => $tradesBySymbol[$symbol] ?? 0,
                '_pnl' => $pnl,
            ];
        }
        usort($ranked, fn ($a, $b) => ($b['_pnl'] <=> $a['_pnl']) ?: strcmp($a['symbol'], $b['symbol']));

        return array_map(fn ($row) => [
            'symbol' => $row['symbol'],
            'pct' => $row['pct'],
            'trades' => $row['trades'],
        ], $ranked);
    }

    /** Nothing to publish yet — a shape the landing page can render safely. */
    private function unavailable(): array
    {
        return [
            'success' => true,
            'available' => false,
            'timezone' => $this->timezone(),
            'stats' => null,
            'series' => [],
        ];
    }

    /**
     * The calendar the series is bucketed in. Published on the payload so a
     * consumer that slices it into windows (the Telegram recaps) uses the same
     * day boundaries rather than assuming UTC.
     */
    private function timezone(): string
    {
        return (string) config('services.track_record.timezone', 'UTC');
    }

    /** The `Y-m-d` a UTC timestamp falls on in the reporting timezone. */
    private function localDay(mixed $utcTimestamp, string $timezone): string
    {
        return Carbon::parse((string) $utcTimestamp, 'UTC')->setTimezone($timezone)->toDateString();
    }
}
