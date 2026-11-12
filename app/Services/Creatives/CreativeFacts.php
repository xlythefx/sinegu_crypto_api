<?php

namespace App\Services\Creatives;

use App\Http\Controllers\PublicStatsController;
use App\Models\CreativeCharacter;
use App\Models\CreativePlan;
use Carbon\CarbonImmutable;

/**
 * The FACTS block of the AI writer: everything the model may state as fact,
 * and nothing else.
 *
 * PRIVACY RULE — the only performance figures in here come from the PUBLISHED
 * track record ({@see PublicStatsController::record()}: the payload of
 * GET /api/public/track-record, which the landing page and the Telegram
 * recaps already publish). Percentages, trade counts and coin names; never a
 * dollar amount, a balance, an account count, a name or a uni_id. It is not
 * re-walked here: the series is sliced and chained, the same way the
 * engine's weekly recap does it, so the AI can never quote a figure the site
 * and the channel do not.
 *
 * The rest is brand facts (static), the character's text, the platform's
 * rules and the titles of recent plans — the team's own words, run through
 * {@see CreativeAiPrompt::scrub()} so a typed email or amount never leaves.
 */
final class CreativeFacts
{
    /** The window of the default recap: the last 7 days, today included. */
    public const WINDOW_DAYS = 7;

    /** The longest window the chat agent may ask for (get_week_results). */
    public const MAX_WINDOW_DAYS = 31;

    /** Recent plan titles sent so the model does not repeat itself. */
    public const RECENT_TITLES = 15;

    /** Coins listed in `top_coins`. */
    public const TOP_COINS = 3;

    public const PLATFORMS = [
        'x' => [
            'name' => 'X (Twitter)',
            'caption_max_chars' => 280,
            'caption_rule' => 'Caption at most 280 characters INCLUDING the hashtags and the disclaimer line.',
            'video' => '16:9 or 1:1',
            'notes' => 'Short, punchy, one idea per post.',
        ],
        'tiktok' => [
            'name' => 'TikTok',
            'caption_max_chars' => 2200,
            'caption_rule' => 'Caption at most 2,200 characters including the hashtags.',
            'video' => '9:16 vertical, 15 to 30 seconds',
            'notes' => 'A strong hook in the first 2 seconds; quick cuts; on-screen text.',
        ],
    ];

    public function __construct(private PublicStatsController $stats) {}

