<?php

namespace App\Http\Controllers;

use App\Services\Visits\VisitorStats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin → Visitors (auth:sanctum + staff middleware). In the STAFF group on
 * purpose: the owner asked that every admin role see it, collaborators
 * included, IP addresses included (2026-10-28) — the same call as Sign-up
 * info. Read-only; nothing here writes.
 */
class AdminVisitorController extends Controller
{
    public function __construct(private VisitorStats $stats) {}

    /**
     * GET /api/admin/visitors/summary?from=YYYY-MM-DD&to=YYYY-MM-DD
     * Both optional: no `from` = since counting started, no `to` = today.
     */
    public function summary(Request $request): JsonResponse
    {
        return response()->json(['success' => true] + $this->stats->summary(
            $request->query('from'),
            $request->query('to'),
        ));
    }

    /**
     * GET /api/admin/visitors/log?from&to&view=clean|suspicious|all&page&country&device&ip&q&limit&offset
     */
    public function log(Request $request): JsonResponse
    {
        $limit = max(1, min(VisitorStats::LOG_PER_PAGE_MAX, (int) $request->query('limit', 25)));
        $offset = max(0, (int) $request->query('offset', 0));
        $filters = array_map(
            fn ($v) => is_string($v) ? $v : null,
            $request->only(['from', 'to', 'view', 'page', 'country', 'device', 'ip', 'q']),
        );

        return response()->json($this->stats->log($filters, $limit, $offset));
    }
}
