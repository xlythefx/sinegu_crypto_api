<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message of a Creatives agent chat.
 *
 * `content` is the EXACT JSON text of the API content blocks — what Claude
 * returned for an assistant row (thinking blocks included), what we sent for
 * a user row — and it is replayed unchanged on every later call. It is never
 * edited, re-ordered or dropped: the conversation is append-only. It is kept
 * as text, not cast, so the bytes cannot change on the way through.
 *
 * `display` is what the page shows: a list of items {kind, text?, activity?,
 * job_id?, plan_id?}. A `notice` row is display-only (content NULL) and is
 * never sent to Claude.
 *
 * @property int $id
 * @property string $session_id
 * @property string $role user | assistant | notice
 * @property string|null $content
 * @property array|null $display
 * @property string|null $mode
 * @property string|null $stop_reason
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class CreativeAgentMessage extends Model
{
    public const USER = 'user';

    public const ASSISTANT = 'assistant';

    public const NOTICE = 'notice';

    public const UPDATED_AT = null;

    protected $table = 'creative_agent_messages';

    protected $fillable = ['session_id', 'role', 'content', 'display', 'mode', 'stop_reason'];

    protected function casts(): array
    {
        return ['display' => 'array'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CreativeAgentSession::class, 'session_id');
    }

    /** A message the PERSON typed (not tool results, not a notice). */
    public function isPerson(): bool
    {
        return $this->role === self::USER && ($this->display[0]['kind'] ?? null) === 'user';
    }

    /** @return list<array<string, mixed>> the content blocks, decoded as arrays (for reading only) */
    public function blocks(): array
    {
        $blocks = $this->content !== null ? json_decode($this->content, true) : null;

        return is_array($blocks) ? array_values($blocks) : [];
    }

    /** @return list<array<string, mixed>> the assistant's tool_use blocks */
    public function toolUses(): array
    {
        return array_values(array_filter($this->blocks(), fn ($b) => is_array($b) && ($b['type'] ?? null) === 'tool_use'));
    }
}
