<?php

namespace App\Services\Creatives;

use App\Models\CreativeAgentJob;
use App\Models\CreativeGeneration;
use App\Models\CreativeVideo;
use App\Models\CreativeVideoScene;
use Illuminate\Support\Collection;

/**
 * The JSON shapes of the video editor: CreativeVideo, CreativeVideoScene and
 * CreativeVideoSummary. A scene's clip is the generation in the
 * {@see CreativePresenter} shape (its `outputs[].url` and signed
 * `download_path`), and a scene's status is DERIVED on read: a template is
 * always ready, a clip whose file Higgsfield has deleted is `expired`.
 */
final class CreativeVideoPresenter
{
    public function __construct(
        private CreativePresenter $creatives,
        private CreativeAgentPresenter $agent,
        private CreativeVideoHistory $history,
    ) {}

    public function video(CreativeVideo $video): array
    {
        $video->loadMissing(['plan:id,title', 'style:id,slug,name']);
        $scenes = $video->scenes()->get();
        $generations = $this->generations($scenes);
        $jobs = $video->jobs()->get();
        // A running scene carries its progress (the editor's timer).
        $byId = $jobs->keyBy('id');
        $stepGenerations = $this->history->generationsOf($jobs->where('status', CreativeAgentJob::RUNNING));
        $presented = $scenes->map(fn (CreativeVideoScene $s) => $this->scene(
            $s,
            $generations->get((int) $s->generation_id),
            $this->history->progress($s, $byId, $stepGenerations),
        ))->values()->all();

        return [
            'id' => (int) $video->id,
            'title' => $video->title,
            'plan_id' => $video->plan_id !== null ? (int) $video->plan_id : null,
            'plan_title' => $video->plan?->title,
            'style' => $video->style !== null
                ? ['id' => (int) $video->style->id, 'slug' => $video->style->slug, 'name' => $video->style->name]
                : null,
            'platform' => $video->platform,
            'aspect' => $video->aspect,
            'max_seconds' => (int) $video->max_seconds,
            'fps' => (int) $video->fps,
            'timeline' => (object) (is_array($video->timeline) ? $video->timeline : []),
            'status' => $video->status,
            'exported_at' => $video->exported_at?->toIso8601String(),
            'exported_by_name' => $video->exported_by_name,
            'export_seconds' => $video->export_seconds !== null ? (float) $video->export_seconds : null,
            'export_bytes' => $video->export_bytes !== null ? (int) $video->export_bytes : null,
            'created_by_name' => $video->created_by_name,
            'updated_by_name' => $video->updated_by_name,
            'created_at' => $video->created_at?->toIso8601String(),
            'updated_at' => $video->updated_at?->toIso8601String(),
            'duration_ms' => (int) array_sum(array_column($presented, 'duration_ms')),
            'scenes' => $presented,
            'jobs' => $this->agent->jobs($jobs),
        ];
    }

    /** The list's row. */
    public function summary(CreativeVideo $video): array
    {
        $scenes = $video->scenes()->get();
        $generations = $this->generations($scenes);
        $statuses = $scenes->map(fn (CreativeVideoScene $s) => $this->status($s, $generations->get((int) $s->generation_id)));

        return [
            'id' => (int) $video->id,
            'title' => $video->title,
            'platform' => $video->platform,
            'aspect' => $video->aspect,
            'style_name' => $video->style?->name,
            'status' => $video->status,
            'scenes_total' => $scenes->count(),
            'scenes_ready' => $statuses->filter(fn ($s) => $s === CreativeVideoScene::READY)->count(),
            'scenes_generating' => $statuses->filter(fn ($s) => in_array($s, [CreativeVideoScene::PLANNED, CreativeVideoScene::GENERATING], true))->count(),
            // Who pressed Go on the job making clips right now (the team is told, never blocked).
            'generating_by' => $video->jobs()->where('status', CreativeAgentJob::RUNNING)->orderByDesc('id')->value('approved_by_name'),
            'duration_ms' => (int) $scenes->sum(fn (CreativeVideoScene $s) => $s->durationMs()),
            'created_by_name' => $video->created_by_name,
            'updated_at' => $video->updated_at?->toIso8601String(),
        ];
    }

    public function scene(CreativeVideoScene $scene, ?CreativeGeneration $generation, ?array $progress = null): array
    {
        return [
            'id' => (int) $scene->id,
            'position' => (int) $scene->position,
            'kind' => $scene->kind,
            'label' => $scene->label,
            'prompt' => $scene->prompt,
            'engine' => $scene->engine,
            'engine_label' => $scene->engine !== null ? (CreativeVideos::ENGINE_LABELS[$scene->engine] ?? $scene->engine) : null,
            'with_character' => (bool) $scene->with_character,
            'seconds' => (int) $scene->seconds,
            'template' => is_array($scene->template) ? $scene->template : null,
            'generation' => $generation !== null ? $this->creatives->generation($generation) : null,
            'output_index' => (int) $scene->output_index,
            'agent_job_id' => $scene->agent_job_id !== null ? (int) $scene->agent_job_id : null,
            'step_index' => $scene->step_index !== null ? (int) $scene->step_index : null,
            'trim_in_ms' => $scene->trim_in_ms !== null ? (int) $scene->trim_in_ms : null,
            'trim_out_ms' => $scene->trim_out_ms !== null ? (int) $scene->trim_out_ms : null,
            'duration_ms' => $scene->durationMs(),
            'status' => $this->status($scene, $generation),
            'error' => $scene->error,
            // {started_at, step, steps, step_label, waiting, typical_seconds} while a job makes it.
            'progress' => $progress,
        ];
    }

    /** The derived status: template = ready; a clip whose file is gone = expired. */
    public function status(CreativeVideoScene $scene, ?CreativeGeneration $generation): string
    {
        if ($scene->kind === CreativeVideoScene::TEMPLATE) {
            return CreativeVideoScene::READY;
        }
        if ($generation !== null && $scene->status === CreativeVideoScene::READY
            && ($generation->status === CreativeGeneration::EXPIRED || $generation->liveOutput((int) $scene->output_index) === null)) {
            return CreativeVideoScene::EXPIRED;
        }

        return $scene->status;
    }

    /**
     * @param  Collection<int, CreativeVideoScene>  $scenes
     * @return Collection<int, CreativeGeneration> keyed by id
     */
    private function generations(Collection $scenes): Collection
    {
        $ids = $scenes->pluck('generation_id')->filter()->unique()->values();

        return $ids->isEmpty()
            ? collect()
            : CreativeGeneration::query()->with('plan:id,title')->whereIn('id', $ids)->get()->keyBy('id');
    }
}
