<?php

namespace App\Http\Controllers;

use App\Models\CreativeAudioAsset;
use App\Services\Creatives\CreativeAudio;
use App\Services\Creatives\CreativeException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin → Creatives → the audio library: music the team holds the rights to
 * and recorded voiceovers, for the video editor's music and voice tracks.
 * Every staff role may upload and delete (collaborators are the marketing
 * team). Files are small (≤ 15 MB, ≤ 40 in all), on the private disk, and
 * reached only through a signed link.
 */
class AdminCreativeAudioController extends Controller
{
    public function __construct(private CreativeAudio $audio) {}

    /** GET /api/admin/creatives/audio — newest first. */
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'audio' => CreativeAudioAsset::query()->orderByDesc('id')->get()
                ->map(fn (CreativeAudioAsset $a) => $this->audio->present($a))->values(),
        ]);
    }

    /** POST /api/admin/creatives/audio (multipart `file`, `kind`, `label`, `duration_ms?`) */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.CreativeAudio::MAX_KB],
            'kind' => ['required', 'string', Rule::in(CreativeAudioAsset::KINDS)],
            'label' => ['required', 'string', 'max:80'],
            'duration_ms' => ['nullable', 'integer', 'min:0', 'max:3600000'],
        ]);

        $asset = $this->audio->store(
            $request->file('file'),
            $data['kind'],
            $data['label'],
            isset($data['duration_ms']) ? (int) $data['duration_ms'] : null,
            $request->user()?->name,
        );

        return response()->json(['success' => true, 'asset' => $this->audio->present($asset)], 201);
    }

    /** DELETE /api/admin/creatives/audio/{id} — refused while a video plays it. */
    public function destroy(int $id): JsonResponse
    {
        $asset = CreativeAudioAsset::find($id) ?? throw CreativeException::notFound('Audio file not found.');
        $this->audio->delete($asset);

        return response()->json(['success' => true]);
    }
}
