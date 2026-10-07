<?php

namespace App\Console\Commands;

use App\Models\ExchangeAccount;
use App\Services\Discord\DiscordRoleSync;
use App\Services\EngineCache;
use App\Services\Exchanges\ExchangeSchema;
use Illuminate\Console\Command;

/**
 * Disconnect accounts whose API key the exchange has refused for longer than
 * the grace period (ExchangeAccount::KEY_GRACE_DAYS) — on every venue.
 *
 * The point is not tidiness — it is that the user can reconnect. One account
 * per user per exchange is allowed, so a dead row silently occupies the slot:
 * until it goes, "Connect exchange" answers "you already have one connected"
 * and the person is stuck with an account that cannot trade. Soft-delete frees
 * the slot while keeping the history (invoices reference these rows).
 *
 * Every venue, not only Binance: until 2026-10-07 only binance_accounts was
 * swept, so a MEXC key the exchange kept refusing stayed flagged for ever and
 * its owner's slot was never freed. Ids repeat across the per-exchange tables,
 * so each table is read through its own model.
 *
 * The clock runs from key_blocked_at, which only the transition into blocked
 * sets — so repeated reports of the same fault cannot extend it forever, and a
 * user who fixes their whitelist has the flag (and the deadline) cleared by the
 * next successful poll.
 *
 * Scheduled daily in routes/console.php; safe to run by hand at any time.
 */
class ExchangeDisconnectBlockedKeys extends Command
{
    protected $signature = 'exchange:disconnect-blocked-keys {--dry-run : List what would go, change nothing}';

    protected $description = 'Disconnect exchange accounts whose API key has been refused for over the grace period';

    public function handle(EngineCache $engineCache, DiscordRoleSync $discordRoles): int
    {
        $deadline = now()->subDays(ExchangeAccount::KEY_GRACE_DAYS);

        /** @var list<array{0: string, 1: ExchangeAccount}> $expired */
        $expired = [];
        foreach (ExchangeSchema::supported() as $exchange) {
            $rows = ExchangeSchema::for($exchange)->accountQuery()
                ->where('key_status', ExchangeAccount::KEY_BLOCKED)
                ->whereNotNull('key_blocked_at')
                ->where('key_blocked_at', '<=', $deadline)
                ->get();

            foreach ($rows as $account) {
                $expired[] = [$exchange, $account];
            }
        }

        if ($expired === []) {
            $this->info('No blocked keys past the '.ExchangeAccount::KEY_GRACE_DAYS.'-day grace period.');

            return self::SUCCESS;
        }

        foreach ($expired as [$exchange, $account]) {
            $this->line(sprintf(
                '%s %s (%s, %s) — blocked since %s [%s]',
                $this->option('dry-run') ? 'WOULD DISCONNECT' : 'disconnecting',
                $account->name,
                $exchange,
                $account->uni_id,
                $account->key_blocked_at?->toDateTimeString(),
                $account->key_error_code ?? '—',
            ));

            if (! $this->option('dry-run')) {
                $account->delete();
            }
        }

        if (! $this->option('dry-run')) {
            $engineCache->refreshAccounts();
            // Losing the last live account takes the Discord Trader role with it.
            $owners = array_unique(array_map(fn ($pair) => (string) $pair[1]->uni_id, $expired));
            foreach ($owners as $uniId) {
                $discordRoles->syncUniId($uniId);
            }
        }

        $this->info(sprintf(
            '%s %d account(s).',
            $this->option('dry-run') ? 'Would disconnect' : 'Disconnected',
            count($expired),
        ));

        return self::SUCCESS;
    }
}
