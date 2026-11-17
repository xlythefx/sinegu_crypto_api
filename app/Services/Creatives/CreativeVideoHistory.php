<?php

namespace App\Services\Creatives;

use App\Models\CreativeAgentJob;
use App\Models\CreativeGeneration;
use App\Models\CreativeVideo;
use App\Models\CreativeVideoScene;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The video editor's History (2026-11-17, "what if a video generation
 * failed"): every attempt made for a video's scenes — each STEP of each job
 * that got past Go — with when it started and ended, how long it took, what
 * came of it and why, what it cost and who started it. Nothing new is
 * recorded for it: the jobs' steps and their generations already hold it, so
 * a retry no longer wipes the reason the previous attempt failed.
 *
 * Also the running scene's progress for the editor's timer ("Rendering
 * 2 m 10 s · usually about 3 min"), from the same steps.
 */
final class CreativeVideoHistory
{
    /** Jobs read per video (a video has a handful; this only bounds a runaway). */
    private const MAX_JOBS = 100;

    public function __construct(
        private CreativeAgentJobs $jobs,
        private CreativeModels $models,
        private CreativeTimings $timings,
    ) {}

    /** @return list<array<string, mixed>> newest first */
    public function forVideo(CreativeVideo $video): array
    {
        $jobs = CreativeAgentJob::query()
            ->where('video_id', $video->id)
            ->whereNotIn('status', [CreativeAgentJob::PROPOSED, CreativeAgentJob::EXPIRED])
            ->orderByDesc('id')
            ->limit(self::MAX_JOBS)
            ->get();
        $scenes = CreativeVideoScene::query()->where('video_id', $video->id)->get()->keyBy('id');
        $generations = $this->generationsOf($jobs);

        $entries = [];
        foreach ($jobs as $job) {
            $steps = is_array($job->steps) ? $job->steps : [];
            foreach ($steps as $i => $step) {
                $entry = $this->entry($job, $steps, (int) $i, $scenes, $generations);
                if ($entry !== null) {
                    $entries[] = $entry;
                }
            }
        }

        // Newest attempt first; inside one job its steps in order (a step not
        // started yet sorts by when its job was approved).
        usort($entries, fn (array $a, array $b) => [$b['sort_at'], $b['job_id'], $a['step_index']]
            <=> [$a['sort_at'], $a['job_id'], $b['step_index']]);

        return array_map(function (array $e) {
            unset($e['sort_at']);

            return $e;
        }, $entries);
    }

    /**
     * A scene being made: when it started, which step it is on, and how long
     * that usually takes. Null when the scene is not being made by a job.
     *
     * @param  Collection<int, CreativeAgentJob>  $jobs  keyed by id
     * @param  Collection<int, CreativeGeneration>  $generations  keyed by id
     */
    public function progress(CreativeVideoScene $scene, Collection $jobs, Collection $generations): ?array
    {
        if (! in_array($scene->status, [CreativeVideoScene::PLANNED, CreativeVideoScene::GENERATING], true) || $scene->agent_job_id === null) {
            return null;
        }
        $job = $jobs->get((int) $scene->agent_job_id);
        if ($job === null || $job->status !== CreativeAgentJob::RUNNING) {
            return null;
        }
        $steps = is_array($job->steps) ? $job->steps : [];
        $chain = [];
        foreach (array_keys($steps) as $i) {
            if ($this->jobs->sceneOfStep($steps, (int) $i) === (int) $scene->id) {
                $chain[] = (int) $i;
            }
        }
        if ($chain === []) {
            return null;
        }

        $current = null;
        foreach ($chain as $n => $i) {
            if (($steps[$i]['status'] ?? 'waiting') !== 'completed') {
                $current = $n;
                break;
            }
        }
        $current ??= count($chain) - 1;
        $first = $steps[$chain[0]];
        $startedAt = $this->startedAt($first, $generations->get((int) ($first['generation_id'] ?? 0)));
        $models = array_map(fn (int $i) => (string) ($steps[$i]['model'] ?? ''), $chain);

        return [
            'started_at' => $startedAt,
            'step' => $current + 1,
            'steps' => count($chain),
            'step_label' => $this->role((string) ($steps[$chain[$current]]['model'] ?? ''), count($chain) > 1),
            'waiting' => ($steps[$chain[$current]]['status'] ?? 'waiting') === 'waiting',
            'typical_seconds' => $this->timings->typicalChain($models),
        ];
    }

    /**
     * Every generation the given jobs' steps point at, keyed by id.
     *
     * @param  Collection<int, CreativeAgentJob>  $jobs
     * @return Collection<int, CreativeGeneration>
     */
    public function generationsOf(Collection $jobs): Collection
    {
        $ids = [];
        foreach ($jobs as $job) {
            foreach ((array) $job->steps as $step) {
                if (! empty($step['generation_id'])) {
                    $ids[] = (int) $step['generation_id'];
                }
            }
        }

        return $ids === [] ? collect() : CreativeGeneration::query()->whereIn('id', array_unique($ids))->get()->keyBy('id');
    }

    /**
     * @param  Collection<int, CreativeVideoScene>  $scenes
     * @param  Collection<int, CreativeGeneration>  $generations
     */
    private function entry(CreativeAgentJob $job, array $steps, int $i, Collection $scenes, Collection $generations): ?array
    {
        $step = $steps[$i];
        $status = (string) ($step['status'] ?? 'waiting');
        // A step that never ran is history only while its job still might run it.
        if ($status === 'waiting' && $job->status !== CreativeAgentJob::RUNNING) {
            return null;
        }
        $generation = $generations->get((int) ($step['generation_id'] ?? 0));
        if ($status === 'canceled' && $generation === null) {
            // Never started (its still failed first, or the job was stopped).
            $status = 'skipped';
        }

        $sceneId = $this->jobs->sceneOfStep($steps, $i);
        $scene = $sceneId !== null ? $scenes->get($sceneId) : null;
        $model = (string) ($step['model'] ?? '');
        $startedAt = $this->startedAt($step, $generation);
        $endedAt = $this->endedAt($step, $generation, $status);
        $isChain = $sceneId !== null && count(array_filter(array_keys($steps), fn ($k) => $this->jobs->sceneOfStep($steps, (int) $k) === $sceneId)) > 1;

        return [
            'id' => $job->id.':'.$i,
            'job_id' => (int) $job->id,
            'job_title' => $job->title,
            'step_index' => $i,
            'scene_id' => $sceneId,
            // The scene may have been deleted or replaced since: the step's own label then.
            'scene_label' => $scene?->label ?? ($step['label'] ?? null),
            'scene_position' => $scene !== null ? (int) $scene->position : null,
            'role' => $this->role($model, $isChain),
            'model' => $model,
            'model_label' => $this->models->has($model) ? $this->models->label($model) : $model,
            'status' => match ($status) {
                'completed' => 'made',
                'running', 'waiting', 'failed', 'canceled', 'skipped' => $status,
                default => 'failed',
            },
            'error' => $status === 'completed' ? null : ($step['error'] ?? $generation?->error),
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'seconds' => CreativeTimings::seconds($startedAt, $endedAt),
            // What it cost: the generation's charge (0 for a refused one); an
            // attempt that never started cost nothing.
            'usd' => $generation !== null ? round((float) $generation->charged_usd, 4) : 0.0,
            'started_by' => $generation?->created_by_name ?? $job->approved_by_name,
            'approved_by' => $job->approved_by_name,
            'generation_id' => $generation !== null ? (int) $generation->id : null,
            'sort_at' => Carbon::parse($startedAt ?? $job->approved_at ?? $job->created_at)->getTimestamp(),
        ];
    }

    private function startedAt(array $step, ?CreativeGeneration $generation): ?string
    {
        return $step['started_at'] ?? $generation?->created_at?->toIso8601String();
    }

    private function endedAt(array $step, ?CreativeGeneration $generation, string $status): ?string
    {
        if (in_array($status, ['running', 'waiting'], true)) {
            return null;
        }
        if (! empty($step['ended_at'])) {
            return $step['ended_at'];
        }
        if ($generation === null) {
            return null;
        }

        // Older steps: a made one ended when we saw it done; a refused one when
        // its row last changed.
        return ($generation->completed_at ?? $generation->updated_at)?->toIso8601String();
    }

    /** "Still image" / "Animation" for a still → animation chain, else what the model makes. */
    private function role(string $model, bool $chain): string
    {
        $kind = $this->models->get($model)['kind'] ?? null;
        if ($kind === 'image') {
            return $chain ? 'Still image' : 'Image';
        }

        return $chain ? 'Animation' : 'Clip';
    }
}

