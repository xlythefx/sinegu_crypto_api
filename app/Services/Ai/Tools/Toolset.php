<?php

namespace App\Services\Ai\Tools;

/**
 * A group of read-only tools for the AI connector.
 *
 * `definitions()` is what tools/list publishes — name => title, description
 * (written for the AI: what the tool answers and how to call it) and a JSON
 * Schema for the arguments. `call()` answers one tool with plain arrays; the
 * registry redacts and serialises them. No toolset may write anything.
 */
interface Toolset
{
    /** @return array<string, array{title: string, description: string, schema: array<string, mixed>}> */
    public function definitions(): array;

    /** @param array<string, mixed> $arguments */
    public function call(string $tool, array $arguments): array;
}
