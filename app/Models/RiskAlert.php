<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One serious failure that needs a person (App\Services\Alerts\RiskAlerts).
 * Open until a full admin marks it resolved; `engine_down` also closes itself
 * when the engine answers again.
 */
class RiskAlert extends Model
{
    public const UPDATED_AT = null;

    public const FEED_BREACH = 'feed_breach';
    public const EXIT_STUCK = 'exit_stuck';
    public const KILL_SWITCH = 'kill_switch';
    public const KEY_REFUSED = 'key_refused';
    public const ENGINE_DOWN = 'engine_down';
    public const ASSETS_UNAVAILABLE = 'assets_unavailable';

    protected $table = 'risk_alerts';

    protected $fillable = [
        'kind', 'dedupe_key', 'exchange', 'title', 'lines', 'trade_log_id', 'occurrences',
        'last_seen_at', 'emailed_at', 'telegram_at', 'resolved_at', 'resolved_by_name', 'resolve_note',
    ];

    protected function casts(): array
    {
        return [
            'lines' => 'array',
            'occurrences' => 'integer',
            'last_seen_at' => 'datetime',
            'emailed_at' => 'datetime',
            'telegram_at' => 'datetime',
            'resolved_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }
}
