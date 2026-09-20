<?php

namespace App\Http\Controllers;

use App\Models\UserCredential;
use App\Services\Exchanges\ExchangeSchema;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    /** The `exchange` value of the record that pools every exchange. */
    public const ALL_EXCHANGES = 'all';

    /**
     * Most tickers one request may narrow to. Every distinct filter is its own
     * cached computation, and the filter is caller-supplied on a public route,
     * so the space of keys is capped here and the route is throttled.
     */
    private const MAX_SYMBOLS = 5;

    /**
     * GET /api/public/track-record[?symbols=LTCUSDT,…]
     * The master account's verified track record: daily percentage returns,
     * their running sum, and the headline stats — feeds the landing page's
     * "See every trade, verified" section. Every supported exchange the master
     * trades on is pooled as ONE portfolio (capital summed, P&L summed).
     *
     * `symbols` narrows the TRADES to those tickers (2026-09-18: the landing
     * page shows the LTC/USDT strategy alone). The capital is still the whole
     * account's — it is not partitioned per asset, every position is backed by
     * all of it — so a filtered `roc` is "what that strategy made on the
     * capital it had". The applied filter is echoed as `symbols` so the page
     * labels what it shows rather than claiming the whole account.
     *
     * Always 200. `available: false` means there is nothing to publish yet
     * (no master account, or no closed trades in scope) — the landing page
     * renders its empty state rather than inventing numbers.
     */
    public function trackRecord(Request $request): JsonResponse
    {
        return response()->json($this->cached(null, $this->symbolsFilter($request)));
    }

    /**
     * GET /api/public/track-record/{exchange}[?symbols=…]
     * The same record restricted to one exchange's accounts, capital and
     * trades. The Telegram recaps read this, one message per exchange, so the
     * channel that labels every entry `LTCUSDT · Binance` recaps the same way.
     * The route's whereIn keeps unknown names out of here. The recaps send no
     * `symbols`: the channel announces every asset's entries and exits, so its
     * recap covers every asset.
     */
    public function trackRecordForExchange(Request $request, string $exchange): JsonResponse
    {
        return response()->json($this->cached($exchange, $this->symbolsFilter($request)));
    }

    /**
     * @param  list<string>  $symbols  normalized, sorted, deduplicated
     */
    private function cached(?string $exchange, array $symbols): array
    {
        $key = self::CACHE_KEY.'.'.($exchange ?? self::ALL_EXCHANGES)
            .($symbols ? '.'.implode('+', $symbols) : '');

        return Cache::remember($key, self::CACHE_TTL_SECONDS, fn () => $this->compute($exchange, $symbols));
    }

    /**
     * The `symbols` query as a canonical list: each name reduced to its
     * {@see symbolKey}, shape-checked, deduplicated, sorted, capped. Anything
     * that does not look like a ticker is dropped rather than rejected — a
     * public route answers 200 with the record it CAN publish.
     *
     * @return list<string>
     */
    private function symbolsFilter(Request $request): array
    {
        $keys = [];
        foreach (explode(',', (string) $request->query('symbols', '')) as $part) {
            $key = self::symbolKey($part);
            if ($key !== '' && preg_match('/^[A-Z0-9]{3,20}$/', $key)) {
                $keys[$key] = true;
            }
        }
        $symbols = array_keys($keys);
        sort($symbols);

        return array_slice($symbols, 0, self::MAX_SYMBOLS);
    }

    /**
     * One name for one market across venues: Binance stores `LTCUSDT`, MEXC
     * `LTC_USDT`. Stripping punctuation and case lets a single filter match
     * the same market wherever the master trades it.
     */
    private static function symbolKey(string $symbol): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $symbol));
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
    private function compute(?string $exchange, array $symbols = []): array
    {
        $master = UserCredential::where('type', 'master')->first();

        if (! $master) {
            return $this->unavailable($exchange, $symbols);
        }

        $exchanges = $exchange === null ? ExchangeSchema::supported() : [$exchange];

        // Scoped by API KEY, not by uni_id: the master's uni_id can also own a
        // TESTNET account (demo=1) and invoice-sandbox scratch accounts, whose
        // trades are play money and must never be published as a verified
        // record. Soft-deleted real accounts are deliberately kept (DB::table
        // applies no soft-delete scope) — a key that has since been
        // disconnected still traded real money, and dropping it would rewrite
        // history every time an account is rotated.
        //
        // Each exchange has its own four tables (ExchangeSchema); the pooled
        // record walks all of them into ONE set of per-day buckets. An API key
        // is unique within its exchange, so trades and transfers are read per
        // exchange against that exchange's own keys, never across.
        $openingCapital = 0.0;
        $contributing = [];   // exchanges on which the master has a real account
        $trades = collect();
        $transactions = collect();
        foreach ($exchanges as $name) {
            $schema = ExchangeSchema::for($name);
            $accounts = DB::table($schema->accountsTable)
                ->where('uni_id', $master->uni_id)
                ->where('demo', 0)
                ->where('is_sandbox', 0)
                ->get(['api_key', 'initial_deposit']);
            if ($accounts->isEmpty()) {
                continue;
            }
            $contributing[] = $name;
            $apiKeys = $accounts->pluck('api_key')->all();
            $openingCapital += (float) $accounts->sum(fn ($a) => (float) $a->initial_deposit);
            $trades = $trades->concat(
                DB::table($schema->pastPositions)
                    ->whereIn('api_key', $apiKeys)
                    ->get(['realized_pnl', 'closed_at', 'symbol'])
            );
            $transactions = $transactions->concat(
                DB::table($schema->transactions)
                    ->whereIn('api_key', $apiKeys)
                    ->get(['type', 'amount', 'created_at'])
            );
        }

        // The filter narrows the TRADES only; the transactions above still
        // seed the capital, because the whole account backs every position.
        if ($symbols) {
            $trades = $trades->filter(
                fn ($t) => in_array(self::symbolKey((string) $t->symbol), $symbols, true)
            );
        }

        if (! $contributing || $trades->isEmpty()) {
            return $this->unavailable($exchange, $symbols);
        }

        $trades = $trades->sortBy('closed_at')->values();
        $cumulativePnl = 0.0;   // running realized P&L, for return-on-capital

        // --- Per-day aggregates ---------------------------------------------
        // Also split per SYMBOL within the day, for the per-asset ranking each
        // series point carries. A ticker is not private: the public channel
        // already names it on every entry and exit it announces.
        //
        // "A day" is a calendar day in the reporting timezone, not UTC — see
        // `services.track_record.timezone`. Timestamps are stored in UTC, so a
        // trade closed at 20:00 UTC belongs to the NEXT Manila day.
        //
        // A "trade" is a ROW — one close order — not the increments it took
        // off. Between 2026-09-17 and 2026-09-20 this summed `increments_closed`
        // instead, so a 2-increment stack closed in one order published as
        // "2 trades closed"; the owner counts a close as one trade, and that
        // is what the reference bot's recap (one row, one trade) prints. The
        // column stays: the close message still says `Increments Closed (n/cap)`.
        $timezone = $this->timezone();
        $pnlByDay = [];
        $tradesByDay = [];
        $pnlBySymbol = [];     // [day][symbol] => realized P&L
        $tradesBySymbol = [];  // [day][symbol] => close orders
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
        // The cost of fixing it is that a new deposit rescales every past `roc`
        // — the whole curve compresses, its shape untouched. Accepted (2026-09-18)
        // for the landing page's CUMULATIVE view, which draws `roc` rather than
        // `cumulative`: `roc` is the dashboard's equity curve as a percentage
        // (the dollar curve over one constant), so the two rise together and
        // the curve ends on the Return on Capital card. The compounded
        // `cumulative` — which on this account fell to −17% while the equity
        // climbed, because chaining measured the early losses against ~1k and
        // every later gain against ~6k — is still published, just not drawn.
        // The DAILY figures (`pct`) keep the per-day capital basis: the
        // Telegram recaps post them, and a day must read the same on the site
        // and in the channel.
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
            return $this->unavailable($exchange, $symbols);
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
            'exchange' => $exchange ?? self::ALL_EXCHANGES,
            // Which exchanges the figures are drawn from — names only, never
            // how many accounts. On the pooled record this is what tells a
            // reader "Binance only, so far".
            'exchanges' => $contributing,
            // The ticker filter applied, canonical form; [] is the whole
            // account. A ticker is already public on every announced entry.
            'symbols' => $symbols,
            'timezone' => $timezone,
            'stats' => [
                // TWO different questions, deliberately both published:
                //  return_on_capital_pct — what every dollar committed has
                //    returned so far. The headline, the figure the Telegram
                //    recap quotes as "All-time", and where the landing page's
                //    cumulative curve (the per-day `roc`) ends — one figure,
                //    so the card and the curve can never disagree.
                //  total_pnl_pct — the compounded (time-weighted) return, which
                //    is what the series' `cumulative` points build to. Kept
                //    for consumers of that series; the site no longer draws it.
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
    private function unavailable(?string $exchange, array $symbols = []): array
    {
        return [
            'success' => true,
            'available' => false,
            'exchange' => $exchange ?? self::ALL_EXCHANGES,
            'exchanges' => [],
            'symbols' => $symbols,
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
