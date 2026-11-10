<?php

namespace Tests\Feature\Support;

use App\Services\Creatives\CreativeWriter;
use App\Services\Creatives\WriterReply;

/**
 * An in-memory Claude for the Feature tests: bind it over CreativeWriter and
 * the AI writer talks to this instead of Anthropic. Records every prompt it
 * was given, so a test can assert what would have left the server.
 */
class FakeCreativeWriter implements CreativeWriter
{
    public bool $configured = true;

    public string $model = 'claude-opus-5-5';

    /** @var list<array{system: string, user: string, schema: array, max_tokens: int}> */
    public array $calls = [];

    /** @var list<WriterReply|\Closure(string): WriterReply> answers, in order; the last one repeats */
    public array $replies = [];

    public function configured(): bool
    {
        return $this->configured;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function write(string $system, string $user, array $schema, int $maxTokens): WriterReply
    {
        $this->calls[] = ['system' => $system, 'user' => $user, 'schema' => $schema, 'max_tokens' => $maxTokens];
        $reply = count($this->replies) > 1 ? array_shift($this->replies) : ($this->replies[0] ?? self::text('{}'));

        return $reply instanceof \Closure ? $reply($user) : $reply;
    }

    /** An end_turn answer whose JSON text is $json, billed at $input / $output tokens. */
    public static function text(string $json, int $input = 2000, int $output = 1500, string $stop = 'end_turn'): WriterReply
    {
        return WriterReply::answered('claude-opus-5-5', $stop, $json, [
            ['model' => 'claude-opus-5-5', 'input' => $input, 'output' => $output, 'cache_write' => 0, 'cache_read' => 0],
        ]);
    }

    /** @param  array<string, mixed>  $data */
    public static function json(array $data, int $input = 2000, int $output = 1500): WriterReply
    {
        return self::text((string) json_encode($data), $input, $output);
    }

    /** The FACTS JSON a recorded prompt carried. */
    public static function factsOf(string $user): array
    {
        $at = strpos($user, "FACTS:\n");

        return $at === false ? [] : (array) json_decode(substr($user, $at + 7), true);
    }

    public function lastUser(): string
    {
        return $this->calls === [] ? '' : $this->calls[count($this->calls) - 1]['user'];
    }
}
