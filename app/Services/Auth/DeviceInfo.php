<?php

namespace App\Services\Auth;

/**
 * A User-Agent string → the three things the admin page shows: what kind of
 * device, which browser (major version only) and which OS. Pure, hand-written,
 * no package: the question is "phone or laptop, Chrome or Safari", not a
 * device database, and a parser we cannot read is worse than a short one.
 *
 * Order is the whole trick. Edge, Opera and Samsung Internet all carry
 * "Chrome/" and "Safari/" too, Chrome carries "Safari/", Android carries
 * "Linux" and iOS "like Mac OS X" — so the more specific token is always
 * tried first. Two known blind spots, both accepted: an iPad in Safari's
 * default "desktop site" mode sends a Mac User-Agent and reads as macOS
 * desktop; and the reduced UAs modern Chrome sends freeze Android at "10"
 * and macOS / Windows at one version (hence no version on either desktop OS).
 */
final class DeviceInfo
{
    public const DESKTOP = 'desktop';

    public const MOBILE = 'mobile';

    public const TABLET = 'tablet';

    public const BOT = 'bot';

    public const UNKNOWN = 'unknown';

    /**
     * Crawlers and scripts. `(?<!cu)` because Cubot is an Android phone maker
     * whose model names ("CUBOT X30") would otherwise read as a bot.
     */
    private const BOT_PATTERN = '/(?<!cu)bot|crawler|spider|curl|python|wget/i';

    /** @var list<array{0: string, 1: string}> regex with the major version as group 1 → label */
    private const BROWSERS = [
        ['#Edg(?:e|A|iOS)?/(\d+)#', 'Edge'],
        ['#OPR/(\d+)#', 'Opera'],
        ['#SamsungBrowser/(\d+)#', 'Samsung Internet'],
        ['#(?:Firefox|FxiOS)/(\d+)#', 'Firefox'],
        ['#(?:Chrome|CriOS)/(\d+)#', 'Chrome'],
    ];

    /**
     * @return array{device_type: string, browser: ?string, os: ?string}
     */
    public static function parse(?string $ua): array
    {
        $ua = trim((string) $ua);
        if ($ua === '') {
            return ['device_type' => self::UNKNOWN, 'browser' => null, 'os' => null];
        }

        $browser = self::browser($ua);
        $os = self::os($ua);

        return [
            'device_type' => self::deviceType($ua, $browser, $os),
            'browser' => $browser,
            'os' => $os,
        ];
    }

    private static function browser(string $ua): ?string
    {
        foreach (self::BROWSERS as [$pattern, $label]) {
            if (preg_match($pattern, $ua, $m)) {
                return "{$label} {$m[1]}";
            }
        }

        // Safari last: every WebKit browser above also says "Safari/". Its
        // own version is in "Version/", not in the Safari token (a build no.).
        if (str_contains($ua, 'Safari/')) {
            return preg_match('#Version/(\d+)#', $ua, $m) ? "Safari {$m[1]}" : 'Safari';
        }

        return null;
    }

    private static function os(string $ua): ?string
    {
        // iOS-family first: their UAs say "like Mac OS X".
        if (str_contains($ua, 'iPad')) {
            return self::withVersion('iPadOS', '#OS (\d+)[_.\d]* like Mac OS X#', $ua);
        }
        if (str_contains($ua, 'iPhone') || str_contains($ua, 'iPod')) {
            return self::withVersion('iOS', '#OS (\d+)[_.\d]* like Mac OS X#', $ua);
        }
        // Android before Linux: every Android UA also says "Linux".
        if (str_contains($ua, 'Android')) {
            return self::withVersion('Android', '#Android (\d+)#', $ua);
        }
        if (str_contains($ua, 'CrOS')) {
            return 'ChromeOS';
        }
        if (str_contains($ua, 'Windows')) {
            return 'Windows';
        }
        if (str_contains($ua, 'Macintosh') || str_contains($ua, 'Mac OS X')) {
            return 'macOS';
        }
        if (str_contains($ua, 'Linux')) {
            return 'Linux';
        }

        return null;
    }

    private static function deviceType(string $ua, ?string $browser, ?string $os): string
    {
        if (preg_match(self::BOT_PATTERN, $ua)) {
            return self::BOT;
        }
        if (str_contains($ua, 'iPad')) {
            return self::TABLET;
        }
        // Android phones say "Mobile"; Android tablets do not.
        if (str_contains($ua, 'Android')) {
            return str_contains($ua, 'Mobile') ? self::MOBILE : self::TABLET;
        }
        if (str_contains($ua, 'iPhone') || str_contains($ua, 'iPod') || str_contains($ua, 'Mobi')) {
            return self::MOBILE;
        }
        // Something we recognised and that is none of the above is a computer.
        // A UA we recognise nothing in (an app's HTTP client) is not evidence
        // of one.
        if ($os !== null || $browser !== null) {
            return self::DESKTOP;
        }

        return self::UNKNOWN;
    }

    private static function withVersion(string $name, string $pattern, string $ua): string
    {
        return preg_match($pattern, $ua, $m) ? "{$name} {$m[1]}" : $name;
    }
}
