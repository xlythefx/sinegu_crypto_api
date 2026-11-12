<?php

namespace App\Services\Creatives;

use App\Models\CreativeAgentJob;
use App\Models\CreativeAgentMessage;
use App\Models\CreativeGeneration;
use App\Models\CreativeMemory;
use App\Models\CreativeSetting;
use App\Models\CreativeVideo;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * The chat agent's JSON shapes (DisplayMessage, Job, Memory, Brief).
 *
 * A stored message can show as several items (an assistant's text, then one
 * activity line per tool call), so a DisplayMessage id is the row id × 1000
 * plus the item's position — stable, numeric and in order.
 */
final class CreativeAgentPresenter
{
    private const ITEM_SLOTS = 1000;

    public function __construct(private CreativePresenter $creatives, private CreativeModels $models) {}

    /**
     * @param  iterable<CreativeAgentMessage>  $rows
     * @return list<array<string, mixed>>
     */
    public function messages(iterable $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            foreach (array_values((array) ($row->display ?? [])) as $i => $item) {
                if (! is_array($item) || ! isset($item['kind'])) {
                    continue;
                }
                $out[] = array_filter([
                    'id' => (int) $row->id * self::ITEM_SLOTS + $i,
                    'kind' => (string) $item['kind'],
                    'text' => $item['text'] ?? null,
                    'activity' => $item['activity'] ?? null,
                    'job_id' => isset($item['job_id']) ? (int) $item['job_id'] : null,
                    'plan_id' => isset($item['plan_id']) ? (int) $item['plan_id'] : null,
                    'video_id' => isset($item['video_id']) ? (int) $item['video_id'] : null,
                    // ask_choices: {question, options: [{label, value}], allow_other}
                    'choices' => is_array($item['choices'] ?? null) ? $item['choices'] : null,
                    'mode' => $row->mode,
                    'created_at' => $row->created_at?->toIso8601String(),
                ], fn ($v) => $v !== null);
            }
        }

        return $out;
    }

    /**
     * @param  iterable<CreativeAgentJob>  $jobs
     * @return list<array<string, mixed>>
     */
    public function jobs(iterable $jobs): array
    {
        // An Eloquent collection, so the plans load in one query.
        $jobs = new EloquentCollection($jobs instanceof Collection ? $jobs->all() : (is_array($jobs) ? $jobs : iterator_to_array($jobs)));
        if ($jobs->isEmpty()) {
            return [];
        }
        $jobs->loadMissing('plan:id,title');
        $ids = $jobs->flatMap(fn (CreativeAgentJob $j) => array_filter(array_column((array) $j->steps, 'generation_id')))->unique()->values();
        $generations = CreativeGeneration::query()->with('plan:id,title')->whereIn('id', $ids)->get()->keyBy('id');

        return $jobs->map(fn (CreativeAgentJob $j) => $this->job($j, $generations))->values()->all();
    }

    /** @param  Collection<int, CreativeGeneration>|null  $generations  preloaded, keyed by id */
    public function job(CreativeAgentJob $job, ?Collection $generations = null): array
    {
        $status = $job->isStale() ? CreativeAgentJob::EXPIRED : $job->status;
        $finished = in_array($status, CreativeAgentJob::FINISHED, true);

        $steps = [];
        foreach ((array) $job->steps as $step) {
            $generation = null;
            if (! empty($step['generation_id'])) {
                $generation = $generations?->get((int) $step['generation_id'])
                    ?? CreativeGeneration::with('plan:id,title')->find((int) $step['generation_id']);
            }
            $stepStatus = (string) ($step['status'] ?? 'waiting');
            if ($generation !== null) {
                // The generation is the truth about a started step.
                $stepStatus = match ($generation->status) {
                    CreativeGeneration::COMPLETED, CreativeGeneration::EXPIRED => 'completed',
                    CreativeGeneration::FAILED, CreativeGeneration::NSFW => 'failed',
                    CreativeGeneration::CANCELED => 'canceled',
                    default => 'running',
                };
            } elseif ($finished && $stepStatus === 'waiting') {
                $stepStatus = 'canceled';
            }

            $model = (string) ($step['model'] ?? '');
            $steps[] = [
                'label' => (string) ($step['label'] ?? $this->models->label($model)),
                'model' => $model,
                'model_label' => $this->models->label($model),
                'usd' => round((float) ($step['usd'] ?? 0), 4),
                'status' => $stepStatus,
                'generation' => $generation !== null ? $this->creatives->generation($generation) : null,
                'error' => $step['error'] ?? ($stepStatus === 'failed' ? $generation?->error : null),
            ];
        }

        return [
            'id' => (int) $job->id,
            'title' => $job->title,
            'status' => $status,
            'plan_id' => $job->plan_id !== null ? (int) $job->plan_id : null,
            'plan_title' => $job->plan?->title,
            // The video project the job fills (editor jobs, create_video), and its editor page.
            'video_id' => $job->video_id !== null ? (int) $job->video_id : null,
            'editor_path' => $job->video_id !== null ? CreativeVideo::editorPath((int) $job->video_id) : null,
            'estimated_usd' => round((float) $job->estimated_usd, 4),
            'steps' => $steps,
            'approved_by_name' => $job->approved_by_name,
            'created_at' => $job->created_at?->toIso8601String(),
            'expires_at' => $status === CreativeAgentJob::PROPOSED ? $job->expiresAt()?->toIso8601String() : null,
        ];
    }

    public function memory(CreativeMemory $memory): array
    {
        return [
            'id' => (int) $memory->id,
            'text' => $memory->text,
            'category' => $memory->category,
            'source' => $memory->source,
            'created_by_name' => $memory->created_by_name,
            'updated_at' => $memory->updated_at?->toIso8601String(),
        ];
    }

    public function brief(CreativeSetting $setting): array
    {
        return [
            'markdown' => (string) ($setting->brief_markdown ?? ''),
            'updated_by_name' => $setting->brief_updated_by_name,
            'updated_at' => $setting->brief_updated_at?->toIso8601String(),
        ];
    }

    /** The video playbook: the Brief shape. */
    public function playbook(CreativeSetting $setting): array
    {
        return [
            'markdown' => (string) ($setting->playbook_markdown ?? ''),
            'updated_by_name' => $setting->playbook_updated_by_name,
            'updated_at' => $setting->playbook_updated_at?->toIso8601String(),
        ];
    }
}
