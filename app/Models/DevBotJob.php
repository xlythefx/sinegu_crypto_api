<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One "investigate this" for the developer agent on Christian's PC (App\Services\DevBot\DevBotJobs). */
class DevBotJob extends Model
{
    public const QUEUED = 'queued';
    public const WORKING = 'working';
    public const DONE = 'done';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';

    protected $table = 'dev_bot_jobs';

    protected $fillable = [
        'status', 'source', 'prompt', 'context', 'message_id', 'claimed_at', 'finished_at', 'summary', 'links',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'links' => 'array',
            'message_id' => 'integer',
            'claimed_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
