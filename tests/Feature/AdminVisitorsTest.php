<?php

namespace Tests\Feature;

use App\Models\UserCredential;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/admin/visitors/{summary,log} — Admin → Visitors. In the STAFF
 * group: every admin role reads it, collaborators included, IP included
 * (owner, 2026-10-28). Figures are derived by hand from the rows seeded
 * here: suspicious rows never count, visitors are daily-unique, sign-ups are
 * customer accounts created in the window.
 */
class AdminVisitorsTest extends EngineTestCase
{
    private int $n = 0;

    private function headers(string $uniId): array
    {
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.UserCredential::find($uniId)->createToken('spa')->plainTextToken];
    }

    /** One site_visits row; `visitor` names the daily visitor it belongs to. */
    private function visit(array $o = []): void
    {
        $this->n++;
        $visitor = $o['visitor'] ?? "v{$this->n}";
        unset($o['visitor']);

        DB::table('site_visits')->insert(array_merge([
            'path' => '/',
            'visitor_hash' => str_pad($visitor, 64, '0'),
            'is_entry' => false,
            'ip' => '203.0.113.1',
            'country' => 'TH',
            'device_type' => 'desktop',
            'browser' => 'Chrome 141',
            'os' => 'Windows',
            'suspicious' => false,
            'created_at' => '2026-10-20 10:00:00',
        ], $o));
    }

    private function seedVisits(): void
    {
        // Visitor a: entered from Google (two Google hosts merge), read two pages, opened sign-up.
        $this->visit(['visitor' => 'a', 'is_entry' => true, 'referrer_host' => 'google.com']);
        $this->visit(['visitor' => 'a', 'path' => '/faq']);
        $this->visit(['visitor' => 'a', 'path' => '/auth']);
        // Visitor b: from Google Thailand on a phone, with an invite link.
        $this->visit(['visitor' => 'b', 'is_entry' => true, 'referrer_host' => 'google.co.th', 'ip' => '203.0.113.2',
            'device_type' => 'mobile', 'browser' => 'Safari 18', 'os' => 'iOS 18', 'country' => 'PH', 'ref_code' => 'JUAN10']);
        // Visitor c: typed the address in, the next day.
        $this->visit(['visitor' => 'c', 'is_entry' => true, 'browser' => 'Chrome 140', 'created_at' => '2026-10-21 09:00:00']);
        // Kept for the admins, never counted.
        $this->visit(['visitor' => 'x', 'ip' => '198.51.100.7', 'suspicious' => true, 'suspicious_reason' => 'flood']);
        $this->visit(['visitor' => 'x', 'path' => '/faq', 'ip' => '198.51.100.7', 'suspicious' => true, 'suspicious_reason' => 'flood']);
        // Outside the window.
        $this->visit(['visitor' => 'old', 'created_at' => '2026-10-01 10:00:00']);

        DB::table('site_visit_filtered')->insert([
            ['day' => '2026-10-20', 'reason' => 'bot', 'hits' => 40],
            ['day' => '2026-10-21', 'reason' => 'bot', 'hits' => 2],
            ['day' => '2026-10-20', 'reason' => 'repeat', 'hits' => 7],
            ['day' => '2026-10-01', 'reason' => 'bot', 'hits' => 999],
        ]);
    }

    public function test_the_summary_counts_real_visitors_and_leaves_suspicious_ones_out(): void
    {
        $this->seedVisits();
        $admin = $this->makeUser(['type' => 'admin']);
        $owner = $this->makeUser(['name' => 'Juan']);
        DB::table('referral_codes')->insert(['user_uni_id' => $owner, 'code' => 'JUAN10', 'created_at' => now(), 'updated_at' => now()]);

        // Two sign-ups in the window: one approved with a live key, one pending and unverified.
        $signed = $this->makeUser(['created_at' => '2026-10-20 11:00:00']);
        $this->makeAccount($signed, ['demo' => 0]);
        $pending = $this->makeUser(['created_at' => '2026-10-21 12:00:00', 'status' => 'pending', 'email_verified' => 0]);
        DB::table('user_signup_sources')->insert([
            ['uni_id' => $signed, 'source' => 'google', 'detail' => null],
            ['uni_id' => $pending, 'source' => 'google', 'detail' => null],
            ['uni_id' => $pending, 'source' => 'telegram', 'detail' => null],
        ]);

        $res = $this->getJson('/api/admin/visitors/summary?from=2026-10-20&to=2026-10-21', $this->headers($admin))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('range', ['from' => '2026-10-20', 'to' => '2026-10-21'])
            ->assertJsonPath('ip_days', 90)
            ->assertJsonPath('totals.visitors', 3)
            ->assertJsonPath('totals.views', 5)
            ->assertJsonPath('totals.signups', 2)
            ->assertJsonPath('totals.signup_rate', 66.7)
            ->assertJsonPath('bucket', 'day')
            ->assertJsonPath('series', [
                ['start' => '2026-10-20', 'visitors' => 2, 'views' => 4, 'signups' => 1],
                ['start' => '2026-10-21', 'visitors' => 1, 'views' => 1, 'signups' => 1],
            ])
            ->assertJsonPath('funnel', [
                ['key' => 'visited', 'count' => 3],
                ['key' => 'opened_signup', 'count' => 1],
                ['key' => 'signed_up', 'count' => 2],
                ['key' => 'verified', 'count' => 1],
                ['key' => 'approved', 'count' => 1],
                ['key' => 'connected', 'count' => 1],
            ])
            ->assertJsonPath('referral_codes', [
                ['code' => 'JUAN10', 'owner' => ['uni_id' => $owner, 'name' => 'Juan'], 'visitors' => 1],
            ])
            ->assertJsonPath('heard_about', [
                ['slug' => 'google', 'count' => 2],
                ['slug' => 'telegram', 'count' => 1],
            ])
            ->assertJsonPath('filtered.bot', 42)
            ->assertJsonPath('filtered.repeat', 7)
            ->assertJsonPath('filtered.flood', 0)
            ->assertJsonPath('filtered.suspicious', 2);

        // google.com and google.co.th are one source; c came in directly.
        $this->assertSame(
            [['label' => 'Google', 'direct' => false, 'hosts' => ['google.co.th', 'google.com'], 'visitors' => 2],
                ['label' => 'Direct', 'direct' => true, 'hosts' => [], 'visitors' => 1]],
            collect($res->json('sources'))->map(fn ($s) => [...$s, 'hosts' => collect($s['hosts'])->sort()->values()->all()])->all(),
        );
        // Versions fold: Chrome 141 + Chrome 140 are Chrome.
        $this->assertSame([['value' => 'Chrome', 'visitors' => 2], ['value' => 'Safari', 'visitors' => 1]], $res->json('browsers'));
        $this->assertSame([['value' => 'desktop', 'visitors' => 2], ['value' => 'mobile', 'visitors' => 1]], $res->json('devices'));
        $this->assertSame([['value' => 'TH', 'visitors' => 2], ['value' => 'PH', 'visitors' => 1]], $res->json('countries'));
        $this->assertSame(['path' => '/', 'views' => 3, 'visitors' => 3], $res->json('pages.0'));
    }

    public function test_a_long_window_is_charted_per_week_with_empty_buckets(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);
        $this->visit(['created_at' => '2026-07-01 10:00:00']);

        $res = $this->getJson('/api/admin/visitors/summary?from=2026-06-01&to=2026-10-25', $this->headers($admin))
            ->assertOk()
            ->assertJsonPath('bucket', 'week');

        $series = collect($res->json('series'));
        // Mondays, every week present, the visit in its own.
        $this->assertSame('2026-06-01', $series->first()['start']);
        $this->assertTrue($series->every(fn ($b) => Carbon::parse($b['start'])->isMonday()));
        $this->assertSame(1, $series->sum('visitors'));
        $this->assertSame(1, $series->firstWhere('start', '2026-06-29')['visitors']);
    }

    public function test_every_staff_role_reads_it_and_a_customer_does_not(): void
    {
        $this->seedVisits();
        $collaborator = $this->makeUser(['type' => 'collaborator']);

        // IP included — the owner's call for every staff role.
        $this->getJson('/api/admin/visitors/log?from=2026-10-20&to=2026-10-21', $this->headers($collaborator))
            ->assertOk()
            ->assertJsonPath('rows.0.ip', '203.0.113.1');
        $this->getJson('/api/admin/visitors/summary', $this->headers($collaborator))->assertOk();

        $customer = $this->makeUser();
        $this->getJson('/api/admin/visitors/summary', $this->headers($customer))
            ->assertForbidden()
            ->assertJsonPath('error_code', 'FORBIDDEN');

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/admin/visitors/log')->assertUnauthorized();
    }

    public function test_the_log_filters_and_says_which_accounts_used_an_ip(): void
    {
        $this->seedVisits();
        $admin = $this->makeUser(['type' => 'admin']);
        $maria = $this->makeUser(['name' => 'Maria']);
        DB::table('user_access_events')->insert([
            'uni_id' => $maria, 'event' => 'register', 'method' => 'password', 'ip' => '203.0.113.2',
            'device_type' => 'mobile', 'created_at' => '2026-10-20 12:00:00',
        ]);
        $h = $this->headers($admin);
        $range = 'from=2026-10-20&to=2026-10-21';

        // Clean by default, newest first.
        $this->getJson("/api/admin/visitors/log?{$range}", $h)
            ->assertOk()
            ->assertJsonPath('pagination.total', 5)
            ->assertJsonPath('rows.0.path', '/')
            ->assertJsonPath('rows.0.at', '2026-10-21T09:00:00.000000Z')
            ->assertJsonPath('rows.0.source', 'Direct')
            ->assertJsonPath('options.pages', ['/', '/auth', '/faq'])
            ->assertJsonPath('ip_detail', null);

        $this->getJson("/api/admin/visitors/log?{$range}&view=suspicious", $h)
            ->assertJsonPath('pagination.total', 2)
            ->assertJsonPath('rows.0.suspicious', true)
            ->assertJsonPath('rows.0.suspicious_reason', 'flood');
        $this->getJson("/api/admin/visitors/log?{$range}&view=all", $h)->assertJsonPath('pagination.total', 7);

        $this->getJson("/api/admin/visitors/log?{$range}&page=/faq&device=desktop&country=th", $h)
            ->assertJsonPath('pagination.total', 1);

        // Clicking an address: that IP only, and who signed up from it.
        $this->getJson("/api/admin/visitors/log?{$range}&ip=203.0.113.2", $h)
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('rows.0.accounts', [['uni_id' => $maria, 'name' => 'Maria']])
            ->assertJsonPath('ip_detail.ip', '203.0.113.2')
            ->assertJsonPath('ip_detail.views', 1)
            ->assertJsonPath('ip_detail.countries', ['PH'])
            ->assertJsonPath('ip_detail.accounts', [['uni_id' => $maria, 'name' => 'Maria']]);

        // Typing the start of an address; anything that is not one (a LIKE wildcard) is ignored.
        $this->getJson("/api/admin/visitors/log?{$range}&q=198.51", $h)->assertJsonPath('pagination.total', 0);
        $this->getJson("/api/admin/visitors/log?{$range}&view=all&q=198.51", $h)->assertJsonPath('pagination.total', 2);
        $this->getJson("/api/admin/visitors/log?{$range}&q=%25", $h)->assertJsonPath('pagination.total', 5);

        // Paging.
        $this->getJson("/api/admin/visitors/log?{$range}&limit=2&offset=4", $h)
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('pagination', ['total' => 5, 'limit' => 2, 'offset' => 4]);
    }
}
