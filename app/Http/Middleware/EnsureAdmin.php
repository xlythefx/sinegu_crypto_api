<?php

namespace App\Http\Middleware;

use App\Models\UserCredential;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Allows only admin / master / developer / tester accounts through. */
class EnsureAdmin
{
    /**
     * Roles that may reach the admin portal at all. `tester` joined on
     * 2026-10-16 (owner): an internal test account is run by staff, who need
     * the portal (Webhooks, Signal Log, manual trade) to drive the test feed.
     * It has no developer-only surface — the Database console and test-mode
     * payments check `developer` exactly.
     */
    public const ROLES = ['admin', 'master', 'developer', UserCredential::TESTER];

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
