<?php

namespace App\Services\Creatives;

use Anthropic\Beta\AnthropicBeta;
use Anthropic\Beta\Messages\BetaAdvisorMessageIterationUsage;
use Anthropic\Beta\Messages\BetaCompactionIterationUsage;
use Anthropic\Beta\Messages\BetaFallbackMessageIterationUsage;
use Anthropic\Beta\Messages\BetaJSONOutputFormat;
use Anthropic\Beta\Messages\BetaMessage;
use Anthropic\Beta\Messages\BetaMessageIterationUsage;
use Anthropic\Beta\Messages\BetaOutputConfig;

/**
 * The one class that talks to Claude (Admin → Creatives' AI writer), through
 * the official Anthropic PHP SDK.
 *
 * Same posture as {@see HiggsfieldGateway}: nothing here throws. Every call
 * answers a {@see WriterReply}, and the key never leaves this class — not in
 * a log line (every logged text is scrubbed of it), not in a response, not
 * in an exception message that bubbles up.
 *
 * The request (facts verified against the SDK source, v1.7):
 *  - `beta->messages->create(...)`, because the server-side refusal fallback
 *    lives on the beta call: `fallbacks: 'default'` with the
 *    `server-side-fallback-2026-07-01` beta. A policy decline is re-run on the
 *    model Anthropic recommends for that category; `usage.iterations` then
 *    lists every attempt, each billed at its own model's rate.
 *  - `outputConfig` = effort + a JSON schema (structured output): the first
 *    text block after the last `fallback` block is the JSON.
 *  - no `thinking` (always on for this model, effort is the control), no
 *    prefill, no temperature.
 *  - the system prompt is one text block marked cacheable; it is static.
 *  - transport, CA bundle, timeout, the one retry, no OpenTelemetry: the
 *    shared {@see ClaudeClient}, which the chat agent ({@see ClaudeAgent})
 *    builds its client from too.
 *
 * `stop_reason` is passed back untouched; the caller reads it BEFORE the
 * content (refusal / max_tokens are answers, not JSON).
 */
class ClaudeWriter implements CreativeWriter
{
    private ClaudeClient $claude;

    public function __construct(?ClaudeClient $claude = null)
    {
        $this->claude = $claude ?? new ClaudeClient;
    }

    public function configured(): bool
    {
        return $this->claude->configured();
    }

    public function model(): string
    {
        return $this->claude->model();
    }

    public function write(string $system, string $user, array $schema, int $maxTokens): WriterReply
    {
        $model = $this->model();
        if (! $this->configured()) {
            return WriterReply::unanswered($model, 'auth');
        }

        try {
            $client = $this->claude->make();
        } catch (\Throwable $e) {
            // Nothing was sent: the client could not even be built.
            $this->claude->warn('Claude client could not be built.', $e);

            return WriterReply::unanswered($model, 'error');
        }

        $this->claude->extendTimeLimit();

        try {
            $message = $client->beta->messages->create(
                maxTokens: $maxTokens,
                messages: [['role' => 'user', 'content' => $user]],
                model: $model,
                fallbacks: 'default',
                outputConfig: BetaOutputConfig::with(
                    effort: $this->claude->effort(),
                    format: BetaJSONOutputFormat::with(schema: $schema),
                ),
                system: [
                    ['type' => 'text', 'text' => $system, 'cacheControl' => ['type' => 'ephemeral']],
                ],
                betas: [AnthropicBeta::SERVER_SIDE_FALLBACK_2026_07_01],
            );
        } catch (\Throwable $e) {
            [$failure, $maybeBilled] = $this->claude->failure($e);

            return WriterReply::unanswered($model, $failure, maybeBilled: $maybeBilled);
        }

        return $this->reply($message, $model);
    }

    /** Presence booleans only — a diagnostic must never become a key leak. */
    public function diagnostics(): array
    {
        return $this->claude->diagnostics();
    }

    // ---- reading the answer ------------------------------------------------

    private function reply(BetaMessage $message, string $asked): WriterReply
    {
        $served = is_string($message->model) && $message->model !== '' ? $message->model : $asked;

        // After a fallback, the answer is what follows the LAST `fallback`
        // block; anything before it belonged to the declined attempt.
        $texts = [];
        foreach ($message->content as $block) {
            $type = is_object($block) ? ($block->type ?? null) : null;
            if ($type === 'fallback') {
                $texts = [];
            } elseif ($type === 'text') {
                $texts[] = (string) $block->text;
            }
        }

        $category = $message->stopDetails?->category;

        return WriterReply::answered(
            model: $served,
            stopReason: is_string($message->stopReason) ? $message->stopReason : null,
            text: $texts !== [] ? implode('', $texts) : null,
            usage: $this->attempts($message, $served),
            refusalCategory: is_string($category) ? $category : null,
        );
    }

    /**
     * Token usage per attempt. `usage.iterations` is the per-attempt truth
     * when present (a fallback ran: the declined attempt AND the one that
     * answered, at their own rates); otherwise the top-level usage is the one
     * attempt there was.
     *
     * @return list<array{model: string, input: int, output: int, cache_write: int, cache_read: int}>
     */
    private function attempts(BetaMessage $message, string $served): array
    {
        $usage = $message->usage;
        $iterations = $usage->iterations;

        if (is_array($iterations) && $iterations !== []) {
            $out = [];
            foreach ($iterations as $it) {
                $model = match (true) {
                    $it instanceof BetaFallbackMessageIterationUsage, $it instanceof BetaAdvisorMessageIterationUsage => $it->model,
                    $it instanceof BetaMessageIterationUsage => $it->model ?? $served,
                    $it instanceof BetaCompactionIterationUsage => $served,
                    default => null,
                };
                if ($model === null) {
                    // A shape this SDK does not know: count the top level instead of guessing.
                    $out = null;
                    break;
                }
                $out[] = [
                    'model' => (string) $model,
                    'input' => (int) $it->inputTokens,
                    'output' => (int) $it->outputTokens,
                    'cache_write' => (int) $it->cacheCreationInputTokens,
                    'cache_read' => (int) $it->cacheReadInputTokens,
                ];
            }
            if ($out !== null) {
                return $out;
            }
        }

        return [[
            'model' => $served,
            'input' => (int) $usage->inputTokens,
            'output' => (int) $usage->outputTokens,
            'cache_write' => (int) ($usage->cacheCreationInputTokens ?? 0),
            'cache_read' => (int) ($usage->cacheReadInputTokens ?? 0),
        ]];
    }
}
