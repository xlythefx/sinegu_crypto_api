<?php

namespace App\Console\Commands;

use App\Models\CreativeAgentJob;
use App\Models\CreativeAgentMessage;
use App\Models\CreativeAgentSession;
use App\Models\CreativeGeneration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Admin → Creatives' chat agent keeps only the current conversation (owner,
 * 2026-11-10): a chat idle for 7 days is deleted with its messages and its
 * jobs. Nothing is lost by that — decisions live in Memory, the Brief and
 * Plans; the generations a job made (and the month's spend they carry) are
 * never deleted, they only lose their link to the job.
 *
 * A chat with a job still RUNNING is kept until the job ends, so a step is
 * never orphaned mid-way.
 */
class CreativesAgentPrune extends Command
{
    protected $signature = 'creatives:agent-prune {--days=7 : delete chats idle longer than this}';

    protected $description = 'Delete Creatives agent chats idle for more than 7 days';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days)->toDateTimeString();

        $ids = CreativeAgentSession::query()
            ->where('updated_at', '<', $cutoff)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('creative_agent_jobs')
                ->whereColumn('creative_agent_jobs.session_id', 'creative_agent_sessions.id')
                ->where('creative_agent_jobs.status', CreativeAgentJob::RUNNING))
            ->pluck('id');

        $deleted = 0;
        foreach ($ids->chunk(200) as $chunk) {
            DB::transaction(function () use ($chunk, &$deleted) {
                // Explicit, not only the FK cascade: the children go whatever the driver enforces.
                $jobIds = CreativeAgentJob::query()->whereIn('session_id', $chunk)->pluck('id');
                if ($jobIds->isNotEmpty()) {
                    CreativeGeneration::query()->whereIn('agent_job_id', $jobIds)->update(['agent_job_id' => null]);
                }
                CreativeAgentMessage::query()->whereIn('session_id', $chunk)->delete();
                CreativeAgentJob::query()->whereIn('session_id', $chunk)->delete();
                $deleted += CreativeAgentSession::query()->whereIn('id', $chunk)->delete();
            });
        }

        if ($deleted > 0) {
            $this->info("deleted {$deleted} chat(s) idle for more than {$days} days");
        }

        return self::SUCCESS;
    }
}
