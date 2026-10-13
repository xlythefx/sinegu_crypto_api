<?php

namespace App\Console\Commands;

use App\Services\Assets\PendingSizing;
use Illuminate\Console\Command;

/**
 * Put live every waiting size change whose position has closed. The engine's
 * close and position syncs already do this the moment they land; this is the
 * once-a-minute safety net for a close that reached the database another way.
 */
class AssetsApplyPending extends Command
{
    protected $signature = 'assets:apply-pending';

    protected $description = 'Apply waiting asset size changes whose open position has closed';

    public function handle(PendingSizing $pendingSizing): int
    {
        foreach ($pendingSizing->applyDue() as $ticker) {
            $this->info("{$ticker}: the waiting size change is live");
        }

        return self::SUCCESS;
    }
}
