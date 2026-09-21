<?php

namespace Tests\Feature;

use App\Services\Discord\DiscordRoleSync;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * The role rules and every write site that fires them: approve / reject /
 * admin status edit → Member; connect / disconnect (trader and admin) →
 * Trader; the nightly reconcile for whatever those missed.
 */
class DiscordRoleSyncTest extends DiscordTestCase
{
    private const DID = '123456789012345678';

    /** Put the fake user in the server with the given roles. */
    private function inServer(array $roles = []): void
    {
        $this->discord->members[self::DID] = $roles;
    }

    // ---- approval queue -------------------------------------------------------

    public function test_accept_grants_member(): void
    {
        $uniId = $this->makeLinkedUser(['status' => 'pending']);
        $admin = $this->makeUser(['type' => 'admin']);
        $this->inServer();

        $this->postJson("/api/admin/users/{$uniId}/accept", [], $this->userHeaders($admin))->assertOk();

        $this->assertSame([self::ROLE_MEMBER], $this->discord->rolesOf(self::DID));
    }

    public function test_reject_leaves_a_pending_user_with_nothing(): void
    {
        $uniId = $this->makeLinkedUser(['status' => 'pending']);
        $admin = $this->makeUser(['type' => 'admin']);
        $this->inServer([self::ROLE_MEMBER, self::ROLE_TRADER]);

        $this->postJson("/api/admin/users/{$uniId}/reject", [], $this->userHeaders($admin))->assertOk();

        $this->assertSame([], $this->discord->rolesOf(self::DID));
    }

    public function test_admin_suspend_removes_both_roles_and_reactivate_restores_member(): void
    {
        $uniId = $this->makeLinkedUser(['status' => 'active']);
        $this->makeAccount($uniId, ['demo' => 0]);
        $admin = $this->makeUser(['type' => 'admin']);
        $this->inServer([self::ROLE_MEMBER, self::ROLE_TRADER, 'keep-me']);

        $this->putJson("/api/admin/users/{$uniId}", ['status' => 'suspended'], $this->userHeaders($admin))->assertOk();
        $this->assertSame(['keep-me'], $this->discord->rolesOf(self::DID));

        $this->putJson("/api/admin/users/{$uniId}", ['status' => 'active'], $this->userHeaders($admin))->assertOk();
        $this->assertEqualsCanonicalizing(['keep-me', self::ROLE_MEMBER, self::ROLE_TRADER], $this->discord->rolesOf(self::DID));
    }

    public function test_a_fee_edit_does_not_touch_discord(): void
    {
        $uniId = $this->makeLinkedUser(['status' => 'active']);
        $admin = $this->makeUser(['type' => 'admin']);
        $this->inServer();

        $this->putJson("/api/admin/users/{$uniId}", ['realized_percentage' => 15, 'status' => 'active'], $this->userHeaders($admin))
            ->assertOk();

        $this->assertSame([], $this->discord->calls);
    }

    // ---- exchange accounts ----------------------------------------------------

    public function test_connecting_a_live_account_grants_trader_and_a_demo_one_does_not(): void
    {
        $uniId = $this->makeLinkedUser(['status' => 'active']);
        $this->inServer([self::ROLE_MEMBER]);

        $this->postJson('/api/exchange/binance', [
            'name' => 'Demo', 'api_key' => 'demo-key-000000000001', 'secret_key' => 'x', 'demo' => true,
        ], $this->userHeaders($uniId))->assertCreated();
        $this->assertSame([self::ROLE_MEMBER], $this->discord->rolesOf(self::DID));

        $this->postJson('/api/exchange/mexc', [
            'name' => 'Live', 'api_key' => 'live-key-000000000001', 'secret_key' => 'x', 'demo' => false,
        ], $this->userHeaders($uniId))->assertCreated();
        $this->assertEqualsCanonicalizing([self::ROLE_MEMBER, self::ROLE_TRADER], $this->discord->rolesOf(self::DID));
    }

    public function test_disconnecting_the_last_live_account_removes_trader(): void
    {
        $uniId = $this->makeLinkedUser(['status' => 'active']);
        $accountId = $this->makeAccount($uniId, ['demo' => 0]);
        $this->inServer([self::ROLE_MEMBER, self::ROLE_TRADER]);

        $this->deleteJson("/api/exchange/binance/accounts/{$accountId}", [], $this->userHeaders($uniId))->assertOk();

        $this->assertSame([self::ROLE_MEMBER], $this->discord->rolesOf(self::DID));
    }

    public function test_admin_key_disconnect_and_bulk_disconnect_remove_trader(): void
    {
        $uniId = $this->makeLinkedUser(['status' => 'active']);
        $first = $this->makeAccount($uniId, ['demo' => 0]);
        $second = $this->makeAccount($uniId, ['demo' => 0], 'mexc');
        $admin = $this->makeUser(['type' => 'admin']);
        $this->inServer([self::ROLE_MEMBER, self::ROLE_TRADER]);

        $this->deleteJson("/api/admin/api-keys/binance/{$first}", [], $this->userHeaders($admin))->assertOk();
        // Still one live account on MEXC.
        $this->assertEqualsCanonicalizing([self::ROLE_MEMBER, self::ROLE_TRADER], $this->discord->rolesOf(self::DID));

        $this->postJson('/api/admin/api-keys/bulk-delete', [
            'keys' => [['exchange' => 'mexc', 'id' => $second]],
        ], $this->userHeaders($admin))->assertOk();
        $this->assertSame([self::ROLE_MEMBER], $this->discord->rolesOf(self::DID));
    }

