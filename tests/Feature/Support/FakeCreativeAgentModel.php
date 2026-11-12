<?php

namespace Tests\Feature\Support;

use App\Services\Creatives\AgentReply;
use App\Services\Creatives\CreativeAgentModel;

/**
 * An in-memory Claude for the chat agent's Feature tests: bind it over
 * CreativeAgentModel and every model call lands here instead of Anthropic.
 *
 * It answers scripted content blocks — thinking (with a signature), text and
 * `tool_use` — as the exact JSON text the real API returns, and records every
 * request as the JSON it would have sent, so a test can compare bytes.
 */
class FakeCreativeAgentModel implements CreativeAgentModel
{
    public bool $configured = true;

    public string $model = 'claude-opus-5-5';

    /** @var list<array{system: string, tools: string, messages: string, decoded: array, objects: array, max_tokens: int}> */
    public array $calls = [];

    /** @var list<AgentReply|\Closure(array): AgentReply> answers, in order; the last one repeats */
    public array $replies = [];

    private static int $ids = 0;

    public const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    public function configured(): bool
    {
        return $this->configured;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function step(string $system, array $tools, array $messages, int $maxTokens): AgentReply
    {
        $messagesJson = (string) json_encode($messages, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        $this->calls[] = [
            'system' => $system,
            'tools' => (string) json_encode($tools, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'messages' => $messagesJson,
            'decoded' => (array) json_decode($messagesJson, true),
            // Objects keep {} as {} — compare BYTES with these, never with `decoded`.
            'objects' => (array) json_decode($messagesJson, false),
            'max_tokens' => $maxTokens,
        ];
        $reply = count($this->replies) > 1 ? array_shift($this->replies) : ($this->replies[0] ?? self::text('OK.'));

        return $reply instanceof \Closure ? $reply($this->calls[count($this->calls) - 1]['decoded']) : $reply;
    }

    // ---- scripted answers -------------------------------------------------------

    /** @param  list<array<string, mixed>>  $blocks */
    public static function answer(array $blocks, string $stop, int $input = 3000, int $output = 600, int $cacheWrite = 0, int $cacheRead = 0): AgentReply
    {
        return AgentReply::answered('claude-opus-5-5', $stop, (string) json_encode($blocks, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), [
            ['model' => 'claude-opus-5-5', 'input' => $input, 'output' => $output, 'cache_write' => $cacheWrite, 'cache_read' => $cacheRead],
        ]);
    }

    /** An end_turn answer: a thinking block (with a readable summary when `$thinking` is given), then the text. */
    public static function text(string $text, int $input = 3000, int $output = 600, string $thinking = ''): AgentReply
    {
        return self::answer([self::thinking($thinking), ['type' => 'text', 'text' => $text]], 'end_turn', $input, $output);
    }

    /**
     * A tool_use answer: a thinking block, optional text, then one tool_use
     * block per [name, input] pair.
     *
     * @param  list<array{0: string, 1: array<string, mixed>}>  $calls
     */
    public static function tools(array $calls, string $text = '', int $input = 3000, int $output = 600, string $thinking = ''): AgentReply
    {
        $blocks = [self::thinking($thinking)];
        if ($text !== '') {
            $blocks[] = ['type' => 'text', 'text' => $text];
        }
        foreach ($calls as [$name, $input_]) {
            $blocks[] = [
                'type' => 'tool_use',
                'id' => 'toolu_'.str_pad((string) ++self::$ids, 6, '0', STR_PAD_LEFT),
                'name' => $name,
                'input' => $input_ === [] ? new \stdClass : $input_,
                'caller' => ['type' => 'direct'],
            ];
        }

        return self::answer($blocks, 'tool_use', $input, $output);
    }

    /**
     * A thinking block. `$text` is its summary (`display: summarized`); ""
     * is what an omitted display returns.
     *
     * @return array{type: string, thinking: string, signature: string}
     */
    public static function thinking(string $text = ''): array
    {
        return ['type' => 'thinking', 'thinking' => $text, 'signature' => 'EqQBCkYIBhgCKkDsig'.(++self::$ids).'=='];
    }

    /** The tool_result blocks of the last user message of a recorded call. */
    public static function results(array $call): array
    {
        $messages = $call['decoded'];
        $last = $messages[count($messages) - 1] ?? ['content' => []];

        return array_values(array_filter($last['content'], fn ($b) => ($b['type'] ?? null) === 'tool_result'));
    }
}
