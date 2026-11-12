<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreativeStyleRequest;
use App\Models\CreativeStyle;
use App\Services\Creatives\CreativeStyles;
use Illuminate\Http\JsonResponse;

/**
 * Admin → Creatives → Styles: the library of marketing styles the chat agent
 * picks from (the look of a video). Every staff role may manage them —
 * collaborators are the marketing team. A style a video still uses is not
 * deleted (STYLE_IN_USE, 409).
 */
class AdminCreativeStyleController extends Controller
{
    public function __construct(private CreativeStyles $styles) {}

    /** GET /api/admin/creatives/styles — sort, then name; disabled ones included. */
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'styles' => $this->styles->all()->map(fn (CreativeStyle $s) => $this->styles->present($s))->values(),
        ]);
    }

    /** POST /api/admin/creatives/styles */
    public function store(CreativeStyleRequest $request): JsonResponse
    {
        $style = $this->styles->create($request->validated(), $request->user()?->name);

        return response()->json(['success' => true, 'style' => $this->styles->present($style->fresh())], 201);
    }

    /** PUT /api/admin/creatives/styles/{id} — partial. */
    public function update(CreativeStyleRequest $request, int $id): JsonResponse
    {
        $style = $this->styles->update($this->styles->find($id), $request->validated(), $request->user()?->name);

        return response()->json(['success' => true, 'style' => $this->styles->present($style->fresh())]);
    }

    /** DELETE /api/admin/creatives/styles/{id} — refused while a video uses it. */
    public function destroy(int $id): JsonResponse
    {
        $this->styles->delete($this->styles->find($id));

        return response()->json(['success' => true]);
    }
}