    // ---- rules in isolation ---------------------------------------------------

    public function test_desired_roles_follow_status_and_live_accounts(): void
    {
        $sync = $this->app->make(DiscordRoleSync::class);

        $active = \App\Models\UserCredential::find($this->makeLinkedUser(['status' => 'active']));
        $this->assertSame([self::ROLE_MEMBER], $sync->desiredRoles($active));

        $this->makeAccount($active->uni_id, ['demo' => 1]);
        $this->assertSame([self::ROLE_MEMBER], $sync->desiredRoles($active));

        $this->makeAccount($active->uni_id, ['demo' => 0], 'mexc');
        $this->assertSame([self::ROLE_MEMBER, self::ROLE_TRADER], $sync->desiredRoles($active));

        $active->status = 'suspended';
        $this->assertSame([], $sync->desiredRoles($active));
    }

    public function test_an_empty_role_id_switches_that_rule_off(): void
    {
        config(['services.discord.role_trader_id' => '']);
        $sync = $this->app->make(DiscordRoleSync::class);

        $user = \App\Models\UserCredential::find($this->makeLinkedUser(['status' => 'active']));
        $this->makeAccount($user->uni_id, ['demo' => 0]);

        $this->assertSame([self::ROLE_MEMBER], $sync->desiredRoles($user));
        $this->assertSame([self::ROLE_MEMBER], $sync->managedRoles());
    }

    public function test_a_user_without_discord_or_a_bot_without_config_is_silent(): void
    {
        $sync = $this->app->make(DiscordRoleSync::class);

        $plain = \App\Models\UserCredential::find($this->makeUser(['status' => 'active']));
        $sync->syncUser($plain);
        $this->assertSame([], $this->discord->calls);

        config(['services.discord.bot_token' => null]);
        $linked = \App\Models\UserCredential::find($this->makeLinkedUser(['status' => 'active']));
        $sync->syncUser($linked, 'user-token');
        $this->assertSame([], $this->discord->calls);
    }

    public function test_a_user_not_in_the_server_is_left_alone(): void
    {
        $sync = $this->app->make(DiscordRoleSync::class);
        $user = \App\Models\UserCredential::find($this->makeLinkedUser(['status' => 'active']));

        $result = $sync->syncUser($user);

        $this->assertFalse($result['member']);
        $this->assertSame([], $this->discord->callsTo('addRole'));
    }

    // ---- nightly reconcile ----------------------------------------------------

    public function test_sync_roles_command_fixes_drift_and_skips_non_members(): void
    {
        $inServer = $this->makeLinkedUser(['status' => 'active']);
        $this->makeAccount($inServer, ['demo' => 0]);
        // Member missing (drift); Trader earned; the unmanaged role is not ours to touch.
        $this->discord->members[self::DID] = ['stale-unmanaged', self::ROLE_TRADER];

        $suspended = $this->makeUser(['status' => 'suspended', 'discord_id' => '555555555555555555']);
        $this->discord->members['555555555555555555'] = [self::ROLE_MEMBER, self::ROLE_TRADER];

        $this->makeUser(['status' => 'active', 'discord_id' => '666666666666666666']); // not in the server

        $exit = Artisan::call('discord:sync-roles');

        $this->assertSame(0, $exit);
        $this->assertEqualsCanonicalizing(['stale-unmanaged', self::ROLE_TRADER, self::ROLE_MEMBER], $this->discord->rolesOf(self::DID));
        $this->assertSame([], $this->discord->rolesOf('555555555555555555'));
        $this->assertNull($this->discord->rolesOf('666666666666666666'));
        $this->assertStringContainsString('2 in the server', Artisan::output());
        $this->assertSame('suspended', DB::table('user_credentials')->where('uni_id', $suspended)->value('status'));
    }

    public function test_sync_roles_dry_run_changes_nothing(): void
    {
        $this->makeLinkedUser(['status' => 'active']);
        $this->inServer([]);

        Artisan::call('discord:sync-roles', ['--dry-run' => true]);

        $this->assertSame([], $this->discord->rolesOf(self::DID));
        $this->assertSame([], $this->discord->callsTo('addRole'));
        $this->assertStringContainsString('WOULD SYNC', Artisan::output());
    }

    public function test_sync_roles_stops_when_discord_answers_nothing(): void
    {
        foreach (['1', '2', '3', '4'] as $i) {
            $this->makeUser(['status' => 'active', 'discord_id' => str_repeat($i, 18)]);
        }
        $this->discord->down = true;

        Artisan::call('discord:sync-roles');

        $this->assertStringContainsString('stopping', Artisan::output());
        // Three misses, then it stops — the fourth user is never asked about.
        $this->assertCount(3, $this->discord->callsTo('memberRoles'));
    }
}
