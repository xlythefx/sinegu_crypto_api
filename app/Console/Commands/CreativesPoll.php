<?php

namespace App\Console\Commands;

use App\Models\CreativeAgentJob;
use App\Models\CreativeGeneration;
use App\Services\Creatives\CreativeAgentJobs;
use App\Services\Creatives\GenerationPoller;
use Illuminate\Console\Command;
use Throwable;

/**
 * Admin → Creatives: ask Higgsfield about the generations still running (it
 * pushes nothing), mark finished ones whose files have been deleted as
 * expired, and record a submit that never came back as unconfirmed. Then move
 * the running jobs on (start a scene's animation once its still is made).
 * With nothing running, it sends no request at all.
 *
 * `--for=N` keeps ONE process checking every `--every` seconds for up to N
 * seconds while something is still running, and exits as soon as nothing is.
 * The scheduler starts it on odd minutes only (the CPU spread, 2026-11-12),
 * so this is how a video made while nobody has the editor open still moves on
 * every ~30 s instead of every 2 minutes — without booting PHP more often.
 */
class CreativesPoll extends Command
{
    protected $signature = 'creatives:poll
        {--limit=50 : running generations to check per pass}
        {--for=0 : keep checking for up to this many seconds while something is running}
        {--every=30 : seconds between passes when --for is set}';

    protected $description = 'Refresh running Higgsfield generations and expire finished ones';

    /** The generations page refreshes rows itself; one checked this recently is left alone. */
    private const STALE_SECONDS = 20;

    public function handle(GenerationPoller $poller, CreativeAgentJobs $jobs): int
    {
        $until = microtime(true) + max(0, (int) $this->option('for'));
        $every = max(5, (int) $this->option('every'));

        while (true) {
            $this->pass($poller, $jobs);
            if (microtime(true) + $every > $until || ! self::somethingRunning()) {
                break;
            }
            sleep($every);
        }

        return self::SUCCESS;
    }

    private function pass(GenerationPoller $poller, CreativeAgentJobs $jobs): void
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
    }

    private static function somethingRunning(): bool
    {
        return CreativeAgentJob::query()->where('status', CreativeAgentJob::RUNNING)->exists()
            || CreativeGeneration::query()->whereIn('status', [CreativeGeneration::QUEUED, CreativeGeneration::IN_PROGRESS])->exists();
    }
}
