<?php

namespace App\Services\Ai;

use App\Models\AiAccessKey;
use App\Services\Ai\Tools\AssetTools;
use App\Services\Ai\Tools\CreativeMakeTools;
use App\Services\Ai\Tools\CreativeTools;
use App\Services\Ai\Tools\GuideTools;
use App\Services\Ai\Tools\KeyAwareToolset;
use App\Services\Ai\Tools\KeyScopedToolset;
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
 * READ-ONLY by construction, with ONE exception (owner, 2026-11-17): the
 * Creatives make-tools (CreativeMakeTools — price / start an image or clip,
 * save an Idea plan), a {@see KeyAwareToolset} listed and run only for a key
 * a full admin switched on (`can_create`). Every other key neither sees nor
 * can call them. Any other new tool is a new read in a toolset's
 * definitions() — never a write.
 *
 * The Creatives toolsets are the CONNECTOR's own (CONNECTOR_ONLY): the
 * Creatives assistant's query_data runs the data tools only
 * ({@see dataToolNames()}), because its tool list must stay byte-identical
 * within a chat and it has Creatives tools of its own.
 *
 * Contact details are the one thing a key's switch can add to a READ
 * (`can_see_contacts`, 2026-11-26): the Telegram usernames people gave at
 * sign-up, in the users tool. See AiRedactor::allowedFor().
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
        CreativeTools::class,
        CreativeMakeTools::class,
    ];

    /** Listed to the connector, never runnable by the Creatives assistant's query_data. */
    public const CONNECTOR_ONLY = [CreativeTools::class, CreativeMakeTools::class];

    /** @var array<string, Toolset>|null */
    private ?array $owners = null;

    /**
     * The MCP tools/list entries for this key. A key-aware toolset's tools
     * appear only for a key it allows (no key = none of them).
     *
     * @return list<array<string, mixed>>
     */
    public function list(?AiAccessKey $key = null): array
    {
        $tools = [];
        foreach ($this->toolsets() as $set) {
            if ($set instanceof KeyAwareToolset && ! $set->allows($key)) {
                continue;
            }
            foreach ($set->definitions() as $name => $def) {
                $writes = (bool) ($def['writes'] ?? false);
                $tools[] = [
                    'name' => $name,
                    'title' => $def['title'],
                    'description' => $def['description'],
                    'inputSchema' => $def['schema'],
                    'annotations' => [
                        'title' => $def['title'],
                        'readOnlyHint' => ! $writes,
                        'destructiveHint' => false,
                        'idempotentHint' => ! $writes,
                        'openWorldHint' => $writes,
                    ],
                ];
            }
        }

        return $tools;
    }

    /** A tool this key may call (a hidden make-tool is as unknown as a typo). */
    public function has(string $name, ?AiAccessKey $key = null): bool
    {
        $set = $this->owners()[$name] ?? null;

        return $set !== null && (! $set instanceof KeyAwareToolset || $set->allows($key));
    }

    /**
     * The read-only DATA tools (everything but the connector's Creatives
     * toolsets): what the Creatives assistant's query_data may run.
     *
     * @return list<string>
     */
    public function dataToolNames(): array
    {
        $names = [];
        foreach ($this->toolsets() as $set) {
            if (! in_array($set::class, self::CONNECTOR_ONLY, true)) {
                array_push($names, ...array_keys($set->definitions()));
            }
        }

        return $names;
    }

    /**
     * Run one tool. Returns [ok, text]: the JSON answer, or — when the AI's
     * arguments were wrong or something failed — a sentence it can act on.
     *
     * @param  array<string, mixed>  $arguments
     * @return array{0: bool, 1: string}
     */
    public function run(string $name, array $arguments, ?AiAccessKey $key = null): array
    {
        $set = $this->owners()[$name] ?? null;
        if ($set === null || ($set instanceof KeyAwareToolset && ! $set->allows($key))) {
            return [false, "Unknown tool: {$name}"];
        }
        if ($set instanceof KeyScopedToolset) {
            $set->withKey($key);
        }

        try {
            $answer = AiRedactor::clean($set->call($name, $arguments), AiRedactor::allowedFor($key));
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
