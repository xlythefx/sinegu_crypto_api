<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TradeLog extends Model
{
    protected $table = 'trade_logs';

    /** Append-only: created_at (useCurrent) but no updated_at column. */
    public const UPDATED_AT = null;
    public const CREATED_AT = null;

    protected $fillable = [
        'exchange',
        // main (TradingView, everyone) | test (testers only) — WebhookPaths::FEEDS.
        'feed',
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

    /**
     * Signals from the MAIN feed only — what the business numbers count.
     * Test-feed traffic (tester accounts, other signal providers) is real
     * engine work but not real trading: it must never move a "signals today",
     * a failure count, a strategy's reliability or an asset's last price.
     * The Signal Log shows it; the dashboards do not.
     */
    public function scopeMainFeed(Builder $query): Builder
    {
        return $query->where('feed', 'main');
    }
}
