<?php

namespace App\Console\Commands;

use App\Services\Pnl\FeeRebase;
use App\Services\Pnl\TradingFee;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Re-attribute fee receipts to closed trades — the safety net behind the
 * ingest-time rebase in EngineSyncController::insertFees.
 *
 * The ingest already rebases each pair the moment its receipts land, so on a
 * healthy day this finds nothing to do. It exists for the days that are not:
 * a rebase that threw during ingest (the request still succeeded, by design),
 * receipts that reached the ledger after the close they belong to had already
 * been attributed (funding indexed late), or a code fix to the attribution
 * that should be applied to every row it now reads differently.
 *
 * `--dry-run` is the way to see WHY a trade is still estimated: every
 * unconfirmable close is listed with its reason (entry_missing,
 * entry_qty_short, non_usdt), which the row itself does not store.
 *
 * Scheduled daily in routes/console.php; safe to run by hand at any time —
 * FeeRebase is idempotent and never touches pre-cutoff, sandbox or manual rows.
 */
class FeesReconcile extends Command
{
    protected $signature = 'fees:reconcile
        {--dry-run : Report what would change, write nothing}
        {--api-key= : One account only}
        {--since= : Only closes at/after this UTC datetime (default: the net-of-fees cutoff)}';

    protected $description = 'Rebase closed trades from estimated to actual exchange fees using the receipts ledger';

    public function handle(FeeRebase $rebase): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $since = $this->option('since') ?: TradingFee::NET_SINCE;

        $pairs = FeeRebase::qualifying()
            ->where('closed_at', '>=', $since)
            ->when($this->option('api-key'), fn ($q, $key) => $q->where('api_key', $key))
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('exchange_fee_receipts as f')
                    ->whereColumn('f.api_key', 'binance_pastpositions.api_key')
                    ->whereColumn('f.symbol', 'binance_pastpositions.symbol');
            })
            ->select('api_key', 'symbol')
            ->distinct()
            ->orderBy('api_key')
            ->orderBy('symbol')
            ->get();

        if ($pairs->isEmpty()) {
            $this->info('No closed trades with receipts to reconcile.');

            return self::SUCCESS;
        }

        $totals = ['rows' => 0, 'rebased' => 0, 'unchanged' => 0, 'skipped' => 0, 'unconfirmed' => 0];
        $changes = [];
        $unconfirmed = [];
        $failed = 0;

        foreach ($pairs as $pair) {
            try {
                $report = $rebase->pair($pair->api_key, $pair->symbol, $dryRun);
            } catch (\Throwable $e) {
                report($e);
                $failed++;
                $this->error(sprintf('%s %s — FAILED: %s', $this->keyHint($pair->api_key), $pair->symbol, $e->getMessage()));

                continue;
            }

            $this->line(sprintf(
                '%s %-10s rows=%d %s=%d unchanged=%d unconfirmed=%d skipped=%d',
                $this->keyHint($pair->api_key),
                $pair->symbol,
                $report['rows'],
                $dryRun ? 'would-rebase' : 'rebased',
                $report['rebased'],
                $report['unchanged'],
                count($report['unconfirmed']),
                $report['skipped'],
            ));

            foreach (['rows', 'rebased', 'unchanged', 'skipped'] as $k) {
                $totals[$k] += $report[$k];
            }
            $totals['unconfirmed'] += count($report['unconfirmed']);

            foreach ($report['changes'] as $c) {
                $changes[] = [
                    $this->keyHint($pair->api_key),
                    $pair->symbol,
                    $c['order_id'],
                    $c['closed_at'],
                    sprintf('%.8f → %.8f', $c['fee_before'], $c['fee_after']),
                    sprintf('%.8f → %.8f', $c['pnl_before'], $c['pnl_after']),
                    $c['source_before'],
                ];
            }
            foreach ($report['unconfirmed'] as $u) {
                $unconfirmed[] = [$this->keyHint($pair->api_key), $pair->symbol, $u['order_id'], $u['reason']];
            }
        }

        if ($changes) {
            $this->newLine();
            $this->info($dryRun ? 'Would change:' : 'Changed:');
            $this->table(['account', 'symbol', 'order', 'closed at', 'fee', 'realized P&L', 'was'], $changes);
        }

        if ($unconfirmed) {
            $this->newLine();
            $this->warn('Still estimated (cannot be confirmed from the ledger):');
            $this->table(['account', 'symbol', 'order', 'reason'], $unconfirmed);
        }

        $this->newLine();
        $this->info(sprintf(
            '%d pair(s): rows=%d %s=%d unchanged=%d unconfirmed=%d skipped=%d%s',
            $pairs->count(),
            $totals['rows'],
            $dryRun ? 'would-rebase' : 'rebased',
            $totals['rebased'],
            $totals['unchanged'],
            $totals['unconfirmed'],
            $totals['skipped'],
            $failed ? " FAILED={$failed}" : '',
        ));

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** First 6 + last 4 — the same hint Admin → API Keys prints; a full key never reaches a log. */
    private function keyHint(string $apiKey): string
    {
        if (strlen($apiKey) <= 12) {
            return $apiKey;
        }

        return substr($apiKey, 0, 6).'…'.substr($apiKey, -4);
    }
}
