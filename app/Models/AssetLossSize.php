<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step of an asset's loss-streak ladder: from `losses` losing trades in a
 * row on, the next entry is `size` (before balance scaling).
 */
class AssetLossSize extends Model
{
    protected $table = 'asset_loss_sizes';

    protected $fillable = ['asset_id', 'losses', 'size'];

    protected function casts(): array
    {
        return [
            'losses' => 'integer',
            'size' => 'float',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id', 'asset_id');
    }
}
