<?php

namespace Tests\Feature;

use App\Models\UserCredential;
use Illuminate\Support\Facades\DB;

/**
 * "Sign in with Discord" end to end against the in-memory Discord: the three
 * callback outcomes (known id → login, known email → password step, new →
 * Terms step), the guards around each, and the two invariants that matter
 * most — no secret ever appears in a response, and a dead Discord never
 * fails a login, an approval or a connect.
 */
class DiscordAuthTest extends DiscordTestCase
{
    // ---- config -----------------------------------------------------------

    public function test_config_reports_disabled_when_the_app_is_not_set_up(): void
    {
        config(['services.discord.client_id' => null, 'services.discord.client_secret' => null]);

        $this->getJson('/api/auth/discord/config')
            ->assertOk()
            ->assertJsonPath('configured', false)
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('authorize_url', null);
    }

    public function test_config_hides_the_button_but_keeps_the_flow_while_not_public(): void
    {
        config(['services.discord.login_public' => false]);

        $res = $this->getJson('/api/auth/discord/config')
            ->assertOk()
            ->assertJsonPath('configured', true)
            ->assertJsonPath('enabled', false);

        $this->assertStringContainsString('discord.com/oauth2/authorize', $res->json('authorize_url'));
        $this->assertStringContainsString('client_id=111111111111111111', $res->json('authorize_url'));
        $this->assertStringNotContainsString('state=', $res->json('authorize_url'));
        $this->assertStringNotContainsString('redirect_uri=', $res->json('authorize_url'));
    }

    public function test_config_enables_the_button_when_public(): void
    {
        $this->getJson('/api/auth/discord/config')
            ->assertOk()
            ->assertJsonPath('enabled', true);
    }

    // ---- callback: known Discord id ---------------------------------------

    public function test_callback_refuses_a_redirect_uri_outside_the_allow_list(): void
    {
        $this->makeLinkedUser();

        $this->oauthCallback('good-code', 'https://evil.example/callback')
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'REDIRECT_URI_NOT_ALLOWED');

