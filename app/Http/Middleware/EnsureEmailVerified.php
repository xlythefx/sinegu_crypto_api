<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses every authenticated route to an account whose email is not yet
 * verified. Applied to the whole auth:sanctum surface EXCEPT /auth/me,
 * /auth/logout and /auth/email/* — the routes the code screen itself needs.
 *
 * The frontend's redirect to /auth/verify is cosmetic; this is the
 * enforcement, same rule as EnsureAdmin.
 */
class EnsureEmailVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->email_verified) {
            return response()->json([
                'success' => false,
                'error_code' => 'EMAIL_UNVERIFIED',
                'code' => 'EMAIL_UNVERIFIED',
                'message' => 'Please verify your email address to continue.',
            ], 403);
        }

        return $next($request);
    }
}
