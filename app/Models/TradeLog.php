<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TradeLog extends Model
{
    protected $table = 'trade_logs';

    /** Append-only: created_at (useCurrent) but no updated_at column. */
    public const UPDATED_AT = null;
    public const CREATED_AT = null;

    protected $fillable = [
        'exchange',
        'action',
        'ticker',
        'success',
        'price',
        'strategy',
        'leverage',
        'category',
        'target_count',
        'filled',
        'failed',
        'skipped',
        'details',
        'ts',
    ];

    protected function casts(): array
    {
        return [
            'success' => 'boolean',
            'details' => 'array',
            'ts' => 'datetime',
        ];
    }
}
