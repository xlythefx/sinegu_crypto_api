<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;

/**
 * POST /api/auth/register — the Terms checkbox — and the brakes on
 * POST /api/auth/login.
 *
 * The acceptance is recorded on the row (timestamp + the version shown),
 * because a ticked box with nothing behind it is not evidence of anything.
 * Login is limited per IP AND per account, and answers an unknown email and
 * a wrong password identically, because an account here holds exchange API
 * keys and "which of these addresses has one" is the first thing an
 * attacker asks.
 */
class AuthRegistrationTest extends EngineTestCase
{
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'New Trader',
            'email' => 'new-trader@test.local',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
            'terms' => true,
            'terms_version' => 'September 1, 2026',
        ], $overrides);
    }

    private function login(string $email, string $password, ?string $ip = null)
    {
        if ($ip !== null) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip]);
        }

        return $this->postJson('/api/auth/login', ['email' => $email, 'password' => $password]);
    }

    public function test_registration_records_when_and_which_terms_were_accepted(): void
    {
        $this->travelTo(now()->setSeconds(0));

        $this->postJson('/api/auth/register', $this->payload())
            ->assertCreated()
            ->assertJsonPath('success', true);

        $row = DB::table('user_credentials')->where('email', 'new-trader@test.local')->first();

        $this->assertNotNull($row->terms_accepted_at);
        $this->assertSame(now()->format('Y-m-d H:i'), substr($row->terms_accepted_at, 0, 16));
        $this->assertSame('September 1, 2026', $row->terms_version);
    }

    public function test_registration_is_refused_without_accepting_the_terms(): void
    {
        $this->postJson('/api/auth/register', $this->payload(['terms' => false]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['terms']);

        // Absent is a refusal too — the field may not default to yes.
        $payload = $this->payload();
        unset($payload['terms']);

        $this->postJson('/api/auth/register', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['terms']);

        $this->assertDatabaseMissing('user_credentials', ['email' => 'new-trader@test.local']);
    }

    // ---- login ----------------------------------------------------------------

    public function test_login_gives_one_answer_for_an_unknown_email_and_a_wrong_password(): void
    {
        $this->makeUser(['email' => 'victim@test.local']);

        $wrongPassword = $this->login('victim@test.local', 'wrong')->assertStatus(401);
        $unknownEmail = $this->login('nobody@test.local', 'wrong')->assertStatus(401);

        // Byte-for-byte the same body: nothing in it may say which it was.
        $this->assertSame($wrongPassword->json(), $unknownEmail->json());
        $wrongPassword
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'INVALID_CREDENTIALS')
            ->assertJsonPath('message', 'Email or password is incorrect.');

        foreach (['USER_NOT_FOUND', 'INVALID_PASSWORD'] as $leak) {
            $this->assertStringNotContainsString($leak, $unknownEmail->getContent());
        }
    }

    public function test_login_is_rate_limited_per_ip(): void
    {
        // Ten DIFFERENT addresses, so it is the IP bucket that fills and not
        // the per-account one below.
        for ($i = 1; $i <= 10; $i++) {
            $this->login("nobody{$i}@test.local", 'wrong')->assertStatus(401);
        }

        $this->login('nobody11@test.local', 'wrong')->assertStatus(429);
    }

    public function test_login_for_one_email_is_rate_limited_across_ips(): void
    {
        $this->makeUser(['email' => 'victim@test.local']);

        for ($i = 1; $i <= 5; $i++) {
            $this->login('victim@test.local', 'wrong', "10.0.0.{$i}")->assertStatus(401);
        }

        $this->login('victim@test.local', 'wrong', '10.0.0.6')->assertStatus(429);

        // The bucket counts requests, not failures: the owner is held off too
        // — for the minute, not locked out. Case and whitespace share it.
        $this->login('victim@test.local', 'secret-password', '10.0.0.7')->assertStatus(429);
        $this->login(' Victim@Test.local ', 'secret-password', '10.0.0.8')->assertStatus(429);

        $this->travel(61)->seconds();
        $this->login('victim@test.local', 'secret-password', '10.0.0.9')->assertOk();
    }
}
