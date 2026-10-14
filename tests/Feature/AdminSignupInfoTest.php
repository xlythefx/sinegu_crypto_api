<?php

namespace Tests\Feature;

use App\Models\UserCredential;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/admin/users/{uniId}/signup-info — the sign-up details card on admin
 * user detail. In the STAFF group: every admin role reads it, collaborators
 * included (owner, 2026-10-14), and a plain user is refused. Users from before
 * the feature have nothing on record and read as nulls / empty lists.
 *
 * Also: the three tables cascade with the user, so deleting a user leaves no
 * orphaned fingerprints behind.
 */
class AdminSignupInfoTest extends EngineTestCase
{
    private const IPHONE_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

    private const WINDOWS_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';

    private function headers(string $uniId): array
    {
        $this->app['auth']->forgetGuards();
        $token = UserCredential::find($uniId)->createToken('spa')->plainTextToken;

        return ['Authorization' => 'Bearer '.$token];
    }

    /** A sign-up through the real endpoint, then one login a minute later. */
    private function registerAndLogin(): string
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10']);
        $this->postJson('/api/auth/register', [
            'name' => 'Sam Signup',
            'email' => 'sam@test.local',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
            'terms' => true,
            // Ticked in a different order than the form shows them.
            'heard_from' => ['other', 'google', 'discord'],
            'heard_from_other' => 'A podcast',
            'telegram' => '@Sam_Signup',
            'discord' => 'Sam.Signup',
            'consent' => 'all',
            'client' => ['timezone' => 'Asia/Bangkok', 'language' => 'en-US', 'screen' => '390x844'],
        ], ['User-Agent' => self::IPHONE_UA, 'CF-IPCountry' => 'TH'])->assertCreated();

        $this->travel(1)->minutes();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.20']);
        $this->postJson('/api/auth/login', [
            'email' => 'sam@test.local',
            'password' => 'correct-horse-battery',
            'consent' => 'essential',
        ], ['User-Agent' => self::WINDOWS_UA, 'CF-IPCountry' => 'PH'])->assertOk();

