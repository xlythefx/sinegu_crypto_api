<?php

namespace Tests\Feature;

use App\Mail\EmailVerificationCode;
use App\Mail\NewRegistrationNotice;
use App\Services\Auth\EmailVerification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Sign-up email verification: register creates the row UNVERIFIED and mails a
 * 6-digit code; POST /auth/email/verify accepts it; every other signed-in
 * route answers 403 EMAIL_UNVERIFIED until then; and the team's "someone
 * registered" notice and the approval queue wait for it.
 *
 * Extends DiscordTestCase for the in-memory Discord (the Discord sign-up
 * cases); its makeUser() is EngineTestCase's, verified by default.
 */
class EmailVerificationTest extends DiscordTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['mail.admin_address' => 'desk@pixel-alpha.test']);
    }

    /** Register through the API; returns [uni_id, bearer headers]. */
    private function register(string $email = 'newbie@example.test'): array
    {
        $res = $this->postJson('/api/auth/register', [
            'name' => 'New Bie',
            'email' => $email,
            'password' => 'super-secret-1',
            'password_confirmation' => 'super-secret-1',
            'terms' => true,
        ])->assertCreated();

        return [$res->json('user.uni_id'), ['Authorization' => 'Bearer '.$res->json('token')]];
    }

    private function storedCode(string $uniId): string
    {
        return (string) DB::table('user_credentials')->where('uni_id', $uniId)->value('verification_code');
    }

    private function wrongCode(string $uniId): string
    {
        return $this->storedCode($uniId) === '000000' ? '111111' : '000000';
    }

    // ---- register -----------------------------------------------------------

    public function test_register_leaves_the_user_unverified_mails_a_code_and_tells_nobody_else(): void
    {
        $res = $this->postJson('/api/auth/register', [
            'name' => 'New Bie',
            'email' => 'newbie@example.test',
            'password' => 'super-secret-1',
            'password_confirmation' => 'super-secret-1',
            'terms' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('user.email_verified', false);

        // The session is still issued: the code screen needs to know who is typing.
        $this->assertNotEmpty($res->json('token'));

        $row = DB::table('user_credentials')->where('email', 'newbie@example.test')->first();
        $this->assertSame(0, (int) $row->email_verified);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $row->verification_code);
        $this->assertSame(0, (int) $row->verification_code_attempts);
        $this->assertNotNull($row->verification_code_expires_at);

        Mail::assertSent(EmailVerificationCode::class, fn (EmailVerificationCode $m) => $m->hasTo('newbie@example.test')
            && $m->code === $row->verification_code
            && $m->ttlMinutes === EmailVerification::CODE_TTL_MINUTES);
        Mail::assertNotSent(NewRegistrationNotice::class);

        // The code is never echoed back to the client.
        $this->assertStringNotContainsString($row->verification_code, $res->getContent());
    }

    // ---- verify -------------------------------------------------------------

    public function test_the_correct_code_verifies_clears_the_code_and_notifies_the_team(): void
    {
        [$uniId, $headers] = $this->register();

        $this->postJson('/api/auth/email/verify', ['code' => $this->storedCode($uniId)], $headers)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('user.uni_id', $uniId)
            ->assertJsonPath('user.email_verified', true);

        $row = DB::table('user_credentials')->where('uni_id', $uniId)->first();
        $this->assertSame(1, (int) $row->email_verified);
        $this->assertNull($row->verification_code);
        $this->assertNull($row->verification_code_expires_at);

        Mail::assertSent(NewRegistrationNotice::class, fn (NewRegistrationNotice $m) => $m->hasTo('desk@pixel-alpha.test')
            && $m->uniId === $uniId
            && $m->via === 'Email + password');
    }

    public function test_verifying_an_already_verified_user_is_an_idempotent_200(): void
    {
        $uniId = $this->makeUser();

        $this->postJson('/api/auth/email/verify', ['code' => '123456'], $this->userHeaders($uniId))
            ->assertOk()
            ->assertJsonPath('user.email_verified', true);

        Mail::assertNotSent(NewRegistrationNotice::class);
    }

    public function test_a_wrong_code_counts_an_attempt_and_five_lock_the_code(): void
    {
        [$uniId, $headers] = $this->register();
        $wrong = $this->wrongCode($uniId);

        $this->postJson('/api/auth/email/verify', ['code' => $wrong], $headers)
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'INVALID_CODE')
            ->assertJsonPath('code', 'INVALID_CODE')
            ->assertJsonPath('attempts_left', 4)
            ->assertJsonPath('expired', false);

        for ($i = 3; $i >= 0; $i--) {
            $this->postJson('/api/auth/email/verify', ['code' => $wrong], $headers)
                ->assertStatus(422)
                ->assertJsonPath('attempts_left', $i);
        }

        // Locked: even the right code is refused now, and is not counted further.
        $this->postJson('/api/auth/email/verify', ['code' => $this->storedCode($uniId)], $headers)
            ->assertStatus(422)
            ->assertJsonPath('attempts_left', 0)
            ->assertJsonPath('expired', false);

        $this->assertSame(5, (int) DB::table('user_credentials')->where('uni_id', $uniId)->value('verification_code_attempts'));
        $this->assertSame(0, (int) DB::table('user_credentials')->where('uni_id', $uniId)->value('email_verified'));

        // Only a resend unlocks it.
        $this->travel(61)->seconds();
        $this->postJson('/api/auth/email/resend', [], $headers)->assertOk();

        $this->postJson('/api/auth/email/verify', ['code' => $this->storedCode($uniId)], $headers)
            ->assertOk()
            ->assertJsonPath('user.email_verified', true);
    }

    public function test_an_expired_code_is_refused_and_says_so(): void
    {
        [$uniId, $headers] = $this->register();
        $code = $this->storedCode($uniId);

        $this->travel(EmailVerification::CODE_TTL_MINUTES)->minutes();
        $this->travel(1)->seconds();

        $this->postJson('/api/auth/email/verify', ['code' => $code], $headers)
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'INVALID_CODE')
            ->assertJsonPath('expired', true)
            ->assertJsonPath('attempts_left', 0);

        $this->assertSame(0, (int) DB::table('user_credentials')->where('uni_id', $uniId)->value('email_verified'));
    }

    public function test_a_malformed_code_is_a_validation_error(): void
    {
        [, $headers] = $this->register();

        $this->postJson('/api/auth/email/verify', ['code' => '12ab'], $headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }

    public function test_verify_and_resend_require_a_session(): void
    {
        $this->postJson('/api/auth/email/verify', ['code' => '123456'])->assertUnauthorized();
        $this->postJson('/api/auth/email/resend')->assertUnauthorized();
    }

    // ---- resend -------------------------------------------------------------

    public function test_resend_inside_the_cooldown_answers_409_with_the_wait(): void
    {
        [, $headers] = $this->register();

        $this->travel(20)->seconds();

        $res = $this->postJson('/api/auth/email/resend', [], $headers)
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'RESEND_TOO_SOON')
            ->assertJsonPath('code', 'RESEND_TOO_SOON');

        $wait = $res->json('retry_after');
        $this->assertIsInt($wait);
        $this->assertGreaterThanOrEqual(39, $wait);
        $this->assertLessThanOrEqual(40, $wait);

        Mail::assertSent(EmailVerificationCode::class, 1);
    }

    public function test_resend_after_the_cooldown_issues_a_fresh_code_and_resets_attempts(): void
    {
        [$uniId, $headers] = $this->register();
        $this->postJson('/api/auth/email/verify', ['code' => $this->wrongCode($uniId)], $headers)->assertStatus(422);
        $this->postJson('/api/auth/email/verify', ['code' => $this->wrongCode($uniId)], $headers)->assertStatus(422);

        $this->travel(61)->seconds();

        $this->postJson('/api/auth/email/resend', [], $headers)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('retry_after', 60);

        $this->assertSame(0, (int) DB::table('user_credentials')->where('uni_id', $uniId)->value('verification_code_attempts'));
        Mail::assertSent(EmailVerificationCode::class, 2);
    }

    public function test_resend_for_an_already_verified_user_sends_nothing(): void
    {
        $uniId = $this->makeUser();

        $this->postJson('/api/auth/email/resend', [], $this->userHeaders($uniId))
            ->assertOk()
            ->assertJsonPath('user.email_verified', true);

        Mail::assertNothingSent();
    }

    // ---- enforcement --------------------------------------------------------

    public function test_guarded_routes_answer_403_email_unverified_but_me_and_logout_stay_open(): void
    {
        [, $headers] = $this->register();

        $this->getJson('/api/dashboard/summary', $headers)
            ->assertForbidden()
            ->assertJsonPath('error_code', 'EMAIL_UNVERIFIED')
            ->assertJsonPath('code', 'EMAIL_UNVERIFIED');
        $this->getJson('/api/exchange/accounts', $headers)
            ->assertForbidden()
            ->assertJsonPath('error_code', 'EMAIL_UNVERIFIED');

        $this->getJson('/api/auth/me', $headers)
            ->assertOk()
            ->assertJsonPath('user.email_verified', false);

        $this->postJson('/api/auth/logout', [], $headers)->assertOk();
    }

    public function test_an_unverified_admin_is_refused_too(): void
    {
        $admin = $this->makeUser(['type' => 'admin', 'email_verified' => 0]);

        $this->getJson('/api/admin/users', $this->userHeaders($admin))
            ->assertForbidden()
            ->assertJsonPath('error_code', 'EMAIL_UNVERIFIED');
    }

    public function test_verifying_opens_the_guarded_routes(): void
    {
        [$uniId, $headers] = $this->register();

        $this->postJson('/api/auth/email/verify', ['code' => $this->storedCode($uniId)], $headers)->assertOk();

        $this->getJson('/api/dashboard/summary', $headers)->assertOk();
    }

    // ---- Discord sign-ups ---------------------------------------------------

    public function test_a_discord_verified_signup_skips_the_code(): void
    {
        $signupToken = $this->oauthCallback()->json('signup_token');

        $this->postJson('/api/auth/discord/complete', ['signup_token' => $signupToken, 'terms' => true])
            ->assertCreated()
            ->assertJsonPath('user.email_verified', true);

        Mail::assertNotSent(EmailVerificationCode::class);
        Mail::assertSent(NewRegistrationNotice::class, fn (NewRegistrationNotice $m) => $m->via === 'Discord');
    }

    public function test_a_discord_unverified_signup_gets_a_code_and_the_notice_waits(): void
    {
        $this->discord->profile['verified'] = false;
        $signupToken = $this->oauthCallback()->json('signup_token');

        $res = $this->postJson('/api/auth/discord/complete', ['signup_token' => $signupToken, 'terms' => true])
            ->assertCreated()
            ->assertJsonPath('user.email_verified', false);

        $uniId = $res->json('user.uni_id');
        Mail::assertSent(EmailVerificationCode::class, fn (EmailVerificationCode $m) => $m->hasTo('trader@example.com'));
        Mail::assertNotSent(NewRegistrationNotice::class);

        $this->postJson('/api/auth/email/verify', ['code' => $this->storedCode($uniId)], ['Authorization' => 'Bearer '.$res->json('token')])
            ->assertOk();

        // The deferred notice names the right path in.
        Mail::assertSent(NewRegistrationNotice::class, fn (NewRegistrationNotice $m) => $m->via === 'Discord');
    }

    // ---- approval queue -----------------------------------------------------

    public function test_the_pending_queue_excludes_unverified_signups(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);
        $verified = $this->makeUser(['status' => 'pending', 'name' => 'Verified Pending']);
        $unverified = $this->makeUser(['status' => 'pending', 'name' => 'Unverified Pending', 'email_verified' => 0]);
        Cache::flush();

        $res = $this->getJson('/api/admin/insights/overview', $this->userHeaders($admin))->assertOk();

        $ids = collect($res->json('attention.pending_users'))->pluck('uni_id')->all();
        $this->assertContains($verified, $ids);
        $this->assertNotContains($unverified, $ids);
        $this->assertSame(1, $res->json('attention.pending_users_count'));

        // The full user list keeps them (staff can still find the row), flagged.
        $users = collect($this->getJson('/api/admin/users', $this->userHeaders($admin))->assertOk()->json('users'))
            ->keyBy('uni_id');
        $this->assertTrue($users->has($unverified));
        $this->assertFalse($users[$unverified]['email_verified']);
        $this->assertTrue($users[$verified]['email_verified']);
    }

    // ---- catalogue ----------------------------------------------------------

    public function test_the_code_email_is_in_the_catalogue_and_renders(): void
    {
        $entry = collect(\App\Services\Notifications\EmailCatalogue::all())->firstWhere('slug', 'email-verification-code');

        $this->assertNotNull($entry);
        $this->assertTrue($entry['live']);
        $this->assertSame('customer', $entry['audience']);
        $this->assertStringContainsString('408217', $entry['mail']->render());
    }
}
