<?php

namespace App\Services\Creatives;

use App\Models\CreativeCharacter;
use App\Models\CreativeCharacterRef;
use App\Models\CreativeGeneration;
use App\Models\CreativePlan;
use Illuminate\Support\Facades\URL;

/**
 * The JSON shapes of the Creatives API (Plan, Character, CharacterRef,
 * Generation). Files are reached through SIGNED RELATIVE paths — an
 * `<img src>` or a download link cannot send a Bearer token — which the
 * frontend prefixes with the API's origin.
 */
final class CreativePresenter
{
    /** How long a reference image's signed path works. */
    public const REF_LINK_MINUTES = 120;

    /** How long a download's signed path works. */
    public const DOWNLOAD_LINK_MINUTES = 30;

    public function __construct(private CreativeModels $models) {}

    public function plan(CreativePlan $plan): array
    {
        return [
            'id' => (int) $plan->id,
            'title' => $plan->title,
            'status' => $plan->status,
            'platform' => $plan->platform,
            'format' => $plan->format,
            'planned_for' => $plan->plannedFor(),
            'hook' => $plan->hook,
            'script' => $plan->script,
            'caption' => $plan->caption,
            'hashtags' => $plan->hashtags,
            'notes' => $plan->notes,
            // The Studio prompt the AI writer wrote (editable).
            'video_prompt' => $plan->video_prompt,
            'posted_url' => $plan->posted_url,
            // Set when AI-drafted fields were saved on this plan.
            'ai_drafted_at' => $plan->ai_drafted_at?->toIso8601String(),
            'generations_count' => (int) ($plan->generations_count ?? $plan->generations()->count()),
            'created_by_name' => $plan->created_by_name,
            'updated_by_name' => $plan->updated_by_name,
            'created_at' => $plan->created_at?->toIso8601String(),
            'updated_at' => $plan->updated_at?->toIso8601String(),
        ];
    }

    public function character(CreativeCharacter $character): array
    {
        return [
            'id' => (int) $character->id,
            'name' => $character->name,
            'look' => $character->look,
            'style_prompt' => $character->style_prompt,
            'rules' => $character->rules,
            'refs' => $character->refs()->get()->map(fn (CreativeCharacterRef $r) => $this->ref($r))->values()->all(),
            'updated_by_name' => $character->updated_by_name,
            'updated_at' => $character->updated_at?->toIso8601String(),
        ];
    }

    public function ref(CreativeCharacterRef $ref): array
    {
        return [
            'id' => (int) $ref->id,
            'label' => $ref->label,
            'source' => $ref->source,
            'mime' => $ref->mime,
            'bytes' => (int) $ref->bytes,
            'image_path' => URL::temporarySignedRoute(
                'creatives.refs.image',
                now()->addMinutes(self::REF_LINK_MINUTES),
                ['id' => $ref->id],
                absolute: false,
            ),
            'created_at' => $ref->created_at?->toIso8601String(),
        ];
    }

    public function generation(CreativeGeneration $g): array
    {
        $outputs = [];
        if ($g->hasLiveOutputs()) {
            foreach (array_values($g->outputs) as $i => $output) {
                if (! is_array($output) || ! is_string($output['url'] ?? null)) {
                    continue;
                }
                $outputs[] = [
                    'type' => $output['type'] === 'video' ? 'video' : 'image',
                    'url' => $output['url'],
                    'download_path' => URL::temporarySignedRoute(
                        'creatives.generations.download',
                        now()->addMinutes(self::DOWNLOAD_LINK_MINUTES),
                        ['id' => $g->id, 'index' => $i],
                        absolute: false,
                    ),
                ];
            }
        }

        return [
            'id' => (int) $g->id,
            'plan_id' => $g->plan_id !== null ? (int) $g->plan_id : null,
            'plan_title' => $g->plan?->title,
            'kind' => $g->kind,
            'model' => $g->model,
            'model_label' => $this->models->label($g->model),
            'prompt' => $g->prompt,
            // Not in the original contract: what was actually sent, so the page can show it.
            'full_prompt' => $g->full_prompt,
            'params' => (object) ($g->params ?? []),
            'used_character' => (bool) $g->used_character,
            'status' => $g->status,
            'error' => $g->error,
            'outputs' => $outputs,
            'estimated_usd' => round((float) $g->estimated_usd, 4),
            'charged_usd' => round((float) $g->charged_usd, 4),
            'expires_at' => $g->expires_at?->toIso8601String(),
            'completed_at' => $g->completed_at?->toIso8601String(),
            'created_at' => $g->created_at?->toIso8601String(),
            'created_by_name' => $g->created_by_name,
        ];
    }
}
