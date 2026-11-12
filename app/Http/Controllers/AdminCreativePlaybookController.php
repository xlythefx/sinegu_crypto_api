<?php

namespace App\Http\Controllers;

use App\Models\CreativeSetting;
use App\Services\Creatives\CreativeAgentPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin → Creatives → Playbook: how the team builds a VIDEO (Markdown) — the
 * base every video follows, read by the chat agent in every [Context] beside
 * the brand brief. The same rules as the brief ({@see AdminCreativeBriefController}):
 * every staff role may edit it, it has its own "last edited by", and the
 * cap's `updated_by_*` on the same settings row is untouched.
 */
class AdminCreativePlaybookController extends Controller
{
    public const MAX_CHARS = 20000;

    public function __construct(private CreativeAgentPresenter $presenter) {}

    /** GET /api/admin/creatives/playbook */
    public function show(): JsonResponse
    {
        return response()->json(['success' => true, 'playbook' => $this->presenter->playbook(CreativeSetting::current())]);
    }

    /** PUT /api/admin/creatives/playbook {markdown} */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'markdown' => ['present', 'nullable', 'string', 'max:'.self::MAX_CHARS],
        ]);

        CreativeSetting::current();
        CreativeSetting::query()->whereKey(CreativeSetting::ID)->toBase()->update([
            'playbook_markdown' => (string) ($data['markdown'] ?? ''),
            'playbook_updated_by_name' => $request->user()?->name,
            'playbook_updated_at' => now(),
        ]);

        return response()->json(['success' => true, 'playbook' => $this->presenter->playbook(CreativeSetting::current())]);
    }
}
