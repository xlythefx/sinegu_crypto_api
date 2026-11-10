<?php

namespace App\Services\Creatives;

/**
 * The language model behind the Creatives chat agent: ONE model call with a
 * static system prompt, the fixed tools array and the whole conversation.
 *
 * {@see ClaudeAgent} is the real one (the official Anthropic PHP SDK); tests
 * bind a fake that answers scripted content blocks, `tool_use` included, so
 * the suite never reaches Anthropic.
 *
 * Contract: step() never throws. Whatever happens comes back as an
 * {@see AgentReply} — an answer (any stop reason, its exact content blocks,
 * its token usage), or no answer (nothing was billed, or it may have been).
 */
interface CreativeAgentModel
{
    /** A key is set on this server. */
    public function configured(): bool;

    /** The model asked for. */
    public function model(): string;

    /**
     * @param  list<array<string, mixed>>  $tools  identical on every call (SDK shape)
     * @param  list<array{role: string, content: list<mixed>}>  $messages  the conversation; content blocks exactly as stored
     */
    public function step(string $system, array $tools, array $messages, int $maxTokens): AgentReply;
}
