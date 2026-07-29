<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Machine-to-machine gate for the Python trading engine (/api/engine/*).
 * The engine authenticates with a shared secret in the X-Engine-Secret header,
 * compared against config('services.engine.secret') with hash_equals.
 * Fails closed: an unconfigured secret rejects everything.
 */
class VerifyEngineSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.engine.secret');

        if ($secret === '') {
            return response()->json([
                'success' => false,
                'error_code' => 'ENGINE_NOT_CONFIGURED',
                'message' => 'Engine secret is not configured on the server.',
            ], 503);
        }

        if (! hash_equals($secret, (string) $request->header('X-Engine-Secret', ''))) {
            return response()->json([
                'success' => false,
                'error_code' => 'ENGINE_UNAUTHORIZED',
                'message' => 'Invalid engine secret.',
            ], 401);
        }

        return $next($request);
    }
}
