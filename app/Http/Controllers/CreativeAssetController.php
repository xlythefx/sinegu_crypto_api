<?php

namespace App\Http\Controllers;

use App\Models\CreativeAudioAsset;
use App\Models\CreativeCharacterRef;
use App\Models\CreativeGeneration;
use App\Services\Creatives\CreativeAudio;
use App\Services\Creatives\CreativeReferences;
use App\Services\Creatives\HiggsfieldGateway;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Creatives routes an `<img src>`, an `<audio src>` or a download link
 * opens, which cannot carry a Bearer token. They sit OUTSIDE the auth group and are
 * reachable only through a short-lived SIGNED relative path that the staff
 * API hands out (CreativePresenter) — the signature is the authorisation.
 */
class CreativeAssetController extends Controller
{
    private const EXTENSIONS = [
        'video/mp4' => 'mp4',
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/webp' => 'webp',
    ];

    /** GET /api/creatives/refs/{id}/image (signed, 2 h) — a stored reference image, inline. */
    public function refImage(int $id): Response
    {
        $ref = CreativeCharacterRef::find($id);
        $disk = CreativeReferences::disk();
        if (! $ref || ! $disk->exists($ref->path)) {
            return $this->error('NOT_FOUND', 'Reference image not found.', 404);
        }

        return $disk->response($ref->path, null, [
            'Content-Type' => $ref->mime,
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }

    /** GET /api/creatives/audio/{id}/file (signed, 2 h) — a stored audio file, inline, for the editor. */
    public function audioFile(int $id): Response
    {
        $asset = CreativeAudioAsset::find($id);
        $disk = CreativeAudio::disk();
        if (! $asset || ! $disk->exists($asset->path)) {
            return $this->error('NOT_FOUND', 'Audio file not found.', 404);
        }

        return $disk->response($asset->path, null, [
            'Content-Type' => $asset->mime,
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }

    /**
     * GET /api/creatives/generations/{id}/download/{index} (signed, 30 min).
     *
     * Streams one output from Higgsfield straight to the browser — never
     * written to our disk. The URL fetched is the one recorded from
     * Higgsfield's own status response for that row and index; nothing in
     * the request can name another.
     */
    public function download(int $id, int $index, HiggsfieldGateway $gateway): Response
    {
        $generation = CreativeGeneration::find($id);
        if (! $generation) {
            return $this->error('NOT_FOUND', 'Generation not found.', 404);
        }
        $output = $generation->liveOutput($index);
        if ($output === null) {
            return $this->expired();
        }

        $upstream = $gateway->openOutput($output['url']);
        if ($upstream === null || $upstream->serverError()) {
            return $this->error('HIGGSFIELD_UNAVAILABLE', 'Higgsfield did not answer. Try again in a minute.', 503);
        }
        if (! $upstream->successful()) {
            // Higgsfield deleted it early, or the link stopped working.
            return $this->expired();
        }

        $type = strtolower(trim(explode(';', (string) $upstream->header('Content-Type'))[0]));
        $ext = self::EXTENSIONS[$type] ?? $this->extensionFromUrl($output['url']) ?? ($output['type'] === 'video' ? 'mp4' : 'png');
        if (! isset(self::EXTENSIONS[$type])) {
            $type = $output['type'] === 'video' ? 'video/mp4' : 'application/octet-stream';
        }

        $headers = [
            'Content-Type' => $type,
            'Content-Disposition' => 'attachment; filename="pixel-alpha-'.$generation->id.'-'.($index + 1).'.'.$ext.'"',
            'X-Accel-Buffering' => 'no',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ];
        $length = $upstream->header('Content-Length');
        // A compressed upstream is decoded on the way through, so its length would lie.
        if (is_numeric($length) && $upstream->header('Content-Encoding') === '') {
            $headers['Content-Length'] = (string) (int) $length;
        }

        $body = $upstream->toPsrResponse()->getBody();

        return new StreamedResponse(function () use ($body) {
            while (! $body->eof()) {
                echo $body->read(262144);
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                flush();
            }
            $body->close();
        }, 200, $headers);
    }

    private function extensionFromUrl(string $url): ?string
    {
        $ext = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        return in_array($ext, ['mp4', 'png', 'jpg', 'webp'], true) ? $ext : ($ext === 'jpeg' ? 'jpg' : null);
    }

    private function expired(): JsonResponse
    {
        return $this->error('EXPIRED', 'This file is gone: Higgsfield keeps results for 7 days.', 410);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'error_code' => $code, 'message' => $message], $status);
    }
}
