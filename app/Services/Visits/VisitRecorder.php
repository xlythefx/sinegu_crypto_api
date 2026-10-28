<?php

namespace App\Services\Visits;

use App\Http\Middleware\EnsureStaff;
use App\Models\SiteVisit;
use App\Services\Auth\AccessLog;
use App\Services\Auth\DeviceInfo;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * The visitor counter's front door (owner, 2026-10-28): decides whether one
 * ping from a public page is a VISIT, and writes it if so.
 *
 * The ping comes from the page's own JavaScript once the page has been on
 * screen for a moment (frontend `VisitTracker`), so the first spam filter is
 * that scanners, scripts and link-preview fetchers never run it. Everything
 * that does arrive goes through these rules, in this order:
 *
 *  1. bot          — the browser says it is a crawler, a headless or
 *                    remote-controlled browser, or nothing we recognise.
 *  2. foreign      — not sent from our own site (Origin / Referer), or from a
 *                    developer's localhost against the live API.
 *  3. unknown_page — not one of VisitPages::PATHS.
 *  4. team         — a signed-in staff account. Our own clicks are not traffic.
 *  5. flood        — more than FLOOD_PER_MINUTE / FLOOD_PER_DAY pings from one
 *                    IP. Dropped, and the IP is SUSPICIOUS for the rest of the
 *                    day: what it already sent is flagged too.
 *  6. repeat       — the same visitor on the same page within REPEAT_MINUTES.
 *                    Refreshing fifty times is one view.
 *
 * Refused pings are never stored; only a per-day tally of each reason is
 * (site_visit_filtered), so the page can show the filter working. A ping
 * that passes is a row — flagged suspicious when its IP already is, or when
 * it completes a BURST (BURST_PAGES views in BURST_SECONDS from one IP, which
 * no person reading a page does). Suspicious rows are kept for the admins to
 * look at and left out of every count.
 *
 * "One visitor" is a daily HMAC of IP + User-Agent (no cookie, nothing stored
 * on the device), so someone back tomorrow counts again, and the hash cannot
 * be turned back into an address once `visits:purge-ips` has removed the IP.
 *
 * BEST-EFFORT, like AccessLog: a failure is logged and swallowed. The
 * endpoint answers 204 whatever happened, so it never tells a spammer which
 * rule caught them.
 */
final class VisitRecorder
{
    /** IPs (and User-Agents) are removed after this many days; the counts stay. */
    public const IP_DAYS = 90;

    public const REPEAT_MINUTES = 30;

    public const FLOOD_PER_MINUTE = 30;

    public const FLOOD_PER_DAY = 300;

    public const BURST_PAGES = 6;

    public const BURST_SECONDS = 15;

    public const COUNTED = 'counted';

    public const SUSPICIOUS = 'suspicious';

    public const FILTER_BOT = 'bot';

    public const FILTER_FOREIGN = 'foreign';

    public const FILTER_UNKNOWN_PAGE = 'unknown_page';

    public const FILTER_TEAM = 'team';

    public const FILTER_FLOOD = 'flood';

    public const FILTER_REPEAT = 'repeat';

    /** Every reason a ping can be thrown away, in the order the rules run. */
    public const FILTERS = [
        self::FILTER_BOT,
        self::FILTER_FOREIGN,
        self::FILTER_UNKNOWN_PAGE,
        self::FILTER_TEAM,
        self::FILTER_FLOOD,
        self::FILTER_REPEAT,
    ];

    public const SUSPECT_FLOOD = 'flood';

    public const SUSPECT_BURST = 'burst';

    /**
     * What a real browser never says. DeviceInfo already catches
     * bot / crawler / spider / curl / python / wget.
     */
    private const AUTOMATION_PATTERN = '/headless|phantomjs|lighthouse|puppeteer|playwright|selenium|webdriver|scrapy|httpclient|okhttp|axios|node-fetch|undici|go-http-client|java\/|libwww|preview|facebookexternalhit|slurp|monitor|uptime|pingdom|gtmetrix|semrush|ahrefs/i';

    /** @return string COUNTED, SUSPICIOUS or one of FILTERS ('error' when the write failed). */
    public function record(Request $request): string
    {
        try {
            return $this->evaluate($request);
        } catch (Throwable $e) {
            Log::warning('VisitRecorder: a visit could not be recorded.', ['error' => $e->getMessage()]);

            return 'error';
        }
    }

