<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One VIDEO PROJECT of the Creatives editor: scenes on a timeline, captions,
 * music and a voiceover, exported in the browser as one MP4. The MP4 is
 * never stored — only when, by whom, how long and how big (`export_*`).
 *
 * `timeline` is the client's TimelineDoc (captions, look, music, voiceover,
 * sound effects), checked shallowly by the server (an object, version 1,
 * ≤ 64 KB, audio ids that exist). The scenes are their own rows
 * ({@see CreativeVideoScene}).
 *
 * @property int $id
 * @property int|null $plan_id
 * @property int|null $style_id
 * @property string $title
 * @property string $platform x | tiktok
 * @property string $aspect 9:16 | 16:9 | 1:1
 * @property int $max_seconds
 * @property int $fps
 * @property array $timeline
 * @property string $status draft | exported
 * @property Carbon|null $exported_at
 * @property string|null $exported_by_name
 * @property string|null $export_seconds
 * @property int|null $export_bytes
 * @property string $created_by_uni_id
 * @property string $created_by_name
 * @property string|null $updated_by_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CreativeVideo extends Model
{
    public const PLATFORMS = ['x', 'tiktok'];

    public const ASPECTS = ['9:16', '16:9', '1:1'];

    public const DRAFT = 'draft';

    public const EXPORTED = 'exported';

    public const MAX_SECONDS = 30;

    protected $table = 'creative_videos';

    protected $fillable = [
        'plan_id', 'style_id', 'title', 'platform', 'aspect', 'max_seconds', 'fps', 'timeline', 'status',
        'exported_at', 'exported_by_name', 'export_seconds', 'export_bytes',
        'created_by_uni_id', 'created_by_name', 'updated_by_name',
    ];

    protected function casts(): array
    {
        return [
            'plan_id' => 'integer',
            'style_id' => 'integer',
            'max_seconds' => 'integer',
            'fps' => 'integer',
            'timeline' => 'array',
            'exported_at' => 'datetime',
            'export_seconds' => 'decimal:2',
            'export_bytes' => 'integer',
        ];
    }

    public function scenes(): HasMany
    {
        return $this->hasMany(CreativeVideoScene::class, 'video_id')->orderBy('position')->orderBy('id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(CreativePlan::class, 'plan_id');
    }

    public function style(): BelongsTo
    {
        return $this->belongsTo(CreativeStyle::class, 'style_id');
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(CreativeAgentJob::class, 'video_id')->orderBy('id');
    }

    /** The editor's page for this project (the website's route). */
    public static function editorPath(int $id): string
    {
        return '/admin/creatives/editor/'.$id;
    }
}
