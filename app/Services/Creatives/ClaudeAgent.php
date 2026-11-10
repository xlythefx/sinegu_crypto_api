<?php

namespace App\Services\Creatives;

/**
 * The Creatives chat agent's one model call, through the official Anthropic
 * PHP SDK and the shared {@see ClaudeClient} (CA bundle, 45 s timeout, one
 * retry, no OpenTelemetry). Nothing here throws, and the key never appears in
 * a log line or a reply.
 *
 * The request (verified against the SDK source, v1.7, and its wire output):
 *  - `messages->raw->create(params: …)` — the documented create() with the
 *    same camelCase params, taken RAW so the reply's content blocks can be
 *    stored as the exact JSON Claude returned (thinking blocks and their
 *    signatures included) rather than re-serialised from SDK objects. The
 *    stored blocks are sent back as decoded objects, which the SDK passes to
 *    the wire unchanged — the history is replayed byte for byte.
 *  - `outputConfig.effort` (medium by default); no `thinking` (always on for
 *    this model; effort is the control); no `toolChoice` (auto — forced tool
 *    use is a 400 on this model); no prefill; no temperature.
 *  - `tools`: the agent's fixed list, identical on every call and in both
 *    modes (modes are enforced server-side, never by changing the tools).
 *  - `system`: one static text block marked cacheable (it caches the tools
 *    with it); the conversation tail is cached by the top-level
 *    `cacheControl` (automatic placement on the last block).
 *  - no refusal fallback: a fallback answer comes from another model, whose
 *    thinking blocks this conversation could not replay. A refusal is an
 *    answer (stop_reason `refusal`) and the caller reports it.
 */
class ClaudeAgent implements CreativeAgentModel
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

    public function step(string $system, array $tools, array $messages, int $maxTokens): AgentReply
    {
        $model = $this->model();
        if (! $this->configured()) {
            return AgentReply::unanswered($model, 'auth');
        }

        try {
            $client = $this->claude->make();
        } catch (\Throwable $e) {
            // Nothing was sent: the client could not even be built.
            $this->claude->warn('Claude client could not be built.', $e);

            return AgentReply::unanswered($model, 'error');
        }

        $this->claude->extendTimeLimit();

        try {
            $response = $client->messages->raw->create(params: [
                'maxTokens' => $maxTokens,
                'messages' => $messages,
                'model' => $model,
                'cacheControl' => ['type' => 'ephemeral'],
                'outputConfig' => ['effort' => $this->claude->effort()],
                'system' => [
                    ['type' => 'text', 'text' => $system, 'cacheControl' => ['type' => 'ephemeral']],
                ],
                'tools' => $tools,
            ]);
            $body = (string) $response->getBody();
        } catch (\Throwable $e) {
            [$failure, $maybeBilled] = $this->claude->failure($e);

            return AgentReply::unanswered($model, $failure, maybeBilled: $maybeBilled);
        }

        $data = json_decode($body, false);
        if (! $data instanceof \stdClass || ! isset($data->content) || ! is_array($data->content)) {
            // A 2xx we cannot read: it ran and was billed.
            $this->claude->warn('Claude answered something unreadable.', new \RuntimeException('Unreadable 2xx body ('.strlen($body).' bytes).'));

            return AgentReply::unanswered($model, 'error', maybeBilled: true);
        }

        $served = is_string($data->model ?? null) && $data->model !== '' ? $data->model : $model;
        $content = json_encode($data->content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        return AgentReply::answered(
            model: $served,
            stopReason: is_string($data->stop_reason ?? null) ? $data->stop_reason : null,
            content: $content !== false ? $content : '[]',
            usage: self::usage($data, $served),
        );
    }

    /** Presence booleans only. */
    public function diagnostics(): array
    {
        return $this->claude->diagnostics();
    }

    /**
     * Token usage per attempt: `usage.iterations` when present and readable,
     * else the top-level usage as the one attempt there was.
     *
     * @return list<array{model: string, input: int, output: int, cache_write: int, cache_read: int}>
     */
    private static function usage(\stdClass $data, string $served): array
    {
        $usage = $data->usage ?? null;
        if (! $usage instanceof \stdClass) {
            return [['model' => $served, 'input' => 0, 'output' => 0, 'cache_write' => 0, 'cache_read' => 0]];
        }
        $row = fn (\stdClass $u, string $model) => [
            'model' => $model,
            'input' => (int) ($u->input_tokens ?? 0),
            'output' => (int) ($u->output_tokens ?? 0),
            'cache_write' => (int) ($u->cache_creation_input_tokens ?? 0),
            'cache_read' => (int) ($u->cache_read_input_tokens ?? 0),
        ];

        $iterations = $usage->iterations ?? null;
        if (is_array($iterations) && $iterations !== []) {
            $out = [];
            foreach ($iterations as $it) {
                if (! $it instanceof \stdClass) {
                    $out = null;
                    break;
                }
                $out[] = $row($it, is_string($it->model ?? null) && $it->model !== '' ? $it->model : $served);
            }
            if ($out !== null) {
                return $out;
            }
        }

        return [$row($usage, $served)];
    }
}
