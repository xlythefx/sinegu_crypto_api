<?php

namespace Tests\Feature;

use App\Models\UserCredential;

/**
 * PUT /api/admin/users/{uniId} — the role column.
 *
 * Two rules carry weight here: an admin cannot re-role themselves (self-
 * escalation, and locking yourself out of the portal), and `master` stays a
 * single row because every consumer resolves it with ->first().
 */
class AdminUserRoleTest extends EngineTestCase
{
    private function headersFor(string $uniId): array
    {
        $user = UserCredential::find($uniId);

        return ['Authorization' => 'Bearer '.$user->createToken('spa')->plainTextToken];
    }

    private function setRole(string $actorUniId, string $targetUniId, string $role)
    {
        // The guard caches the resolved user per application instance.
        $this->app['auth']->forgetGuards();

        return $this->putJson(
            "/api/admin/users/{$targetUniId}",
            ['type' => $role],
            $this->headersFor($actorUniId)
        );
    }

    public function test_an_admin_can_change_another_users_role(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);
        $target = $this->makeUser();

        foreach (['developer', 'admin', 'user'] as $role) {
            $this->setRole($admin, $target, $role)
                ->assertOk()
                ->assertJsonPath('user.type', $role);

            $this->assertDatabaseHas('user_credentials', [
                'uni_id' => $target,
                'type' => $role,
            ]);
        }
    }

    public function test_you_can_change_your_own_role_sideways_and_upward(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);

        $this->setRole($admin, $admin, 'developer')
            ->assertOk()
            ->assertJsonPath('user.type', 'developer');

        // …including taking the house account when it is free.
        $this->setRole($admin, $admin, 'master')
            ->assertOk()
            ->assertJsonPath('user.type', 'master');
    }

    public function test_you_cannot_demote_yourself_to_a_plain_user(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);

        $this->setRole($admin, $admin, 'user')
            ->assertStatus(422)
            ->assertJson(['success' => false]);

        $this->assertDatabaseHas('user_credentials', ['uni_id' => $admin, 'type' => 'admin']);
    }

    /** Someone else may still demote you — the lockout is only self-inflicted. */
    public function test_another_admin_can_demote_you_to_user(): void
    {
        $actor = $this->makeUser(['type' => 'admin']);
        $target = $this->makeUser(['type' => 'admin']);

        $this->setRole($actor, $target, 'user')->assertOk();

        $this->assertDatabaseHas('user_credentials', ['uni_id' => $target, 'type' => 'user']);
    }

    public function test_a_second_master_is_refused(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);
        $this->makeUser(['type' => 'master', 'email' => 'house@sinegu.test']);
        $target = $this->makeUser();

        $response = $this->setRole($admin, $target, 'master')->assertStatus(422);
        $this->assertStringContainsString('house@sinegu.test', $response->json('message'));

        $this->assertDatabaseHas('user_credentials', ['uni_id' => $target, 'type' => 'user']);
    }

    /** Demote, then promote — the supported way to move the house account. */
    public function test_master_can_be_transferred_once_the_old_one_steps_down(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);
        $oldMaster = $this->makeUser(['type' => 'master']);
        $newMaster = $this->makeUser();

        $this->setRole($admin, $oldMaster, 'admin')->assertOk();
        $this->setRole($admin, $newMaster, 'master')->assertOk();

        $this->assertSame(1, UserCredential::where('type', 'master')->count());
        $this->assertDatabaseHas('user_credentials', ['uni_id' => $newMaster, 'type' => 'master']);
    }

    public function test_a_plain_user_cannot_change_roles(): void
    {
        $plain = $this->makeUser();
        $target = $this->makeUser();

        $this->setRole($plain, $target, 'admin')
            ->assertStatus(403)
            ->assertJson(['error_code' => 'FORBIDDEN']);
    }

    public function test_an_unknown_role_is_rejected(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);
        $target = $this->makeUser();

        $this->setRole($admin, $target, 'superuser')->assertStatus(422);
    }
}