        $this->assertSame([], $this->discord->callsTo('exchangeCode'));
    }

    public function test_callback_passes_the_allowed_redirect_uri_to_the_exchange(): void
    {
        $this->makeLinkedUser();

        $this->oauthCallback('the-code', self::REDIRECT)->assertOk();

        $this->assertSame([['exchangeCode', 'the-code', self::REDIRECT]], $this->discord->callsTo('exchangeCode'));
    }

    public function test_known_discord_id_logs_in_joins_the_server_and_grants_member(): void
    {
        $uniId = $this->makeLinkedUser(['status' => 'active']);

        $res = $this->oauthCallback()
            ->assertOk()
            ->assertJsonPath('status', 'logged_in')
            ->assertJsonPath('user.uni_id', $uniId)
            ->assertJsonPath('user.discord.id', $this->discord->profile['id'])
            ->assertJsonPath('user.has_password', true);

        $this->assertNotEmpty($res->json('token'));
        $this->assertIsString($res->json('user.discord.id'));
        $this->assertNotNull(DB::table('user_credentials')->where('uni_id', $uniId)->value('last_activity'));

        $this->assertCount(1, $this->discord->callsTo('addGuildMember'));
        $this->assertSame([self::ROLE_MEMBER], $this->discord->rolesOf($this->discord->profile['id']));
    }

    public function test_pending_user_logs_in_but_earns_no_role_yet(): void
    {
        $this->makeLinkedUser(['status' => 'pending']);

        $this->oauthCallback()->assertOk()->assertJsonPath('status', 'logged_in');

        $this->assertSame([], $this->discord->rolesOf($this->discord->profile['id']));
    }

    public function test_suspended_user_is_refused_with_no_token(): void
    {
        $this->makeLinkedUser(['status' => 'suspended']);

        $res = $this->oauthCallback()
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'ACCOUNT_SUSPENDED');

        $this->assertNull($res->json('token'));
        $this->assertSame([], $this->discord->callsTo('addGuildMember'));
    }

    // ---- callback: known email → password step -----------------------------

    public function test_matching_email_is_never_auto_linked_even_when_discord_says_verified(): void
    {
        $uniId = $this->makeUser(['email' => 'trader@example.com']);
        $this->discord->profile['verified'] = true;

        $res = $this->oauthCallback()
            ->assertOk()
            ->assertJsonPath('status', 'password_required')
            ->assertJsonPath('profile.email', 'trader@example.com');

        $this->assertNotEmpty($res->json('link_token'));
        $this->assertNull($res->json('token'));
        $this->assertNull(DB::table('user_credentials')->where('uni_id', $uniId)->value('discord_id'));
        $this->assertStringNotContainsString($this->discord->accessToken, $res->getContent());
    }

    public function test_right_password_links_and_logs_in(): void
    {
        $uniId = $this->makeUser(['email' => 'trader@example.com', 'password' => bcrypt('hunter22'), 'status' => 'active']);
        $linkToken = $this->oauthCallback()->json('link_token');

        $res = $this->postJson('/api/auth/discord/link-with-password', [
            'link_token' => $linkToken,
            'password' => 'hunter22',
        ])
            ->assertOk()
            ->assertJsonPath('status', 'logged_in')
            ->assertJsonPath('user.uni_id', $uniId)
            ->assertJsonPath('user.discord.username', 'pixeltrader');

        $this->assertNotEmpty($res->json('token'));
        $this->assertSame(
            $this->discord->profile['id'],
            DB::table('user_credentials')->where('uni_id', $uniId)->value('discord_id'),
        );
        $this->assertSame([self::ROLE_MEMBER], $this->discord->rolesOf($this->discord->profile['id']));

        // Single use.
        $this->postJson('/api/auth/discord/link-with-password', [
            'link_token' => $linkToken,
            'password' => 'hunter22',
        ])->assertStatus(410)->assertJsonPath('error_code', 'LINK_EXPIRED');
    }

    public function test_wrong_password_does_not_link_and_counts_down(): void
    {
        $uniId = $this->makeUser(['email' => 'trader@example.com', 'password' => bcrypt('hunter22')]);
        $linkToken = $this->oauthCallback()->json('link_token');

        $this->postJson('/api/auth/discord/link-with-password', [
            'link_token' => $linkToken,
            'password' => 'nope',
        ])
            ->assertStatus(401)
            ->assertJsonPath('error_code', 'INVALID_PASSWORD')
            ->assertJsonPath('attempts_left', 4);

        $this->assertNull(DB::table('user_credentials')->where('uni_id', $uniId)->value('discord_id'));

        // The right password still works after a typo.
        $this->postJson('/api/auth/discord/link-with-password', [
            'link_token' => $linkToken,
            'password' => 'hunter22',
        ])->assertOk();
    }

    public function test_link_token_is_voided_after_too_many_wrong_passwords(): void
    {
        $this->makeUser(['email' => 'trader@example.com', 'password' => bcrypt('hunter22')]);
        $linkToken = $this->oauthCallback()->json('link_token');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/discord/link-with-password', [
                'link_token' => $linkToken,
                'password' => 'nope',
            ])->assertStatus(401);
        }

        $this->postJson('/api/auth/discord/link-with-password', [
            'link_token' => $linkToken,
            'password' => 'hunter22',
        ])->assertStatus(410)->assertJsonPath('error_code', 'LINK_EXPIRED');
    }

    public function test_link_token_expires(): void
    {
        $this->makeUser(['email' => 'trader@example.com', 'password' => bcrypt('hunter22')]);
        $linkToken = $this->oauthCallback()->json('link_token');

        $this->travel(16)->minutes();

        $this->postJson('/api/auth/discord/link-with-password', [
            'link_token' => $linkToken,
            'password' => 'hunter22',
        ])->assertStatus(410)->assertJsonPath('error_code', 'LINK_EXPIRED');
    }

    public function test_password_step_refuses_a_discord_only_account_under_that_email(): void
    {
        // The email belongs to a Discord-only account linked to a DIFFERENT
        // Discord user; the one calling now has no password to prove anything.
        $this->makeUser([
            'email' => 'trader@example.com',
            'password' => null,
            'discord_id' => '999999999999999999',
        ]);
        $linkToken = $this->oauthCallback()->json('link_token');

        $this->postJson('/api/auth/discord/link-with-password', [
            'link_token' => $linkToken,
            'password' => 'anything',
        ])->assertStatus(409)->assertJsonPath('error_code', 'NO_PASSWORD');
    }

    // ---- callback: new user → terms step -----------------------------------

    public function test_new_user_gets_the_terms_step_and_no_row_yet(): void
    {
        $res = $this->oauthCallback()
            ->assertOk()
            ->assertJsonPath('status', 'terms_required')
            ->assertJsonPath('profile.name', 'Pixel Trader')
            ->assertJsonPath('profile.email', 'trader@example.com');

        $this->assertNotEmpty($res->json('signup_token'));
        $this->assertStringContainsString('cdn.discordapp.com/avatars/', $res->json('profile.avatar_url'));
        $this->assertDatabaseMissing('user_credentials', ['email' => 'trader@example.com']);
        $this->assertStringNotContainsString($this->discord->accessToken, $res->getContent());
    }

    public function test_complete_creates_a_pending_discord_only_account(): void
    {
        $referrerId = $this->makeUser();
        DB::table('referral_codes')->insert([
            'code' => 'FRIEND1',
            'user_uni_id' => $referrerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $signupToken = $this->oauthCallback()->json('signup_token');

        $res = $this->postJson('/api/auth/discord/complete', [
            'signup_token' => $signupToken,
            'terms' => true,
            'terms_version' => 'September 1, 2026',
            'referral_code' => 'FRIEND1',
        ])
            ->assertCreated()
            ->assertJsonPath('status', 'logged_in')
            ->assertJsonPath('user.status', 'pending')
            ->assertJsonPath('user.has_password', false)
            ->assertJsonPath('user.discord.username', 'pixeltrader');

        $this->assertNotEmpty($res->json('token'));

        $row = DB::table('user_credentials')->where('email', 'trader@example.com')->first();
        $this->assertNotNull($row);
        $this->assertNull($row->password);
        $this->assertSame('Pixel Trader', $row->name);
        $this->assertSame($this->discord->profile['id'], $row->discord_id);
        $this->assertSame('September 1, 2026', $row->terms_version);
        $this->assertNotNull($row->terms_accepted_at);
        $this->assertStringContainsString('cdn.discordapp.com', $row->user_profile);
        $this->assertDatabaseHas('referral_tracking', ['referred_user_uni_id' => $row->uni_id, 'referrer_uni_id' => $referrerId]);

        // Joined the server; pending → no role yet.
        $this->assertCount(1, $this->discord->callsTo('addGuildMember'));
        $this->assertSame([], $this->discord->rolesOf($this->discord->profile['id']));
    }

    public function test_complete_lets_the_user_rename_themselves(): void
    {
        $signupToken = $this->oauthCallback()->json('signup_token');

        $this->postJson('/api/auth/discord/complete', [
            'signup_token' => $signupToken,
            'terms' => true,
            'name' => 'Sam Trader',
        ])->assertCreated()->assertJsonPath('user.name', 'Sam Trader');
    }

    public function test_complete_without_terms_creates_nothing(): void
    {
        $signupToken = $this->oauthCallback()->json('signup_token');

        $this->postJson('/api/auth/discord/complete', [
            'signup_token' => $signupToken,
            'terms' => false,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('user_credentials', ['email' => 'trader@example.com']);
    }

    public function test_signup_token_is_single_use_and_expires(): void
    {
        $signupToken = $this->oauthCallback()->json('signup_token');

        $this->postJson('/api/auth/discord/complete', ['signup_token' => $signupToken, 'terms' => true])
            ->assertCreated();
        $this->postJson('/api/auth/discord/complete', ['signup_token' => $signupToken, 'terms' => true])
            ->assertStatus(410)
            ->assertJsonPath('error_code', 'SIGNUP_EXPIRED');

        $this->discord->profile['id'] = '222222222222222222';
        $this->discord->profile['email'] = 'other@example.com';
        $second = $this->oauthCallback()->json('signup_token');
        $this->travel(16)->minutes();

        $this->postJson('/api/auth/discord/complete', ['signup_token' => $second, 'terms' => true])
            ->assertStatus(410);
    }

    public function test_complete_refuses_when_the_email_got_taken_meanwhile(): void
    {
        $signupToken = $this->oauthCallback()->json('signup_token');
        $this->makeUser(['email' => 'trader@example.com']);

        $this->postJson('/api/auth/discord/complete', ['signup_token' => $signupToken, 'terms' => true])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'EMAIL_TAKEN');
    }

    // ---- callback: Discord refuses ----------------------------------------

    public function test_a_refused_code_is_a_401(): void
    {
        $this->discord->refuseCode = true;

        $this->oauthCallback()->assertStatus(401)->assertJsonPath('error_code', 'DISCORD_DENIED');
    }

    public function test_a_discord_account_without_email_is_refused(): void
    {
        $this->discord->profile['email'] = null;

        $this->oauthCallback()->assertStatus(422)->assertJsonPath('error_code', 'DISCORD_NO_EMAIL');
    }

    public function test_callback_is_a_503_when_the_app_is_not_configured(): void
    {
        config(['services.discord.client_secret' => null]);

        $this->oauthCallback()->assertStatus(503)->assertJsonPath('error_code', 'DISCORD_NOT_CONFIGURED');
    }

    // ---- password login on a Discord-only account ---------------------------

    public function test_password_login_on_a_discord_only_account_says_so(): void
    {
        $this->makeLinkedUser(['email' => 'trader@example.com', 'password' => null]);

        $this->postJson('/api/auth/login', ['email' => 'trader@example.com', 'password' => 'anything'])
            ->assertStatus(401)
            ->assertJsonPath('error_code', 'DISCORD_ONLY');
    }

    public function test_forgot_password_can_give_a_discord_only_account_a_password(): void
    {
        $uniId = $this->makeLinkedUser([
            'email' => 'trader@example.com',
            'password' => null,
            'reset_code' => '123456',
            'reset_code_expires_at' => now()->addMinutes(10),
            'reset_code_attempts' => 0,
        ]);

        $this->postJson('/api/auth/reset-password', [
            'email' => 'trader@example.com',
            'code' => '123456',
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ])->assertOk();

        $this->assertNotNull(DB::table('user_credentials')->where('uni_id', $uniId)->value('password'));
        $this->postJson('/api/auth/login', ['email' => 'trader@example.com', 'password' => 'brand-new-pass'])
            ->assertOk()
            ->assertJsonPath('user.has_password', true);
    }

    // ---- settings: link / unlink / set password -----------------------------

    public function test_signed_in_user_can_link_discord(): void
    {
        $uniId = $this->makeUser(['status' => 'active']);

        $this->postJson('/api/user/discord/link', ['code' => 'c', 'redirect_uri' => self::REDIRECT], $this->userHeaders($uniId))
            ->assertOk()
            ->assertJsonPath('user.discord.id', $this->discord->profile['id']);

        $this->assertSame([self::ROLE_MEMBER], $this->discord->rolesOf($this->discord->profile['id']));
    }

    public function test_link_refuses_a_discord_account_already_on_another_user(): void
    {
        $this->makeLinkedUser();
        $uniId = $this->makeUser();

        $this->postJson('/api/user/discord/link', ['code' => 'c', 'redirect_uri' => self::REDIRECT], $this->userHeaders($uniId))
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'DISCORD_TAKEN');
    }

    public function test_link_refuses_when_already_linked(): void
    {
        $uniId = $this->makeUser(['discord_id' => '777777777777777777']);

        $this->postJson('/api/user/discord/link', ['code' => 'c', 'redirect_uri' => self::REDIRECT], $this->userHeaders($uniId))
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'ALREADY_LINKED');
    }

    public function test_unlink_is_refused_without_a_password(): void
    {
        $uniId = $this->makeLinkedUser(['password' => null]);

        $this->deleteJson('/api/user/discord', [], $this->userHeaders($uniId))
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'NO_PASSWORD');

        $this->assertNotNull(DB::table('user_credentials')->where('uni_id', $uniId)->value('discord_id'));
    }

    public function test_unlink_clears_the_columns_and_the_roles(): void
    {
        $uniId = $this->makeLinkedUser(['status' => 'active']);
        $this->discord->members[$this->discord->profile['id']] = [self::ROLE_MEMBER, self::ROLE_TRADER, 'unmanaged-role'];

        $this->deleteJson('/api/user/discord', [], $this->userHeaders($uniId))
            ->assertOk()
            ->assertJsonPath('user.discord', null);

        $row = DB::table('user_credentials')->where('uni_id', $uniId)->first();
        $this->assertNull($row->discord_id);
        $this->assertNull($row->discord_username);
        $this->assertNull($row->discord_linked_at);
        // Only the managed roles came off.
        $this->assertSame(['unmanaged-role'], $this->discord->rolesOf($this->discord->profile['id']));
    }

    public function test_set_password_works_once_then_defers_to_change_password(): void
    {
        $uniId = $this->makeLinkedUser(['password' => null]);
        $headers = $this->userHeaders($uniId);

        $this->putJson('/api/user/password', [
            'current_password' => 'x',
            'new_password' => 'first-password',
            'new_password_confirmation' => 'first-password',
        ], $headers)->assertStatus(422)->assertJsonPath('error_code', 'NO_PASSWORD');

        $this->postJson('/api/user/password/set', [
            'password' => 'first-password',
            'password_confirmation' => 'first-password',
        ], $headers)->assertOk()->assertJsonPath('user.has_password', true);

        $this->postJson('/api/user/password/set', [
            'password' => 'second-password',
            'password_confirmation' => 'second-password',
        ], $headers)->assertStatus(409)->assertJsonPath('error_code', 'HAS_PASSWORD');

        // Now the account is a normal password account too.
        $email = DB::table('user_credentials')->where('uni_id', $uniId)->value('email');
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'first-password'])->assertOk();
    }

    // ---- invariants -----------------------------------------------------

    public function test_no_secret_ever_appears_in_a_response(): void
    {
        $bodies = [];

        $bodies[] = $this->getJson('/api/auth/discord/config')->getContent();

        $bodies[] = $this->oauthCallback()->getContent();                       // terms_required
        $signupToken = json_decode(end($bodies), true)['signup_token'];
        $bodies[] = $this->postJson('/api/auth/discord/complete', ['signup_token' => $signupToken, 'terms' => true])->getContent();

        $bodies[] = $this->oauthCallback()->getContent();                       // logged_in
        $uniId = UserCredential::where('email', 'trader@example.com')->value('uni_id');
        $bodies[] = $this->getJson('/api/auth/me', $this->userHeaders($uniId))->getContent();

        $this->discord->profile = ['id' => '333', 'username' => 'u2', 'global_name' => null, 'avatar' => null, 'email' => 'trader@example.com', 'verified' => false];
        $bodies[] = $this->oauthCallback()->getContent();                       // password_required

        foreach ($bodies as $body) {
            $this->assertStringNotContainsString(self::CLIENT_SECRET, $body);
            $this->assertStringNotContainsString(self::BOT_TOKEN, $body);
            $this->assertStringNotContainsString($this->discord->accessToken, $body);
        }
    }

    public function test_a_dead_discord_never_fails_login_signup_approval_or_connect(): void
    {
        // Sign-up and login need Discord for the exchange itself, so "dead"
        // there means the join/roles half; the bot half is what dies here.
        $signupToken = $this->oauthCallback()->json('signup_token');
        $this->discord->down = true;
        $this->discord->refuseCode = false;

        // complete(): the row is created and a token issued even though the join fails.
        $res = $this->postJson('/api/auth/discord/complete', ['signup_token' => $signupToken, 'terms' => true])
            ->assertCreated();
        $uniId = $res->json('user.uni_id');
        $this->assertNotEmpty($res->json('token'));

        // Approval: the status flips, the role sync silently does nothing.
        $admin = $this->makeUser(['type' => 'admin']);
        $this->postJson("/api/admin/users/{$uniId}/accept", [], $this->userHeaders($admin))
            ->assertOk()
            ->assertJsonPath('user.status', 'active');

        // Connect: the account row lands.
        $this->postJson('/api/exchange/binance', [
            'name' => 'Main',
            'api_key' => 'k'.str_repeat('1', 20),
            'secret_key' => 's'.str_repeat('1', 20),
            'demo' => false,
        ], $this->userHeaders($uniId))->assertCreated();

        // Link from Settings for another user: the exchange itself is what
        // Discord refuses when it is down, and that IS a failure to report.
        $other = $this->makeUser();
        $this->postJson('/api/user/discord/link', ['code' => 'c', 'redirect_uri' => self::REDIRECT], $this->userHeaders($other))
            ->assertStatus(401)
            ->assertJsonPath('error_code', 'DISCORD_DENIED');
    }
}
