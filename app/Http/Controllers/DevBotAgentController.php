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
            // What the agent learned before (Admin → Error Bot → Memory), read first.
            'memories' => $this->jobs->memoriesForAgent(),
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
            // Something worth remembering for the next job (optional).
            'memory' => ['nullable', 'array'],
            'memory.title' => ['required_with:memory', 'string', 'max:200'],
            'memory.body' => ['required_with:memory', 'string', 'max:6000'],
            'memory.replaces_id' => ['nullable', 'integer'],
        ]);
        $job = DevBotJob::where('status', DevBotJob::WORKING)->findOrFail($id);
        $this->jobs->finish($job, (bool) $data['ok'], $data['summary'], $data['links'] ?? [], $data['memory'] ?? null);

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/devbot/agent/merged — one of the agent's pull requests was
     * merged on GitHub; the bot offers the deploy (DevBotJobs::pullRequestMerged).
     * The URL must be that repo's own pull request, so the message can only
     * ever link to GitHub.
     */
    public function merged(Request $request): JsonResponse
    {
        if (! $this->authorised($request)) {
            return response()->json(['error' => 'FORBIDDEN'], 403);
        }
        $data = $request->validate([
            'repo' => ['required', 'in:web,api'],
            'number' => ['required', 'integer', 'min:1'],
            'url' => ['required', 'string', 'max:300', 'regex:#^https://github\.com/xlythefx/sinegu_crypto(_api)?/pull/\d+$#'],
            'title' => ['required', 'string', 'max:300'],
            'branch' => ['nullable', 'string', 'max:100'],
            'files' => ['present', 'array', 'max:500'],
            'files.*' => ['string', 'max:300'],
            'job_id' => ['nullable', 'integer'],
        ]);
        $action = $this->jobs->pullRequestMerged($data);

        return response()->json(['ok' => true, 'targets' => $action->payload['targets'] ?? []]);
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
