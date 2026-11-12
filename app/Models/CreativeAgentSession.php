<?php

namespace App\Models;

use App\Services\Creatives\CreativeAgentPrompt;
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
 * @property int|null $video_id the video this chat edits (the editor chat); null = the workspace chat
 * @property string $last_mode ask | generate
 * @property array|null $sent_context {brief, playbook, memory: version} already sent in this chat
 * @property int $tools_version the agent tool list the chat started with (CreativeAgentPrompt::TOOLS_VERSION)
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

    protected $fillable = ['uni_id', 'user_name', 'video_id', 'last_mode', 'sent_context', 'tools_version'];

    /**
     * Set (not stored) when resolving a chat closed an older one because the
     * agent's tools changed — the session read then says so, once.
     */
    public bool $replacedForNewTools = false;

    protected function casts(): array
    {
        return ['sent_context' => 'array', 'tools_version' => 'integer', 'video_id' => 'integer'];
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

    /**
     * Started with the agent's CURRENT tool list. The list must stay
     * byte-identical within a chat (preserved thinking), so a chat from
     * before a tools change is over, however recent.
     */
    public function hasCurrentTools(): bool
    {
        return (int) ($this->tools_version ?? 1) >= CreativeAgentPrompt::TOOLS_VERSION;
    }
}
