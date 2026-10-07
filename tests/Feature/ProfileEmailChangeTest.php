<?php

namespace Tests\Feature;

use App\Mail\EmailVerificationCode;
use App\Mail\NewRegistrationNotice;
use App\Models\UserCredential;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * PUT /api/user/profile — changing the email is a credential change.
 *
 * The new address needs the current password and then proves itself by the
 * mailed 6-digit code (POST /auth/email/verify); until it does, the row holds
 * it as `pending_email` and the OLD address keeps every login and guarded
 * route. A stolen bearer token alone must never be enough to re-point an
 * account at another inbox — that was the hole: email rewritten in place,
 * `email_verified` untouched, forgot-password to the new address next.
 *
 * Extends DiscordTestCase for userHeaders() and a Discord-only (password =
 * NULL) row; the fake Discord itself is not exercised.
 */
class ProfileEmailChangeTest extends DiscordTestCase
{
    private const OLD = 'owner@test.local';

    private const NEW = 'owner-new@test.local';

    private const PASSWORD = 'secret-password';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        // A configured desk is what makes "no registration notice" a real
        // assertion — with no address the notice is skipped either way.
        config(['mail.admin_address' => 'desk@pixel-alpha.test']);
    }

    /** A verified, active owner on the OLD address; returns [uni_id, bearer headers]. */
    private function owner(array $overrides = []): array
    {
        $uniId = $this->makeUser(array_merge(['name' => 'Owner', 'email' => self::OLD], $overrides));

        return [$uniId, $this->userHeaders($uniId)];
    }

    private function row(string $uniId): object
    {
        return DB::table('user_credentials')->where('uni_id', $uniId)->first();
    }

    private function storedCode(string $uniId): string
    {
        return (string) $this->row($uniId)->verification_code;
    }

    private function wrongCode(string $uniId): string
    {
        return $this->storedCode($uniId) === '000000' ? '111111' : '000000';
    }

    /** The profile body: the OLD email by default, so a test changes exactly what it names. */
    private function profile(array $overrides = []): array
    {
        return array_merge(['name' => 'Owner', 'email' => self::OLD], $overrides);
    }

    /** Request the change to NEW with the right password; returns the response. */
    private function requestChange(array $headers, string $to = self::NEW)
    {
        return $this->putJson('/api/user/profile', $this->profile([
            'email' => $to,
            'current_password' => self::PASSWORD,
        ]), $headers);
    }

    /**
     * Sanctum's RequestGuard caches the user it resolved for the whole test,
     * so a request meant to be judged on ITS OWN token must drop that cache
     * first (see PaymentTestCase::userHeaders).
     */
    private function freshGuard(): void
    {
        $this->app['auth']->forgetGuards();
    }

    // ---- the name alone ----------------------------------------------------

    public function test_a_name_change_needs_no_password_and_sends_nothing(): void
    {
        [$uniId, $headers] = $this->owner();

        $this->putJson('/api/user/profile', $this->profile(['name' => 'Renamed Owner']), $headers)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('user.name', 'Renamed Owner')
            ->assertJsonPath('user.email', self::OLD)
            ->assertJsonPath('user.pending_email', null);

        $this->assertSame('Renamed Owner', $this->row($uniId)->name);
        Mail::assertNothingSent();
    }

    public function test_the_same_address_in_another_case_is_not_a_change(): void
    {
        [$uniId, $headers] = $this->owner();

        $this->putJson('/api/user/profile', $this->profile(['email' => ' OWNER@Test.Local ']), $headers)
            ->assertOk()
            ->assertJsonPath('user.email', self::OLD)
            ->assertJsonPath('user.pending_email', null);

        $this->assertSame(self::OLD, $this->row($uniId)->email);
        Mail::assertNothingSent();
    }

    // ---- the password gate -------------------------------------------------

    public function test_an_email_change_without_the_password_is_refused_and_saves_nothing(): void
    {
        [$uniId, $headers] = $this->owner();

        $this->putJson('/api/user/profile', $this->profile(['name' => 'Sneaky', 'email' => self::NEW]), $headers)
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'PASSWORD_REQUIRED');

        $row = $this->row($uniId);
        $this->assertSame(self::OLD, $row->email);
        $this->assertNull($row->pending_email);
        // The whole request is refused — the name does not slip through.
        $this->assertSame('Owner', $row->name);
        Mail::assertNothingSent();
    }

    public function test_a_wrong_password_is_refused(): void
    {
        [$uniId, $headers] = $this->owner();

        $this->putJson('/api/user/profile', $this->profile([
            'email' => self::NEW,
            'current_password' => 'not-the-password',
        ]), $headers)
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'INVALID_PASSWORD');

        $this->assertSame(self::OLD, $this->row($uniId)->email);
        $this->assertNull($this->row($uniId)->pending_email);
        Mail::assertNothingSent();
    }

    public function test_a_discord_only_account_must_set_a_password_first(): void
    {
        [$uniId, $headers] = $this->owner(['password' => null, 'discord_id' => '123456789012345678']);

        // With or without a guess — there is nothing to compare it against.
        $this->putJson('/api/user/profile', $this->profile(['email' => self::NEW]), $headers)
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'NO_PASSWORD');
        $this->putJson('/api/user/profile', $this->profile(['email' => self::NEW, 'current_password' => 'anything']), $headers)
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'NO_PASSWORD');

        $this->assertSame(self::OLD, $this->row($uniId)->email);
        $this->assertNull($this->row($uniId)->pending_email);
        Mail::assertNothingSent();
    }

    public function test_an_address_another_account_already_holds_is_a_validation_error(): void
    {
        $this->makeUser(['email' => self::NEW]);
        [$uniId, $headers] = $this->owner();

        $this->requestChange($headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        $this->assertNull($this->row($uniId)->pending_email);
        Mail::assertNothingSent();
    }

    // ---- the pending address -----------------------------------------------

    public function test_the_right_password_parks_the_address_and_mails_the_new_inbox_only(): void
    {
        [$uniId, $headers] = $this->owner();

        $this->requestChange($headers)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'We sent a code to '.self::NEW.'. Your email changes once you enter it.')
            ->assertJsonPath('user.email', self::OLD)
            ->assertJsonPath('user.pending_email', self::NEW)
            ->assertJsonPath('user.email_verified', true);

        $row = $this->row($uniId);
        $this->assertSame(self::OLD, $row->email);
        $this->assertSame(self::NEW, $row->pending_email);
        $this->assertSame(1, (int) $row->email_verified);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $row->verification_code);
        $this->assertSame(0, (int) $row->verification_code_attempts);

        Mail::assertSent(EmailVerificationCode::class, 1);
        Mail::assertSent(EmailVerificationCode::class, fn (EmailVerificationCode $m) => $m->hasTo(self::NEW)
            && $m->code === $row->verification_code);
        Mail::assertNotSent(EmailVerificationCode::class, fn (EmailVerificationCode $m) => $m->hasTo(self::OLD));
        Mail::assertNotSent(NewRegistrationNotice::class);

        // /auth/me carries the change, and the OLD address keeps the dashboard.
        $this->getJson('/api/auth/me', $headers)
            ->assertOk()
            ->assertJsonPath('user.email', self::OLD)
            ->assertJsonPath('user.pending_email', self::NEW);
        $this->getJson('/api/dashboard/summary', $headers)->assertOk();
    }

    public function test_a_second_change_inside_the_send_cooldown_is_refused(): void
    {
        [$uniId, $headers] = $this->owner();
        $this->requestChange($headers)->assertOk();

        $this->travel(20)->seconds();

        $res = $this->requestChange($headers, 'third@test.local')
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'RESEND_TOO_SOON')
            ->assertJsonPath('code', 'RESEND_TOO_SOON');
        $this->assertGreaterThanOrEqual(39, $res->json('retry_after'));

        // Still the first address, still one mail.
        $this->assertSame(self::NEW, $this->row($uniId)->pending_email);
        Mail::assertSent(EmailVerificationCode::class, 1);

        $this->travel(41)->seconds();
        $this->requestChange($headers, 'third@test.local')->assertOk();
        $this->assertSame('third@test.local', $this->row($uniId)->pending_email);
        Mail::assertSent(EmailVerificationCode::class, fn (EmailVerificationCode $m) => $m->hasTo('third@test.local'));
    }

    public function test_submitting_the_live_address_again_drops_a_pending_change(): void
    {
        [$uniId, $headers] = $this->owner();
        $this->requestChange($headers)->assertOk();

        $this->putJson('/api/user/profile', $this->profile(['name' => 'Renamed']), $headers)
            ->assertOk()
            ->assertJsonPath('user.name', 'Renamed')
            ->assertJsonPath('user.pending_email', null);

        $row = $this->row($uniId);
        $this->assertNull($row->pending_email);
        $this->assertNull($row->verification_code);
        $this->assertSame(self::OLD, $row->email);
    }

    public function test_cancelling_clears_the_address_and_kills_the_code(): void
    {
        [$uniId, $headers] = $this->owner();
        $this->requestChange($headers)->assertOk();
        $code = $this->storedCode($uniId);

        $this->deleteJson('/api/user/email/pending', [], $headers)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('user.email', self::OLD)
            ->assertJsonPath('user.pending_email', null);

        $row = $this->row($uniId);
        $this->assertNull($row->pending_email);
        $this->assertNull($row->verification_code);
        $this->assertNull($row->verification_code_expires_at);

        // The code it mailed proves nothing any more: the row is simply verified.
        $this->postJson('/api/auth/email/verify', ['code' => $code], $headers)
            ->assertOk()
            ->assertJsonPath('message', 'Email already verified.')
            ->assertJsonPath('user.email', self::OLD);

        // Cancelling with nothing in flight is a harmless 200.
        $this->deleteJson('/api/user/email/pending', [], $headers)->assertOk();
    }

    public function test_resend_mails_the_pending_address(): void
    {
        [$uniId, $headers] = $this->owner();
        $this->requestChange($headers)->assertOk();
        $first = $this->storedCode($uniId);

        $this->travel(61)->seconds();

        $this->postJson('/api/auth/email/resend', [], $headers)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('retry_after', 60);

        $second = $this->storedCode($uniId);
        Mail::assertSent(EmailVerificationCode::class, 2);
        Mail::assertSent(EmailVerificationCode::class, fn (EmailVerificationCode $m) => $m->hasTo(self::NEW) && $m->code === $second);
        Mail::assertNotSent(EmailVerificationCode::class, fn (EmailVerificationCode $m) => $m->hasTo(self::OLD));

        // The first code is replaced, not kept alongside.
        if ($first !== $second) {
            $this->postJson('/api/auth/email/verify', ['code' => $first], $headers)
                ->assertStatus(422)
                ->assertJsonPath('error_code', 'INVALID_CODE');
        }
    }

    // ---- proving it --------------------------------------------------------

    public function test_the_code_moves_the_email_keeps_this_session_and_revokes_the_others(): void
    {
        [$uniId, $headers] = $this->owner();
        $otherDevice = $this->userHeaders($uniId);
        $this->assertSame(2, UserCredential::find($uniId)->tokens()->count());

        $this->freshGuard();
        $this->requestChange($headers)->assertOk();

        $this->postJson('/api/auth/email/verify', ['code' => $this->storedCode($uniId)], $headers)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('user.email', self::NEW)
            ->assertJsonPath('user.pending_email', null)
            ->assertJsonPath('user.email_verified', true);

        $row = $this->row($uniId);
        $this->assertSame(self::NEW, $row->email);
        $this->assertNull($row->pending_email);
        $this->assertSame(1, (int) $row->email_verified);
        $this->assertNull($row->verification_code);
        $this->assertNull($row->verification_code_expires_at);
        $this->assertSame(0, (int) $row->verification_code_attempts);

        // An email change is not a registration: the desk hears nothing.
        Mail::assertNotSent(NewRegistrationNotice::class);

        // The session that typed the code survives; every other one is gone.
        $this->assertSame(1, UserCredential::find($uniId)->tokens()->count());
        $this->freshGuard();
        $this->getJson('/api/auth/me', $otherDevice)->assertUnauthorized();
        $this->freshGuard();
        $this->getJson('/api/auth/me', $headers)->assertOk()->assertJsonPath('user.email', self::NEW);

        // And the live address is the new one, end to end.
        $this->postJson('/api/auth/login', ['email' => self::NEW, 'password' => self::PASSWORD])->assertOk();
        $this->postJson('/api/auth/login', ['email' => self::OLD, 'password' => self::PASSWORD])->assertStatus(401);
    }

    public function test_a_wrong_code_counts_an_attempt_and_changes_nothing(): void
    {
        [$uniId, $headers] = $this->owner();
        $this->requestChange($headers)->assertOk();

        $this->postJson('/api/auth/email/verify', ['code' => $this->wrongCode($uniId)], $headers)
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'INVALID_CODE')
            ->assertJsonPath('code', 'INVALID_CODE')
            ->assertJsonPath('attempts_left', 4)
            ->assertJsonPath('expired', false);

        $row = $this->row($uniId);
        $this->assertSame(1, (int) $row->verification_code_attempts);
        $this->assertSame(self::OLD, $row->email);
        $this->assertSame(self::NEW, $row->pending_email);
        $this->assertSame(1, (int) $row->email_verified);

        // The owner is not locked out while a guess is wrong.
        $this->getJson('/api/dashboard/summary', $headers)->assertOk();
    }

    public function test_an_address_registered_meanwhile_is_refused_at_verify_and_the_change_dropped(): void
    {
        [$uniId, $headers] = $this->owner();
        $this->requestChange($headers)->assertOk();

        // Someone else registers the address between the request and the code.
        $this->makeUser(['email' => self::NEW]);

        $this->postJson('/api/auth/email/verify', ['code' => $this->storedCode($uniId)], $headers)
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'EMAIL_TAKEN')
            ->assertJsonPath('code', 'EMAIL_TAKEN')
            ->assertJsonPath('user.email', self::OLD)
            ->assertJsonPath('user.pending_email', null);

        $row = $this->row($uniId);
        $this->assertSame(self::OLD, $row->email);
        $this->assertNull($row->pending_email);
        $this->assertNull($row->verification_code);
        $this->assertSame(1, (int) $row->email_verified);

        // Nothing was revoked: the change did not happen.
        $this->freshGuard();
        $this->getJson('/api/auth/me', $headers)->assertOk()->assertJsonPath('user.email', self::OLD);
    }

    public function test_the_pending_route_needs_a_session(): void
    {
        $this->deleteJson('/api/user/email/pending')->assertUnauthorized();
    }
}
