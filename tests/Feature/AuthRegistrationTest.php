<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;

/**
 * POST /api/auth/register — the Terms checkbox.
 *
 * The acceptance is recorded on the row (timestamp + the version shown),
 * because a ticked box with nothing behind it is not evidence of anything.
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

    public function test_login_is_rate_limited_per_ip(): void
    {
        $this->makeUser(['email' => 'victim@test.local']);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'victim@test.local', 'password' => 'wrong'])
                ->assertStatus(401);
        }

        $this->postJson('/api/auth/login', ['email' => 'victim@test.local', 'password' => 'wrong'])
            ->assertStatus(429);
    }
}
