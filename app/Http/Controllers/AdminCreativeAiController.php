<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreativeAiDraftRequest;
use App\Http\Requests\CreativeAiIdeasRequest;
use App\Services\Creatives\CreativeAi;
use App\Services\Creatives\CreativeBudget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin → Creatives → "Write it for me" / "Suggest ideas": Claude drafts,
 * the team edits and saves. Every staff role may use it (the marketing team
 * are collaborators); the weekly-recap switch is for full admins (its route
 * is in the `admin` group).
 *
 * Every call spends from the Creatives monthly cap (CreativeAi reserves
 * before it calls and records the real cost after), so every answer carries
 * the fresh `budget`.
 */
class AdminCreativeAiController extends Controller
{
    public function __construct(private CreativeAi $ai, private CreativeBudget $budget) {}

    /** POST /api/admin/creatives/ai/draft — the draft is returned, not saved. */
    public function draft(CreativeAiDraftRequest $request): JsonResponse
    {
        $user = $request->user();
        $result = $this->ai->draft($request->validated(), $user?->uni_id, $user?->name);

        return response()->json([
            'success' => true,
            'draft' => $result['draft'],
            // Also at the top level, beside the draft it describes.
            'warnings' => $result['draft']['warnings'],
            'facts_used' => $result['facts_used'],
            'cost_usd' => $result['cost_usd'],
            'budget' => $this->budget->summary(),
        ]);
    }

    /** POST /api/admin/creatives/ai/ideas */
    public function ideas(CreativeAiIdeasRequest $request): JsonResponse
    {
        $user = $request->user();
        $result = $this->ai->ideas($request->validated(), $user?->uni_id, $user?->name);

        return response()->json([
            'success' => true,
            'ideas' => $result['ideas'],
            'cost_usd' => $result['cost_usd'],
            'budget' => $this->budget->summary(),
        ]);
    }

    /** PUT /api/admin/creatives/ai/settings {weekly_recap} — full admins only. */
    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'weekly_recap' => ['required', 'boolean'],
        ]);
        $this->ai->setWeeklyRecap((bool) $data['weekly_recap']);

        return response()->json(['success' => true, 'ai' => $this->ai->meta()]);
    }
}
