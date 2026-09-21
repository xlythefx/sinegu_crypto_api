<?php

namespace Tests\Feature;

use App\Http\Controllers\PasswordResetController;
use App\Mail\PasswordResetCode;
use App\Models\UserCredential;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * POST /api/auth/forgot-password + /reset-password.
 *
 * The code is six digits stored in clear, so everything that makes that safe
 * is asserted here: the expiry, the attempt cap, the single-use, and that a
 * reset ends every session the account had.
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

        return DB::table('user_credentials')->where('email', $email)->value('reset_code');
    }

    public function test_forgot_emails_a_six_digit_code_and_stores_it_with_an_expiry(): void
    {
        $this->makeUser(['email' => 'trader@test.local']);

        $code = $this->requestCode('trader@test.local');

        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);

        Mail::assertSent(PasswordResetCode::class, function (PasswordResetCode $mail) use ($code) {
            return $mail->code === $code && $mail->hasTo('trader@test.local');
        });

        $row = DB::table('user_credentials')->where('email', 'trader@test.local')->first();
        $this->assertNotNull($row->reset_code_expires_at);
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

        $this->postJson('/api/auth/reset-password', [
            'email' => 'trader@test.local',
            'code' => $code,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertOk()->assertJsonPath('success', true);

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
        $wrong = $code === '000000' ? '000001' : '000000';

        $attempt = fn (string $c) => $this->postJson('/api/auth/reset-password', [
            'email' => 'trader@test.local',
            'code' => $c,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ]);

        for ($i = 1; $i <= PasswordResetController::MAX_ATTEMPTS; $i++) {
            $attempt($wrong)->assertUnprocessable();
            $this->assertSame($i, (int) DB::table('user_credentials')
                ->where('email', 'trader@test.local')->value('reset_code_attempts'));
        }

        // The right code no longer works once the attempts are spent.
        $attempt($code)->assertUnprocessable()->assertJsonPath('error_code', 'INVALID_CODE');
        $this->assertFalse(Hash::check('brand-new-password',
            UserCredential::where('email', 'trader@test.local')->first()->password));
    }

    public function test_an_expired_code_is_refused(): void
    {
        $this->makeUser(['email' => 'trader@test.local']);
        $code = $this->requestCode('trader@test.local');

        $this->travel(PasswordResetController::CODE_TTL_MINUTES + 1)->minutes();

        $this->postJson('/api/auth/reset-password', [
            'email' => 'trader@test.local',
            'code' => $code,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertUnprocessable()->assertJsonPath('error_code', 'INVALID_CODE');
    }
}
