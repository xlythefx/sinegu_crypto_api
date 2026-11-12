<?php

namespace App\Services\Creatives;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Reads and checks what Claude answered — in PHP, never trusted to the model.
 *
 * Two kinds of finding, and they are not the same:
 *  - The JSON does not match the shape (not JSON, a key missing, a wrong
 *    type): the answer is unusable → null, and the caller answers
 *    AI_BAD_OUTPUT (the tokens were still spent and are recorded).
 *  - The JSON is fine but the WRITING needs a human look: a percentage that
 *    is not in FACTS, a money amount, a caption over the platform limit, a
 *    field longer than the plan can store. Nothing is stripped silently and
 *    the call does not fail — each finding is a `warnings` line the page
 *    shows beside the draft.
 *
 * The disclaimer is the one thing fixed in place: any draft that cites a
 * percentage gets {@see CreativeAi::DISCLAIMER} at the end of its caption.
 */
final class CreativeAiOutput
{
    /** Lengths the plan columns / request rules accept (CreativePlanRequest). */
    private const LIMITS = [
        'title' => 160,
        'hook' => 2000,
        'script' => 10000,
        'caption' => 5000,
        'hashtags' => 500,
        'video_prompt' => 4000,
        'notes' => 5000,
    ];

    private const TEXT_FIELDS = ['title', 'hook', 'script', 'caption', 'hashtags', 'video_prompt', 'notes'];

    /** A percentage in running text: "+2.41%", "−1.2 %", "4,5%". */
    private const PERCENT = '/([+\-−]?\s?\d+(?:[.,]\d+)?)\s?%/u';

    /** An entry that names a day ("5 Nov", "2026-11-05", "Nov 9–15"). */
    private const DATE_WORDS = '/\b(?:jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\b|\d{4}-\d{2}-\d{2}/i';

    /** A money amount in the model's own words. */
    private const MONEY = '/(?:(?:US)?[$€£]\s?\d[\d,]*(?:\.\d+)?(?:\s?[kKmMbB]\b)?|\b\d[\d,]*(?:\.\d+)?\s?(?:[kKmM]\s?)?(?:USDT|USDC|USD|dollars?)\b)/iu';

