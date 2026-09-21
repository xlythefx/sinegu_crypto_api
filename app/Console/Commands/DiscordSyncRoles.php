<?php

namespace App\Console\Commands;

use App\Models\UserCredential;
use App\Services\Discord\DiscordGateway;
use App\Services\Discord\DiscordRoleSync;
use Illuminate\Console\Command;

/**
 * Reconcile every linked user's Discord roles with their account state.
 *
 * The per-write syncs (approve, suspend, connect, disconnect) normally leave
 * nothing for this to do; it exists for the write that happened while Discord
 * was down, and for a role someone removed by hand in the server. Bot-only:
 * a user who is not in the server is skipped (they are joined on their next
 * login, which is the one moment we hold their token).
 *
 * Stops after a few consecutive auth failures: 401/403 answers count toward
 * Discord's Cloudflare ban, and a bad bot token would otherwise be retried
 * once per user.
 *
 * Scheduled nightly in routes/console.php; safe to run by hand at any time.
 */
class DiscordSyncRoles extends Command
{
    protected $signature = 'discord:sync-roles {--dry-run : Report what would change, touch nothing}';

    protected $description = 'Grant / revoke the managed Discord server roles for every linked account';

    /** Consecutive users with no answer from Discord before giving up. */
    private const MAX_CONSECUTIVE_FAILURES = 3;

    public function handle(DiscordGateway $discord, DiscordRoleSync $roles): int
    {
        if (! $discord->botConfigured()) {
            $this->warn('Discord bot is not configured (DISCORD_BOT_TOKEN / DISCORD_GUILD_ID) — nothing to sync.');

            return self::SUCCESS;
        }
        if ($roles->managedRoles() === []) {
            $this->warn('No managed role ids configured (DISCORD_ROLE_MEMBER_ID / DISCORD_ROLE_TRADER_ID) — nothing to sync.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $seen = 0;
        $members = 0;
        $added = 0;
        $removed = 0;
        $consecutiveMisses = 0;

        UserCredential::whereNotNull('discord_id')
            ->chunkById(100, function ($users) use ($roles, $dryRun, &$seen, &$members, &$added, &$removed, &$consecutiveMisses) {
                foreach ($users as $user) {
                    $seen++;
                    $result = $roles->syncUser($user, null, $dryRun);

                    if (! $result['member']) {
                        $consecutiveMisses++;
                        if ($consecutiveMisses >= self::MAX_CONSECUTIVE_FAILURES) {
                            $this->error('Discord answered nothing usable for '.self::MAX_CONSECUTIVE_FAILURES.' users in a row — stopping (bad token, or the API is down).');

                            return false;
                        }

                        continue;
                    }

                    $consecutiveMisses = 0;
                    $members++;
                    $added += count($result['added']);
                    $removed += count($result['removed']);

                    if ($result['added'] !== [] || $result['removed'] !== []) {
                        $this->line(sprintf(
                            '%s %s (%s): +%s -%s',
                            $dryRun ? 'WOULD SYNC' : 'synced',
                            $user->email,
                            $user->status,
                            implode(',', $result['added']) ?: '—',
                            implode(',', $result['removed']) ?: '—',
                        ));
                    }
                }

                return true;
            }, 'uni_id');

        $this->info(sprintf(
            '%s %d linked user(s): %d in the server, %d role(s) %s, %d %s.',
            $dryRun ? 'Checked' : 'Synced',
            $seen,
            $members,
            $added,
            $dryRun ? 'to add' : 'added',
            $removed,
            $dryRun ? 'to remove' : 'removed',
        ));

        return self::SUCCESS;
    }
}
