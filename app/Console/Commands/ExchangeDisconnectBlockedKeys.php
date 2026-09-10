<?php

namespace App\Console\Commands;

use App\Models\BinanceAccount;
use App\Services\EngineCache;
use Illuminate\Console\Command;

/**
 * Disconnect accounts whose API key the exchange has refused for longer than
 * the grace period (BinanceAccount::KEY_GRACE_DAYS).
 *
 * The point is not tidiness — it is that the user can reconnect. One Binance
 * account per user is allowed, so a dead row silently occupies the slot: until
 * it goes, "Connect exchange" answers "you already have one connected" and the
 * person is stuck with an account that cannot trade. Soft-delete frees the slot
 * while keeping the history (invoices reference these rows).
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

    public function handle(EngineCache $engineCache): int
    {
        $deadline = now()->subDays(BinanceAccount::KEY_GRACE_DAYS);

        $expired = BinanceAccount::where('key_status', BinanceAccount::KEY_BLOCKED)
            ->whereNotNull('key_blocked_at')
            ->where('key_blocked_at', '<=', $deadline)
            ->get();

        if ($expired->isEmpty()) {
            $this->info('No blocked keys past the '.BinanceAccount::KEY_GRACE_DAYS.'-day grace period.');

            return self::SUCCESS;
        }

        foreach ($expired as $account) {
            $this->line(sprintf(
                '%s %s (%s) — blocked since %s [%s]',
                $this->option('dry-run') ? 'WOULD DISCONNECT' : 'disconnecting',
                $account->name,
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
        }

        $this->info(sprintf(
            '%s %d account(s).',
            $this->option('dry-run') ? 'Would disconnect' : 'Disconnected',
            $expired->count(),
        ));

        return self::SUCCESS;
    }
}
