<?php

namespace App\Console\Commands;

use App\Services\Creatives\GenerationPoller;
use Illuminate\Console\Command;

/**
 * Admin → Creatives, once a minute: ask Higgsfield about the generations
 * still running (it pushes nothing), mark finished ones whose files have
 * been deleted as expired, and record a submit that never came back as
 * unconfirmed. With nothing running, it sends no request at all.
 */
class CreativesPoll extends Command
{
    protected $signature = 'creatives:poll {--limit=50 : running generations to check per run}';

    protected $description = 'Refresh running Higgsfield generations and expire finished ones';

    /** The generations page refreshes rows itself; one checked this recently is left alone. */
    private const STALE_SECONDS = 20;

    public function handle(GenerationPoller $poller): int
    {
        $expired = $poller->expireFinished();
        $lost = $poller->failStaleSubmitting();
        $checked = $poller->refreshPending(max(1, (int) $this->option('limit')), self::STALE_SECONDS);

        if ($checked + $expired + $lost > 0) {
            $this->info("checked {$checked}, expired {$expired}, unconfirmed {$lost}");
        }

        return self::SUCCESS;
    }
}
