<?php

namespace App\Services\Discord;

use App\Models\UserCredential;
use Illuminate\Support\Facades\Log;

/**
 * Keeps a user's Discord server roles in step with their account.
 *
 * The rules are the whole point of "Sign in with Discord":
 *   Member  — while the account is `active` (approved by an admin);
 *   Trader  — while a LIVE exchange account (demo = 0) is connected;
 *   neither — while suspended, and both come off on unlink.
 * Each role id is optional in config; an empty one switches that rule off,
 * and the bot never touches a role it was not given.
 *
 * Called EXPLICITLY at every write that can change the answer — approve /
 * reject, an admin status edit, an exchange account connected or
 * disconnected — after the row is saved and outside any transaction, the same
 * way {@see \App\Services\EngineCache} is pinged. Not a model observer: that
 * would fire inside DB::transaction and hide an HTTP call behind every save().
 * Best-effort throughout — a Discord outage must never fail an approval or a
 * connect — with the nightly `discord:sync-roles` as the catch-up.
 *
 * The user's OAuth token is needed only to put them in the server, and only
 * the login / signup path has one in hand; it is passed through and never
 * stored. Everything else is bot-only, and a user who is not in the server
 * (memberRoles() → null) is left alone until they next log in.
 */
class DiscordRoleSync
{
    public function __construct(private DiscordGateway $discord) {}

    /**
     * The managed role ids this user SHOULD hold right now.
     *
     * @return list<string>
     */
    public function desiredRoles(UserCredential $user): array
    {
        $roles = [];

        $member = (string) config('services.discord.role_member_id');
        if ($member !== '' && $user->status === 'active') {
            $roles[] = $member;
        }

        $trader = (string) config('services.discord.role_trader_id');
        if ($trader !== '' && $user->status === 'active' && $user->hasLiveExchangeAccount()) {
            $roles[] = $trader;
        }

        return $roles;
    }

    /**
     * Every role id the bot manages — the set it may add OR remove. Anything
     * outside it is the server's own business.
     *
     * @return list<string>
     */
    public function managedRoles(): array
    {
        return array_values(array_filter([
            (string) config('services.discord.role_member_id'),
            (string) config('services.discord.role_trader_id'),
        ]));
    }

    /**
     * Join (when a user token is given) and reconcile. Returns what happened,
     * for the console command's summary; callers in request handlers ignore it.
     *
     * @return array{joined: bool, member: bool, added: list<string>, removed: list<string>}
     */
    public function syncUser(UserCredential $user, ?string $accessToken = null, bool $dryRun = false): array
    {
        $result = ['joined' => false, 'member' => false, 'added' => [], 'removed' => []];

        if (! $user->hasDiscord() || ! $this->discord->botConfigured()) {
            return $result;
        }

        $discordId = (string) $user->discord_id;

        if ($accessToken !== null && ! $dryRun) {
            $result['joined'] = $this->discord->addGuildMember($discordId, $accessToken);
        }

        $current = $this->discord->memberRoles($discordId);
        if ($current === null) {
            // Not in the server, or Discord could not be asked: nothing to reconcile.
            return $result;
        }
        $result['member'] = true;

        $managed = $this->managedRoles();
        $desired = $this->desiredRoles($user);

        $toAdd = array_values(array_diff($desired, $current));
        $toRemove = array_values(array_intersect(array_diff($managed, $desired), $current));

        foreach ($toAdd as $roleId) {
            if ($dryRun || $this->discord->addRole($discordId, $roleId)) {
                $result['added'][] = $roleId;
            }
        }
        foreach ($toRemove as $roleId) {
            if ($dryRun || $this->discord->removeRole($discordId, $roleId)) {
                $result['removed'][] = $roleId;
            }
        }

        return $result;
    }

    /** Strip every managed role — the unlink path, before the id is cleared. */
    public function revoke(UserCredential $user): void
    {
        if (! $user->hasDiscord() || ! $this->discord->botConfigured()) {
            return;
        }

        $discordId = (string) $user->discord_id;
        $current = $this->discord->memberRoles($discordId);
        if ($current === null) {
            return;
        }

        foreach (array_intersect($this->managedRoles(), $current) as $roleId) {
            $this->discord->removeRole($discordId, $roleId);
        }
    }

    /** Sync by id — for callers that hold a uni_id rather than a model. */
    public function syncUniId(string $uniId): void
    {
        $user = UserCredential::find($uniId);
        if ($user === null) {
            return;
        }

        try {
            $this->syncUser($user);
        } catch (\Throwable $e) {
            // The gateway never throws; this guards the query above and any
            // future edit to it. A role is never worth failing the caller.
            Log::warning('Discord role sync failed.', ['uni_id' => $uniId, 'exception' => $e->getMessage()]);
        }
    }
}
