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
        'kill_switch_losses',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'max_increments' => 'float',
            'base_size' => 'float',
            'streak_sizing_enabled' => 'boolean',
            'kill_switch_losses' => 'integer',
            'kill_switch_armed_at' => 'datetime',
            'killed_at' => 'datetime',
            'enabled' => 'boolean',
        ];
    }

    /**
     * The kill switch's two lifecycle rules, wherever the write comes from
     * (asset form, card toggle, the Streak Sizing Settings tab):
     * - configuring or changing it re-arms it: only master losses from now on
     *   count, never the run that was already on the books;
     * - turning the coin back ON clears a trip and re-arms it, so the losing
     *   run that switched it off cannot switch it off again on the next sync.
     * A trip itself (App\Services\Assets\KillSwitch::trip) is a direct query
     * update and never passes through here.
     */
    protected static function booted(): void
    {
        static::saving(function (Asset $asset) {
            if ($asset->isDirty('kill_switch_losses')) {
                $asset->kill_switch_armed_at = now();
            }
            if ($asset->exists && $asset->isDirty('enabled') && $asset->enabled) {
                $asset->killed_at = null;
                $asset->killed_reason = null;
                $asset->kill_switch_armed_at = now();
            }
        });
    }

    /** Switched off by the kill switch and not turned back on yet. */
    public function isKilled(): bool
    {
        return ! $this->enabled && $this->killed_at !== null;
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
