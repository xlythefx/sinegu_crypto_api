<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One thing the PC developer agent learned (or an admin wrote down): a cause,
 * how to recognise it, what fixes it. Every job starts by reading these, so a
 * known problem is answered faster. Shown and editable on Admin → Error Bot.
 * Private (full admins + the agent's key), never pushed to the public repos.
 */
class DevBotMemory extends Model
{
    /** How many the agent is handed per job, newest first. */
    public const FOR_AGENT = 60;

    protected $table = 'dev_bot_memories';

    protected $fillable = ['title', 'body', 'signature', 'source', 'job_id', 'updated_by_name'];

    protected function casts(): array
    {
        return ['job_id' => 'integer'];
    }

    /** @return array<string, mixed> */
    public function present(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'signature' => $this->signature,
            'source' => $this->source,
            'job_id' => $this->job_id,
            'updated_by' => $this->updated_by_name,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
