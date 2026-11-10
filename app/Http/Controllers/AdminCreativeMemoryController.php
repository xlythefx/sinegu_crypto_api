<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreativeMemoryRequest;
use App\Models\CreativeMemory;
use App\Services\Creatives\CreativeAgentPresenter;
use App\Services\Creatives\CreativeException;
use Illuminate\Http\JsonResponse;

/**
 * Admin → Creatives → Memory: the team-wide notes the chat agent reads in
 * every chat. Every staff role may add, edit and delete them; the agent adds
 * them too (source `agent`) when a person asks it to.
 */
class AdminCreativeMemoryController extends Controller
{
    public function __construct(private CreativeAgentPresenter $presenter) {}

    /** GET /api/admin/creatives/memory — newest first. */
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'memories' => CreativeMemory::query()->orderByDesc('id')->get()
                ->map(fn (CreativeMemory $m) => $this->presenter->memory($m))->values(),
        ]);
    }

    /** POST /api/admin/creatives/memory {text, category?} */
    public function store(CreativeMemoryRequest $request): JsonResponse
    {
        if (CreativeMemory::count() >= CreativeMemory::MAX_ROWS) {
            throw new CreativeException('MEMORY_FULL', 'Memory is full ('.CreativeMemory::MAX_ROWS.' notes). Delete one first.', 422);
        }
        $data = $request->validated();
        $name = $request->user()?->name;
        $memory = CreativeMemory::create([
            'text' => trim($data['text']),
            'category' => $data['category'] ?? 'other',
            'source' => CreativeMemory::SOURCE_PERSON,
            'created_by_name' => $name,
            'updated_by_name' => $name,
        ]);

        return response()->json(['success' => true, 'memory' => $this->presenter->memory($memory)], 201);
    }

    /** PUT /api/admin/creatives/memory/{id} {text?, category?} */
    public function update(CreativeMemoryRequest $request, int $id): JsonResponse
    {
        $memory = CreativeMemory::find($id) ?? throw CreativeException::notFound('Memory note not found.');
        $data = $request->validated();
        if (isset($data['text'])) {
            $data['text'] = trim($data['text']);
        }
        $memory->fill($data);
        $memory->updated_by_name = $request->user()?->name;
        $memory->save();

        return response()->json(['success' => true, 'memory' => $this->presenter->memory($memory)]);
    }

    /** DELETE /api/admin/creatives/memory/{id} */
    public function destroy(int $id): JsonResponse
    {
        $memory = CreativeMemory::find($id) ?? throw CreativeException::notFound('Memory note not found.');
        $memory->delete();

        return response()->json(['success' => true]);
    }
}
