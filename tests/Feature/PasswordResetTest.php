<?php

namespace Tests\Feature;

use App\Http\Controllers\PasswordResetController;
use App\Mail\PasswordResetCode;
use App\Models\UserCredential;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

/**
 * POST /api/auth/forgot-password + /reset-password.
 *
 * The code is six digits stored in clear, so everything that makes that safe
 * is asserted here: the expiry, the attempt cap, the single-use, that a
 * reset ends every session the account had — and the per-ACCOUNT rules that
 * stop a proxy pool from buying more guesses: the resend cooldown, the
 * window (count AND expiry) inherited across a reissue, and the per-email
 * throttles.
 */
class PasswordResetTest extends EngineTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function requestCode(string $email): string
    {
        $this->postJson('/api/auth/forgot-password', ['email' => $email])->assertOk();

        return $this->row($email)->reset_code;
    }

    private function row(string $email): object
    {
        return DB::table('user_credentials')->where('email', $email)->first();
    }

    private function attempts(string $email): int
    {
        return (int) $this->row($email)->reset_code_attempts;
    }

    private function wrongFor(string $code): string
    {
        return $code === '000000' ? '000001' : '000000';
    }

    private function attempt(string $email, string $code, ?string $ip = null): TestResponse
    {
        if ($ip !== null) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip]);
        }

        return $this->postJson('/api/auth/reset-password', [
            'email' => $email,
            'code' => $code,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ]);
    }

    private function forgotFrom(string $ip, string $email): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/auth/forgot-password', ['email' => $email]);
    }

    public function test_forgot_emails_a_six_digit_code_and_stores_it_with_an_expiry(): void
    {
        $this->makeUser(['email' => 'trader@test.local']);

        $code = $this->requestCode('trader@test.local');

        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);

        Mail::assertSent(PasswordResetCode::class, function (PasswordResetCode $mail) use ($code) {
            return $mail->code === $code && $mail->hasTo('trader@test.local');
        });

        $row = $this->row('trader@test.local');
        $this->assertNotNull($row->reset_code_expires_at);
        $this->assertNotNull($row->reset_code_sent_at);
        $this->assertSame(0, (int) $row->reset_code_attempts);
    }

    public function test_forgot_answers_the_same_for_an_unknown_email_and_sends_nothing(): void
    {
        $this->makeUser(['email' => 'trader@test.local']);

        $known = $this->postJson('/api/auth/forgot-password', ['email' => 'trader@test.local'])->assertOk();
        $unknown = $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@test.local'])->assertOk();

        $this->assertSame($known->json(), $unknown->json());
        Mail::assertSent(PasswordResetCode::class, 1);
    }

    public function test_the_right_code_changes_the_password_and_revokes_every_token(): void
    {
        $uniId = $this->makeUser(['email' => 'trader@test.local']);
        UserCredential::find($uniId)->createToken('spa');
        UserCredential::find($uniId)->createToken('spa');

        $code = $this->requestCode('trader@test.local');

        $this->attempt('trader@test.local', $code)->assertOk()->assertJsonPath('success', true);

        $user = UserCredential::find($uniId);
        $this->assertTrue(Hash::check('brand-new-password', $user->password));
        $this->assertNull($user->reset_code);
        $this->assertNull($user->reset_code_expires_at);
        $this->assertSame(0, $user->tokens()->count());

        // Single use: the same code is dead after it worked.
        $this->postJson('/api/auth/reset-password', [
            'email' => 'trader@test.local',
            'code' => $code,
            'password' => 'another-password-1',
            'password_confirmation' => 'another-password-1',
        ])->assertUnprocessable()->assertJsonPath('error_code', 'INVALID_CODE');
    }

    public function test_a_wrong_code_counts_an_attempt_and_the_cap_voids_the_code(): void
    {
        $this->makeUser(['email' => 'trader@test.local']);
        $code = $this->requestCode('trader@test.local');
        $wrong = $this->wrongFor($code);

        for ($i = 1; $i <= PasswordResetController::MAX_ATTEMPTS; $i++) {
            $this->attempt('trader@test.local', $wrong)->assertUnprocessable();
            $this->assertSame($i, $this->attempts('trader@test.local'));
        }

        // The right code no longer works once the attempts are spent.
        $this->attempt('trader@test.local', $code)->assertUnprocessable()->assertJsonPath('error_code', 'INVALID_CODE');
        $this->assertFalse(Hash::check('brand-new-password',
            UserCredential::where('email', 'trader@test.local')->first()->password));
    }

    public function test_an_expired_code_is_refused(): void
    {
        $this->makeUser(['email' => 'trader@test.local']);
        $code = $this->requestCode('trader@test.local');

        $this->travel(PasswordResetController::CODE_TTL_MINUTES + 1)->minutes();

        $this->attempt('trader@test.local', $code)->assertUnprocessable()->assertJsonPath('error_code', 'INVALID_CODE');
    }

    // ---- forgot: the per-account rules ----------------------------------------

    public function test_a_second_forgot_inside_the_cooldown_answers_the_same_and_sends_nothing(): void
    {
        $this->makeUser(['email' => 'trader@test.local']);

        $first = $this->postJson('/api/auth/forgot-password', ['email' => 'trader@test.local'])->assertOk();
        $before = $this->row('trader@test.local');

        $this->travel(PasswordResetController::RESEND_COOLDOWN_SECONDS - 1)->seconds();
        $second = $this->postJson('/api/auth/forgot-password', ['email' => 'trader@test.local'])->assertOk();

        // The body is identical: the cooldown must not be readable from it,
        // or it would say "this address has an account that asked recently".
        $this->assertSame($first->json(), $second->json());

        // Nothing moved — same code, same expiry, same send time — and no
        // second mail.
        $after = $this->row('trader@test.local');
        $this->assertSame($before->reset_code, $after->reset_code);
        $this->assertSame($before->reset_code_expires_at, $after->reset_code_expires_at);
        $this->assertSame($before->reset_code_sent_at, $after->reset_code_sent_at);
        Mail::assertSent(PasswordResetCode::class, 1);
    }

    public function test_the_cooldown_follows_the_last_send_not_the_window(): void
    {
        $this->makeUser(['email' => 'trader@test.local']);
        $this->requestCode('trader@test.local');

        // A reissue inside the window: the expiry stays, the send time moves.
        $this->travel(PasswordResetController::RESEND_COOLDOWN_SECONDS + 1)->seconds();
        $this->requestCode('trader@test.local');
        $reissued = $this->row('trader@test.local');
        Mail::assertSent(PasswordResetCode::class, 2);

        // Derived from the (unmoved) expiry this would read as "cooldown long
        // past" and mail a third time; counted from the last send it holds.
        $this->postJson('/api/auth/forgot-password', ['email' => 'trader@test.local'])->assertOk();
        $this->assertSame($reissued->reset_code_sent_at, $this->row('trader@test.local')->reset_code_sent_at);
        Mail::assertSent(PasswordResetCode::class, 2);

        $this->travel(PasswordResetController::RESEND_COOLDOWN_SECONDS + 1)->seconds();
        $this->postJson('/api/auth/forgot-password', ['email' => 'trader@test.local'])->assertOk();
        Mail::assertSent(PasswordResetCode::class, 3);
    }

    public function test_a_reissue_inside_the_ttl_keeps_the_guesses_already_spent(): void
    {
        $this->makeUser(['email' => 'trader@test.local']);
        $first = $this->requestCode('trader@test.local');
        $originalExpiry = $this->row('trader@test.local')->reset_code_expires_at;

        for ($i = 1; $i <= 3; $i++) {
            $this->attempt('trader@test.local', $this->wrongFor($first))->assertUnprocessable();
        }
        $this->assertSame(3, $this->attempts('trader@test.local'));

        // Past the cooldown, inside the TTL: a new code is mailed...
        $this->travel(PasswordResetController::RESEND_COOLDOWN_SECONDS + 1)->seconds();
        $second = $this->requestCode('trader@test.local');
        Mail::assertSent(PasswordResetCode::class, 2);

        // ...but it inherits the window: the three guesses are still spent
        // and the expiry has not moved.
        $row = $this->row('trader@test.local');
        $this->assertSame(3, (int) $row->reset_code_attempts);
        $this->assertSame($originalExpiry, $row->reset_code_expires_at);

        // Only two remain on the new code; once they are gone the right code
        // is refused like any other.
        $this->attempt('trader@test.local', $this->wrongFor($second))->assertUnprocessable();
        $this->attempt('trader@test.local', $this->wrongFor($second))->assertUnprocessable();
        $this->assertSame(PasswordResetController::MAX_ATTEMPTS, $this->attempts('trader@test.local'));

        $this->attempt('trader@test.local', $second)->assertUnprocessable()->assertJsonPath('error_code', 'INVALID_CODE');
        $this->assertFalse(Hash::check('brand-new-password',
            UserCredential::where('email', 'trader@test.local')->first()->password));
    }

    public function test_a_reissue_after_the_ttl_starts_the_count_afresh(): void
    {
        $this->makeUser(['email' => 'trader@test.local']);
        $first = $this->requestCode('trader@test.local');

        for ($i = 1; $i <= 3; $i++) {
            $this->attempt('trader@test.local', $this->wrongFor($first))->assertUnprocessable();
        }
        $this->assertSame(3, $this->attempts('trader@test.local'));

        $this->travel(PasswordResetController::CODE_TTL_MINUTES + 1)->minutes();
        $second = $this->requestCode('trader@test.local');

        // The old code's guesses died with it.
        $this->assertSame(0, $this->attempts('trader@test.local'));
        $this->attempt('trader@test.local', $second)->assertOk();
    }

    public function test_an_attacker_cannot_keep_an_account_locked_by_reissuing(): void
    {
        $this->makeUser(['email' => 'trader@test.local']);
        $code = $this->requestCode('trader@test.local');
        $originalExpiry = $this->row('trader@test.local')->reset_code_expires_at;

        // Spend the window's five guesses.
        for ($i = 1; $i <= PasswordResetController::MAX_ATTEMPTS; $i++) {
            $this->attempt('trader@test.local', $this->wrongFor($code))->assertUnprocessable();
        }
        $this->attempt('trader@test.local', $code)->assertUnprocessable();

        // Reissuing every 61 s hands out new codes, but each inherits the
        // spent window — unusable, uncountable — and the window does NOT
        // restart under the attacker.
        for ($round = 1; $round <= 3; $round++) {
            $this->travel(PasswordResetController::RESEND_COOLDOWN_SECONDS + 1)->seconds();
            $reissued = $this->requestCode('trader@test.local');

            $row = $this->row('trader@test.local');
            $this->assertSame($originalExpiry, $row->reset_code_expires_at);
            $this->assertSame(PasswordResetController::MAX_ATTEMPTS, (int) $row->reset_code_attempts);

            $this->attempt('trader@test.local', $reissued)
                ->assertUnprocessable()->assertJsonPath('error_code', 'INVALID_CODE');
            $this->attempt('trader@test.local', $this->wrongFor($reissued))->assertUnprocessable();
            $this->assertSame(PasswordResetController::MAX_ATTEMPTS, $this->attempts('trader@test.local'));
        }

        // Once the ORIGINAL window has run out, the next request is a fresh
        // start and the owner is back in.
        $this->travel(PasswordResetController::CODE_TTL_MINUTES)->minutes();
        $fresh = $this->requestCode('trader@test.local');

        $row = $this->row('trader@test.local');
        $this->assertNotSame($originalExpiry, $row->reset_code_expires_at);
        $this->assertSame(0, (int) $row->reset_code_attempts);

        $this->attempt('trader@test.local', $fresh)->assertOk();
        Mail::assertSent(PasswordResetCode::class, 5);
    }

    // ---- the throttles ---------------------------------------------------------

    public function test_forgot_for_one_email_is_limited_across_ips(): void
    {
        $this->makeUser(['email' => 'trader@test.local']);

        $this->forgotFrom('10.0.0.1', 'trader@test.local')->assertOk();
        $this->forgotFrom('10.0.0.2', 'trader@test.local')->assertOk();
        $this->forgotFrom('10.0.0.3', 'trader@test.local')->assertStatus(429);

        // The limit keys on the address TYPED, not on whether it has a row:
        // an unknown email is throttled identically, so the 429 itself says
        // nothing about the account.
        $this->forgotFrom('10.0.0.4', 'nobody@test.local')->assertOk();
        $this->forgotFrom('10.0.0.5', 'nobody@test.local')->assertOk();
        $this->forgotFrom('10.0.0.6', 'nobody@test.local')->assertStatus(429);

        // Case and whitespace do not open a second bucket.
        $this->forgotFrom('10.0.0.7', ' Trader@Test.local ')->assertStatus(429);

        // Of the two accepted calls only the first mailed (the second sat in
        // the cooldown).
        Mail::assertSent(PasswordResetCode::class, 1);
    }

    public function test_forgot_is_limited_per_ip(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->postJson('/api/auth/forgot-password', ['email' => "nobody{$i}@test.local"])->assertOk();
        }

        $this->postJson('/api/auth/forgot-password', ['email' => 'nobody4@test.local'])->assertStatus(429);
    }

    public function test_reset_for_one_email_is_limited_across_ips(): void
    {
        $this->makeUser(['email' => 'trader@test.local']);
        $code = $this->requestCode('trader@test.local');

        // The attempt cap (5) is reached first and voids the code — that is
        // the ordering the route comment promises; the throttle is the
        // backstop that stops the hammering itself.
        for ($i = 1; $i <= 10; $i++) {
            $this->attempt('trader@test.local', $this->wrongFor($code), "10.0.1.{$i}")
                ->assertUnprocessable()
                ->assertJsonPath('error_code', 'INVALID_CODE');
        }
        $this->assertSame(PasswordResetController::MAX_ATTEMPTS, $this->attempts('trader@test.local'));

        $this->attempt('trader@test.local', $this->wrongFor($code), '10.0.1.11')->assertStatus(429);
    }
}
