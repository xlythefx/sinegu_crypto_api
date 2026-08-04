<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Allows only admin / master / developer accounts through. */
class EnsureAdmin
{
    /** Roles that may reach the admin portal at all. */
    public const ROLES = ['admin', 'master', 'developer'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! in_array($user->type, self::ROLES, true)) {
            return response()->json([
                'success' => false,
                'error_code' => 'FORBIDDEN',
                'message' => 'Admin access required.',
            ], 403);
        }

        return $next($request);
    }
}
