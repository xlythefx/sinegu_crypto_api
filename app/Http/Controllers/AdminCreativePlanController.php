<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreativePlanRequest;
use App\Models\CreativeGeneration;
use App\Models\CreativePlan;
use App\Services\Creatives\CreativePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Admin → Creatives → content plan: one row per X / TikTok post, from idea
 * to posted. Every staff role may write here (the marketing team are
 * collaborators).
 */
class AdminCreativePlanController extends Controller
{
    public function __construct(private CreativePresenter $presenter) {}

    /** GET /api/admin/creatives/plans?status=&platform= — dated first (soonest), undated last, then newest. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', Rule::in(CreativePlan::STATUSES)],
            'platform' => ['nullable', 'string', Rule::in(CreativePlan::PLATFORMS)],
        ]);

        $q = CreativePlan::query()->withCount('generations')
            ->orderByRaw('planned_for IS NULL')
            ->orderBy('planned_for')
            ->orderByDesc('id');
        if (! empty($data['status'])) {
            $q->where('status', $data['status']);
        }
        if (! empty($data['platform'])) {
            $q->where('platform', $data['platform']);
        }

        return response()->json([
            'success' => true,
            'plans' => $q->get()->map(fn (CreativePlan $p) => $this->presenter->plan($p))->values(),
        ]);
    }

    /** POST /api/admin/creatives/plans */
    public function store(CreativePlanRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        $plan = CreativePlan::create([
            ...$data,
            'title' => trim($data['title']),
            'status' => $data['status'] ?? 'idea',
            'platform' => $data['platform'] ?? 'tiktok',
            'format' => $data['format'] ?? 'video',
            'created_by_uni_id' => $user?->uni_id,
            'created_by_name' => $user?->name,
            'updated_by_name' => $user?->name,
        ]);

        return response()->json(['success' => true, 'plan' => $this->presenter->plan($plan->loadCount('generations'))], 201);
    }

    /** PUT /api/admin/creatives/plans/{id} — absent key = unchanged. */
    public function update(CreativePlanRequest $request, int $id): JsonResponse
    {
        $plan = CreativePlan::find($id);
        if (! $plan) {
            return $this->notFound();
        }

        $data = $request->validated();
        if (isset($data['title'])) {
            $data['title'] = trim($data['title']);
        }
        $plan->fill($data);
        $plan->updated_by_name = $request->user()?->name;
        $plan->save();

        return response()->json(['success' => true, 'plan' => $this->presenter->plan($plan->loadCount('generations'))]);
    }

    /** DELETE /api/admin/creatives/plans/{id} — its generations keep their records (and their spend). */
    public function destroy(int $id): JsonResponse
    {
        $plan = CreativePlan::find($id);
        if (! $plan) {
            return $this->notFound();
        }

        DB::transaction(function () use ($plan) {
            CreativeGeneration::where('plan_id', $plan->id)->update(['plan_id' => null]);
            $plan->delete();
        });

        return response()->json(['success' => true]);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['success' => false, 'error_code' => 'NOT_FOUND', 'message' => 'Plan not found.'], 404);
    }
}
