<?php

namespace App\Services\Visits;

/**
 * Where a visitor came from: the referring SITE, never the full link (a
 * Google results URL carries the search, a Telegram one the chat), reduced
 * to a host and given a readable name for the ones that matter.
 *
 * Our own site is not a source — a visitor moving from the landing page to
 * /auth "came from" pixel-alpha.com — so it is dropped, as are localhost and
 * bare IPs. Pure.
 */
final class VisitSources
{
    /** Hosts we serve from: a referrer here is an internal click, not a source. */
    private const OWN_HOSTS = ['pixel-alpha.com', 'localhost', '127.0.0.1'];

    /**
     * host suffix → label. Matched on the host or any parent domain, so
     * `l.facebook.com` and `m.facebook.com` are both Facebook and
     * `google.co.th` is Google (the `google.` prefix rule below).
     */
    private const LABELS = [
        't.me' => 'Telegram',
        'telegram.org' => 'Telegram',
        'telegram.me' => 'Telegram',
        'discord.com' => 'Discord',
        'discord.gg' => 'Discord',
        'discordapp.com' => 'Discord',
        'x.com' => 'X (Twitter)',
        'twitter.com' => 'X (Twitter)',
        't.co' => 'X (Twitter)',
        'facebook.com' => 'Facebook',
        'fb.com' => 'Facebook',
        'instagram.com' => 'Instagram',
        'youtube.com' => 'YouTube',
        'youtu.be' => 'YouTube',
        'tiktok.com' => 'TikTok',
        'reddit.com' => 'Reddit',
        'linkedin.com' => 'LinkedIn',
        'lnkd.in' => 'LinkedIn',
        'bing.com' => 'Bing',
        'duckduckgo.com' => 'DuckDuckGo',
        'yahoo.com' => 'Yahoo',
        'chatgpt.com' => 'ChatGPT',
        'openai.com' => 'ChatGPT',
        'claude.ai' => 'Claude',
        'perplexity.ai' => 'Perplexity',
        'binance.com' => 'Binance',
        'tradingview.com' => 'TradingView',
    ];

    /** The label of a visit with no referring site: typed in, bookmarked, or an app. */
    public const DIRECT = 'Direct';

    /** The referring host for a raw `document.referrer`, or null when it is not another site. */
    public static function host(mixed $referrer): ?string
    {
        if (! is_string($referrer) || $referrer === '' || strlen($referrer) > 2000) {
            return null;
        }

        $host = parse_url(trim($referrer), PHP_URL_HOST);
        if (! is_string($host)) {
            return null;
        }

        $host = strtolower(rtrim($host, '.'));
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        // A real domain: letters, digits, dots and hyphens, with at least one dot.
        if (strlen($host) > 120 || ! preg_match('/^(?=.*[a-z])[a-z0-9-]+(\.[a-z0-9-]+)+$/', $host)) {
            return null;
        }

        foreach (self::OWN_HOSTS as $own) {
            if ($host === $own || str_ends_with($host, '.'.$own)) {
                return null;
            }
        }

        return $host;
    }

    /** "Google", "Telegram" … or the host itself; Direct for none. */
    public static function label(?string $host): string
    {
        if ($host === null) {
            return self::DIRECT;
        }

        if (preg_match('/(^|\.)google\.[a-z.]+$/', $host)) {
            return 'Google';
        }

        foreach (self::LABELS as $suffix => $label) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                return $label;
            }
        }

        return $host;
    }
}
