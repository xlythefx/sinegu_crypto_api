<?php

namespace Tests\Unit;

use App\Services\Auth\DeviceInfo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * DeviceInfo turns a User-Agent into device type, browser and OS for the
 * admin sign-up details. Pure, so plain PHPUnit. The UAs are real ones from
 * each browser/OS pair; what matters most is the ORDER of the checks, since
 * Edge, Opera and Samsung Internet all also say "Chrome" and "Safari".
 */
class DeviceInfoTest extends TestCase
{
    /** @return array<string, array{0: ?string, 1: string, 2: ?string, 3: ?string}> */
    public static function userAgents(): array
    {
        return [
            'Windows Chrome' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36',
                'desktop', 'Chrome 141', 'Windows',
            ],
            'iPhone Safari' => [
                'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1',
                'mobile', 'Safari 18', 'iOS 18',
            ],
            'iPhone Chrome' => [
                'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/141.0.7390.41 Mobile/15E148 Safari/604.1',
                'mobile', 'Chrome 141', 'iOS 18',
            ],
            'iPhone Firefox' => [
                'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) FxiOS/131.0 Mobile/15E148 Safari/605.1.15',
                'mobile', 'Firefox 131', 'iOS 17',
            ],
            'iPad Safari' => [
                'Mozilla/5.0 (iPad; CPU OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
                'tablet', 'Safari 17', 'iPadOS 17',
            ],
            'Android phone Chrome' => [
                'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36',
                'mobile', 'Chrome 141', 'Android 15',
            ],
            'Android tablet Chrome' => [
                'Mozilla/5.0 (Linux; Android 14; SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36',
                'tablet', 'Chrome 141', 'Android 14',
            ],
            'Android phone Firefox' => [
                'Mozilla/5.0 (Android 15; Mobile; rv:131.0) Gecko/131.0 Firefox/131.0',
                'mobile', 'Firefox 131', 'Android 15',
            ],
            'Samsung Internet' => [
                'Mozilla/5.0 (Linux; Android 14; SAMSUNG SM-S921B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/26.0 Chrome/122.0.0.0 Mobile Safari/537.36',
                'mobile', 'Samsung Internet 26', 'Android 14',
            ],
            'Edge on Windows' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.3537.57',
                'desktop', 'Edge 141', 'Windows',
            ],
            'Edge on Android' => [
                'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36 EdgA/141.0.0.0',
                'mobile', 'Edge 141', 'Android 10',
            ],
            'Opera on Windows' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36 OPR/124.0.0.0',
                'desktop', 'Opera 124', 'Windows',
            ],
            'Firefox on Windows' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:131.0) Gecko/20100101 Firefox/131.0',
                'desktop', 'Firefox 131', 'Windows',
            ],
            'Firefox on Linux' => [
                'Mozilla/5.0 (X11; Linux x86_64; rv:131.0) Gecko/20100101 Firefox/131.0',
                'desktop', 'Firefox 131', 'Linux',
            ],
            'Safari on macOS' => [
                'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Safari/605.1.15',
                'desktop', 'Safari 18', 'macOS',
            ],
            'Chrome on ChromeOS' => [
                'Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36',
                'desktop', 'Chrome 141', 'ChromeOS',
            ],
            'Googlebot' => [
                'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
                'bot', null, null,
            ],
            'Googlebot smartphone' => [
                'Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
                'bot', 'Chrome 141', 'Android 6',
            ],
            'curl' => ['curl/8.4.0', 'bot', null, null],
            'python requests' => ['python-requests/2.32.3', 'bot', null, null],
            'wget' => ['Wget/1.21.4', 'bot', null, null],
            'spider' => ['Mozilla/5.0 (compatible; Bytespider; spider-feedback@bytedance.com)', 'bot', null, null],
            // A phone MAKER called Cubot must not read as a bot.
            'Cubot phone' => [
                'Mozilla/5.0 (Linux; Android 13; CUBOT KINGKONG 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36',
                'mobile', 'Chrome 141', 'Android 13',
            ],
            'empty' => ['', 'unknown', null, null],
            'whitespace' => ['   ', 'unknown', null, null],
            'null' => [null, 'unknown', null, null],
            // Recognisably nothing: an app's HTTP client is not evidence of a computer.
            'unrecognised client' => ['okhttp/4.12.0', 'unknown', null, null],
        ];
    }

    #[DataProvider('userAgents')]
    public function test_parse(?string $ua, string $deviceType, ?string $browser, ?string $os): void
    {
        $this->assertSame(
            ['device_type' => $deviceType, 'browser' => $browser, 'os' => $os],
            DeviceInfo::parse($ua),
        );
    }
}
