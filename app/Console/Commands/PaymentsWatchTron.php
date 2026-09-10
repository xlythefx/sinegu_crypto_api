<?php

namespace App\Console\Commands;

use App\Services\Payments\PaymentEnvironment;
use App\Services\Payments\TronWatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Detects direct USDT-TRC20 payments and settles the invoices they pay for.
 *
 * The counterpart of the Coinsbuy webhook: TRON pushes nothing, so this is how
 * a payment is noticed at all. Scheduled every minute (routes/console.php) and
 * safe to run by hand at any time — every transfer is deduped on
 * UNIQUE(network, event_key) and every settlement goes through
 * InvoiceService::settle(), which is itself idempotent, so a hand-run racing the
 * scheduled one cannot double-settle anything.
 *
 * It scans EVERY configured network, test networks included, on whichever box it
 * runs. That is deliberate: a developer's intents live on Nile even in
 * production, so production has to watch Nile for the rehearsal to work, and
 * watching a test network costs one HTTP call a minute.
 *
 * Exits SUCCESS even when a network was unreachable. A command that exits
 * non-zero every minute floods cron mail until someone silences it, which is
 * the last thing you want on the job that settles invoices; the failure is
 * logged, reported in the summary, and surfaced in Admin → Crypto Transfers as
 * a stale-scan warning.
 */
class PaymentsWatchTron extends Command
{
    protected $signature = 'payments:watch-tron
        {--network= : Scan only this network (default: every configured one)}
        {--since= : ISO-8601 timestamp or unix milliseconds, overriding the cursor — for a manual backfill}
        {--dry-run : Report what would happen, write nothing}';

    protected $description = 'Scan TRON for incoming USDT-TRC20 payments and settle their invoices';

    public function handle(TronWatcher $watcher, PaymentEnvironment $env): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $only = (string) ($this->option('network') ?? '');

        $networks = $only !== '' ? [$only] : $env->tronNetworks();

        if ($networks === []) {
            $this->warn('No TRON network is configured — nothing to scan.');

            return self::SUCCESS;
        }

        $since = $this->resolveSince();
        $totals = ['fetched' => 0, 'stored' => 0, 'settled' => 0, 'unmatched' => 0, 'rejected' => 0, 'ambiguous' => 0];

        foreach ($networks as $network) {
            $result = $watcher->scan($network, $dryRun, $since);

            foreach (array_keys($totals) as $key) {
                $totals[$key] += (int) ($result[$key] ?? 0);
            }

            if (! $result['configured']) {
                $this->warn(sprintf('%s: skipped (%s).', $network, $result['error']));

                continue;
            }

            if ($result['error'] !== null) {
                // Not a failure of THIS command — the next run picks up from the
                // same cursor, because the cursor is derived from stored rows.
                $this->warn(sprintf('%s: %s.', $network, $result['error']));
            }

            $this->line(sprintf(
                '%s: fetched %d, stored %d, settled %d, unmatched %d, ambiguous %d, rejected %d%s',
                $network,
                $result['fetched'], $result['stored'], $result['settled'],
                $result['unmatched'], $result['ambiguous'], $result['rejected'],
                $result['truncated'] ? ' (page budget reached — the next run continues)' : '',
            ));
        }

        $this->info(sprintf(
            '%sTRON scan complete. Fetched %d, stored %d, settled %d, unmatched %d, ambiguous %d, rejected %d.',
            $dryRun ? '[dry run] ' : '',
            $totals['fetched'], $totals['stored'], $totals['settled'],
            $totals['unmatched'], $totals['ambiguous'], $totals['rejected'],
        ));

        return self::SUCCESS;
    }

    /** `--since` accepts either unix milliseconds or anything Carbon can parse. */
    private function resolveSince(): ?int
    {
        $since = (string) ($this->option('since') ?? '');
        if ($since === '') {
            return null;
        }

        if (ctype_digit($since)) {
            return (int) $since;
        }

        try {
            return Carbon::parse($since)->getTimestampMs();
        } catch (\Throwable $e) {
            $this->warn("Could not read --since={$since}; using the stored cursor instead.");

            return null;
        }
    }
}
