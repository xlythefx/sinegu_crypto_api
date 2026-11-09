<?php

namespace App\Http\Middleware;

use App\Services\Ai\AiAccessKeys;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for the AI connector (POST /api/mcp): a key made on Admin → AI Access,
 * sent as `X-Api-Key: pxa_…` (what the Grok connector form's "HTTP headers"
 * row is for) or `Authorization: Bearer pxa_…`.
 *
 * A key in the URL is REFUSED, not ignored: query strings land in nginx's
 * access log, Cloudflare's logs and the AI provider's own records, so a key
 * that worked there would be a key published in three places.
 *
 * Revoked and expired keys answer the same 401 as a wrong one.
 *
 * The PER-KEY limit lives here, not in a `throttle:` middleware after this
 * one: Laravel sorts ThrottleRequests ahead of non-priority middleware, so a
 * throttle listed after the key check actually runs BEFORE it and could only
 * ever count per IP (found by AiAccessTest). The per-IP guard against key
 * guessing is the route's `throttle:ai-guard`, which runs first on purpose.
 */
class VerifyAiAccessKey
{
    /** [cache-key prefix, max calls, window in seconds] per key. */
    private const LIMITS = [
        ['ai-minute|', 60, 60],
        ['ai-day|', 3000, 86400],
    ];

    public function __construct(private AiAccessKeys $keys) {}

    public function handle(Request $request, Closure $next): Response
    {
        foreach (['key', 'api_key', 'apikey', 'token', 'access_token'] as $param) {
            if ($request->query->has($param)) {
                return $this->deny(400, 'KEY_IN_URL', 'Send the key in the X-Api-Key header, never in the URL.');
            }
        }

        $plain = trim((string) $request->header('X-Api-Key', ''));
        if ($plain === '') {
            $plain = (string) $request->bearerToken();
        }
        if ($plain === '') {
            return $this->deny(401, 'KEY_MISSING', 'An access key is required (X-Api-Key header).');
        }

        $key = $this->keys->find($plain);
        if (! $key) {
            return $this->deny(401, 'KEY_INVALID', 'This access key is not valid, has expired or was revoked.');
        }

        foreach (self::LIMITS as [$prefix, $max]) {
            if (RateLimiter::tooManyAttempts($prefix.$key->id, $max)) {
                $wait = RateLimiter::availableIn($prefix.$key->id);

                return $this->deny(429, 'RATE_LIMITED', "Too many questions for this key — try again in {$wait} seconds.")
                    ->header('Retry-After', (string) $wait);
            }
        }
        foreach (self::LIMITS as [$prefix, , $window]) {
            RateLimiter::hit($prefix.$key->id, $window);
        }

        $this->keys->touch($key, $request->ip());
        $request->attributes->set('ai_key', $key);

        return $next($request);
    }

    private function deny(int $status, string $code, string $message): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => ['code' => -32001, 'message' => $message, 'data' => ['error_code' => $code]],
        ], $status);
    }
}
