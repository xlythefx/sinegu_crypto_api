<?php

namespace Tests\Feature;

use App\Models\UserCredential;
use Illuminate\Support\Facades\Hash;

/**
 * POST /api/admin/users — the "Create user" modal on /admin/users.
 *
 * What matters here: the account is REAL (not sandbox) and usable immediately,
 * the password is hashed rather than stored raw, and the two rules that already
 * govern the role column (admin-only access, one master) hold on creation too —
 * a guard that only lives on the edit path is a guard with a hole next to it.
 */
class AdminUserCreateTest extends EngineTestCase
{
    private function headersFor(string $uniId): array
    {
        $user = UserCredential::find($uniId);

        return ['Authorization' => 'Bearer '.$user->createToken('spa')->plainTextToken];
    }

    private function create(string $actorUniId, array $body)
    {
        // The guard caches the resolved user per application instance.
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/admin/users', $body, $this->headersFor($actorUniId));
    }

    public function test_an_admin_creates_an_active_user_by_default(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);

        $this->create($admin, [
            'name' => 'Jane Cooper',
            'email' => 'jane@example.test',
            'password' => 'super-secret-1',
        ])
            ->assertStatus(201)
            ->assertJsonPath('user.email', 'jane@example.test')
            ->assertJsonPath('user.type', 'user')
            // An admin typing the account in IS the approval — it must not
            // land back in that same admin's own pending queue.
            ->assertJsonPath('user.status', 'active');

        $created = UserCredential::where('email', 'jane@example.test')->first();

        $this->assertNotNull($created);
        $this->assertTrue(Hash::check('super-secret-1', $created->password));
        // Real account: the engine and invoicing must treat it like any other.
        $this->assertFalse((bool) $created->is_sandbox);
        $this->assertTrue((bool) $created->email_verified);
        // Untouched fee columns keep their DB defaults.
        $this->assertSame('20.00', (string) $created->realized_percentage);
        $this->assertSame('6.00', (string) $created->unrealized_percentage);
    }

    public function test_role_status_and_fee_percentages_are_settable_up_front(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);

        $response = $this->create($admin, [
            'name' => 'Ops Account',
            'email' => 'ops@example.test',
            'password' => 'super-secret-1',
            'type' => 'admin',
            'status' => 'pending',
            'realized_percentage' => 15,
            'unrealized_percentage' => 4.5,
            'affiliate_percentage' => 25,
        ])
            ->assertStatus(201)
            ->assertJsonPath('user.type', 'admin')
            ->assertJsonPath('user.status', 'pending');

        // Loose compare: JSON gives back 15 for 15.00, and the point of the
        // assertion is the value, not int-vs-float.
        $this->assertEquals(15, $response->json('user.realized_percentage'));
        $this->assertEquals(4.5, $response->json('user.unrealized_percentage'));
        $this->assertEquals(25, $response->json('user.affiliate_percentage'));

        $created = UserCredential::where('email', 'ops@example.test')->first();
        $this->assertEquals(15, $created->realized_percentage);
        $this->assertEquals(4.5, $created->unrealized_percentage);
    }

    public function test_a_duplicate_email_is_refused(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);
        $this->makeUser(['email' => 'taken@example.test']);

        $this->create($admin, [
            'name' => 'Impostor',
            'email' => 'taken@example.test',
            'password' => 'super-secret-1',
        ])->assertStatus(422);

        $this->assertSame(1, UserCredential::where('email', 'taken@example.test')->count());
    }

    public function test_a_short_password_is_refused(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);

        $this->create($admin, [
            'name' => 'Jane Cooper',
            'email' => 'jane@example.test',
            'password' => 'short',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('user_credentials', ['email' => 'jane@example.test']);
    }

    /** Same single-master rule the edit path enforces. */
    public function test_a_second_master_cannot_be_created(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);
        $this->makeUser(['type' => 'master', 'email' => 'house@sinegu.test']);

        $response = $this->create($admin, [
            'name' => 'Second House',
            'email' => 'house2@sinegu.test',
            'password' => 'super-secret-1',
            'type' => 'master',
        ])->assertStatus(422);

        $this->assertStringContainsString('house@sinegu.test', $response->json('message'));
        $this->assertDatabaseMissing('user_credentials', ['email' => 'house2@sinegu.test']);
    }

    public function test_a_plain_user_cannot_create_accounts(): void
    {
        $plain = $this->makeUser();

        $this->create($plain, [
            'name' => 'Jane Cooper',
            'email' => 'jane@example.test',
            'password' => 'super-secret-1',
        ])
            ->assertStatus(403)
            ->assertJson(['error_code' => 'FORBIDDEN']);

        $this->assertDatabaseMissing('user_credentials', ['email' => 'jane@example.test']);
    }
}
