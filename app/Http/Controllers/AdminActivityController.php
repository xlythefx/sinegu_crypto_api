<?php

namespace App\Http\Controllers;

use App\Services\Activity\ActivityFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /admin/activity — Admin → Activity, every user's actions in one list
 * (ActivityFeed). Full admins only: it carries billing and admin actions,
 * like the customer Timeline it shares its rows with.
 */
class AdminActivityController extends Controller
{
    public function index(Request $request, ActivityFeed $feed): JsonResponse
    {
        $request->validate([
            'source' => ['nullable', 'in:all,customer,signin,admin'],
            'uni_id' => ['nullable', 'string', 'max:36'],
            'q' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:'.ActivityFeed::MAX_PER_PAGE],
        ]);

        return response()->json(['success' => true] + $feed->list([
            'source' => $request->query('source') === 'all' ? null : $request->query('source'),
            'uni_id' => $request->query('uni_id'),
            'q' => $request->query('q'),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'page' => (int) $request->query('page', 1),
            'per_page' => (int) $request->query('per_page', 50),
        ]));
    }
}
