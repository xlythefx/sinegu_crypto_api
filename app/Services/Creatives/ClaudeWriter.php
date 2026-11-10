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
use Anthropic\Client;
use Anthropic\Core\Exceptions\AnthropicException;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\InternalServerException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;
use Anthropic\RequestOptions;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Support\Facades\Log;

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
 *  - transport: our own Guzzle client, because the SDK leaves the timeout to
 *    the transport and WAMP ships no CA bundle (`services.anthropic.ca_bundle`,
 *    same file as TRON's). The base URL is pinned so an environment variable
 *    can never send the key somewhere else.
 *
 * `stop_reason` is passed back untouched; the caller reads it BEFORE the
 * content (refusal / max_tokens are answers, not JSON).
 */
class ClaudeWriter implements CreativeWriter
{
    private const BASE_URL = 'https://api.anthropic.com';

    private const DEFAULT_MODEL = 'claude-opus-5-5';

    private const DEFAULT_EFFORT = 'medium';

    private const EFFORTS = ['low', 'medium', 'high', 'xhigh', 'max'];

    /** cURL's CURLE_OPERATION_TIMEDOUT: the request went out and no reply came back in time. */
    private const CURL_TIMED_OUT = 28;

    public function configured(): bool
    {
        return $this->key() !== '';
    }

    public function model(): string
    {
        $model = trim((string) config('services.anthropic.model'));

        return $model !== '' ? $model : self::DEFAULT_MODEL;
    }

    public function write(string $system, string $user, array $schema, int $maxTokens): WriterReply
    {
        $model = $this->model();
        if (! $this->configured()) {
            return WriterReply::unanswered($model, 'auth');
        }

        try {
            $client = $this->client();
        } catch (\Throwable $e) {
            // Nothing was sent: the client could not even be built.
            $this->warn('Claude client could not be built.', $e);

            return WriterReply::unanswered($model, 'error');
        }

        $timeout = $this->timeout();
        if (PHP_SAPI !== 'cli' && function_exists('set_time_limit')) {
            // Windows counts wall time against max_execution_time; give the
            // call (and the SDK's retries' backoff) room to finish.
            @set_time_limit($timeout + 60);
        }

        try {
            $message = $client->beta->messages->create(
                maxTokens: $maxTokens,
                messages: [['role' => 'user', 'content' => $user]],
                model: $model,
                fallbacks: 'default',
                outputConfig: BetaOutputConfig::with(
                    effort: $this->effort(),
                    format: BetaJSONOutputFormat::with(schema: $schema),
                ),
                system: [
                    ['type' => 'text', 'text' => $system, 'cacheControl' => ['type' => 'ephemeral']],
                ],
                betas: [AnthropicBeta::SERVER_SIDE_FALLBACK_2026_07_01],
            );
        } catch (RateLimitException $e) {
            $this->warn('Claude rate-limited the request.', $e);

            return WriterReply::unanswered($model, 'rate_limited');
        } catch (InternalServerException $e) {
            // 5xx, overloaded (529) and service unavailable, after the SDK's retries.
            $this->warn('Claude answered with a server error.', $e);

            return WriterReply::unanswered($model, 'server_error');
        } catch (AuthenticationException|PermissionDeniedException $e) {
            $this->warn('Claude refused this server\'s key.', $e);

            return WriterReply::unanswered($model, 'auth');
        } catch (APIStatusException $e) {
            // Any other 4xx: the request itself was refused, nothing was billed.
            $this->warn('Claude refused the request.', $e);

            return WriterReply::unanswered($model, 'rejected');
        } catch (APIConnectionException $e) {
            // No response. A TIMEOUT means the request did go out and may have
            // run (and been billed); a refused connection means it never did.
            $previous = $e->getPrevious();
            $timedOut = $previous instanceof ConnectException
                && (int) ($previous->getHandlerContext()['errno'] ?? 0) === self::CURL_TIMED_OUT;
            $this->warn('Claude could not be reached.', $e);

            return WriterReply::unanswered($model, 'unreachable', maybeBilled: $timedOut);
        } catch (AnthropicException $e) {
            $this->warn('Claude call failed.', $e);

            return WriterReply::unanswered($model, 'error', maybeBilled: true);
        } catch (\Throwable $e) {
            // Sent, but the reply could not be read: it may have been billed.
            $this->warn('Claude call failed.', $e);

            return WriterReply::unanswered($model, 'error', maybeBilled: true);
        }

        return $this->reply($message, $model);
    }

    /** Presence booleans only — a diagnostic must never become a key leak. */
    public function diagnostics(): array
    {
        return [
            'key_set' => $this->configured(),
            'ca_bundle_set' => (string) config('services.anthropic.ca_bundle') !== '',
            'model' => $this->model(),
        ];
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

    // ---- transport -----------------------------------------------------------

    private function client(): Client
    {
        $ca = (string) config('services.anthropic.ca_bundle');

        $transport = new GuzzleClient([
            'timeout' => $this->timeout(),
            'connect_timeout' => 10,
            'verify' => $ca !== '' ? $ca : true,
        ]);

        return new Client(
            apiKey: $this->key(),
            baseUrl: self::BASE_URL,
            requestOptions: RequestOptions::with(
                timeout: (float) $this->timeout(),
                // One retry, not the SDK's default two: Cloudflare cuts the
                // browser's request at 100 s, so timeout × (retries + 1) must
                // stay under it (45 s × 2 ≈ 91 s). A draft takes ~16 s.
                maxRetries: self::MAX_RETRIES,
                transporter: $transport,
            ),
            // No tracing spans: a span may carry request content.
            openTelemetry: false,
        );
    }

    private function warn(string $what, \Throwable $e): void
    {
        $context = ['class' => $e::class, 'model' => $this->model()];
        if ($e instanceof APIStatusException) {
            $context['status'] = $e->status;
            $context['type'] = $e->getType();
            $context['detail'] = $this->scrub(mb_substr((string) $e->getAPIMessage(), 0, 400));
        } else {
            $context['exception'] = $this->scrub(mb_substr($e->getMessage(), 0, 400));
        }

        Log::warning($what, $context);
    }

    /** The key out of any text we log. */
    private function scrub(string $text): string
    {
        $key = $this->key();

        $text = $key !== '' ? str_replace($key, '[redacted]', $text) : $text;

        return (string) preg_replace('/sk-ant-[A-Za-z0-9_\-]+/', '[redacted]', $text);
    }

    private function key(): string
    {
        return trim((string) config('services.anthropic.key'));
    }

    private function effort(): string
    {
        $effort = strtolower(trim((string) config('services.anthropic.effort')));

        return in_array($effort, self::EFFORTS, true) ? $effort : self::DEFAULT_EFFORT;
    }

    /** See the client: (MAX_RETRIES + 1) × timeout must stay under Cloudflare's 100 s. */
    public const MAX_RETRIES = 1;

    private function timeout(): int
    {
        return max(10, min(45, (int) config('services.anthropic.timeout', 45)));
    }
}
