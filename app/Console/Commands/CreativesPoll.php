<?php

namespace App\Console\Commands;

use App\Services\Creatives\CreativeAgentJobs;
use App\Services\Creatives\GenerationPoller;
use Throwable;
use Illuminate\Console\Command;

/**
 * Admin → Creatives, once a minute: ask Higgsfield about the generations
 * still running (it pushes nothing), mark finished ones whose files have
 * been deleted as expired, and record a submit that never came back as
 * unconfirmed. Then move the chat agent's running jobs on (start the next
 * step once the previous one completed). With nothing running, it sends no
 * request at all.
 */
class CreativesPoll extends Command
{
    protected $signature = 'creatives:poll {--limit=50 : running generations to check per run}';

    protected $description = 'Refresh running Higgsfield generations and expire finished ones';

    /** The generations page refreshes rows itself; one checked this recently is left alone. */
    private const STALE_SECONDS = 20;

    public function handle(GenerationPoller $poller, CreativeAgentJobs $jobs): int
    {
        $expired = $poller->expireFinished();
        $lost = $poller->failStaleSubmitting();
        $checked = $poller->refreshPending(max(1, (int) $this->option('limit')), self::STALE_SECONDS);

        // After the refresh, so a step that just completed starts the next one now.
        try {
            $advanced = $jobs->advanceRunning();
        } catch (Throwable $e) {
            report($e);
            $advanced = 0;
        }

        if ($checked + $expired + $lost + $advanced > 0) {
            $this->info("checked {$checked}, expired {$expired}, unconfirmed {$lost}, jobs {$advanced}");
        }

        return self::SUCCESS;
    }
}
