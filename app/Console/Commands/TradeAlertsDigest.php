<?php

namespace App\Console\Commands;

use App\Services\Notifications\TeamNotices;
use App\Mail\TradeFailureSummary;
use App\Services\Admin\TradeFailureDigest;
use App\Services\Notifications\TradeAlerts;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The hourly failed-trade summary (TradeAlerts). Scheduled at :02 for the
 * hour that just ended (UTC); sends nothing when the hour was clean, and
 * never the same hour twice (a re-run, or the scheduler catching up).
 */
class TradeAlertsDigest extends Command
{
    protected $signature = 'trade-alerts:digest
        {--hour= : The hour to summarise, "Y-m-d H" in UTC (default: the hour that just ended)}
        {--dry-run : Print what would be sent; send nothing}
        {--force : Send even if this hour was already sent}';

    protected $description = 'Email the hourly summary of failed, shrunk and rejected trades (only when there is one)';

    public function handle(TradeFailureDigest $digest): int
    {
        $from = $this->option('hour')
            ? CarbonImmutable::createFromFormat('!Y-m-d H', (string) $this->option('hour'), 'UTC')
            : CarbonImmutable::now('UTC')->startOfHour()->subHour();
        if (! $from) {
            $this->error('--hour must look like 2026-11-12 09');

            return self::FAILURE;
        }
        $to = $from->addHour();
        $data = $digest->build($from, $to);
        $c = $data['counts'];
        $this->line(sprintf('%s to %s UTC: failed %d, fixed by retry %d, shrunk %d, at limit %d, rejected %d, other %d',
            $from->format('Y-m-d H:i'), $to->format('H:i'), $c['failed'], $c['resolved'], $c['shrunk'], $c['limit'], $c['rejected'], $c['other_problems']));

        if (! TradeFailureDigest::hasNews($data)) {
            $this->line('Nothing to report: no email.');

            return self::SUCCESS;
        }
        $recipients = TeamNotices::recipients('trade-failure-summary');
        if ($recipients === []) {
            $this->line('No recipients (switched off on Admin → Notifications, or TRADE_ALERT_EMAILS empty): no email.');

            return self::SUCCESS;
        }
        if ($this->option('dry-run')) {
            $this->line('Dry run: would email '.count($recipients).' recipient(s) about '.count($data['signals']).' signal(s).');

            return self::SUCCESS;
        }
        $key = 'trade-alerts:digest:'.$from->format('Y-m-d-H');
        if (! $this->option('force') && ! Cache::add($key, true, now()->addDays(2))) {
            $this->line('Already sent for this hour.');

            return self::SUCCESS;
        }

        $bkk = fn (CarbonImmutable $t) => $t->setTimezone('Asia/Bangkok')->format('H:i');
        $window = $from->format('j M').', '.$from->format('H:i').'–'.$to->format('H:i').' UTC ('.$bkk($from).'–'.$bkk($to).' Bangkok)';
        try {
            Mail::to($recipients)->send(new TradeFailureSummary($window, $data['signals'], $c));
            $this->info('Sent.');
        } catch (Throwable $e) {
            Cache::forget($key);
            $this->error('Could not send: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