    /** The Asia/Manila (track-record) calendar's today. */
    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone())->startOfDay();
    }

    /**
     * The Facts shape of the API contract for the $days days (default 7,
     * 1–31) ending on $endDay (default today, in the track record's calendar):
     * `{available, period, return_pct, trades, trading_days, green_days,
     * best_day, top_coins, daily, exchange, coin}`. `available: false` =
     * nothing published in that window; the model is then told it has no
     * figures.
     *
     * $exchange narrows it to one exchange's slice of the published record
     * (measured on that exchange's own capital, exactly as
     * /api/public/track-record/{exchange} publishes it); $coin to one market
     * (its trades over the whole account's capital, as `?symbols=` does).
     * Null = every exchange / every coin. `daily` is the window's days, one
     * entry per day with closed trades: `{date, pct, trades}`.
     */
    public function performance(?CarbonImmutable $endDay = null, int $days = self::WINDOW_DAYS, ?string $exchange = null, ?string $coin = null): array
    {
        $timezone = $this->timezone();
        $days = max(1, min(self::MAX_WINDOW_DAYS, $days));
        $end = ($endDay ?? $this->today())->setTimezone($timezone)->startOfDay();
        $start = $end->subDays($days - 1);
        $period = self::periodLabel($start, $end);
        $coin = $coin !== null && trim($coin) !== '' ? PublicStatsController::symbolKey($coin) : null;
        $scope = ['exchange' => $exchange ?? 'any', 'coin' => $coin];

        $record = $exchange === null && $coin === null
            ? $this->stats->record()
            : $this->stats->recordFor($exchange, $coin !== null ? [$coin] : []);
        $points = [];
        if (($record['available'] ?? false) === true && is_array($record['series'] ?? null)) {
            $from = $start->toDateString();
            $to = $end->toDateString();
            foreach ($record['series'] as $point) {
                $date = (string) ($point['date'] ?? '');
                if ($date >= $from && $date <= $to && is_numeric($point['pct'] ?? null)) {
                    $points[] = $point;
                }
            }
        }

        if ($points === []) {
            return [
                'available' => false,
                'period' => $period,
                'return_pct' => null,
                'trades' => 0,
                'trading_days' => 0,
                'green_days' => 0,
                'best_day' => null,
                'top_coins' => [],
                'daily' => [],
            ] + $scope;
        }

        // Chained, not summed — exactly the engine's weekly Telegram recap.
        $growth = 1.0;
        $trades = 0;
        $green = 0;
        $best = null;
        $shares = [];
        $daily = [];
        foreach ($points as $point) {
            $pct = (float) $point['pct'];
            $daily[] = ['date' => (string) $point['date'], 'pct' => round($pct, 2), 'trades' => (int) ($point['trades'] ?? 0)];
            $growth *= max(0.0, 1 + $pct / 100);
            $trades += (int) ($point['trades'] ?? 0);
            if ($pct > 0) {
                $green++;
            }
            if ($best === null || $pct > $best['pct']) {
                $best = ['date' => (string) $point['date'], 'pct' => $pct];
            }
            foreach ((array) ($point['assets'] ?? []) as $asset) {
                $symbol = is_array($asset) ? (string) ($asset['symbol'] ?? '') : '';
                if ($symbol !== '' && is_numeric($asset['pct'] ?? null)) {
                    $shares[$symbol] = ($shares[$symbol] ?? 0.0) + (float) $asset['pct'];
                }
            }
        }

        arsort($shares);
        $top = [];
        foreach (array_slice($shares, 0, self::TOP_COINS, true) as $symbol => $share) {
            $top[] = ['symbol' => (string) $symbol, 'share_pct' => round($share, 2)];
        }

        return [
            'available' => true,
            'period' => $period,
            'return_pct' => round(($growth - 1) * 100, 2),
            'trades' => $trades,
            'trading_days' => count($points),
            'green_days' => $green,
            'best_day' => ['date' => $best['date'], 'pct' => round($best['pct'], 2)],
            'top_coins' => $top,
            'daily' => array_slice($daily, 0, self::MAX_WINDOW_DAYS),
        ] + $scope;
    }

    /**
     * The whole FACTS JSON the model receives. `$performance` null = the
     * person switched "use this week's results" off: the block then says
     * there are no figures, exactly as when nothing was published.
     * `$platform` null (ideas for any platform) = both platforms' rules.
     */
    public function forPrompt(?array $performance, ?string $platform, ?string $format = null): array
    {
        $character = CreativeCharacter::current();
        $platformRules = $platform !== null && isset(self::PLATFORMS[$platform])
            ? ['key' => $platform] + self::PLATFORMS[$platform]
            : ['key' => 'any', 'x' => self::PLATFORMS['x'], 'tiktok' => self::PLATFORMS['tiktok']];

        return [
            'brand' => [
                'product' => 'Pixel Alpha',
                'what_it_is' => 'An automated crypto trading bot. Users connect their own Binance, Bybit or MEXC account with trade-only API keys (the bot can trade, it can never withdraw), and the strategy trades on their account.',
                'pricing' => 'No subscription: Pixel Alpha takes 20% of the profit it makes, and nothing when there is no profit.',
                'fee_pct' => 20,
                'site' => 'pixel-alpha.com',
                'risk' => 'Crypto trading is risky: results vary, losses happen, and past results do not predict future ones.',
            ],
            'character' => [
                'name' => CreativeAiPrompt::scrub((string) $character->name),
                'look' => CreativeAiPrompt::scrub((string) $character->look),
                'style' => CreativeAiPrompt::scrub((string) $character->style_prompt),
                'rules' => CreativeAiPrompt::scrub((string) $character->rules),
            ],
            'platform' => $platformRules,
            'format' => $format,
            'performance' => $performance === null
                ? ['available' => false, 'note' => 'No figures for this draft: write it without any performance figure.']
                : $performance + [
                    'source' => 'Pixel Alpha\'s published track record (the master account), days in Asia/Manila time. return_pct is the period\'s compounded return; best_day.pct and top_coins[].share_pct are percentages of the account; trades counts closed trades.',
                ],
            'recent_titles' => $this->recentTitles(),
        ];
    }

    /** @return list<string> the newest plan titles, scrubbed */
    public function recentTitles(): array
    {
        return CreativePlan::query()->orderByDesc('id')->limit(self::RECENT_TITLES)->pluck('title')
            ->map(fn ($t) => CreativeAiPrompt::scrub((string) $t))
            ->values()->all();
    }

    /** "3–9 Nov 2026", "28 Oct – 3 Nov 2026", "29 Dec 2026 – 4 Jan 2027". */
    public static function periodLabel(CarbonImmutable $start, CarbonImmutable $end): string
    {
        if ($start->year !== $end->year) {
            return $start->format('j M Y').' – '.$end->format('j M Y');
        }
        if ($start->month !== $end->month) {
            return $start->format('j M').' – '.$end->format('j M Y');
        }

        return $start->format('j').'–'.$end->format('j M Y');
    }

    private function timezone(): string
    {
        return (string) config('services.track_record.timezone', 'UTC') ?: 'UTC';
    }
}
