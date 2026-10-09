<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step of an asset's streak ladder: from `streak` losses (kind 'loss') or
 * wins (kind 'win') in a row on, the next entry is `size` (before balance
 * scaling). App\Services\Assets\StreakSizing owns the rules.
 */
class AssetStreakSize extends Model
{
    public const LOSS = 'loss';

    public const WIN = 'win';

    protected $table = 'asset_streak_sizes';

    protected $fillable = ['asset_id', 'kind', 'streak', 'size'];

    protected function casts(): array
    {
        return [
            'streak' => 'integer',
            'size' => 'float',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id', 'asset_id');
    }
}
