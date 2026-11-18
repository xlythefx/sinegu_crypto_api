<?php

namespace App\Http\Controllers;

use App\Models\RiskAlert;
use App\Services\Admin\AdminAudit;
use App\Services\Alerts\RiskAlerts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * High-risk alerts on Admin → Overview → Needs attention (RiskAlerts).
 * Full admins only (the `admin` route group): the rows name accounts.
 */
class AdminRiskAlertController extends Controller
{
    /** GET /api/admin/risk-alerts?status=open|all — newest first, ≤ 100. */
    public function index(Request $request): JsonResponse
    {
        $query = RiskAlert::query()->orderByDesc('last_seen_at')->limit(100);
        if ($request->query('status', 'open') !== 'all') {
            $query->open();
        }

        return response()->json([
            'success' => true,
            'alerts' => $query->get()->map(fn (RiskAlert $a) => RiskAlerts::present($a) + [
                'resolved_at' => $a->resolved_at?->toIso8601String(),
                'resolved_by' => $a->resolved_by_name,
                'resolve_note' => $a->resolve_note,
            ])->values(),
        ]);
    }

    /** POST /api/admin/risk-alerts/{id}/resolve {note?} — "I have dealt with it." */
    public function resolve(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $alert = RiskAlert::find($id);
        if (! $alert) {
            return response()->json(['success' => false, 'error_code' => 'NOT_FOUND', 'message' => 'Alert not found.'], 404);
        }
        if ($alert->resolved_at !== null) {
            return response()->json(['success' => false, 'error_code' => 'ALREADY_RESOLVED', 'message' => 'This alert is already resolved.'], 409);
        }
        $user = $request->user();
        $alert->forceFill([
            'resolved_at' => now(),
            'resolved_by_name' => mb_substr((string) ($user->name ?: $user->email), 0, 120),
            'resolve_note' => $data['note'] ?? null,
        ])->save();
        Cache::forget(AdminInsightsController::CACHE_PREFIX.'overview');

        return response()->json(['success' => true, 'message' => 'Marked as resolved.']);
    }
}
