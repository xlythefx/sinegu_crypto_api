<?php

namespace Tests\Feature;

use App\Services\Signup\ContactHandles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sign-up details on POST /api/auth/register and POST /api/auth/login:
 * "Where did you hear about us?" (one row per ticked option), the optional
 * Telegram / Discord handles (normalised, then held to each platform's
 * username rules), and the access "fingerprint" — IP, Cloudflare country,
 * device — of the registration and of every successful login.
 *
 * The browser's own timezone / language / screen are stored ONLY with the
 * cookie banner's "Accept all", and client input is sanitised silently: a
 * malformed field is a null on a note, never a refused sign-in. And the note
 * is best-effort — losing it must never fail the login it describes.
 */
class SignupDetailsTest extends EngineTestCase
{
    private const IPHONE_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

    private const WINDOWS_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'New Trader',
            'email' => 'new-trader@test.local',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
            'terms' => true,
        ], $overrides);
    }

    /**
     * Register from its own IP: the route allows 5 per minute per IP, and a
     * 422 counts toward it like any other request.
     */
    private function register(array $overrides = [], array $headers = [])
    {
        static $n = 0;
        $n++;
        $this->withServerVariables(['REMOTE_ADDR' => "198.51.100.{$n}"]);

        return $this->postJson('/api/auth/register', $this->payload($overrides), $headers);
    }

    private function uniIdOf(string $email): string
    {
        return DB::table('user_credentials')->where('email', $email)->value('uni_id');
    }

    // ---- sources ----------------------------------------------------------------

    public function test_register_stores_one_row_per_ticked_source_and_the_other_detail(): void
    {
        $this->register([
            'heard_from' => ['google', 'friend', 'other'],
            'heard_from_other' => '  A podcast  ',
        ])->assertCreated();

        $uniId = $this->uniIdOf('new-trader@test.local');
        $rows = DB::table('user_signup_sources')->where('uni_id', $uniId)->orderBy('source')->get(['source', 'detail']);

        $this->assertSame(
            [['friend', null], ['google', null], ['other', 'A podcast']],
            $rows->map(fn ($r) => [$r->source, $r->detail])->all(),
        );
    }

    public function test_the_other_detail_is_dropped_when_other_is_not_ticked(): void
    {
        $this->register([
            'heard_from' => ['youtube'],
            'heard_from_other' => 'Typed, then unticked',
        ])->assertCreated();

        $uniId = $this->uniIdOf('new-trader@test.local');
        $this->assertSame(1, DB::table('user_signup_sources')->where('uni_id', $uniId)->count());
        $this->assertDatabaseHas('user_signup_sources', ['uni_id' => $uniId, 'source' => 'youtube', 'detail' => null]);
        $this->assertDatabaseMissing('user_signup_sources', ['detail' => 'Typed, then unticked']);
    }

    public function test_skipping_the_question_stores_nothing(): void
    {
        $this->register()->assertCreated();

        $this->assertSame(0, DB::table('user_signup_sources')->count());
    }

    public function test_an_unknown_or_repeated_source_is_a_422_and_creates_no_user(): void
    {
        $this->register(['heard_from' => ['tiktok']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['heard_from.0']);

        $this->register(['heard_from' => ['google', 'google']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['heard_from.0']);

        $this->register(['heard_from' => 'google'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['heard_from']);

        $this->register(['heard_from' => ['other'], 'heard_from_other' => str_repeat('a', 121)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['heard_from_other']);

        $this->assertDatabaseMissing('user_credentials', ['email' => 'new-trader@test.local']);
        $this->assertSame(0, DB::table('user_signup_sources')->count());
    }

    // ---- handles ----------------------------------------------------------------

    public function test_register_normalises_the_handles(): void
    {
        $cases = [
            ['@Pixel_Alpha', '@Foo.Bar', 'Pixel_Alpha', 'foo.bar'],
            ['t.me/foo_bar', ' someone_1 ', 'foo_bar', 'someone_1'],
            ['https://t.me/Foo_Bar99', 'UPPER.case', 'Foo_Bar99', 'upper.case'],
        ];

        foreach ($cases as $i => [$telegram, $discord, $wantTelegram, $wantDiscord]) {
            $email = "handles{$i}@test.local";
            $this->register(['email' => $email, 'telegram' => $telegram, 'discord' => $discord])->assertCreated();

            $this->assertDatabaseHas('user_contact_handles', [
                'uni_id' => $this->uniIdOf($email),
                'telegram' => $wantTelegram,
                'discord' => $wantDiscord,
            ]);
        }
    }

    public function test_no_handles_row_without_handles(): void
    {
        $this->register(['email' => 'none@test.local'])->assertCreated();
        $this->register(['email' => 'blank@test.local', 'telegram' => '', 'discord' => null])->assertCreated();
        $this->register(['email' => 'at-only@test.local', 'telegram' => '@', 'discord' => '  @ '])->assertCreated();

        $this->assertSame(0, DB::table('user_contact_handles')->count());
    }

    public function test_one_handle_alone_is_stored(): void
    {
        $this->register(['telegram' => '@solo_handle'])->assertCreated();

        $this->assertDatabaseHas('user_contact_handles', [
            'uni_id' => $this->uniIdOf('new-trader@test.local'),
            'telegram' => 'solo_handle',
            'discord' => null,
        ]);
    }

    public function test_an_invalid_telegram_is_a_422_with_the_plain_message_and_creates_no_user(): void
    {
        // Too short, starts with a digit, a space, a hyphen, too long, not a string.
        foreach (['@abc', '1abcde', 'pixel alpha', 'pixel-alpha', 'a'.str_repeat('b', 32), 12345] as $bad) {
            $this->register(['telegram' => $bad])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['telegram' => ContactHandles::TELEGRAM_MESSAGE]);
        }

        $this->assertDatabaseMissing('user_credentials', ['email' => 'new-trader@test.local']);
        $this->assertSame(0, DB::table('user_contact_handles')->count());
    }

    public function test_an_invalid_discord_is_a_422_with_the_plain_message_and_creates_no_user(): void
    {
        foreach (['a', 'has space', 'bad!name', str_repeat('a', 33), 'pixel#1234'] as $bad) {
            $this->register(['discord' => $bad])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['discord' => ContactHandles::DISCORD_MESSAGE]);
        }

        $this->assertDatabaseMissing('user_credentials', ['email' => 'new-trader@test.local']);
    }

    // ---- access events ----------------------------------------------------------

    public function test_register_records_a_register_event_with_ip_country_and_device(): void
    {
        $this->register([
            'consent' => 'all',
            'client' => ['timezone' => 'Asia/Bangkok', 'language' => 'th-TH', 'screen' => '390x844'],
        ], ['User-Agent' => self::IPHONE_UA, 'CF-IPCountry' => 'th'])->assertCreated();

        $event = DB::table('user_access_events')->sole();

        $this->assertSame($this->uniIdOf('new-trader@test.local'), $event->uni_id);
        $this->assertSame('register', $event->event);
        $this->assertSame('password', $event->method);
        $this->assertStringStartsWith('198.51.100.', $event->ip);
        $this->assertSame('TH', $event->country);
        $this->assertSame('mobile', $event->device_type);
        $this->assertSame('Safari 18', $event->browser);
        $this->assertSame('iOS 18', $event->os);
        $this->assertSame(self::IPHONE_UA, $event->user_agent);
        $this->assertSame('Asia/Bangkok', $event->timezone);
        $this->assertSame('th-TH', $event->language);
        $this->assertSame('390x844', $event->screen);
        $this->assertSame('all', $event->consent);
        $this->assertNotNull($event->created_at);
    }

    public function test_login_records_a_login_event(): void
    {
        $uniId = $this->makeUser(['email' => 'trader@test.local']);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7']);

        $this->postJson('/api/auth/login', [
            'email' => 'trader@test.local',
            'password' => 'secret-password',
        ], ['User-Agent' => self::WINDOWS_UA, 'CF-IPCountry' => 'PH'])->assertOk();

        $event = DB::table('user_access_events')->sole();
        $this->assertSame($uniId, $event->uni_id);
        $this->assertSame('login', $event->event);
        $this->assertSame('password', $event->method);
        $this->assertSame('203.0.113.7', $event->ip);
        $this->assertSame('PH', $event->country);
        $this->assertSame('desktop', $event->device_type);
        $this->assertSame('Chrome 141', $event->browser);
        $this->assertSame('Windows', $event->os);
    }

    public function test_cloudflare_unknown_country_and_no_header_are_null(): void
    {
        $this->makeUser(['email' => 'trader@test.local']);

        foreach (['XX', 'Thailand', ''] as $header) {
            $this->postJson('/api/auth/login', ['email' => 'trader@test.local', 'password' => 'secret-password'], [
                'CF-IPCountry' => $header,
            ])->assertOk();
        }
        // Locally there is no Cloudflare at all.
        $this->postJson('/api/auth/login', ['email' => 'trader@test.local', 'password' => 'secret-password'])->assertOk();

        $this->assertSame([null, null, null, null], DB::table('user_access_events')->orderBy('id')->pluck('country')->all());
    }

    public function test_client_fields_are_kept_only_with_accept_all(): void
    {
        $this->makeUser(['email' => 'trader@test.local']);
        $client = ['timezone' => 'Europe/London', 'language' => 'en-GB', 'screen' => '1920x1080'];

        foreach (['essential', null, 'all'] as $consent) {
            $body = ['email' => 'trader@test.local', 'password' => 'secret-password', 'client' => $client];
            if ($consent !== null) {
                $body['consent'] = $consent;
            }
            $this->postJson('/api/auth/login', $body)->assertOk();
        }

        $rows = DB::table('user_access_events')->orderBy('id')->get();

        // "Essential only": the answer is recorded, the browser details are not.
        $this->assertSame('essential', $rows[0]->consent);
        $this->assertNull($rows[0]->timezone);
        $this->assertNull($rows[0]->language);
        $this->assertNull($rows[0]->screen);

        // No answer at all: the same.
        $this->assertNull($rows[1]->consent);
        $this->assertNull($rows[1]->timezone);
        $this->assertNull($rows[1]->language);
        $this->assertNull($rows[1]->screen);

        $this->assertSame('all', $rows[2]->consent);
        $this->assertSame('Europe/London', $rows[2]->timezone);
        $this->assertSame('en-GB', $rows[2]->language);
        $this->assertSame('1920x1080', $rows[2]->screen);
    }

    public function test_register_with_essential_consent_drops_the_client_fields(): void
    {
        $this->register([
            'consent' => 'essential',
            'client' => ['timezone' => 'Asia/Bangkok', 'language' => 'th', 'screen' => '390x844'],
        ])->assertCreated();

        $event = DB::table('user_access_events')->sole();
        $this->assertSame('essential', $event->consent);
        $this->assertNull($event->timezone);
        $this->assertNull($event->language);
        $this->assertNull($event->screen);
    }

    public function test_a_login_with_garbage_client_data_still_succeeds(): void
    {
        $this->makeUser(['email' => 'trader@test.local']);

        $garbage = [
            ['client' => 'not-an-object', 'consent' => 'all'],
            ['client' => ['timezone' => ['nested'], 'language' => str_repeat('a', 300), 'screen' => '99999999x1'], 'consent' => 'all'],
            ['client' => ['timezone' => 'Europe/Paris; DROP TABLE', 'language' => 'en_US!', 'screen' => '1920 x 1080'], 'consent' => 'all'],
            ['client' => ['timezone' => 'UTC'], 'consent' => ['all']],
            ['client' => null, 'consent' => 'yes please'],
        ];

        foreach ($garbage as $extra) {
            $this->postJson('/api/auth/login', ['email' => 'trader@test.local', 'password' => 'secret-password'] + $extra)
                ->assertOk()
                ->assertJsonPath('success', true);
        }

        $rows = DB::table('user_access_events')->orderBy('id')->get();
        $this->assertCount(5, $rows);
        foreach ($rows as $row) {
            $this->assertNull($row->timezone);
            $this->assertNull($row->language);
            $this->assertNull($row->screen);
        }
        $this->assertSame(['all', 'all', 'all', null, null], $rows->pluck('consent')->all());
    }

    public function test_a_failed_login_records_nothing(): void
    {
        $this->makeUser(['email' => 'trader@test.local']);

        $this->postJson('/api/auth/login', ['email' => 'trader@test.local', 'password' => 'wrong'])->assertStatus(401);
        $this->postJson('/api/auth/login', ['email' => 'nobody@test.local', 'password' => 'wrong'])->assertStatus(401);
        $this->makeUser(['email' => 'suspended@test.local', 'status' => 'suspended']);
        $this->postJson('/api/auth/login', ['email' => 'suspended@test.local', 'password' => 'secret-password'])->assertForbidden();

        $this->assertSame(0, DB::table('user_access_events')->count());
    }

    public function test_a_failure_to_record_never_fails_a_login_or_a_registration(): void
    {
        $this->makeUser(['email' => 'trader@test.local']);
        Schema::drop('user_access_events');

        $this->postJson('/api/auth/login', ['email' => 'trader@test.local', 'password' => 'secret-password'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->register(['heard_from' => ['google'], 'telegram' => '@still_saved'])
            ->assertCreated()
            ->assertJsonPath('success', true);

        // The rest of the sign-up is not collateral damage.
        $uniId = $this->uniIdOf('new-trader@test.local');
        $this->assertDatabaseHas('user_signup_sources', ['uni_id' => $uniId, 'source' => 'google']);
        $this->assertDatabaseHas('user_contact_handles', ['uni_id' => $uniId, 'telegram' => 'still_saved']);
    }
}
