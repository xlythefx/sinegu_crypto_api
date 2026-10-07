<?php

namespace Tests\Feature;

use App\Models\UserCredential;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * A suspended account is OUT — now, not at its next login.
 *
 * Until 2026-10-07 only `login` looked at `status`, and Sanctum tokens never
 * expired, so a suspended user kept every open browser signed in for ever.
 * Three things close that: suspend / reject delete the user's tokens,
 * EnsureAccountActive refuses a token that survives anyway (everywhere but
 * sign-out), and tokens expire.
 */
class AccountSuspensionTest extends EngineTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Admin writes ping the engine cache and the Discord role sync.
        Http::fake();
    }

    private function bearer(string $uniId, string $name = 'spa'): array
    {
        $user = UserCredential::find($uniId);

        return ['Authorization' => 'Bearer '.$user->createToken($name)->plainTextToken];
    }

    private function tokensOf(string $uniId): int
    {
        return DB::table('personal_access_tokens')->where('tokenable_id', $uniId)->count();
    }

    public function test_a_suspended_users_token_is_refused_everywhere_but_sign_out(): void
    {
        $uniId = $this->makeUser(['status' => 'suspended']);
        $headers = $this->bearer($uniId);

        $this->getJson('/api/dashboard/summary', $headers)
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'ACCOUNT_SUSPENDED');

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/auth/me', $headers)
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'ACCOUNT_SUSPENDED');

        // Dropping your own token must always work.
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/logout', [], $headers)->assertOk();
        $this->assertSame(0, $this->tokensOf($uniId));
    }

    /** Pending may look around while an admin approves them — unchanged. */
    public function test_a_pending_user_still_gets_in(): void
    {
        $uniId = $this->makeUser(['status' => 'pending']);

        $this->getJson('/api/auth/me', $this->bearer($uniId))->assertOk();
    }

    public function test_suspending_a_user_ends_every_session_they_have(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);
        $victim = $this->makeUser();
        $laptop = $this->bearer($victim, 'laptop');
        $this->bearer($victim, 'phone');
        $this->assertSame(2, $this->tokensOf($victim));

        $this->app['auth']->forgetGuards();
        $this->putJson("/api/admin/users/{$victim}", ['status' => 'suspended'], $this->bearer($admin))
            ->assertOk()
            ->assertJsonPath('user.status', 'suspended');

        $this->assertSame(0, $this->tokensOf($victim));
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/auth/me', $laptop)->assertStatus(401);
    }

    /** Only a suspension is a session event — a fee edit must not log anyone out. */
    public function test_editing_fees_leaves_sessions_alone(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);
        $target = $this->makeUser();
        $this->bearer($target);

        $this->app['auth']->forgetGuards();
        $this->putJson("/api/admin/users/{$target}", ['realized_percentage' => 15], $this->bearer($admin))
            ->assertOk();

        $this->assertSame(1, $this->tokensOf($target));
    }

    /** Register signs the user in, so a rejected sign-up has a session to end. */
    public function test_rejecting_a_pending_sign_up_ends_its_session(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);
        $pending = $this->makeUser(['status' => 'pending']);
        $this->bearer($pending);

        $this->app['auth']->forgetGuards();
        $this->postJson("/api/admin/users/{$pending}/reject", [], $this->bearer($admin))->assertOk();

        $this->assertSame(0, $this->tokensOf($pending));
    }

    public function test_tokens_expire(): void
    {
        config(['sanctum.expiration' => 1]);
        $uniId = $this->makeUser();
        $headers = $this->bearer($uniId);

        $this->getJson('/api/auth/me', $headers)->assertOk();

        $this->travel(2)->minutes();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/auth/me', $headers)->assertStatus(401);
    }
}