    /**
     * The Draft shape (+ warnings), or null when the JSON is unusable.
     *
     * @param  array<string, mixed>|null  $performance  the Facts block sent, null = no figures were sent
     * @param  array<string, mixed>  $facts  the whole FACTS JSON sent
     * @return array<string, mixed>|null
     */
    public static function draft(?string $json, string $platform, ?array $performance, array $facts): ?array
    {
        $data = self::decode($json);
        if ($data === null) {
            return null;
        }
        foreach (self::TEXT_FIELDS as $field) {
            if (! is_string($data[$field] ?? null)) {
                return null;
            }
        }
        if (! is_array($data['numbers_used'] ?? null) || ! array_is_list($data['numbers_used'])) {
            return null;
        }
        foreach ($data['numbers_used'] as $n) {
            if (! is_string($n)) {
                return null;
            }
        }
        if (trim($data['title']) === '') {
            return null;
        }

        $warnings = [];
        $draft = [];
        foreach (self::TEXT_FIELDS as $field) {
            $draft[$field] = trim($data[$field]);
        }
        $draft['hashtags'] = self::hashtags($draft['hashtags'], $warnings);
        $draft['numbers_used'] = array_values(array_unique(array_filter(array_map('trim', $data['numbers_used']), fn ($n) => $n !== '')));

        // --- figures ----------------------------------------------------------
        $available = $performance !== null && ($performance['available'] ?? false) === true;
        $percents = self::factPercents($facts);
        $numbers = self::factValues($facts);
        $dateNumbers = self::factDateNumbers($facts);
        $cited = [];
        $citesPercent = false;

        foreach ($draft['numbers_used'] as $entry) {
            // "1.83% on 5 Nov": the percentage must be one FACTS states as a
            // percentage; any other number (a count, a date) one it states at all.
            $ok = true;
            if (preg_match_all(self::PERCENT, $entry, $m)) {
                $citesPercent = true;
                foreach ($m[1] as $raw) {
                    $value = self::normalise($raw);
                    $ok = $ok && $value !== null && self::inSet($value, $percents);
                }
            }
            // A date may be any day FACTS names; any other number must be one of
            // its VALUES — "9 trades" is not vouched for by "9–15 Nov".
            $rest = (string) preg_replace(self::PERCENT, ' ', $entry);
            $pool = preg_match(self::DATE_WORDS, $rest) === 1 ? [...$numbers, ...$dateNumbers] : $numbers;
            foreach (self::numberTokens($rest) as $token) {
                $ok = $ok && self::inSet($token, $pool);
            }
            if (! $ok) {
                $cited[$entry] = true;
            }
        }
        foreach (['hook', 'script', 'caption'] as $field) {
            if (preg_match_all(self::PERCENT, $draft[$field], $m)) {
                $citesPercent = true;
                foreach ($m[1] as $i => $raw) {
                    $value = self::normalise($raw);
                    if ($value === null || ! self::inSet($value, $percents)) {
                        $cited[trim(preg_replace('/\s+/u', '', $m[0][$i]) ?? $m[0][$i])] = true;
                    }
                }
            }
        }
        foreach (array_keys($cited) as $figure) {
            $warnings[] = $available
                ? "The draft cites {$figure}, which is not in this week's figures — check it."
                : "The draft cites {$figure}, but it was given no figures — check it or take it out.";
        }

        foreach (['hook', 'script', 'caption', 'video_prompt', 'notes', 'title'] as $field) {
            if (preg_match(self::MONEY, $draft[$field], $m)) {
                $warnings[] = "The draft mentions a money amount ({$m[0]}). Pixel Alpha posts never show money amounts — take it out.";
                break;
            }
        }

        // --- the disclaimer, fixed in PHP -------------------------------------
        if ($citesPercent && ! self::hasDisclaimer($draft['caption'])) {
            $draft['caption'] = rtrim($draft['caption']).($draft['caption'] === '' ? '' : "\n\n").CreativeAi::DISCLAIMER;
        }

        // --- platform limit -----------------------------------------------------
        $limit = CreativeFacts::PLATFORMS[$platform]['caption_max_chars'] ?? null;
        if ($limit !== null) {
            $length = mb_strlen($draft['caption']) + ($draft['hashtags'] !== '' ? 1 + mb_strlen($draft['hashtags']) : 0);
            if ($length > $limit) {
                $name = $platform === 'x' ? 'X' : 'TikTok';
                $warnings[] = sprintf(
                    'The caption and hashtags come to %s characters; %s allows %s. Shorten them before posting.',
                    number_format($length), $name, number_format($limit),
                );
            }
        }

        // --- what the plan can store -------------------------------------------
        foreach (self::LIMITS as $field => $max) {
            if (mb_strlen($draft[$field]) > $max) {
                $draft[$field] = rtrim(mb_substr($draft[$field], 0, $max - 1)).'…';
                $warnings[] = "The {$field} was longer than a plan can hold and was shortened to {$max} characters — check its ending.";
            }
        }

        $draft['warnings'] = array_values(array_unique($warnings));

        return $draft;
    }

    /**
     * The Idea list, or null when the JSON is unusable or holds no idea.
     *
     * @return list<array{title: string, hook: string, why: string, platform: string, format: string}>|null
     */
    public static function ideas(?string $json, int $count, ?string $platform): ?array
    {
        $data = self::decode($json);
        if ($data === null || ! is_array($data['ideas'] ?? null) || ! array_is_list($data['ideas'])) {
            return null;
        }

        $ideas = [];
        foreach ($data['ideas'] as $idea) {
            if (! is_array($idea)) {
                return null;
            }
            foreach (['title', 'hook', 'why', 'platform', 'format'] as $key) {
                if (! is_string($idea[$key] ?? null)) {
                    return null;
                }
            }
            if (! in_array($idea['platform'], ['x', 'tiktok'], true) || ! in_array($idea['format'], ['video', 'image', 'carousel'], true)) {
                return null;
            }
            $title = trim($idea['title']);
            if ($title === '') {
                continue;
            }
            $ideas[] = [
                'title' => mb_strlen($title) > 160 ? rtrim(mb_substr($title, 0, 159)).'…' : $title,
                'hook' => mb_substr(trim($idea['hook']), 0, 2000),
                'why' => mb_substr(trim($idea['why']), 0, 1000),
                // The person asked for one platform: every idea is for it.
                'platform' => $platform ?? $idea['platform'],
                'format' => $idea['format'],
            ];
        }

        return $ideas === [] ? null : array_slice($ideas, 0, $count);
    }

