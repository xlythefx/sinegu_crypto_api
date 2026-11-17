<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreativeVideoRequest;
use App\Models\CreativeVideo;
use App\Models\UserCredential;
use App\Services\Creatives\CreativeAgentJobs;
use App\Services\Creatives\CreativeAgentPresenter;
use App\Services\Creatives\CreativeBudget;
use App\Services\Creatives\CreativeException;
use App\Services\Creatives\CreativeVideoHistory;
use App\Services\Creatives\CreativeVideoPresenter;
use App\Services\Creatives\CreativeVideos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin → Creatives → the video editor's projects. Every staff role may make
 * and edit them (collaborators are the marketing team). Exports happen in
 * the browser; only their metadata comes back here — the MP4 is never stored.
 *
 * The read refreshes the video's running clips first, so a person watching
 * the timeline sees each clip land without waiting for the minute poll.
 * Proposing scenes prices their clips as ONE job and spends nothing; Go is
 * the existing job approve (any staff member may Go a job with no chat).
 */
class AdminCreativeVideoController extends Controller
{
    public function __construct(
        private CreativeVideos $videos,
        private CreativeVideoPresenter $presenter,
        private CreativeAgentJobs $jobs,
        private CreativeAgentPresenter $agentPresenter,
        private CreativeBudget $budget,
    ) {}

    /** GET /api/admin/creatives/videos?limit= — newest first. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['limit' => ['nullable', 'integer', 'between:1,50']]);
        $rows = CreativeVideo::query()->with('style:id,slug,name')
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->limit((int) ($data['limit'] ?? 20))
            ->get();

        return response()->json([
            'success' => true,
            'videos' => $rows->map(fn (CreativeVideo $v) => $this->presenter->summary($v))->values(),
        ]);
    }

    /** POST /api/admin/creatives/videos {title, platform, aspect, style_id?, plan_id?} */
    public function store(CreativeVideoRequest $request): JsonResponse
    {
        $video = $this->videos->create($request->validated(), $this->user($request));

        return response()->json(['success' => true, 'video' => $this->presenter->video($video->fresh())], 201);
    }

    /** GET /api/admin/creatives/videos/{id} — its running clips refreshed first. */
    public function show(int $id): JsonResponse
    {
        $video = $this->find($id);
        // Clips not checked for 20 s are asked about; each running job is
        // advanced at most every 4 s (the session read's throttle).
        $this->jobs->refreshVideo($video);

        return $this->answer($video->fresh());
    }

    /**
     * GET /api/admin/creatives/videos/{id}/history — every attempt at this
     * video's scenes (made, failed and why, how long, cost, who), newest first.
     */
    public function history(int $id): JsonResponse
    {
        return response()->json([
            'success' => true,
            'history' => app(CreativeVideoHistory::class)->forVideo($this->find($id)),
        ]);
    }

    /** PUT /api/admin/creatives/videos/{id} — the client-owned fields only. */
    public function update(CreativeVideoRequest $request, int $id): JsonResponse
    {
        $video = $this->videos->update($this->find($id), $request->body(), $this->user($request));

        return $this->answer($video->fresh());
    }

    /**
     * POST /api/admin/creatives/videos/{id}/scenes —
     * `{kind: 'clip', generation_id, output_index, position?}` or
     * `{kind: 'template', template: {key, title, value, subtitle}, seconds, position?}`.
     */
    public function addScene(Request $request, int $id): JsonResponse
    {
        $video = $this->find($id);
        $data = $request->validate([
            'kind' => ['required', 'string', Rule::in(['clip', 'template'])],
            'generation_id' => ['required_if:kind,clip', 'nullable', 'integer', 'min:1'],
            'output_index' => ['nullable', 'integer', 'min:0', 'max:50'],
            'template' => ['required_if:kind,template', 'nullable', 'array'],
            'seconds' => ['required_if:kind,template', 'nullable', 'integer'],
            'position' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ]);
        $position = isset($data['position']) ? (int) $data['position'] : null;

        $video = $data['kind'] === 'clip'
            ? $this->videos->addClip($video, (int) $data['generation_id'], (int) ($data['output_index'] ?? 0), $position)
            : $this->videos->addTemplate($video, (array) $request->input('template', []), (int) $data['seconds'], $position);

        return $this->answer($video->fresh());
    }

    /** DELETE /api/admin/creatives/videos/{id}/scenes/{sceneId} — the others close up. */
    public function destroyScene(int $id, int $sceneId): JsonResponse
    {
        $video = $this->videos->removeScene($this->find($id), $sceneId);

        return $this->answer($video->fresh());
    }

    /** DELETE /api/admin/creatives/videos/{id} — the scenes go; the generations stay. */
    public function destroy(int $id): JsonResponse
    {
        $this->videos->delete($this->find($id));

        return response()->json(['success' => true]);
    }

    /** POST /api/admin/creatives/videos/{id}/exported {seconds, bytes} — metadata only. */
    public function exported(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'seconds' => ['required', 'numeric', 'min:0', 'max:999'],
            'bytes' => ['required', 'integer', 'min:0', 'max:4294967295'],
        ]);
        $video = $this->videos->exported($this->find($id), (float) $data['seconds'], (int) $data['bytes'], $this->user($request));

        return $this->answer($video->fresh());
    }

    /**
     * POST /api/admin/creatives/videos/{id}/scenes/propose
     * `{scenes: SceneDraft[1–6], replace_scene_id?}` — prices the clips as one
     * job and creates their `planned` slots; spends nothing.
     */
    public function propose(Request $request, int $id): JsonResponse
    {
        $video = $this->find($id);
        $data = $request->validate([
            'scenes' => ['required', 'array', 'min:1', 'max:'.CreativeVideos::MAX_DRAFTS],
            'scenes.*' => ['array'],
            'replace_scene_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $user = $this->user($request);
        $made = $this->videos->proposeScenes(
            $video,
            array_values((array) $request->input('scenes', [])),
            isset($data['replace_scene_id']) ? (int) $data['replace_scene_id'] : null,
            $user,
        );

        return response()->json([
            'success' => true,
            'job' => $made['job'] !== null ? $this->agentPresenter->job($made['job']) : null,
            'video' => $this->presenter->video($made['video']->fresh()),
            'budget' => $this->budget->summary(),
        ]);
    }

    private function find(int $id): CreativeVideo
    {
        return CreativeVideo::find($id) ?? throw CreativeException::notFound('Video not found.');
    }

    private function answer(CreativeVideo $video): JsonResponse
    {
        return response()->json(['success' => true, 'video' => $this->presenter->video($video)]);
    }

    private function user(Request $request): UserCredential
    {
        /** @var UserCredential $user */
        $user = $request->user();

        return $user;
    }
}
