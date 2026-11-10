<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureStaff;
use App\Services\Creatives\CreativeAi;
use App\Services\Creatives\CreativeBudget;
use App\Services\Creatives\CreativeModels;
use App\Services\Creatives\CreativeReferences;
use App\Services\Creatives\HiggsfieldGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin → Creatives: what the page needs to draw itself (the models, the
 * budget), and the monthly cap.
 *
 * The page is the marketing team's (the `collaborator` role) — the one admin
 * area where a collaborator may WRITE. The cap is the exception: any full
 * admin sets it (PUT, in the `admin` group); a collaborator only sees it.
 */
class AdminCreativesController extends Controller
{
    public function __construct(private CreativeBudget $budget) {}

    /** GET /api/admin/creatives */
    public function index(Request $request, CreativeModels $models, HiggsfieldGateway $gateway, CreativeAi $ai): JsonResponse
    {
        return response()->json([
            'success' => true,
            'configured' => $gateway->configured(),
            'retention_days' => CreativeModels::RETENTION_DAYS,
            'max_references' => CreativeReferences::MAX,
            'models' => $models->all(),
            'budget' => $this->budget->summary(),
            'can_edit_budget' => ! EnsureStaff::isLimited($request),
            // The AI writer: {configured, model, weekly_recap}. The weekly
            // switch is a full admin's (PUT /admin/creatives/ai/settings);
            // `can_edit_budget` says who that is.
            'ai' => $ai->meta(),
        ]);
    }

    /** GET /api/admin/creatives/budget */
    public function budget(): JsonResponse
    {
        return response()->json(['success' => true, 'budget' => $this->budget->summary()]);
    }

    /** PUT /api/admin/creatives/budget {monthly_cap_usd} — full admins only. 0 = generation off. */
    public function updateBudget(Request $request): JsonResponse
    {
        $data = $request->validate([
            'monthly_cap_usd' => ['required', 'numeric', 'min:0', 'max:10000'],
        ]);
        $admin = $request->user();
        $this->budget->setCap((float) $data['monthly_cap_usd'], $admin?->uni_id, $admin?->name);

        return response()->json(['success' => true, 'budget' => $this->budget->summary()]);
    }
}
