<?php

namespace App\Services\Creatives;

/**
 * The language model behind Admin → Creatives' AI writer. One call: a static
 * system prompt, one user message, a JSON schema the answer must follow.
 *
 * {@see ClaudeWriter} is the real one (the official Anthropic PHP SDK); tests
 * bind a fake, so the suite never reaches Anthropic.
 *
 * Contract: write() never throws. Whatever happens comes back as a
 * {@see WriterReply} — an answer (any stop reason, with its token usage), or
 * no answer (nothing was billed, or it may have been: `maybeBilled`).
 */
interface CreativeWriter
{
    /** A key is set on this server. */
    public function configured(): bool;

    /** The model asked for (an answer may come from a refusal fallback; see WriterReply::$model). */
    public function model(): string;

    /** @param  array<string, mixed>  $schema  JSON schema of the answer (structured output) */
    public function write(string $system, string $user, array $schema, int $maxTokens): WriterReply;
}
