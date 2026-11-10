<?php

namespace App\Services\Creatives;

/**
 * What one {@see CreativeWriter} call came back with.
 *
 * Two families, and they are different money:
 *  - ANSWERED: the model replied (end_turn, refusal, max_tokens, …). Tokens
 *    were spent, so the call is recorded at its real cost whatever it said.
 *    `usage` is one entry per attempt — a refusal retried server-side on a
 *    fallback model is two attempts at two models' rates.
 *  - NOT ANSWERED: an HTTP error or no response at all. Normally nothing was
 *    billed (`maybeBilled` false); when the request went out and the reply
 *    was lost or unreadable it may have been, and the caller keeps its
 *    reservation rather than guessing zero.
 */
final class WriterReply
{
    /**
     * @param  list<array{model: string, input: int, output: int, cache_write: int, cache_read: int}>  $usage
     */
    private function __construct(
        public readonly bool $answered,
        public readonly ?string $stopReason,
        public readonly ?string $text,
        public readonly string $model,
        public readonly array $usage,
        public readonly ?string $refusalCategory,
        public readonly ?string $failure,
        public readonly bool $maybeBilled,
    ) {}

    /**
     * @param  list<array{model: string, input: int, output: int, cache_write: int, cache_read: int}>  $usage
     */
    public static function answered(string $model, ?string $stopReason, ?string $text, array $usage, ?string $refusalCategory = null): self
    {
        return new self(true, $stopReason, $text, $model, $usage, $refusalCategory, null, true);
    }

    /**
     * @param  string  $failure  unreachable | rate_limited | server_error | auth | rejected | error
     */
    public static function unanswered(string $model, string $failure, bool $maybeBilled = false): self
    {
        return new self(false, null, null, $model, [], null, $failure, $maybeBilled);
    }

    public function inputTokens(): int
    {
        return array_sum(array_map(fn (array $u) => $u['input'] + $u['cache_write'] + $u['cache_read'], $this->usage));
    }

    public function outputTokens(): int
    {
        return array_sum(array_column($this->usage, 'output'));
    }
}
