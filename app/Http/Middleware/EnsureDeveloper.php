<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Allows only `developer` accounts through — stricter than EnsureAdmin, and
 * deliberately not satisfied by master.
 *
 * Guards the Database console, which can read and write any table directly.
 * That is a tool for whoever maintains the schema, not for whoever runs the
 * business, so the two roles stay separate rather than nested.
 */
class EnsureDeveloper
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->type !== 'developer') {
            return response()->json([
                'success' => false,
                'error_code' => 'FORBIDDEN',
                'message' => 'Developer access required.',
            ], 403);
        }

        return $next($request);
    }
}
