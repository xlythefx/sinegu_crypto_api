<?php

namespace App\Services\Creatives;

use App\Models\CreativeCharacter;
use App\Models\CreativeCharacterRef;
use App\Models\CreativeGeneration;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The character's reference images — the ONLY files the Creatives feature
 * keeps. They are its identity, so they live on the API's private local disk
 * (`creatives/refs/<random>.<ext>`), are served only through a signed URL,
 * and are re-uploaded to Higgsfield for each generation that uses them
 * (Higgsfield's uploads are temporary).
 *
 * At most MAX per character, counted under a row lock on the character so
 * two uploads at once cannot make it nine.
 */
final class CreativeReferences
{
    public const MAX = 8;

    public const DIR = 'creatives/refs';

    /** A copied output may be at most this big. */
    public const MAX_COPY_BYTES = 10 * 1024 * 1024;

    /** The only kinds stored, by their sniffed type. */
    public const MIMES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    public function __construct(private HiggsfieldGateway $gateway) {}

    /** The PRIVATE local disk (storage/app/private), never `public`. */
    public static function disk(): FilesystemAdapter
    {
        return Storage::disk('local');
    }

    /** An uploaded file (already validated: png/jpg/webp, ≤ 5 MB). */
    public function storeUpload(CreativeCharacter $character, UploadedFile $file, ?string $label, ?string $byName): CreativeCharacterRef
    {
        $bytes = (string) file_get_contents($file->getRealPath());
        $mime = $this->sniff($bytes) ?? throw ValidationException::withMessages([
            'image' => 'The image must be a PNG, JPEG or WebP file.',
        ]);

        return $this->store($character, $bytes, $mime, $label, CreativeCharacterRef::SOURCE_UPLOAD, null, $byName);
    }

    /**
     * Copy one of a completed image generation's outputs into a stored
     * reference. Fetched from the URL Higgsfield's own status response gave
     * us (never a client-supplied one), ≤ 10 MB, and it must be an image.
     */
    public function copyFromGeneration(CreativeCharacter $character, ?CreativeGeneration $generation, int $index, ?string $label, ?string $byName): CreativeCharacterRef
    {
        $output = $generation?->kind === 'image' ? $generation->liveOutput($index) : null;
        if ($output === null || $output['type'] !== 'image') {
            throw $this->unavailable('That image is no longer available. Results stay at Higgsfield for 7 days.');
        }
        if ($this->count($character) >= self::MAX) {
            throw $this->tooMany();
        }

        $response = $this->gateway->openOutput($output['url']);
        if ($response === null || $response->serverError()) {
            throw $this->unavailable('Could not fetch that image from Higgsfield. Try again in a minute.');
        }
        if (! $response->successful()) {
            throw $this->unavailable('That image is no longer available at Higgsfield.');
        }
        $type = strtolower((string) $response->header('Content-Type'));
        if ($type !== '' && ! str_starts_with($type, 'image/')) {
            throw $this->unavailable('That file is not an image.');
        }
        $length = (int) $response->header('Content-Length');
        if ($length > self::MAX_COPY_BYTES) {
            throw $this->unavailable('That image is larger than 10 MB.');
        }

        $body = $response->toPsrResponse()->getBody();
        $bytes = '';
        while (! $body->eof()) {
            $bytes .= $body->read(65536);
            if (strlen($bytes) > self::MAX_COPY_BYTES) {
                $body->close();
                throw $this->unavailable('That image is larger than 10 MB.');
            }
        }

        $mime = $this->sniff($bytes);
        if ($mime === null) {
            throw $this->unavailable('That file is not a PNG, JPEG or WebP image.');
        }

        return $this->store($character, $bytes, $mime, $label, CreativeCharacterRef::SOURCE_GENERATION, $generation->id, $byName);
    }

    /** The row and its file. */
    public function delete(CreativeCharacterRef $ref): void
    {
        self::disk()->delete($ref->path);
        $ref->delete();
    }

    /** The stored bytes, or null if the file has gone missing. */
    public function bytes(CreativeCharacterRef $ref): ?string
    {
        $disk = self::disk();
        if (! $disk->exists($ref->path)) {
            return null;
        }
        $bytes = $disk->get($ref->path);

        return is_string($bytes) && $bytes !== '' ? $bytes : null;
    }

    public function count(CreativeCharacter $character): int
    {
        return CreativeCharacterRef::where('character_id', $character->id)->count();
    }

    private function store(CreativeCharacter $character, string $bytes, string $mime, ?string $label, string $source, ?int $generationId, ?string $byName): CreativeCharacterRef
    {
        return DB::transaction(function () use ($character, $bytes, $mime, $label, $source, $generationId, $byName) {
            CreativeCharacter::query()->whereKey($character->id)->lockForUpdate()->first();
            if ($this->count($character) >= self::MAX) {
                throw $this->tooMany();
            }

            // Random name: nothing about the file or its uploader is in the path.
            $path = self::DIR.'/'.Str::random(40).'.'.self::MIMES[$mime];
            if (! self::disk()->put($path, $bytes)) {
                throw new CreativeException('STORAGE_FAILED', 'Could not save the image on the server.', 500);
            }

            try {
                return CreativeCharacterRef::create([
                    'character_id' => $character->id,
                    'label' => $label !== null && trim($label) !== '' ? trim($label) : null,
                    'path' => $path,
                    'mime' => $mime,
                    'bytes' => strlen($bytes),
                    'source' => $source,
                    'generation_id' => $generationId,
                    'created_by_name' => $byName,
                ]);
            } catch (Throwable $e) {
                self::disk()->delete($path);
                throw $e;
            }
        });
    }

    /** The real type of the bytes, if it is one we keep. */
    private function sniff(string $bytes): ?string
    {
        if ($bytes === '') {
            return null;
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        return is_string($mime) && isset(self::MIMES[$mime]) ? $mime : null;
    }

    private function tooMany(): CreativeException
    {
        return new CreativeException(
            'TOO_MANY_REFERENCES',
            'The character already has '.self::MAX.' reference images. Delete one first.',
            422,
        );
    }

    private function unavailable(string $message): CreativeException
    {
        return new CreativeException('SOURCE_UNAVAILABLE', $message, 422);
    }
}
