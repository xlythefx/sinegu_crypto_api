<?php

namespace Tests\Feature;

use App\Models\UserCredential;
use App\Services\Visits\VisitRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * POST /api/public/visits — the visitor counter's ping, and the spam rules
 * that decide what counts (VisitRecorder). The owner's requirement: bots,
 * refreshes, floods and our own team never count; a doubtful IP is kept for
 * the admins but left out of the numbers; full IPs go after 90 days.
 *
 * Every answer is 204, so a spammer learns nothing — the tests therefore read
 * the tables, never the response.
 */
class SiteVisitTest extends EngineTestCase
{
    private const WINDOWS_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';

    private const IPHONE_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

    private const ORIGIN = 'https://pixel-alpha.com';

    protected function setUp(): void
    {
        parent::setUp();
        // Pinned, so a developer's CORS_ALLOWED_ORIGINS cannot change what "our site" is here.
        config(['cors.allowed_origins' => [self::ORIGIN, 'http://localhost:5173']]);
    }

    private function ping(array $body = [], array $headers = [], string $ip = '203.0.113.10', ?string $token = null): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->withServerVariables(['REMOTE_ADDR' => $ip]);
        if ($token !== null) {
            $headers['Authorization'] = "Bearer {$token}";
        }

        return $this->postJson('/api/public/visits', $body + ['path' => '/'], $headers + [
            'User-Agent' => self::WINDOWS_UA,
            'Origin' => self::ORIGIN,
            'CF-IPCountry' => 'TH',
        ]);
    }

    private function filtered(string $reason): int
    {
        return (int) DB::table('site_visit_filtered')->where('reason', $reason)->sum('hits');
    }

    private function token(string $uniId): string
    {
        return UserCredential::find($uniId)->createToken('spa')->plainTextToken;
    }

    public function test_a_browser_visit_is_counted_with_where_it_came_from(): void
    {
        DB::table('referral_codes')->insert([
            'user_uni_id' => $this->makeUser(), 'code' => 'JUAN10', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->ping([
            'path' => '/',
            'entry' => true,
            'referrer' => 'https://www.google.co.th/search?q=pixel+alpha',
            'ref' => 'juan10',
            'utm_source' => 'Newsletter',
            'utm_campaign' => 'oct-launch',
        ])->assertNoContent();

        $row = DB::table('site_visits')->sole();
        $this->assertSame('/', $row->path);
        $this->assertSame('203.0.113.10', $row->ip);
        $this->assertSame('TH', $row->country);
        $this->assertSame('desktop', $row->device_type);
        $this->assertSame('Chrome 141', $row->browser);
        $this->assertSame('Windows', $row->os);
        // The site only, never the search that was in the link.
        $this->assertSame('google.co.th', $row->referrer_host);
        $this->assertSame('JUAN10', $row->ref_code);
        $this->assertSame('newsletter', $row->utm_source);
        $this->assertSame('oct-launch', $row->utm_campaign);
        $this->assertSame(1, (int) $row->is_entry);
        $this->assertSame(0, (int) $row->signed_in);
        $this->assertSame(0, (int) $row->suspicious);
        $this->assertSame(64, strlen($row->visitor_hash));
    }

    public function test_bots_and_remote_controlled_browsers_are_never_stored(): void
    {
        $this->ping([], ['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)']);
        $this->ping([], ['User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/141.0.0.0 Safari/537.36']);
        $this->ping([], ['User-Agent' => 'curl/8.4.0']);
        $this->ping([], ['User-Agent' => 'TelegramBot (like TwitterBot)']);
        $this->ping([], ['User-Agent' => 'MyApp/1.0']); // nothing a browser would say
        $this->ping([], ['User-Agent' => '']);
        // A real browser, driven by automation (navigator.webdriver).
        $this->ping(['webdriver' => true]);

        $this->assertSame(0, DB::table('site_visits')->count());
        $this->assertSame(7, $this->filtered(VisitRecorder::FILTER_BOT));
    }

    public function test_a_ping_that_did_not_come_from_our_site_is_not_counted(): void
    {
        $this->ping([], ['Origin' => '']);
        $this->ping([], ['Origin' => 'https://evil.example']);
        $this->ping([], ['Origin' => 'https://pixel-alpha.com.evil.example']);

        $this->assertSame(0, DB::table('site_visits')->count());
        $this->assertSame(3, $this->filtered(VisitRecorder::FILTER_FOREIGN));

        // A browser that sends no Origin is judged on its Referer.
        $this->ping([], ['Origin' => '', 'Referer' => 'https://pixel-alpha.com/faq']);
        $this->assertSame(1, DB::table('site_visits')->count());
    }

    public function test_only_public_pages_count(): void
    {
        $this->ping(['path' => '/dashboard']);
        $this->ping(['path' => '/admin/users']);
        $this->ping(['path' => '/free-money']);
        $this->ping(['path' => str_repeat('a', 300)]);
        $this->ping(['path' => ['/']]);

        $this->assertSame(0, DB::table('site_visits')->count());
        $this->assertSame(5, $this->filtered(VisitRecorder::FILTER_UNKNOWN_PAGE));

        // A query, a fragment or a trailing slash is the same page.
        $this->ping(['path' => '/Docs/Binance/?step=3#part-2']);
        $this->assertSame('/docs/binance', DB::table('site_visits')->value('path'));
    }

    public function test_staff_are_not_counted_and_customers_are_marked_signed_in(): void
    {
        $this->ping([], [], '203.0.113.10', $this->token($this->makeUser(['type' => 'admin'])));
        $this->ping([], [], '203.0.113.11', $this->token($this->makeUser(['type' => 'collaborator'])));
        $this->ping([], [], '203.0.113.12', $this->token($this->makeUser(['type' => 'developer'])));

        $this->assertSame(0, DB::table('site_visits')->count());
        $this->assertSame(3, $this->filtered(VisitRecorder::FILTER_TEAM));

        $this->ping([], [], '203.0.113.13', $this->token($this->makeUser()));
        $this->assertSame(1, (int) DB::table('site_visits')->sole()->signed_in);

        // An expired or made-up token is just a visitor, never an error.
        $this->ping([], [], '203.0.113.14', 'not-a-real-token')->assertNoContent();
        $this->assertSame(2, DB::table('site_visits')->count());
    }

    public function test_refreshing_a_page_counts_once_per_30_minutes(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->ping(['path' => '/faq']);
        }
        $this->assertSame(1, DB::table('site_visits')->count());
        $this->assertSame(4, $this->filtered(VisitRecorder::FILTER_REPEAT));

        // Another page in the same visit counts, so does another browser on the same IP.
        $this->ping(['path' => '/terms']);
        $this->ping(['path' => '/faq'], ['User-Agent' => self::IPHONE_UA]);
        $this->assertSame(3, DB::table('site_visits')->count());

        $this->travel(31)->minutes();
        $this->ping(['path' => '/faq']);
        $this->assertSame(4, DB::table('site_visits')->count());
    }

    public function test_a_flood_from_one_ip_is_dropped_and_the_ip_marked_suspicious(): void
    {
        $this->ping(['path' => '/'], [], '198.51.100.7');
        for ($i = 0; $i < VisitRecorder::FLOOD_PER_MINUTE; $i++) {
            $this->ping(['path' => '/faq'], [], '198.51.100.7');
        }

        // 31 pings: the first of each page counted, the rest repeats — until
        // the 31st, which is over the limit and flags what the IP already sent.
        $this->assertSame(1, $this->filtered(VisitRecorder::FILTER_FLOOD));
        $this->assertSame(2, DB::table('site_visits')->where('ip', '198.51.100.7')->count());
        $this->assertSame(2, DB::table('site_visits')->where('ip', '198.51.100.7')
            ->where('suspicious', true)->where('suspicious_reason', 'flood')->count());

        // A minute later it is under the limit again, but stays suspicious for the day.
        $this->travel(61)->seconds();
        $this->ping(['path' => '/terms'], [], '198.51.100.7');
        $this->assertSame(1, (int) DB::table('site_visits')->where('path', '/terms')->sole()->suspicious);

        // Nobody else is affected.
        $this->ping(['path' => '/terms'], [], '203.0.113.50');
        $this->assertSame(0, (int) DB::table('site_visits')->where('ip', '203.0.113.50')->sole()->suspicious);
    }

    public function test_a_burst_of_pages_in_a_few_seconds_marks_the_ip_suspicious(): void
    {
        $pages = ['/', '/faq', '/terms', '/privacy', '/risk'];
        foreach ($pages as $path) {
            $this->ping(['path' => $path], [], '198.51.100.20');
        }
        // Five pages in a few seconds: a quick reader, still fine.
        $this->assertSame(0, DB::table('site_visits')->where('suspicious', true)->count());

        $this->ping(['path' => '/contact'], [], '198.51.100.20');
        // The sixth makes it a burst: all six are kept, all six flagged.
        $this->assertSame(VisitRecorder::BURST_PAGES, DB::table('site_visits')
            ->where('suspicious', true)->where('suspicious_reason', 'burst')->count());

        // The same pages spread over a few minutes are a person reading.
        foreach ([...$pages, '/contact'] as $path) {
            $this->ping(['path' => $path], [], '203.0.113.60');
            $this->travel(20)->seconds();
        }
        $this->assertSame(0, DB::table('site_visits')->where('ip', '203.0.113.60')->where('suspicious', true)->count());
    }

    public function test_made_up_referral_codes_and_campaign_junk_are_dropped(): void
    {
        $this->ping(['path' => '/', 'ref' => 'NOSUCHCODE', 'utm_source' => 'Free <b>money</b>', 'utm_campaign' => str_repeat('x', 65)]);

        $row = DB::table('site_visits')->sole();
        $this->assertNull($row->ref_code);
        $this->assertNull($row->utm_source);
        $this->assertNull($row->utm_campaign);
    }

    public function test_the_referrer_is_kept_for_the_first_page_only_and_never_our_own_site(): void
    {
        $this->ping(['path' => '/', 'entry' => true, 'referrer' => 'https://pixel-alpha.com/faq']);
        $this->ping(['path' => '/faq', 'entry' => true, 'referrer' => 'http://192.168.1.10/']);
        $this->ping(['path' => '/terms', 'entry' => false, 'referrer' => 'https://t.me/voltraxtrades']);
        $this->ping(['path' => '/privacy', 'entry' => true, 'referrer' => 'https://t.me/voltraxtrades']);

        $this->assertSame(
            ['/' => null, '/faq' => null, '/terms' => null, '/privacy' => 't.me'],
            DB::table('site_visits')->orderBy('id')->pluck('referrer_host', 'path')->all(),
        );
    }

    public function test_a_localhost_page_on_the_live_box_is_a_developer_not_a_visitor(): void
    {
        $this->ping([], ['Origin' => 'http://localhost:5173']);
        $this->assertSame(1, DB::table('site_visits')->count());

        $this->app['env'] = 'production';
        $this->ping(['path' => '/faq'], ['Origin' => 'http://localhost:5173']);
        $this->assertSame(1, DB::table('site_visits')->count());
        $this->assertSame(1, $this->filtered(VisitRecorder::FILTER_FOREIGN));
    }

    public function test_ips_and_browsers_are_removed_after_90_days_and_the_visit_stays(): void
    {
        $row = fn (int $daysAgo) => [
            'path' => '/', 'visitor_hash' => str_repeat('a', 64), 'ip' => '203.0.113.'.$daysAgo,
            'user_agent' => self::WINDOWS_UA, 'country' => 'PH', 'device_type' => 'desktop',
            'created_at' => now()->subDays($daysAgo),
        ];
        DB::table('site_visits')->insert([$row(91), $row(89)]);

        $this->artisan('visits:purge-ips')->assertSuccessful();

        $old = DB::table('site_visits')->where('country', 'PH')->orderBy('created_at')->first();
        $this->assertNull($old->ip);
        $this->assertNull($old->user_agent);
        $this->assertSame('desktop', $old->device_type);
        $this->assertSame(1, DB::table('site_visits')->whereNotNull('ip')->count());
        $this->assertSame(2, DB::table('site_visits')->count());
    }
}
