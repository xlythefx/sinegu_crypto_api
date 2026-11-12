<?php

namespace App\Console\Commands;

use App\Models\CreativeAiCall;
use App\Services\Creatives\ClaudeClient;
use App\Services\Creatives\CreativeAgentPrompt;
use App\Services\Creatives\CreativeAi;
use App\Services\Creatives\CreativeException;
use Illuminate\Console\Command;
use Throwable;

/**
 * Ask Anthropic whether the Creatives agent's EXACT tools array is accepted —
 * the one check no test can make (the suite fakes the model).
 *
 * Born on 2026-11-12: v2 shipped with all 20 tools strict, every chat call
 * came back 400 "The compiled grammar is too large", and the page only said
 * "Request failed (502)". `count_tokens` does NOT compile the grammar, so the
 * only way to know is one minimal real call: SYSTEM + tools() + "Say OK." at
 * low effort (~0.04 USD, recorded against the Creatives budget like any AI
 * call). A refused request is not billed. Run it after any change to
 * CreativeAgentPrompt::tools() — the deploy runbook does.
 */
class CreativesCheckAgentTools extends Command
{
    protected $signature = 'creatives:check-agent-tools';

    protected $description = 'One minimal live call: does Anthropic accept the Creatives agent\'s tools array?';

    public function handle(ClaudeClient $claude, CreativeAi $ai): int
    {
        if (! $claude->configured()) {
            $this->error('ANTHROPIC_API_KEY is not set on this box.');

            return self::FAILURE;
        }
        $tools = CreativeAgentPrompt::tools();
        $model = $claude->model();
        $this->line(sprintf('%d tools, %d strict (%s), model %s.', count($tools), count(CreativeAgentPrompt::STRICT_TOOLS), implode(', ', CreativeAgentPrompt::STRICT_TOOLS), $model));

        try {
            $call = $ai->reserveCall(CreativeAiCall::AGENT, $model, null, 'System check', what: 'the assistant: tool check');
        } catch (CreativeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        try {
            $response = $claude->make()->messages->raw->create(params: [
                'maxTokens' => 1200,
                'model' => $model,
                'messages' => [['role' => 'user', 'content' => 'Say OK.']],
                // The same request shape ClaudeAgent::step sends (only the effort is
                // lowered to keep the check cheap), so a parameter the API refuses
                // shows up here and not in the team's chat.
                'cacheControl' => ['type' => 'ephemeral'],
                'outputConfig' => ['effort' => 'low'],
                'thinking' => ['type' => 'adaptive', 'display' => 'summarized'],
                'system' => [['type' => 'text', 'text' => CreativeAgentPrompt::SYSTEM, 'cacheControl' => ['type' => 'ephemeral']]],
                'tools' => $tools,
            ]);
        } catch (Throwable $e) {
            $ai->abandonCall($call, maybeBilled: false);
            $detail = preg_match('/"message":\s*"([^"]+)"/', $e->getMessage(), $m) === 1 ? $m[1] : class_basename($e);
            $this->error('REFUSED: '.$detail);

            return self::FAILURE;
        }

        $data = json_decode((string) $response->getBody(), true);
        $u = (array) ($data['usage'] ?? []);
        $cost = $ai->settleCall($call, (string) ($data['model'] ?? $model), $data['stop_reason'] ?? null, [[
            'model' => (string) ($data['model'] ?? $model),
            'input' => (int) ($u['input_tokens'] ?? 0),
            'output' => (int) ($u['output_tokens'] ?? 0),
            'cache_write' => (int) ($u['cache_creation_input_tokens'] ?? 0),
            'cache_read' => (int) ($u['cache_read_input_tokens'] ?? 0),
        ]]);
        $this->info(sprintf('ACCEPTED: the tools array works (stop %s, %d input tokens, %.4f USD).', $data['stop_reason'] ?? '?', (int) ($u['input_tokens'] ?? 0), $cost));

        return self::SUCCESS;
    }
}
