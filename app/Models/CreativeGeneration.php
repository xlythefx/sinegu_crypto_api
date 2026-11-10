<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Higgsfield request. The files stay at Higgsfield (until `expires_at`);
 * this row is the record: who, what was sent, the cost, the links.
 *
 * Never deleted: `charged_usd` is what the month's spend is summed from
 * (App\Services\Creatives\CreativeBudget).
 *
 * @property int $id
 * @property int|null $agent_job_id  the chat agent's job this is a step of
 * @property int|null $plan_id
 * @property int|null $character_id
 * @property string $kind image | video
 * @property string $model
 * @property string $endpoint
 * @property string $prompt
 * @property string $full_prompt
 * @property array $params
 * @property bool $used_character
 * @property string|null $request_id
 * @property string $status
 * @property string|null $error
 * @property array|null $outputs
 * @property string $estimated_usd
 * @property string $charged_usd
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon|null $last_checked_at
 * @property string|null $created_by_uni_id
 * @property string|null $created_by_name
 */
class CreativeGeneration extends Model
{
    public const SUBMITTING = 'submitting';

    public const QUEUED = 'queued';

    public const IN_PROGRESS = 'in_progress';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const NSFW = 'nsfw';

    public const CANCELED = 'canceled';

    public const EXPIRED = 'expired';

    /** Still running at Higgsfield: its charge is a reservation, not yet a fact. */
    public const PENDING = [self::SUBMITTING, self::QUEUED, self::IN_PROGRESS];

    /** Ended without a result. Higgsfield does not charge them. */
    public const REFUNDED = [self::FAILED, self::NSFW, self::CANCELED];

    protected $table = 'creative_generations';

    protected $fillable = [
        'agent_job_id', 'plan_id', 'character_id', 'kind', 'model', 'endpoint', 'prompt', 'full_prompt', 'params',
        'used_character', 'request_id', 'status', 'error', 'outputs', 'estimated_usd', 'charged_usd',
        'expires_at', 'completed_at', 'last_checked_at', 'created_by_uni_id', 'created_by_name',
    ];

    protected function casts(): array
    {
        return [
            'agent_job_id' => 'integer',
            'params' => 'array',
            'outputs' => 'array',
            'used_character' => 'boolean',
            'estimated_usd' => 'decimal:4',
            'charged_usd' => 'decimal:4',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(CreativePlan::class, 'plan_id');
    }

    /** Completed, and its files are still at Higgsfield. */
    public function hasLiveOutputs(): bool
    {
        return $this->status === self::COMPLETED
            && $this->expires_at !== null
            && $this->expires_at->isFuture()
            && ! empty($this->outputs);
    }

    /**
     * One recorded output, only while it can still be fetched.
     *
     * @return array{type: string, url: string}|null
     */
    public function liveOutput(int $index): ?array
    {
        if ($index < 0 || ! $this->hasLiveOutputs()) {
            return null;
        }
        $output = $this->outputs[$index] ?? null;

        return is_array($output) && is_string($output['url'] ?? null) && is_string($output['type'] ?? null)
            ? $output
            : null;
    }
}
