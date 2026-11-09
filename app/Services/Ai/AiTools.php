<?php

namespace App\Services\Ai;

use App\Services\Ai\Tools\AssetTools;
use App\Services\Ai\Tools\GuideTools;
use App\Services\Ai\Tools\MoneyTools;
use App\Services\Ai\Tools\PeopleTools;
use App\Services\Ai\Tools\SignalTools;
use App\Services\Ai\Tools\ToolError;
use App\Services\Ai\Tools\Toolset;
use App\Services\Ai\Tools\TradeTools;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Every tool the AI connector publishes, and the one way to run one.
 *
 * READ-ONLY by construction: each toolset only reads, and there is no other
 * path from POST /api/mcp into the application. A new tool is a new entry in
 * a toolset's definitions() — never a write.
 *
 * Every answer passes through AiRedactor before it leaves, and is capped at
 * MAX_BYTES: an answer too big for the AI's context helps nobody, so it is
 * replaced by an instruction to narrow the question.
 */
final class AiTools
{
    public const MAX_BYTES = 400_000;

    /** @var list<class-string<Toolset>> */
    private const TOOLSETS = [
        GuideTools::class,
        SignalTools::class,
        TradeTools::class,
        AssetTools::class,
        PeopleTools::class,
        MoneyTools::class,
    ];

    /** @var array<string, Toolset>|null */
    private ?array $owners = null;

    /** @return list<array<string, mixed>> the MCP tools/list entries */
    public function list(): array
    {
        $tools = [];
        foreach ($this->toolsets() as $set) {
            foreach ($set->definitions() as $name => $def) {
                $tools[] = [
                    'name' => $name,
                    'title' => $def['title'],
                    'description' => $def['description'],
                    'inputSchema' => $def['schema'],
                    'annotations' => [
                        'title' => $def['title'],
                        'readOnlyHint' => true,
                        'destructiveHint' => false,
                        'idempotentHint' => true,
                        'openWorldHint' => false,
                    ],
                ];
            }
        }

        return $tools;
    }

    public function has(string $name): bool
    {
        return isset($this->owners()[$name]);
    }

    /**
     * Run one tool. Returns [ok, text]: the JSON answer, or — when the AI's
     * arguments were wrong or something failed — a sentence it can act on.
     *
     * @param  array<string, mixed>  $arguments
     * @return array{0: bool, 1: string}
     */
    public function run(string $name, array $arguments): array
    {
        try {
            $answer = AiRedactor::clean($this->owners()[$name]->call($name, $arguments));
        } catch (ToolError $e) {
            return [false, $e->getMessage()];
        } catch (Throwable $e) {
            Log::error('ai-access: tool failed', ['tool' => $name, 'error' => $e->getMessage()]);

            return [false, 'This tool hit an error on the server. Try again, or narrow the question.'];
        }

        $text = json_encode($answer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($text === false) {
            return [false, 'The answer could not be encoded. Narrow the question.'];
        }
        if (strlen($text) > self::MAX_BYTES) {
            return [false, 'The answer is too large. Narrow it: a shorter date range, one coin or exchange, a lower limit, '
                .'or group_by instead of listing rows.'];
        }

        return [true, $text];
    }

    /** @return list<Toolset> */
    private function toolsets(): array
    {
        return array_map(fn (string $class) => app($class), self::TOOLSETS);
    }

    /** @return array<string, Toolset> */
    private function owners(): array
    {
        if ($this->owners === null) {
            $this->owners = [];
            foreach ($this->toolsets() as $set) {
                foreach (array_keys($set->definitions()) as $name) {
                    $this->owners[$name] = $set;
                }
            }
        }

        return $this->owners;
    }
}
