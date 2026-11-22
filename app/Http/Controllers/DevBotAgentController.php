<?php

namespace App\Http\Controllers;

use App\Models\DevBotJob;
use App\Services\DevBot\DevBotJobs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/devbot/agent/* — the developer agent on Christian's PC (2026-11-22).
 *
 * The agent's own key in X-Devbot-Agent-Key (DEV_BOT_AGENT_KEY, box-local);
 * none configured = shut (403). It can only take queued jobs and report on
 * them — no data read, no write anywhere else. The one sensitive answer,
 * `private-strings`, exists so the agent can REFUSE to publish them.
 */
class DevBotAgentController extends Controller
{
    public function __construct(private DevBotJobs $jobs) {}

    private function authorised(Request $request): bool
    {
        $key = (string) config('services.dev_bot.agent_key', '');
        $given = (string) $request->header('X-Devbot-Agent-Key', '');

        return strlen($key) >= 24 && hash_equals($key, $given);
    }

    /** POST /api/devbot/agent/claim → the next job, or {job: null}. */
    public function claim(Request $request): JsonResponse
    {
        if (! $this->authorised($request)) {
            return response()->json(['error' => 'FORBIDDEN'], 403);
        }
        $job = $this->jobs->claim();

        return response()->json(['job' => $job ? [
            'id' => $job->id,
            'source' => $job->source,
            'prompt' => $job->prompt,
            'context' => $job->context ?? (object) [],
        ] : null]);
    }

    /** POST /api/devbot/agent/jobs/{id}/progress {text} */
    public function progress(Request $request, int $id): JsonResponse
    {
        if (! $this->authorised($request)) {
            return response()->json(['error' => 'FORBIDDEN'], 403);
        }
        $data = $request->validate(['text' => ['required', 'string', 'max:2000']]);
        $job = DevBotJob::where('status', DevBotJob::WORKING)->findOrFail($id);
        $this->jobs->progress($job, $data['text']);

        return response()->json(['ok' => true]);
    }

    /** POST /api/devbot/agent/jobs/{id}/finish {ok, summary, links[]} */
    public function finish(Request $request, int $id): JsonResponse
    {
        if (! $this->authorised($request)) {
            return response()->json(['error' => 'FORBIDDEN'], 403);
        }
        $data = $request->validate([
            'ok' => ['required', 'boolean'],
            'summary' => ['required', 'string', 'max:20000'],
            'links' => ['nullable', 'array', 'max:6'],
            'links.*.label' => ['required', 'string', 'max:120'],
            'links.*.url' => ['required', 'string', 'max:500'],
        ]);
        $job = DevBotJob::where('status', DevBotJob::WORKING)->findOrFail($id);
        $this->jobs->finish($job, (bool) $data['ok'], $data['summary'], $data['links'] ?? []);

        return response()->json(['ok' => true]);
    }

    /** GET /api/devbot/agent/private-strings → what must never be pushed to GitHub. */
    public function privateStrings(Request $request): JsonResponse
    {
        if (! $this->authorised($request)) {
            return response()->json(['error' => 'FORBIDDEN'], 403);
        }

        return response()->json(['strings' => $this->jobs->privateStrings()]);
    }
}
