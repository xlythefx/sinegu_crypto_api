<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One planned post on X or TikTok, from idea to posted.
 *
 * @property int $id
 * @property string $title
 * @property string $status
 * @property string $platform
 * @property string $format
 * @property string|null $planned_for  Y-m-d
 */
class CreativePlan extends Model
{
    public const STATUSES = ['idea', 'script', 'generating', 'review', 'approved', 'posted'];

    public const PLATFORMS = ['x', 'tiktok'];

    public const FORMATS = ['video', 'image', 'carousel'];

    protected $table = 'creative_plans';

    protected $fillable = [
        'title', 'status', 'platform', 'format', 'planned_for', 'hook', 'script', 'caption',
        'hashtags', 'notes', 'posted_url', 'created_by_uni_id', 'created_by_name', 'updated_by_name',
    ];

    /**
     * `planned_for` is deliberately NOT cast: a date cast would write
     * "Y-m-d H:i:s" into a DATE column. It is stored and read as "Y-m-d".
     */
    public function plannedFor(): ?string
    {
        $value = $this->getAttribute('planned_for');

        return $value !== null && $value !== '' ? substr((string) $value, 0, 10) : null;
    }

    public function generations(): HasMany
    {
        return $this->hasMany(CreativeGeneration::class, 'plan_id');
    }
}
