<?php

namespace App\Console\Commands;

use App\Services\Exchanges\ExchangeSchema;
use App\Services\Pnl\DailyReturnStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Save every user's daily P&L percentages into `daily_returns`.
 *
 * The analytics endpoint already saves the scope it is asked for on every
 * read, so a user looking at the page is always current. This pass covers
 * everyone else — a day that closed while nobody had the page open — and
 * clears rows left behind when an account was disconnected.
 *
 * Idempotent and write-light: an unchanged day is never rewritten. One user
 * failing is logged and never stops the rest.
 */
class PnlDailyReturns extends Command
{
    protected $signature = 'pnl:daily-returns
        {--user=* : Only these uni_ids (default: everyone with an account or a saved row)}';

    protected $description = 'Save each user\'s per-day P&L percentage (the P&L calendar figure) into daily_returns';

    public function handle(DailyReturnStore $store): int
    {
        $users = $this->option('user');
        if ($users === []) {
            $ids = DB::table('daily_returns')->distinct()->pluck('uni_id');
            foreach (ExchangeSchema::supported() as $exchange) {
                $ids = $ids->concat(
                    DB::table(ExchangeSchema::for($exchange)->accountsTable)
                        ->whereNotNull('uni_id')
                        ->distinct()
                        ->pluck('uni_id')
                );
            }
            $users = $ids->unique()->values()->all();
        }

        $failed = 0;
        foreach ($users as $uniId) {
            try {
                $store->refreshUser((string) $uniId);
            } catch (Throwable $e) {
                $failed++;
                report($e);
                $this->warn("{$uniId}: {$e->getMessage()}");
            }
        }

        $this->info(sprintf('Saved daily returns for %d user(s), %d failed.', count($users) - $failed, $failed));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
