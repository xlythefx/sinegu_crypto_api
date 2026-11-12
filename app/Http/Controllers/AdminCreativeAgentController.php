<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreativeAgentTurnRequest;
use App\Models\CreativeAgentJob;
use App\Models\UserCredential;
use App\Models\CreativeAgentSession;
use App\Services\Creatives\CreativeAgent;
use App\Services\Creatives\CreativeAgentContext;
use App\Services\Creatives\CreativeAgentJobs;
use App\Services\Creatives\CreativeAgentPrompt;
use App\Services\Creatives\CreativeAgentPresenter;
use App\Services\Creatives\CreativeBudget;
use App\Services\Creatives\CreativeException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin → Creatives' chat agent: the person's chat, one loop step per
 * request (client-stepped — Cloudflare cuts a request at 100 s), and Go /
 * Cancel on the jobs it proposed.
 *
 * Every staff role may use it, collaborators (the marketing team) included,
 * in both modes. A chat belongs to the person who started it: anyone else's
 * id answers NOT_FOUND, and so does a job in someone else's chat. A job with
 * NO chat (proposed by the video editor) is the team's: any staff member may
 * Go or cancel it.
 */
class AdminCreativeAgentController extends Controller
{
    public function __construct(
        private CreativeAgent $agent,
        private CreativeAgentJobs $jobs,
        private CreativeAgentPresenter $presenter,
        private CreativeBudget $budget,
        private CreativeAgentContext $context,
    ) {}

    /**
     * GET /api/admin/creatives/agent/session?id=&video_id= — theirs if live,
     * else their newest live, else a new one. With `video_id`: that video's
     * editor chat (404 when the video does not exist); without it, the
     * workspace chat (never an editor chat).
     */
    public function session(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['nullable', 'string', 'max:64'],
            'video_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $session = $this->agent->resolveSession(
            $this->user($request),
            $data['id'] ?? null,
            isset($data['video_id']) ? (int) $data['video_id'] : null,
        );

        return response()->json(['success' => true, ...$this->agent->snapshot($session)]);
    }

    /**
     * GET /api/admin/creatives/agent/context?session_id= — READ-ONLY: exactly
     * what the model is told — the system rules, the tools, the playbook,
     * brief and memory (scrubbed, as sent) and the [Context] block the next
     * message would carry, with every document in full. It never writes the
     * chat's `sent_context` and never opens a chat: with no (or someone
     * else's) session id it shows a fresh chat's view.
     */
    public function context(Request $request): JsonResponse
    {
        $data = $request->validate(['session_id' => ['nullable', 'string', 'max:64']]);
        $user = $this->user($request);
        $session = isset($data['session_id']) ? CreativeAgentSession::find($data['session_id']) : null;
        if ($session !== null && $session->uni_id !== $user->uni_id) {
            throw CreativeException::notFound('Chat not found.');
        }
        $session ??= new CreativeAgentSession(['uni_id' => $user->uni_id, 'last_mode' => CreativeAgentSession::ASK]);

        $parts = $this->context->parts();

        return response()->json(['success' => true, 'context' => [
            'system' => CreativeAgentPrompt::SYSTEM,
            'tools' => array_map(fn (array $t) => [
                'name' => $t['name'],
                'description' => $t['description'],
                'input_schema' => $t['inputSchema'],
            ], CreativeAgentPrompt::tools()),
            'playbook' => $parts['playbook'],
            'brief' => $parts['brief'],
            'memory' => $parts['memory'],
            'styles' => $parts['styles'],
            'context_block' => $this->context->build($session, (string) ($session->last_mode ?? CreativeAgentSession::ASK), full: true)['text'],
        ]]);
    }

    /** POST /api/admin/creatives/agent/sessions {video_id?} — "New chat" (an editor chat with video_id). */
    public function newSession(Request $request): JsonResponse
    {
        $data = $request->validate(['video_id' => ['nullable', 'integer', 'min:1']]);
        $session = $this->agent->newSession($this->user($request), isset($data['video_id']) ? (int) $data['video_id'] : null);

        return response()->json([
            'success' => true,
            'session' => CreativeAgent::sessionShape($session),
            'messages' => [],
            'jobs' => [],
            'budget' => $this->budget->summary(),
        ]);
    }

    /** POST /api/admin/creatives/agent/turn — at most ONE model call. */
    public function turn(CreativeAgentTurnRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validated();
        $session = $this->agent->ownedSession($user, $data['session_id']);
        $focus = $this->agent->focusSceneIds($session, $data['focus_scene_ids'] ?? []);
        $result = $this->agent->turn($session, $user, $data['mode'], $data['text'] ?? null, $focus);

        return response()->json([
            'success' => true,
            'status' => $result['status'],
            'messages' => $result['messages'],
            'jobs' => $result['jobs'],
            'budget' => $this->budget->summary(),
            'cost_usd' => $result['cost_usd'],
        ]);
    }

    /** POST /api/admin/creatives/agent/jobs/{id}/approve — Go: spends, within the cap. */
    public function approve(Request $request, int $id): JsonResponse
    {
        $user = $this->user($request);
        $job = $this->job($user, $id);

        try {
            $job = $this->jobs->approve($job, $user);
        } catch (CreativeException $e) {
            throw $e->with(['job' => $this->presenter->job($job->fresh()), 'budget' => $this->budget->summary()]);
        }

        return response()->json(['success' => true, 'job' => $this->presenter->job($job), 'budget' => $this->budget->summary()]);
    }

    /** POST /api/admin/creatives/agent/jobs/{id}/cancel */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $job = $this->job($this->user($request), $id);

        try {
            $job = $this->jobs->cancel($job);
        } catch (CreativeException $e) {
            throw $e->with(['job' => $this->presenter->job($job->fresh()), 'budget' => $this->budget->summary()]);
        }

        return response()->json(['success' => true, 'job' => $this->presenter->job($job->fresh()), 'budget' => $this->budget->summary()]);
    }

    /** A job in the caller's own chat, or one with no chat (the editor's — the team's). */
    private function job(UserCredential $user, int $id): CreativeAgentJob
    {
        $job = CreativeAgentJob::with('session')->find($id);
        if ($job === null || ($job->session_id !== null && $job->session?->uni_id !== $user->uni_id)) {
            throw CreativeException::notFound('Job not found.');
        }

        return $job;
    }

    private function user(Request $request): UserCredential
    {
        /** @var UserCredential $user */
        $user = $request->user();

        return $user;
    }
}