    private function evaluate(Request $request): string
    {
        $now = Carbon::now('UTC');
        $day = $now->toDateString();

        $userAgent = AccessLog::userAgent($request->userAgent());
        $device = DeviceInfo::parse($userAgent);
        if ($userAgent === null || $request->boolean('webdriver') || $this->isAutomated($userAgent, $device['device_type'])) {
            return $this->filter(self::FILTER_BOT, $day);
        }

        if (! $this->fromOurSite($request)) {
            return $this->filter(self::FILTER_FOREIGN, $day);
        }

        $path = VisitPages::normalise($request->input('path'));
        if ($path === null) {
            return $this->filter(self::FILTER_UNKNOWN_PAGE, $day);
        }

        // Optional auth: the ping carries the session token when there is one.
        $user = $request->user('sanctum');
        if ($user && in_array($user->type, EnsureStaff::ROLES, true)) {
            return $this->filter(self::FILTER_TEAM, $day);
        }

        $ip = (string) $request->ip();
        if ($this->flooding($ip, $day)) {
            $this->suspect($ip, $now, self::SUSPECT_FLOOD);

            return $this->filter(self::FILTER_FLOOD, $day);
        }

        $visitor = hash_hmac('sha256', "{$day}|{$ip}|{$userAgent}", (string) config('app.key'));
        if (! Cache::add("visits:seen:{$visitor}:{$path}", 1, $now->copy()->addMinutes(self::REPEAT_MINUTES))) {
            return $this->filter(self::FILTER_REPEAT, $day);
        }

        $suspect = Cache::get(self::suspectKey($day, $ip));
        $entry = $request->boolean('entry');

        SiteVisit::create([
            'path' => $path,
            'visitor_hash' => $visitor,
            'is_entry' => $entry,
            'ip' => $ip,
            'user_agent' => $userAgent,
            'country' => AccessLog::country($request->header('CF-IPCountry')),
            'device_type' => $device['device_type'],
            'browser' => $device['browser'],
            'os' => $device['os'],
            // The referrer belongs to the page the visit STARTED on; later
            // pages of the same visit keep reporting it in the browser.
            'referrer_host' => $entry ? VisitSources::host($request->input('referrer')) : null,
            'ref_code' => $this->referralCode($request->input('ref')),
            'utm_source' => self::token($request->input('utm_source')),
            'utm_campaign' => self::token($request->input('utm_campaign')),
            'signed_in' => $user !== null,
            'suspicious' => $suspect !== null,
            'suspicious_reason' => $suspect,
        ]);

        if ($suspect !== null) {
            return self::SUSPICIOUS;
        }

        $recent = SiteVisit::where('ip', $ip)
            ->where('created_at', '>=', $now->copy()->subSeconds(self::BURST_SECONDS))
            ->count();
        if ($recent >= self::BURST_PAGES) {
            $this->suspect($ip, $now, self::SUSPECT_BURST);

            return self::SUSPICIOUS;
        }

        return self::COUNTED;
    }

    /** Crawlers, scripts and remote-controlled browsers — and anything we recognise nothing in. */
    private function isAutomated(string $userAgent, string $deviceType): bool
    {
        return $deviceType === DeviceInfo::BOT
            || $deviceType === DeviceInfo::UNKNOWN
            || preg_match(self::AUTOMATION_PATTERN, $userAgent) === 1;
    }

    /**
     * Sent by a page of ours: a browser puts the page's origin on every POST
     * (Origin), and Referer is the fallback for one that does not. The list
     * is CORS's, so the two can never disagree about what "our site" is. On
     * the live box a localhost page is a developer running against prod, not
     * a visitor.
     */
    private function fromOurSite(Request $request): bool
    {
        $origin = self::origin($request->headers->get('Origin'))
            ?? self::origin($request->headers->get('Referer'));
        if ($origin === null) {
            return false;
        }

        if (app()->isProduction() && preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#', $origin)) {
            return false;
        }

        return in_array($origin, (array) config('cors.allowed_origins', []), true);
    }

    /** "scheme://host[:port]" of a URL, or null. */
    private static function origin(?string $url): ?string
    {
        if ($url === null || $url === '' || $url === 'null') {
            return null;
        }

        $parts = parse_url($url);
        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $origin = strtolower($parts['scheme'].'://'.$parts['host']);

        return isset($parts['port']) ? "{$origin}:{$parts['port']}" : $origin;
    }

    /** Counts this ping against the IP's minute and day budgets; true once either is spent. */
    private function flooding(string $ip, string $day): bool
    {
        $perMinute = RateLimiter::hit("visits:m:{$ip}", 60);
        $perDay = RateLimiter::hit("visits:d:{$day}:{$ip}", 86400);

        return $perMinute > self::FLOOD_PER_MINUTE || $perDay > self::FLOOD_PER_DAY;
    }

    /**
     * Mark the IP suspicious until the end of the UTC day and flag what it
     * already sent today. Once per IP per day: the first reason sticks.
     */
    private function suspect(string $ip, Carbon $now, string $reason): void
    {
        if (! Cache::add(self::suspectKey($now->toDateString(), $ip), $reason, $now->copy()->endOfDay())) {
            return;
        }

        SiteVisit::where('ip', $ip)
            ->where('created_at', '>=', $now->copy()->startOfDay())
            ->where('suspicious', false)
            ->update(['suspicious' => true, 'suspicious_reason' => $reason]);
    }

    private static function suspectKey(string $day, string $ip): string
    {
        return "visits:suspect:{$day}:{$ip}";
    }

    /** One more ping thrown away for this reason today. */
    private function filter(string $reason, string $day): string
    {
        DB::table('site_visit_filtered')->upsert(
            [['day' => $day, 'reason' => $reason, 'hits' => 1]],
            ['day', 'reason'],
            ['hits' => DB::raw('hits + 1')],
        );

        return $reason;
    }

    /**
     * `?ref=` kept only when it is a real referral code — otherwise anyone
     * could write made-up codes into the Referral links card.
     */
    private function referralCode(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        $code = strtoupper(trim($raw));
        if (! preg_match('/^[A-Z0-9_-]{1,50}$/', $code)) {
            return null;
        }

        return DB::table('referral_codes')->where('code', $code)->exists() ? $code : null;
    }

    /** A utm_* value: short, lowercase, letters / digits / . _ - only; null otherwise. */
    private static function token(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        $value = strtolower(trim($raw));

        return preg_match('/^[a-z0-9._-]{1,64}$/', $value) ? $value : null;
    }
}
