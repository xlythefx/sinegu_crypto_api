<?php

namespace App\Http\Controllers;

use App\Models\CreativeSetting;
use App\Services\Creatives\CreativeAgentPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin → Creatives → Brief: the brand brief (Markdown) the chat agent always
 * follows. Every staff role may edit it (collaborators are the marketing
 * team). It has its own "last edited by"; the cap's `updated_by_*` on the
 * same settings row is untouched.
 */
class AdminCreativeBriefController extends Controller
{
    public const MAX_CHARS = 20000;

    public function __construct(private CreativeAgentPresenter $presenter) {}

    /** GET /api/admin/creatives/brief */
    public function show(): JsonResponse
    {
        return response()->json(['success' => true, 'brief' => $this->presenter->brief(CreativeSetting::current())]);
    }

    /** PUT /api/admin/creatives/brief {markdown} */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'markdown' => ['present', 'nullable', 'string', 'max:'.self::MAX_CHARS],
        ]);

        CreativeSetting::current();
        CreativeSetting::query()->whereKey(CreativeSetting::ID)->toBase()->update([
            'brief_markdown' => (string) ($data['markdown'] ?? ''),
            'brief_updated_by_name' => $request->user()?->name,
            'brief_updated_at' => now(),
        ]);

        return response()->json(['success' => true, 'brief' => $this->presenter->brief(CreativeSetting::current())]);
    }
}
