<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses every authenticated route to a SUSPENDED account.
 *
 * Suspending (and rejecting) a user deletes their tokens, so normally no
 * request ever reaches this check. It exists for the token that was issued
 * before that rule, or that a bug leaves behind: until 2026-10-07 only
 * `login` looked at `status`, so a user suspended for non-payment or abuse
 * kept full API access — dashboard, exchange keys, payouts — for as long as
 * their browser held the token, which Sanctum never expired. The login check
 * stops the NEXT sign-in; this stops the current one. Same rule as
 * EnsureEmailVerified and EnsureAdmin: the client's redirect is cosmetic,
 * this is the enforcement.
 *
 * `pending` is deliberately allowed through — a pending user may sign in and
 * look around while an admin approves them (the exchange features are gated
 * elsewhere). Sign-out is NOT behind this middleware: a suspended user must
 * still be able to drop their own token.
 */
class EnsureAccountActive
{
    public const ERROR_CODE = 'ACCOUNT_SUSPENDED';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->status === 'suspended') {
            return response()->json([
                'success' => false,
                'error_code' => self::ERROR_CODE,
                'message' => 'Your account has been suspended. Please contact support.',
            ], 403);
        }

        return $next($request);
    }
}
