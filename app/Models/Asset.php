<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Asset extends Model
{
    protected $table = 'assets';

    protected $primaryKey = 'asset_id';

    protected $fillable = [
        'ticker',
        'type',
        'broker',
        'side',
        'asset_image',
        'max_increments',
        'base_size',
        'loss_sizing_enabled',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'max_increments' => 'float',
            'base_size' => 'float',
            'loss_sizing_enabled' => 'boolean',
            'enabled' => 'boolean',
        ];
    }

    /** The loss-streak ladder, shallowest step first (App\Services\Assets\LossSizing). */
    public function lossSizes(): HasMany
    {
        return $this->hasMany(AssetLossSize::class, 'asset_id', 'asset_id')->orderBy('losses');
    }

    /**
     * Serialize asset_image as an absolute URL. Stored values are relative
     * paths on the public disk; already-absolute (http/data) values and NULL
     * pass through unchanged. Use getRawOriginal('asset_image') for the stored
     * path (e.g. when deleting the old file).
     */
    protected function assetImage(): Attribute
    {
        return Attribute::make(
            get: function (?string $value) {
                if (! $value) {
                    return null;
                }
                if (preg_match('#^(https?://|data:)#i', $value)) {
                    return $value;
                }

                return url(Storage::url($value));
            },
        );
    }
}
