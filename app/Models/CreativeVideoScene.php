<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One scene on a video's timeline: a CLIP (a Higgsfield video generation,
 * made by a job or picked from the gallery) or a TEMPLATE (a card the browser
 * draws — title, scoreboard, stat, disclaimer — free).
 *
 * Two writers, separate columns: the SERVER owns generation_id, output_index,
 * agent_job_id, step_index, status and error (the job runner attaches a
 * finished clip); the CLIENT owns position, label, trims and the template's
 * text (the editor's autosave). PUT ignores the server's columns.
 *
 * @property int $id
 * @property int $video_id
 * @property int $position
 * @property string $kind clip | template
 * @property string $label
 * @property string|null $prompt
 * @property string|null $engine kling | seedance
 * @property bool $with_character
 * @property int $seconds
 * @property array|null $template {key, title, value, subtitle}
 * @property int|null $generation_id
 * @property int $output_index
 * @property int|null $agent_job_id
 * @property int|null $step_index
 * @property int|null $trim_in_ms
 * @property int|null $trim_out_ms
 * @property string $status empty | planned | generating | ready | failed | expired
 * @property string|null $error
 */
class CreativeVideoScene extends Model
{
    public const CLIP = 'clip';

    public const TEMPLATE = 'template';

    public const KINDS = [self::CLIP, self::TEMPLATE];

    public const KLING = 'kling';

    public const SEEDANCE = 'seedance';

    public const ENGINES = [self::KLING, self::SEEDANCE];

    public const EMPTY = 'empty';

    public const PLANNED = 'planned';

    public const GENERATING = 'generating';

    public const READY = 'ready';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    public const TEMPLATE_KEYS = ['pixel-title', 'pixel-scoreboard', 'pixel-levelup', 'clean-title', 'clean-stat', 'disclaimer'];

    protected $table = 'creative_video_scenes';

    protected $fillable = [
        'video_id', 'position', 'kind', 'label', 'prompt', 'engine', 'with_character', 'seconds', 'template',
        'generation_id', 'output_index', 'agent_job_id', 'step_index', 'trim_in_ms', 'trim_out_ms', 'status', 'error',
    ];

    protected function casts(): array
    {
        return [
            'video_id' => 'integer',
            'position' => 'integer',
            'with_character' => 'boolean',
            'seconds' => 'integer',
            'template' => 'array',
            'generation_id' => 'integer',
            'output_index' => 'integer',
            'agent_job_id' => 'integer',
            'step_index' => 'integer',
            'trim_in_ms' => 'integer',
            'trim_out_ms' => 'integer',
        ];
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(CreativeVideo::class, 'video_id');
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(CreativeGeneration::class, 'generation_id');
    }

    /** How long the scene plays: its trimmed span of `seconds`. */
    public function durationMs(): int
    {
        $out = $this->trim_out_ms ?? $this->seconds * 1000;

        return max(0, $out - ($this->trim_in_ms ?? 0));
    }
}
