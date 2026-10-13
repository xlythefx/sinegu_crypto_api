<?php

namespace App\Console\Commands;

use App\Services\Exchanges\ExchangeSchema;
use App\Services\Positions\CloseSource;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Mark each closed trade as closed by the bot or outside it (CloseSource).
 * Scheduled every five minutes over the last few days; run once with a wider
 * --days after deploying to judge the history the signal log covers.
 */
class PositionsClassifyCloses extends Command
{
    protected $signature = 'positions:classify-closes
        {--days=3 : How far back to look for closes not judged yet}
        {--dry-run : Write nothing; list what would be marked outside the bot}';

    protected $description = 'Mark closed trades as closed by the bot or outside it (customer by hand, own TP/SL, liquidation)';

    public function handle(CloseSource $closeSource): int
    {
        $since = Carbon::now('UTC')->subDays(max(1, (int) $this->option('days')));
        $dryRun = (bool) $this->option('dry-run');
        $failed = 0;

        foreach (ExchangeSchema::supported() as $exchange) {
            try {
                $counts = $closeSource->classify($exchange, $since->copy(), $dryRun);
                if ($counts['bot'] + $counts['outside'] > 0) {
                    $verb = $dryRun ? ' would be' : '';
                    $this->info("{$exchange}: {$counts['bot']}{$verb} closed by the bot, {$counts['outside']}{$verb} outside it");
                }
                if ($dryRun && $counts['outside_ids'] !== []) {
                    $this->line("{$exchange} outside ids: ".implode(',', $counts['outside_ids']));
                }
            } catch (Throwable $e) {
                $failed++;
                $this->error("{$exchange}: {$e->getMessage()}");
                report($e);
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
