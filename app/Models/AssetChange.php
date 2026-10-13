<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One line of an asset's change history (App\Services\Assets\AssetHistory).
 * Append-only: rows are written, never edited.
 */
class AssetChange extends Model
{
    public const UPDATED_AT = null;

    public const CREATED = 'created';

    public const UPDATED = 'updated';

    /** A size change saved while the main account held the coin — waiting for the close. */
    public const WAITING = 'waiting';

    /** A waiting size change went live (after the close, or "Apply now"). */
    public const APPLIED = 'applied';

    /** A waiting size change was dropped before it went live. */
    public const CANCELLED = 'cancelled';

    public const DELETED = 'deleted';

    public const KILL_SWITCH = 'kill_switch';

    protected $table = 'asset_changes';

    protected $fillable = [
        'asset_id', 'ticker', 'broker', 'event',
        'actor_uni_id', 'actor_name', 'changes', 'note', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
