<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Allows every admin-portal role PLUS the read-only `collaborator` through.
 *
 * Guards only the handful of admin GET reads a collaborator may open (the
 * dashboard Overview, User Management, Strategies — see routes/api.php).
 * Everything else stays behind EnsureAdmin, whose list deliberately does NOT
 * contain `collaborator`: that list also decides who may connect a staff-only
 * exchange (ExchangeAccountController::store), so widening it would open far
 * more than the pages a collaborator is meant to see.
 */
class EnsureStaff
{
    /** The read-only staff role — never in EnsureAdmin::ROLES. */
    public const COLLABORATOR = 'collaborator';

    /** Built from the admin list, never a second spelling of it. */
    public const ROLES = [...EnsureAdmin::ROLES, self::COLLABORATOR];

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

    /**
     * True when the requester reached a staff route WITHOUT full admin rights
     * — i.e. a collaborator. Controllers shared with admins use it to redact
     * fee settings and account / key details. Fails closed: any role outside
     * EnsureAdmin::ROLES is treated as limited.
     */
    public static function isLimited(Request $request): bool
    {
        return ! in_array($request->user()?->type, EnsureAdmin::ROLES, true);
    }
}