        return DB::table('user_credentials')->where('email', 'sam@test.local')->value('uni_id');
    }

    public function test_an_admin_reads_the_whole_card(): void
    {
        $uniId = $this->registerAndLogin();
        $admin = $this->makeUser(['type' => 'admin']);

        $res = $this->getJson("/api/admin/users/{$uniId}/signup-info", $this->headers($admin))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('signup.method', 'password')
            // Form order (SignupSources::SLUGS), not the order ticked.
            ->assertJsonPath('signup.sources', [
                ['source' => 'discord', 'detail' => null],
                ['source' => 'google', 'detail' => null],
                ['source' => 'other', 'detail' => 'A podcast'],
            ])
            ->assertJsonPath('signup.contacts', [
                'telegram' => 'Sam_Signup',
                'discord' => 'sam.signup',
                'discord_linked' => null,
            ])
            ->assertJsonPath('signup.first.event', 'register')
            ->assertJsonPath('signup.first.method', 'password')
            ->assertJsonPath('signup.first.ip', '203.0.113.10')
            ->assertJsonPath('signup.first.country', 'TH')
            ->assertJsonPath('signup.first.device_type', 'mobile')
            ->assertJsonPath('signup.first.browser', 'Safari 18')
            ->assertJsonPath('signup.first.os', 'iOS 18')
            ->assertJsonPath('signup.first.timezone', 'Asia/Bangkok')
            ->assertJsonPath('signup.first.consent', 'all')
            ->assertJsonCount(2, 'events')
            // Newest first: the login, then the registration.
            ->assertJsonPath('events.0.event', 'login')
            ->assertJsonPath('events.0.ip', '203.0.113.20')
            ->assertJsonPath('events.0.country', 'PH')
            ->assertJsonPath('events.0.device_type', 'desktop')
            ->assertJsonPath('events.0.timezone', null)
            ->assertJsonPath('events.0.consent', 'essential')
            ->assertJsonPath('events.1.event', 'register');

        $this->assertNotNull($res->json('signup.registered_at'));
        $this->assertSame(
            ['id', 'event', 'method', 'ip', 'country', 'device_type', 'browser', 'os', 'user_agent',
                'timezone', 'language', 'screen', 'consent', 'created_at'],
            array_keys($res->json('signup.first')),
        );
        $this->assertSame($res->json('signup.first'), $res->json('events.1'));
        // ISO 8601, like every other timestamp the admin pages read.
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $res->json('events.0.created_at'));
    }

    public function test_a_collaborator_reads_it_and_a_plain_user_does_not(): void
    {
        $uniId = $this->registerAndLogin();
        $collaborator = $this->makeUser(['type' => 'collaborator']);
        $customer = $this->makeUser();

        // IP included — the owner's call for every staff role.
        $this->getJson("/api/admin/users/{$uniId}/signup-info", $this->headers($collaborator))
            ->assertOk()
            ->assertJsonPath('signup.first.ip', '203.0.113.10');

        $this->getJson("/api/admin/users/{$uniId}/signup-info", $this->headers($customer))
            ->assertForbidden()
            ->assertJsonPath('error_code', 'FORBIDDEN');

        // Sanctum keeps the last user it resolved; drop it to ask as nobody.
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/admin/users/{$uniId}/signup-info")->assertUnauthorized();
    }

    public function test_an_unknown_user_is_a_404_like_user_detail(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);

        $this->getJson('/api/admin/users/no-such-user/signup-info', $this->headers($admin))
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'User not found.']);
    }

    public function test_a_user_from_before_the_feature_reads_as_empty(): void
    {
        $old = $this->makeUser(['created_at' => '2026-08-01 10:00:00']);
        $admin = $this->makeUser(['type' => 'admin']);

        $this->getJson("/api/admin/users/{$old}/signup-info", $this->headers($admin))
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'signup' => [
                    'registered_at' => '2026-08-01T10:00:00.000000Z',
                    'method' => null,
                    'sources' => [],
                    'contacts' => ['telegram' => null, 'discord' => null, 'discord_linked' => null],
                    'first' => null,
                ],
                'events' => [],
            ]);
    }

    public function test_discord_linked_is_the_linked_account_only(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);
        $linked = $this->makeUser(['discord_id' => '123456789012345678', 'discord_username' => 'linked.name']);
        // A username left behind without an id is not a link.
        $stale = $this->makeUser(['discord_id' => null, 'discord_username' => 'stale.name']);

        $this->getJson("/api/admin/users/{$linked}/signup-info", $this->headers($admin))
            ->assertOk()
            ->assertJsonPath('signup.contacts.discord_linked', 'linked.name');

        $this->getJson("/api/admin/users/{$stale}/signup-info", $this->headers($admin))
            ->assertOk()
            ->assertJsonPath('signup.contacts.discord_linked', null);
    }

    public function test_events_are_capped_at_25_newest_first(): void
    {
        $uniId = $this->makeUser();
        $admin = $this->makeUser(['type' => 'admin']);

        $rows = [];
        for ($i = 0; $i < 30; $i++) {
            $rows[] = [
                'uni_id' => $uniId,
                'event' => $i === 0 ? 'register' : 'login',
                'method' => 'password',
                'ip' => "10.0.0.{$i}",
                'device_type' => 'desktop',
                'created_at' => now()->subDays(30 - $i),
            ];
        }
        DB::table('user_access_events')->insert($rows);

        $res = $this->getJson("/api/admin/users/{$uniId}/signup-info", $this->headers($admin))
            ->assertOk()
            ->assertJsonCount(25, 'events')
            ->assertJsonPath('events.0.ip', '10.0.0.29')
            ->assertJsonPath('events.24.ip', '10.0.0.5');

        // The registration is still there as `first`, though it fell off the list.
        $res->assertJsonPath('signup.first.ip', '10.0.0.0')
            ->assertJsonPath('signup.method', 'password');
    }

    public function test_deleting_a_user_deletes_their_signup_details(): void
    {
        $uniId = $this->registerAndLogin();
        DB::table('user_credentials')->where('uni_id', $uniId)->update(['status' => 'suspended']);
        $admin = $this->makeUser(['type' => 'admin']);

        $this->assertGreaterThan(0, DB::table('user_access_events')->where('uni_id', $uniId)->count());
        $this->assertGreaterThan(0, DB::table('user_signup_sources')->where('uni_id', $uniId)->count());
        $this->assertSame(1, DB::table('user_contact_handles')->where('uni_id', $uniId)->count());

        $this->deleteJson("/api/admin/users/{$uniId}", [], $this->headers($admin))->assertOk();

        $this->assertSame(0, DB::table('user_access_events')->where('uni_id', $uniId)->count());
        $this->assertSame(0, DB::table('user_signup_sources')->where('uni_id', $uniId)->count());
        $this->assertSame(0, DB::table('user_contact_handles')->where('uni_id', $uniId)->count());
    }
}
