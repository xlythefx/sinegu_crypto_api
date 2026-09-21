<?php

namespace App\Http\Controllers;

use App\Models\AdminTodoState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin → To be Done (auth:sanctum + admin middleware).
 *
 * The list of items lives in the FRONTEND (`src/lib/adminTodos.ts`): a feature
 * that leaves work only the owner can do — register at a third party, paste a
 * key, decide a name — ships its item in the same change. This controller
 * holds what the owner did about each one: ticked or not, by whom, and the
 * note where a decision's outcome is written down. The API never validates a
 * slug against the list (it does not have it); a state for a slug the list
 * has since dropped is harmless and simply not rendered.
 */
class AdminTodoController extends Controller
{
    /**
     * GET /api/admin/todos
     * Every recorded state, keyed by slug.
     */
    public function index(): JsonResponse
    {
        $states = AdminTodoState::with('doneBy:uni_id,name')->get()
            ->mapWithKeys(fn (AdminTodoState $state) => [$state->slug => $this->present($state)]);

        return response()->json([
            'success' => true,
            'states' => (object) $states->all(),
        ]);
    }

    /**
     * PUT /api/admin/todos/{slug}  { done?: bool, note?: string|null }
     *
     * Upserts. Un-ticking keeps the note — a reopened decision still shows
     * what was decided before.
     */
    public function update(Request $request, string $slug): JsonResponse
    {
        $validated = $request->validate([
            'done' => ['sometimes', 'boolean'],
            'note' => ['sometimes', 'nullable', 'string', 'max:4000'],
        ]);

        $state = AdminTodoState::firstOrNew(['slug' => $slug]);

        if (array_key_exists('done', $validated)) {
            if ($validated['done']) {
                if ($state->done_at === null) {
                    $state->done_at = now();
                    $state->done_by = $request->user()->uni_id;
                }
            } else {
                $state->done_at = null;
                $state->done_by = null;
            }
        }

        if (array_key_exists('note', $validated)) {
            $note = trim((string) $validated['note']);
            $state->note = $note === '' ? null : $note;
        }

        $state->save();
        $state->load('doneBy:uni_id,name');

        return response()->json([
            'success' => true,
            'slug' => $slug,
            'state' => $this->present($state),
        ]);
    }

    private function present(AdminTodoState $state): array
    {
        return [
            'done_at' => $state->done_at?->toISOString(),
            'done_by' => $state->done_by,
            'done_by_name' => $state->doneBy?->name,
            'note' => $state->note,
        ];
    }
}
