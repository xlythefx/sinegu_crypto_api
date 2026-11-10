<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreativeAgentTurnRequest;
use App\Models\CreativeAgentJob;
use App\Models\UserCredential;
use App\Services\Creatives\CreativeAgent;
use App\Services\Creatives\CreativeAgentJobs;
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
 * id answers NOT_FOUND, and so does a job in someone else's chat.
 */
class AdminCreativeAgentController extends Controller
{
    public function __construct(
        private CreativeAgent $agent,
        private CreativeAgentJobs $jobs,
        private CreativeAgentPresenter $presenter,
        private CreativeBudget $budget,
    ) {}

    /** GET /api/admin/creatives/agent/session?id= — theirs if live, else their newest live, else a new one. */
    public function session(Request $request): JsonResponse
    {
        $data = $request->validate(['id' => ['nullable', 'string', 'max:64']]);
        $session = $this->agent->resolveSession($this->user($request), $data['id'] ?? null);

        return response()->json(['success' => true, ...$this->agent->snapshot($session)]);
    }

    /** POST /api/admin/creatives/agent/sessions — "New chat". */
    public function newSession(Request $request): JsonResponse
    {
        $session = $this->agent->newSession($this->user($request));

        return response()->json([
            'success' => true,
            'session' => ['id' => $session->id, 'mode' => $session->last_mode],
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
        $result = $this->agent->turn($session, $user, $data['mode'], $data['text'] ?? null);

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

    /** A job in the caller's own chat. */
    private function job(UserCredential $user, int $id): CreativeAgentJob
    {
        $job = CreativeAgentJob::with('session')->find($id);
        if ($job === null || $job->session?->uni_id !== $user->uni_id) {
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
