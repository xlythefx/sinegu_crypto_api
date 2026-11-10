<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One chat with the Creatives agent, owned by the person who started it —
 * nobody else can open, continue or approve anything in it. Only the current
 * conversation matters: a session idle for {@see MAX_AGE_DAYS} days is no
 * longer offered and is pruned (`creatives:agent-prune`), its messages and
 * jobs with it. Decisions live in Memory, the Brief and Plans, not here.
 *
 * @property string $id uuid
 * @property string $uni_id
 * @property string|null $user_name
 * @property string $last_mode ask | generate
 * @property array|null $sent_context {brief: version, memory: version} already sent in this chat
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class CreativeAgentSession extends Model
{
    use HasUuids;

    /** A chat idle this long is over (and pruned). */
    public const MAX_AGE_DAYS = 7;

    public const ASK = 'ask';

    public const GENERATE = 'generate';

    public const MODES = [self::ASK, self::GENERATE];

    protected $table = 'creative_agent_sessions';

    protected $fillable = ['uni_id', 'user_name', 'last_mode', 'sent_context'];

    protected function casts(): array
    {
        return ['sent_context' => 'array'];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(CreativeAgentMessage::class, 'session_id')->orderBy('id');
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(CreativeAgentJob::class, 'session_id')->orderBy('id');
    }

    /** Still offered: active within the last MAX_AGE_DAYS days. */
    public function isLive(): bool
    {
        return $this->updated_at !== null && $this->updated_at->gt(now()->subDays(self::MAX_AGE_DAYS));
    }
}
