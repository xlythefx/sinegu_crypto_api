<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One fix the developer error bot offered in Telegram (App\Services\DevBot). */
class DevBotAction extends Model
{
    public const ADD_ASSET = 'add_asset';

    public const OPEN = 'open';
    public const APPLIED = 'applied';
    public const SKIPPED = 'skipped';
    public const ALREADY = 'already_added';
    public const FAILED = 'failed';

    protected $table = 'dev_bot_actions';

    protected $fillable = [
        'kind', 'status', 'exchange', 'ticker', 'side', 'feed', 'trade_log_id', 'payload',
        'occurrences', 'message_id', 'chosen', 'result_text', 'resolved_by', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'chosen' => 'array',
            'occurrences' => 'integer',
            'message_id' => 'integer',
            'trade_log_id' => 'integer',
            'resolved_at' => 'datetime',
        ];
    }
}