    // ---- helpers -----------------------------------------------------------

    /** @return array<string, mixed>|null */
    private static function decode(?string $json): ?array
    {
        if ($json === null || trim($json) === '') {
            return null;
        }
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($data) && ! array_is_list($data) ? $data : null;
    }

    /** "#a b, #c" → "#a #b #c"; 3–8 tags expected (a warning otherwise, never a refusal). */
    private static function hashtags(string $raw, array &$warnings): string
    {
        $tags = [];
        foreach (preg_split('/[\s,]+/u', $raw) ?: [] as $part) {
            $part = trim($part);
            if ($part === '' || $part === '#') {
                continue;
            }
            $tag = '#'.ltrim($part, '#');
            $tags[mb_strtolower($tag)] = $tag;
        }
        $count = count($tags);
        if ($count < 3 || $count > 8) {
            $warnings[] = "The draft has {$count} hashtags; 3 to 8 work best.";
        }

        return implode(' ', $tags);
    }

    /**
     * The disclaimer rule for text that did not come through draft() — the
     * chat agent's plans: when any of $texts (hook, script, caption…) cites a
     * percentage and the caption lacks {@see CreativeAi::DISCLAIMER}, it is
     * appended to the caption. Null caption stays null when nothing is cited.
     */
    public static function ensureDisclaimer(?string $caption, ?string ...$texts): ?string
    {
        $cites = false;
        foreach ([$caption, ...$texts] as $text) {
            if ($text !== null && preg_match(self::PERCENT, $text) === 1) {
                $cites = true;
                break;
            }
        }
        if (! $cites || self::hasDisclaimer((string) $caption)) {
            return $caption;
        }
        $caption = rtrim((string) $caption);

        return $caption.($caption === '' ? '' : "

").CreativeAi::DISCLAIMER;
    }

    /** The first money amount in $text ("$40", "300 USDT"), or null. */
    public static function moneyIn(string $text): ?string
    {
        return preg_match(self::MONEY, $text, $m) === 1 ? $m[0] : null;
    }

    /** How long the customers' names are cached for {@see namesIn()}. */
    public const NAMES_CACHE_SECONDS = 600;

    public const NAMES_CACHE_KEY = 'creatives.customer-names';

    /**
     * The first CUSTOMER's name (`user_credentials.name` of `type = 'user'`)
     * in $text, or null — the second half of the post guard. Since the owner
     * let the chat agent read everything (2026-11-12), the model may know a
     * customer's name; a post never may carry one.
     *
     * The whole stored name is matched as a phrase, case-insensitive, on word
     * boundaries; names under 3 characters are skipped. A single first name
     * is deliberately NOT matched on its own: tokens like "Plain" or "Mark"
     * are everyday words and would refuse honest captions. The list is
     * cached for 10 minutes — a refusal, never a leak, is the price of a
     * name added a minute ago.
     */
    public static function namesIn(string $text): ?string
    {
        if (trim($text) === '') {
            return null;
        }
        foreach (self::namePatterns() as $pattern) {
            if (preg_match($pattern, $text, $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }

    /**
     * What makes posted text private, as one sentence for the model, or
     * null when it is fine: a money amount first, then a customer's name.
     *
     * @param  array<string, string|null>  $texts  field => text
     */
    public static function privateIn(array $texts): ?string
    {
        foreach ($texts as $field => $text) {
            if (! is_string($text) || trim($text) === '') {
                continue;
            }
            $money = self::moneyIn($text);
            if ($money !== null) {
                return "the {$field} contains a money amount ({$money})";
            }
        }
        foreach ($texts as $field => $text) {
            if (! is_string($text) || trim($text) === '') {
                continue;
            }
            $name = self::namesIn($text);
            if ($name !== null) {
                return "the {$field} names a customer ({$name})";
            }
        }

        return null;
    }

    /** @return list<string> regexes over the customers' names, in chunks */
    private static function namePatterns(): array
    {
        $names = Cache::remember(self::NAMES_CACHE_KEY, self::NAMES_CACHE_SECONDS, function () {
            $out = [];
            foreach (DB::table('user_credentials')->where('type', 'user')->whereNotNull('name')->pluck('name') as $name) {
                $name = trim((string) preg_replace('/\s+/u', ' ', (string) $name));
                if (mb_strlen($name) >= 3) {
                    $out[mb_strtolower($name)] = $name;
                }
            }

            return array_values($out);
        });

        $patterns = [];
        foreach (array_chunk($names, 200) as $chunk) {
            // Longest first, so "Juan Dela Cruz" wins over a shorter name inside it.
            usort($chunk, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
            $alternatives = array_map(
                fn (string $n) => str_replace(' ', '\s+', preg_quote($n, '/')),
                $chunk,
            );
            $patterns[] = '/(?<![\p{L}\p{N}])('.implode('|', $alternatives).')(?![\p{L}\p{N}])/iu';
        }

        return $patterns;
    }

    private static function hasDisclaimer(string $caption): bool
    {
        $needle = mb_strtolower(rtrim(CreativeAi::DISCLAIMER, '.'));

        return str_contains(mb_strtolower($caption), $needle);
    }

    /**
     * Every percentage FACTS states: the performance block's figures and the
     * pricing.
     *
     * @return list<float>
     */
    private static function factPercents(array $facts): array
    {
        $out = [];
        $p = $facts['performance'] ?? [];
        if (is_numeric($p['return_pct'] ?? null)) {
            $out[] = (float) $p['return_pct'];
        }
        if (is_numeric($p['best_day']['pct'] ?? null)) {
            $out[] = (float) $p['best_day']['pct'];
        }
        foreach ((array) ($p['top_coins'] ?? []) as $coin) {
            if (is_numeric($coin['share_pct'] ?? null)) {
                $out[] = (float) $coin['share_pct'];
            }
        }
        if (is_numeric($facts['brand']['fee_pct'] ?? null)) {
            $out[] = (float) $facts['brand']['fee_pct'];
        }

        return $out;
    }

    /**
     * Every numeric VALUE in FACTS (trades, trading_days, green_days, the
     * percentages, the platform limits), for a `numbers_used` entry that is
     * not a percentage ("14 trades").
     *
     * @return list<float>
     */
    private static function factValues(array $facts): array
    {
        $out = [];
        array_walk_recursive($facts, function ($value) use (&$out) {
            if (is_int($value) || is_float($value)) {
                $out[] = (float) $value;
            }
        });

        return $out;
    }

    /**
     * The numbers inside FACTS' dates and labels ("9–15 Nov 2026",
     * "2026-11-12"), accepted only in an entry that reads as a date.
     *
     * @return list<float>
     */
    private static function factDateNumbers(array $facts): array
    {
        $out = [];
        $p = $facts['performance'] ?? [];
        foreach ([$p['period'] ?? null, $p['best_day']['date'] ?? null] as $text) {
            if (is_string($text)) {
                foreach (self::numberTokens(str_replace('-', ' ', $text)) as $n) {
                    $out[] = $n;
                }
            }
        }

        return $out;
    }

    /** @return list<float> */
    private static function numberTokens(string $text): array
    {
        preg_match_all('/[+\-−]?\d+(?:[.,]\d+)?/u', $text, $m);

        return array_values(array_filter(array_map(fn (string $t) => self::normalise($t), $m[0]), fn ($n) => $n !== null));
    }

    /** "+2,41" → 2.41, "−1.2" → -1.2; null when it is not a number. */
    private static function normalise(string $raw): ?float
    {
        $s = str_replace(['+', '−', ' ', "\u{00A0}", '%'], ['', '-', '', '', ''], $raw);
        $s = str_replace(',', '.', $s);

        return is_numeric($s) ? (float) $s : null;
    }

    /** Equal to a FACTS value as written — the sign aside ("down 1.2%" for −1.2 is fine). */
    private static function inSet(float $value, array $set): bool
    {
        foreach ($set as $candidate) {
            if (abs(abs($value) - abs($candidate)) < 0.0000001) {
                return true;
            }
        }

        return false;
    }
}
