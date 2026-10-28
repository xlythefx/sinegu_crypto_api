<?php

namespace App\Console\Commands;

use App\Models\SiteVisit;
use App\Services\Visits\VisitRecorder;
use Illuminate\Console\Command;

/**
 * The visitor counter keeps full IP addresses for VisitRecorder::IP_DAYS (90)
 * days — the owner's decision, and the period the Privacy Policy states.
 * After that the IP and the User-Agent are removed from the row; the row
 * itself stays, so the counts, the page, the country and the device of an
 * old visit are still there. Scheduled daily.
 */
class PurgeVisitIps extends Command
{
    protected $signature = 'visits:purge-ips {--days= : Keep IP addresses this many days (default: '.VisitRecorder::IP_DAYS.')}';

    protected $description = 'Remove IP addresses and User-Agents from visits older than the retention period';

    public function handle(): int
    {
        $days = $this->option('days') === null ? VisitRecorder::IP_DAYS : (int) $this->option('days');
        if ($days < 1) {
            $this->error('--days must be at least 1.');

            return self::FAILURE;
        }

        $purged = SiteVisit::where('created_at', '<', now()->subDays($days))
            ->where(fn ($q) => $q->whereNotNull('ip')->orWhereNotNull('user_agent'))
            ->update(['ip' => null, 'user_agent' => null]);

        $this->info("Removed the IP address from {$purged} visit(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
