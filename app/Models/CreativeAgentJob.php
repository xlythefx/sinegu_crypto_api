<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A job the Creatives agent PROPOSED in Generate mode: one or more priced
 * Higgsfield steps, started only when a person clicks Go — the agent never
 * spends by itself. Each step becomes a normal generation
 * (creative_generations.agent_job_id), which reserves its own price against
 * the monthly cap as every generation does.
 *
 * `steps`: each {model, label, prompt, full_prompt?, params, use_character,
 * source, usd, generation_id, status, error, scene_id?}; status waiting |
 * running | completed | failed | canceled. `scene_id` sits on the step whose
 * output IS a video scene's clip (the animation, not its still).
 *
 * A job proposed by the video editor has no chat (`session_id` NULL): any
 * staff member may Go it, and `owner_uni_id` says whose spend its later
 * steps are.
 *
 * @property int $id
 * @property string|null $session_id
 * @property int|null $plan_id
 * @property int|null $video_id
 * @property string|null $owner_uni_id
 * @property string $title
 * @property string $status
 * @property array $steps
 * @property string $estimated_usd
 * @property string|null $approved_by_name
 * @property Carbon|null $approved_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 */
class CreativeAgentJob extends Model
{
    public const PROPOSED = 'proposed';

    public const RUNNING = 'running';

    public const COMPLETED = 'completed';

    /** Every step ended, and some failed: the clips that worked still landed. */
    public const COMPLETED_WITH_FAILURES = 'completed_with_failures';

    public const FAILED = 'failed';

    public const CANCELED = 'canceled';

    public const EXPIRED = 'expired';

    /** A job in one of these will never change again. */
    public const FINISHED = [self::COMPLETED, self::COMPLETED_WITH_FAILURES, self::FAILED, self::CANCELED, self::EXPIRED];

    /** A proposal not approved within this many minutes expires. */
    public const EXPIRES_MINUTES = 60;

    protected $table = 'creative_agent_jobs';

    protected $fillable = [
        'session_id', 'plan_id', 'video_id', 'owner_uni_id', 'title', 'status', 'steps', 'estimated_usd',
        'approved_by_name', 'approved_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'plan_id' => 'integer',
            'video_id' => 'integer',
            'steps' => 'array',
            'estimated_usd' => 'decimal:4',
            'approved_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CreativeAgentSession::class, 'session_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(CreativePlan::class, 'plan_id');
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(CreativeVideo::class, 'video_id');
    }

    /** When a proposal stops being approvable. */
    public function expiresAt(): ?Carbon
    {
        return $this->created_at?->copy()->addMinutes(self::EXPIRES_MINUTES);
    }

    /** Still `proposed`, but its hour to be approved is over. */
    public function isStale(): bool
    {
        $at = $this->expiresAt();

        return $this->status === self::PROPOSED && $at !== null && ! $at->isFuture();
    }
}
