<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreativeGenerationRequest;
use App\Models\CreativeGeneration;
use App\Services\Creatives\CreativeBudget;
use App\Services\Creatives\CreativeGenerator;
use App\Services\Creatives\CreativePresenter;
use App\Services\Creatives\GenerationPoller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin → Creatives → generations: price check, generate, cancel, and the
 * record of every request (which is also the month's spend — rows are
 * never deleted). The images and videos stay at Higgsfield; a row only
 * carries their links until they expire.
 */
class AdminCreativeGenerationController extends Controller
{
    /** Rows the list refreshes at most per call, and how stale one must be. */
    private const LIST_REFRESH = 10;

    private const LIST_STALE_SECONDS = 4;

    public function __construct(
        private CreativeGenerator $generator,
        private CreativeBudget $budget,
        private CreativePresenter $presenter,
    ) {}

    /** GET /api/admin/creatives/generations?before_id=&limit=&plan_id= — newest first. */
    public function index(Request $request, GenerationPoller $poller): JsonResponse
    {
        $data = $request->validate([
            'before_id' => ['nullable', 'integer'],
            'limit' => ['nullable', 'integer', 'between:1,100'],
            'plan_id' => ['nullable', 'integer'],
        ]);
        $limit = (int) ($data['limit'] ?? 30);

        // Higgsfield pushes nothing: bring the running rows up to date first.
        $poller->refreshPending(self::LIST_REFRESH, self::LIST_STALE_SECONDS);
        $poller->expireFinished();

        $q = CreativeGeneration::query()->with('plan:id,title')->orderByDesc('id');
        if (! empty($data['before_id'])) {
            $q->where('id', '<', $data['before_id']);
        }
        if (! empty($data['plan_id'])) {
            $q->where('plan_id', $data['plan_id']);
        }
        $rows = $q->limit($limit + 1)->get();

        return response()->json([
            'success' => true,
            'generations' => $rows->take($limit)->map(fn (CreativeGeneration $g) => $this->presenter->generation($g))->values(),
            'has_more' => $rows->count() > $limit,
        ]);
    }

    /** POST /api/admin/creatives/generations/estimate — free; nothing is started. */
    public function estimate(CreativeGenerationRequest $request): JsonResponse
    {
        $quote = $this->generator->quote($request->validated());

        return response()->json([
            'success' => true,
            'usd' => $quote['usd'],
            'budget' => $this->budget->summary(),
            'allowed' => $quote['allowed'],
        ]);
    }

    /** POST /api/admin/creatives/generations — spends money (within the cap). */
    public function store(CreativeGenerationRequest $request): JsonResponse
    {
        $generation = $this->generator->create($request->validated(), $request->user());

        return response()->json([
            'success' => true,
            'generation' => $this->presenter->generation($generation->fresh(['plan'])),
            'budget' => $this->budget->summary(),
        ], 201);
    }

    /** POST /api/admin/creatives/generations/{id}/cancel — only while queued (refunded). */
    public function cancel(int $id): JsonResponse
    {
        $generation = CreativeGeneration::find($id);
        if (! $generation) {
            return response()->json(['success' => false, 'error_code' => 'NOT_FOUND', 'message' => 'Generation not found.'], 404);
        }
        $generation = $this->generator->cancel($generation);

        return response()->json([
            'success' => true,
            'generation' => $this->presenter->generation($generation->fresh(['plan'])),
            'budget' => $this->budget->summary(),
        ]);
    }
}
