<?php

namespace App\Services\Creatives;

/**
 * What one {@see CreativeAgentModel} call came back with.
 *
 *  - ANSWERED: Claude replied. `content` is the EXACT JSON text of the
 *    message's content blocks (thinking blocks included) — stored and replayed
 *    as-is, never rebuilt. `usage` is one entry per attempt.
 *  - NOT ANSWERED: an HTTP error or no response. Normally nothing was billed;
 *    `maybeBilled` when the request went out and the reply was lost.
 */
final class AgentReply
{
    /**
     * @param  list<array{model: string, input: int, output: int, cache_write: int, cache_read: int}>  $usage
     */
    private function __construct(
        public readonly bool $answered,
        public readonly ?string $stopReason,
        public readonly ?string $content,
        public readonly string $model,
        public readonly array $usage,
        public readonly ?string $failure,
        public readonly bool $maybeBilled,
    ) {}

    /**
     * @param  string  $content  JSON text of the content block list
     * @param  list<array{model: string, input: int, output: int, cache_write: int, cache_read: int}>  $usage
     */
    public static function answered(string $model, ?string $stopReason, string $content, array $usage): self
    {
        return new self(true, $stopReason, $content, $model, $usage, null, true);
    }

    /** @param  string  $failure  unreachable | rate_limited | server_error | auth | rejected | error */
    public static function unanswered(string $model, string $failure, bool $maybeBilled = false): self
    {
        return new self(false, null, null, $model, [], $failure, $maybeBilled);
    }

    /** @return list<array<string, mixed>> the content blocks decoded as arrays — for READING only */
    public function blocks(): array
    {
        $blocks = $this->content !== null ? json_decode($this->content, true) : null;

        return is_array($blocks) && array_is_list($blocks) ? $blocks : [];
    }
}
