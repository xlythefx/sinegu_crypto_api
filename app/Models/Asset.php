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
        'streak_sizing_enabled',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'max_increments' => 'float',
            'base_size' => 'float',
            'streak_sizing_enabled' => 'boolean',
            'enabled' => 'boolean',
        ];
    }

    /** The streak ladder — loss and win steps (App\Services\Assets\StreakSizing). */
    public function streakSizes(): HasMany
    {
        return $this->hasMany(AssetStreakSize::class, 'asset_id', 'asset_id')->orderBy('kind')->orderBy('streak');
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
