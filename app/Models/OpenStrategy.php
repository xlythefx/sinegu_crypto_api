<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OpenStrategy extends Model
{
    protected $table = 'open_strategies';

    /** Table has created_at (useCurrent) but no updated_at column. */
    public const UPDATED_AT = null;
    public const CREATED_AT = null;

    protected $fillable = [
        'exchange',
        'api_key',
        'symbol',
        'position_side',
        'strategy',
        // Streak sizing at entry — App\Services\Positions\EntryStreak.
        'streak_kind',
        'streak_run',
        'streak_step',
        'streak_size',
        'streak_base_size',
    ];
}
