<?php

namespace App\Models;

use App\Casts\MillisecondDateTime;
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
        // Delivery timing (App\Services\Admin\SignalDelivery): when TradingView
        // fired the alert, when the engine got it, and how fast it answered.
        'sent_at',
        'received_at',
        'transit_ms',
        'ack_ms',
    ];

    protected function casts(): array
    {
        return [
            'success' => 'boolean',
            'details' => 'array',
            'ts' => 'datetime',
            // Milliseconds kept: the feed comparison tells two signals apart
            // that reached us within the same second.
            'sent_at' => MillisecondDateTime::class,
            'received_at' => MillisecondDateTime::class,
            'transit_ms' => 'integer',
            'ack_ms' => 'float',
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
