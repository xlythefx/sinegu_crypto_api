<?php

namespace App\Services\Creatives;

use App\Models\CreativeAudioAsset;
use App\Models\CreativeVideo;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The Creatives AUDIO library (modelled on {@see CreativeReferences}): music
 * the team holds the rights to and recorded voiceovers, the second and last
 * kind of file the feature keeps (owner's decision, 2026-11-12). They live on
 * the API's PRIVATE local disk (`creatives/audio/<random>.<ext>`) and are
 * served only through a signed link; a video is never stored.
 *
 * Limits: ≤ 15 MB each, ≤ 40 in all — counted under a lock, so two uploads at
 * once cannot make it 41. A file a video's timeline still plays
 * (`music.asset_id` / `voiceover.asset_id`) cannot be deleted.
 */
final class CreativeAudio
{
    public const MAX = 40;

    public const MAX_KB = 15 * 1024;

    public const DIR = 'creatives/audio';

    /** How long a file's signed path works (like a reference image's). */
    public const LINK_MINUTES = 120;

    /** The types kept, with the extension stored. */
    public const MIMES = [
        'audio/mpeg' => 'mp3',
        'audio/wav' => 'wav',
        'audio/x-wav' => 'wav',
        'audio/mp4' => 'm4a',
        'audio/x-m4a' => 'm4a',
        'audio/aac' => 'aac',
        'audio/webm' => 'webm',
        'audio/ogg' => 'ogg',
    ];

    /**
     * What a server's type sniffing calls the same files: a WebM/MP4/Ogg
     * container reads as video/* or application/* to libmagic even when it
     * holds sound only (a browser's MediaRecorder voiceover is
     * audio/webm;codecs=opus but sniffs as video/webm). Stored and served as
     * the audio type.
     */
    private const ALIASES = [
        'video/webm' => 'audio/webm',
        'video/mp4' => 'audio/mp4',
        'application/ogg' => 'audio/ogg',
        'audio/mp3' => 'audio/mpeg',
        'audio/vnd.wave' => 'audio/wav',
        'audio/wave' => 'audio/wav',
        'audio/x-hx-aac-adts' => 'audio/aac',
        'audio/m4a' => 'audio/x-m4a',
    ];

    /** The PRIVATE local disk (storage/app/private), never `public`. */
    public static function disk(): FilesystemAdapter
    {
        return Storage::disk('local');
    }

    /** An uploaded file (size already validated); its type is checked here. */
    public function store(UploadedFile $file, string $kind, string $label, ?int $durationMs, ?string $byName): CreativeAudioAsset
    {
        $mime = $this->mime($file) ?? throw ValidationException::withMessages([
            'file' => 'The file must be an MP3, WAV, M4A, AAC, WebM or Ogg audio file.',
        ]);

        $lock = Cache::lock('creatives-audio-upload', 30);
        try {
            $lock->block(10);
        } catch (LockTimeoutException) {
            throw new CreativeException('SESSION_BUSY', 'Another upload is being saved right now. Try again in a moment.', 409);
        }

        try {
            if (CreativeAudioAsset::count() >= self::MAX) {
                throw new CreativeException(
                    'AUDIO_LIBRARY_FULL',
                    'The audio library already holds '.self::MAX.' files. Delete one first.',
                    422,
                );
            }

            // Random name: nothing about the file or its uploader is in the path.
            $path = self::DIR.'/'.Str::random(40).'.'.self::MIMES[$mime];
            $stream = fopen($file->getRealPath(), 'rb');
            try {
                $stored = $stream !== false && self::disk()->writeStream($path, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            if (! $stored) {
                throw new CreativeException('STORAGE_FAILED', 'Could not save the file on the server.', 500);
            }

            try {
                return CreativeAudioAsset::create([
                    'kind' => $kind,
                    'label' => trim($label),
                    'mime' => $mime,
                    'bytes' => (int) $file->getSize(),
                    'duration_ms' => $durationMs,
                    'path' => $path,
                    'created_by_name' => mb_substr((string) ($byName ?? 'Someone'), 0, 120),
                ]);
            } catch (Throwable $e) {
                self::disk()->delete($path);
                throw $e;
            }
        } finally {
            $lock->release();
        }
    }

    /** The row and its file — refused while a video's timeline plays it. */
    public function delete(CreativeAudioAsset $asset): void
    {
        if ($this->usedBy($asset->id) !== null) {
            throw new CreativeException(
                'AUDIO_IN_USE',
                'A video project still uses this file for its music or voiceover. Take it off that video first.',
                409,
            );
        }
        self::disk()->delete($asset->path);
        $asset->delete();
    }

    /** The first video whose timeline references the asset, or null. */
    public function usedBy(int $assetId): ?int
    {
        foreach (CreativeVideo::query()->select(['id', 'timeline'])->orderBy('id')->cursor() as $video) {
            if (in_array($assetId, self::assetIds(is_array($video->timeline) ? $video->timeline : []), true)) {
                return (int) $video->id;
            }
        }

        return null;
    }

    /**
     * The audio ids a TimelineDoc references: `music.asset_id` (source
     * "asset") and `voiceover.asset_id`.
     *
     * @return list<int>
     */
    public static function assetIds(array $timeline): array
    {
        $ids = [];
        $music = $timeline['music'] ?? null;
        if (is_array($music) && ($music['source'] ?? null) === 'asset' && is_numeric($music['asset_id'] ?? null)) {
            $ids[] = (int) $music['asset_id'];
        }
        $voice = $timeline['voiceover'] ?? null;
        if (is_array($voice) && is_numeric($voice['asset_id'] ?? null)) {
            $ids[] = (int) $voice['asset_id'];
        }

        return $ids;
    }

    /** The CreativeAudioAsset shape: `url` is a signed RELATIVE path (2 h). */
    public function present(CreativeAudioAsset $asset): array
    {
        return [
            'id' => (int) $asset->id,
            'kind' => $asset->kind,
            'label' => $asset->label,
            'mime' => $asset->mime,
            'bytes' => (int) $asset->bytes,
            'duration_ms' => $asset->duration_ms !== null ? (int) $asset->duration_ms : null,
            'url' => URL::temporarySignedRoute(
                'creatives.audio.file',
                now()->addMinutes(self::LINK_MINUTES),
                ['id' => $asset->id],
                absolute: false,
            ),
            'created_by_name' => $asset->created_by_name,
            'created_at' => $asset->created_at?->toIso8601String(),
        ];
    }

    /** The type we store for this upload, or null when it is not audio we keep. */
    private function mime(UploadedFile $file): ?string
    {
        $mime = strtolower(trim(explode(';', (string) $file->getMimeType())[0]));
        $mime = self::ALIASES[$mime] ?? $mime;

        return isset(self::MIMES[$mime]) ? $mime : null;
    }
}
