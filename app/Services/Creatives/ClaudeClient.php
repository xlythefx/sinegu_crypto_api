<?php

namespace App\Services\Creatives;

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
 * The ONE place the Anthropic SDK client is built, for every Claude caller in
 * Admin → Creatives: the AI writer ({@see ClaudeWriter}) and the chat agent
 * ({@see ClaudeAgent}). Both must reach Claude the same way, so neither builds
 * its own:
 *
 *  - transport: our own Guzzle client, because the SDK leaves the timeout to
 *    the transport and WAMP ships no CA bundle (`services.anthropic.ca_bundle`,
 *    same file as TRON's);
 *  - the base URL is pinned, so an environment variable can never send the key
 *    somewhere else;
 *  - ONE retry, not the SDK's default two: Cloudflare cuts the browser's
 *    request at 100 s, so timeout × (retries + 1) must stay under it
 *    (45 s × 2 ≈ 91 s);
 *  - no OpenTelemetry spans: a span may carry request content.
 *
 * The key never leaves this class: not in a log line ({@see scrub()}), not in a
 * diagnostic (presence booleans), not in an exception message that bubbles up.
 */
class ClaudeClient
{
    public const BASE_URL = 'https://api.anthropic.com';

    public const DEFAULT_MODEL = 'claude-opus-5-5';

    public const DEFAULT_EFFORT = 'medium';

    private const EFFORTS = ['low', 'medium', 'high', 'xhigh', 'max'];

    /** See the class comment: (MAX_RETRIES + 1) × timeout must stay under Cloudflare's 100 s. */
    public const MAX_RETRIES = 1;

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

    public function effort(): string
    {
        $effort = strtolower(trim((string) config('services.anthropic.effort')));

        return in_array($effort, self::EFFORTS, true) ? $effort : self::DEFAULT_EFFORT;
    }

    public function timeout(): int
    {
        return max(10, min(45, (int) config('services.anthropic.timeout', 45)));
    }

    /** The SDK client. Throws only if it cannot even be built (nothing was sent). */
    public function make(): Client
    {
        return new Client(
            apiKey: $this->key(),
            baseUrl: self::BASE_URL,
            requestOptions: RequestOptions::with(
                timeout: (float) $this->timeout(),
                maxRetries: self::MAX_RETRIES,
                transporter: $this->transport(),
            ),
            openTelemetry: false,
        );
    }

    /**
     * Windows counts wall time against max_execution_time; give one call (and
     * the SDK's retry backoff) room to finish. Never under the CLI.
     */
    public function extendTimeLimit(): void
    {
        if (PHP_SAPI !== 'cli' && function_exists('set_time_limit')) {
            @set_time_limit($this->timeout() * (self::MAX_RETRIES + 1) + 30);
        }
    }

    /**
     * What a thrown call means, logged once (scrubbed): the failure word the
     * callers map to a message, and whether it may have been billed.
     *
     * @return array{0: string, 1: bool} failure (rate_limited | server_error | auth | rejected | unreachable | error), maybeBilled
     */
    public function failure(\Throwable $e): array
    {
        if ($e instanceof RateLimitException) {
            $this->warn('Claude rate-limited the request.', $e);

            return ['rate_limited', false];
        }
        if ($e instanceof InternalServerException) {
            // 5xx, overloaded (529) and service unavailable, after the SDK's retries.
            $this->warn('Claude answered with a server error.', $e);

            return ['server_error', false];
        }
        if ($e instanceof AuthenticationException || $e instanceof PermissionDeniedException) {
            $this->warn('Claude refused this server\'s key.', $e);

            return ['auth', false];
        }
        if ($e instanceof APIStatusException) {
            // Any other 4xx: the request itself was refused, nothing was billed.
            $this->warn('Claude refused the request.', $e);

            return ['rejected', false];
        }
        if ($e instanceof APIConnectionException) {
            // No response. A TIMEOUT means the request did go out and may have
            // run (and been billed); a refused connection means it never did.
            $previous = $e->getPrevious();
            $timedOut = $previous instanceof ConnectException
                && (int) ($previous->getHandlerContext()['errno'] ?? 0) === self::CURL_TIMED_OUT;
            $this->warn('Claude could not be reached.', $e);

            return ['unreachable', $timedOut];
        }
        if ($e instanceof AnthropicException) {
            $this->warn('Claude call failed.', $e);

            return ['error', true];
        }

        // Sent, but the reply could not be read: it may have been billed.
        $this->warn('Claude call failed.', $e);

        return ['error', true];
    }

    public function warn(string $what, \Throwable $e): void
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
    public function scrub(string $text): string
    {
        $key = $this->key();

        $text = $key !== '' ? str_replace($key, '[redacted]', $text) : $text;

        return (string) preg_replace('/sk-ant-[A-Za-z0-9_\-]+/', '[redacted]', $text);
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

    /** The HTTP transport the SDK sends through. */
    protected function transport(): GuzzleClient
    {
        $ca = (string) config('services.anthropic.ca_bundle');

        return new GuzzleClient([
            'timeout' => $this->timeout(),
            'connect_timeout' => 10,
            'verify' => $ca !== '' ? $ca : true,
        ]);
    }

    private function key(): string
    {
        return trim((string) config('services.anthropic.key'));
    }
}
