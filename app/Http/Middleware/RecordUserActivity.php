<?php

namespace App\Http\Middleware;

use App\Services\Activity\UserActivity;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `->middleware('activity:password.changed')` on a route: after the
 * controller answers 2xx (and not `success: false`), one row goes into the
 * user's activity log. The route says WHAT it records, so a new customer
 * action is one middleware entry, never a controller edit.
 */
class RecordUserActivity
{
    public function __construct(private UserActivity $activity) {}

    public function handle(Request $request, Closure $next, string $action): Response
    {
        $response = $next($request);

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return $response;
        }
        $data = $response instanceof JsonResponse ? (array) $response->getData(true) : [];
        if (($data['success'] ?? true) === false) {
            return $response;
        }

        $this->activity->fromRequest($request, $action, $data);

        return $response;
    }
}
