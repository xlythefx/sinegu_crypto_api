<?php

namespace Tests\Feature;

use App\Services\Signup\ContactHandles;
use Illuminate\Support\Facades\DB;

/**
 * Sign-up details on the Discord path: every way Discord signs someone in
 * writes a `login / discord` access event, and POST /auth/discord/complete
 * takes the same "where did you hear about us" and Telegram fields as the
 * password form — but no Discord handle, since that sign-up already knows the
 * linked account — and writes a `register / discord` event.
 */
class DiscordSignupDetailsTest extends DiscordTestCase
{
    private const ANDROID_UA = 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36';

    public function test_a_known_discord_login_records_a_discord_login_event(): void
    {
        $uniId = $this->makeLinkedUser();

        $this->postJson('/api/auth/discord/callback', [
            'code' => 'good-code',
            'redirect_uri' => self::REDIRECT,
            'consent' => 'all',
            'client' => ['timezone' => 'Asia/Manila', 'language' => 'fil', 'screen' => '412x915'],
        ], ['User-Agent' => self::ANDROID_UA, 'CF-IPCountry' => 'PH'])
            ->assertOk()
            ->assertJsonPath('status', 'logged_in');

        $event = DB::table('user_access_events')->sole();
        $this->assertSame($uniId, $event->uni_id);
        $this->assertSame('login', $event->event);
        $this->assertSame('discord', $event->method);
        $this->assertSame('PH', $event->country);
        $this->assertSame('mobile', $event->device_type);
        $this->assertSame('Android 15', $event->os);
        $this->assertSame('Asia/Manila', $event->timezone);
    }

    public function test_the_password_and_terms_steps_record_nothing_yet(): void
    {
        // New user → terms step; existing email → password step. Neither is a login.
        $this->oauthCallback()->assertOk()->assertJsonPath('status', 'terms_required');

        $this->makeUser(['email' => 'trader@example.com']);
        $this->oauthCallback()->assertOk()->assertJsonPath('status', 'password_required');

        $this->assertSame(0, DB::table('user_access_events')->count());
    }

    public function test_linking_with_the_password_records_a_discord_login_and_a_wrong_one_does_not(): void
    {
        $uniId = $this->makeUser(['email' => 'trader@example.com', 'password' => bcrypt('hunter22')]);
        $linkToken = $this->oauthCallback()->json('link_token');

        $this->postJson('/api/auth/discord/link-with-password', ['link_token' => $linkToken, 'password' => 'wrong'])
            ->assertStatus(401);
        $this->assertSame(0, DB::table('user_access_events')->count());

        $this->postJson('/api/auth/discord/link-with-password', ['link_token' => $linkToken, 'password' => 'hunter22'])
            ->assertOk();

        $event = DB::table('user_access_events')->sole();
        $this->assertSame($uniId, $event->uni_id);
        $this->assertSame('login', $event->event);
        $this->assertSame('discord', $event->method);
    }

    public function test_complete_stores_sources_and_telegram_and_records_a_discord_registration(): void
    {
        $signupToken = $this->oauthCallback()->json('signup_token');

        $res = $this->postJson('/api/auth/discord/complete', [
            'signup_token' => $signupToken,
            'terms' => true,
            'heard_from' => ['x', 'discord'],
            'heard_from_other' => 'ignored — other is not ticked',
            'telegram' => 't.me/Pixel_Trader',
            // Not a field on this path: the linked Discord account is known.
            'discord' => '@someone.else',
            'consent' => 'all',
            'client' => ['timezone' => 'Asia/Bangkok', 'language' => 'th-TH', 'screen' => '1440x900'],
        ], ['User-Agent' => self::ANDROID_UA, 'CF-IPCountry' => 'TH'])
            ->assertCreated()
            ->assertJsonPath('status', 'logged_in');

        $uniId = $res->json('user.uni_id');

        $this->assertSame(
            ['discord', 'x'],
            DB::table('user_signup_sources')->where('uni_id', $uniId)->orderBy('source')->pluck('source')->all(),
        );
        $this->assertSame(0, DB::table('user_signup_sources')->whereNotNull('detail')->count());
        $this->assertDatabaseHas('user_contact_handles', [
            'uni_id' => $uniId,
            'telegram' => 'Pixel_Trader',
            'discord' => null,
        ]);

        $event = DB::table('user_access_events')->sole();
        $this->assertSame($uniId, $event->uni_id);
        $this->assertSame('register', $event->event);
        $this->assertSame('discord', $event->method);
        $this->assertSame('TH', $event->country);
        $this->assertSame('Asia/Bangkok', $event->timezone);
        $this->assertSame('all', $event->consent);
    }

    public function test_complete_without_details_creates_no_sources_or_handles(): void
    {
        $signupToken = $this->oauthCallback()->json('signup_token');

        $this->postJson('/api/auth/discord/complete', ['signup_token' => $signupToken, 'terms' => true])
            ->assertCreated();

        $this->assertSame(0, DB::table('user_signup_sources')->count());
        $this->assertSame(0, DB::table('user_contact_handles')->count());
        $this->assertSame(1, DB::table('user_access_events')->where('event', 'register')->where('method', 'discord')->count());
    }

    public function test_complete_refuses_a_bad_telegram_or_source_and_keeps_the_token_usable(): void
    {
        $signupToken = $this->oauthCallback()->json('signup_token');

        $this->postJson('/api/auth/discord/complete', [
            'signup_token' => $signupToken,
            'terms' => true,
            'telegram' => 'no',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['telegram' => ContactHandles::TELEGRAM_MESSAGE]);

        $this->postJson('/api/auth/discord/complete', [
            'signup_token' => $signupToken,
            'terms' => true,
            'heard_from' => ['myspace'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['heard_from.0']);

        $this->assertDatabaseMissing('user_credentials', ['email' => 'trader@example.com']);

        // Validation runs before the token is consumed: fixing the field finishes the sign-up.
        $this->postJson('/api/auth/discord/complete', [
            'signup_token' => $signupToken,
            'terms' => true,
            'telegram' => '@fixed_name',
        ])->assertCreated();

        $this->assertDatabaseHas('user_contact_handles', ['telegram' => 'fixed_name']);
    }
}
