<?php

namespace App\Services\Creatives;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The one class that talks to Higgsfield (Admin → Creatives).
 *
 * Same posture as {@see \App\Services\Discord\DiscordGateway}: nothing here
 * throws. Every API call answers a {@see HiggsfieldReply} that separates a 2xx,
 * a 4xx refusal (with Higgsfield's own `detail` text) and NO ANSWER (a
 * transport error or a 5xx) — for a submit those last two are different
 * money: a refusal costs nothing, an unanswered submit may have started a
 * paid job.
 *
 * The key never leaves this class: not in a log line (every logged text is
 * scrubbed of it), not in a response, not in diagnostics (presence booleans
 * only). The Authorization header goes to the Higgsfield API base ONLY — the
 * presigned upload URL and the output files are fetched WITHOUT it, because
 * they live on other hosts and a key sent there is a key handed to them.
 *
 * Facts this class encodes (docs.higgsfield.ai, verified 2026-11-10):
 *  - auth is `Authorization: Key {KEY_ID}:{KEY_SECRET}`; a wrong key answers
 *    401 `{"detail":"Invalid credentials"}`;
 *  - submit `POST /{endpoint}` → `{request_id, status, …}`; the price check
 *    `POST /estimate/{endpoint}` with the same body is free;
 *  - status `GET /requests/{id}/status`; cancel `POST /requests/{id}/cancel`
 *    (only while queued);
 *  - an upload is `POST /files/generate-upload-url` then a PUT of the raw
 *    bytes to a presigned S3 URL with EVERY header Higgsfield returned.
 */
class HiggsfieldGateway
{
    public const DEFAULT_BASE = 'https://api.higgsfield.ai';

    /** What a request id must look like before it goes into a URL path or the DB. */
    private const REQUEST_ID = '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/';

    // ---- configuration ---------------------------------------------------

    public function configured(): bool
    {
        return $this->keyId() !== '' && $this->keySecret() !== '';
    }

    /** Presence booleans only — a diagnostic must never become a key leak. */
    public function diagnostics(): array
    {
        return [
            'key_id_set' => $this->keyId() !== '',
            'key_secret_set' => $this->keySecret() !== '',
            'ca_bundle_set' => (string) config('services.higgsfield.ca_bundle') !== '',
        ];
    }

    public static function validRequestId(mixed $id): bool
    {
        return is_string($id) && preg_match(self::REQUEST_ID, $id) === 1;
    }

    // ---- API -------------------------------------------------------------

    /** The free price check: `{type: estimate, usd}` or `{type: description, …}`. */
    public function estimate(string $endpoint, array $body): HiggsfieldReply
    {
        return $this->api('post', '/estimate/'.$this->path($endpoint), $body, 'estimate');
    }

    /** Starts a PAID generation. */
    public function submit(string $endpoint, array $body): HiggsfieldReply
    {
        return $this->api('post', '/'.$this->path($endpoint), $body, 'submit');
    }

    public function status(string $requestId): HiggsfieldReply
    {
        if (! self::validRequestId($requestId)) {
            return HiggsfieldReply::refused(400, 'Invalid request id.');
        }

        return $this->api('get', '/requests/'.$requestId.'/status', null, 'status');
    }

    public function cancel(string $requestId): HiggsfieldReply
    {
        if (! self::validRequestId($requestId)) {
            return HiggsfieldReply::refused(400, 'Invalid request id.');
        }

        return $this->api('post', '/requests/'.$requestId.'/cancel', [], 'cancel');
    }

    /** `{public_url, upload_url, content_type, upload_headers}`. */
    public function generateUploadUrl(string $contentType): HiggsfieldReply
    {
        return $this->api('post', '/files/generate-upload-url', ['content_type' => $contentType], 'upload-url');
    }

    /**
     * PUT the raw bytes to the presigned URL with exactly the headers
     * Higgsfield handed back — and never our Authorization header.
     *
     * @param  array<string, mixed>  $headers
     */
    public function putUpload(string $uploadUrl, array $headers, string $bytes): bool
    {
        if (! str_starts_with($uploadUrl, 'https://')) {
            Log::warning('Higgsfield gave an upload URL that is not https; not used.');

            return false;
        }

        $clean = [];
        foreach ($headers as $name => $value) {
            if (is_string($name) && (is_string($value) || is_numeric($value))) {
                $clean[$name] = (string) $value;
            }
        }
        $contentType = '';
        foreach ($clean as $name => $value) {
            if (strtolower($name) === 'content-type') {
                $contentType = $value;
                unset($clean[$name]);
            }
        }

        $response = $this->send(
            fn () => $this->bare(60)->withHeaders($clean)
                ->withBody($bytes, $contentType !== '' ? $contentType : 'application/octet-stream')
                ->put($uploadUrl),
            'upload.put',
        );

        return $response !== null && $response->successful();
    }

    /**
     * GET a file Higgsfield made (an output URL recorded from its own status
     * response), STREAMED — the caller reads it in chunks and nothing is
     * written to disk. No Authorization header: the files are on a CDN.
     * Null when the host was never reached.
     */
    public function openOutput(string $url): ?Response
    {
        if (! str_starts_with($url, 'https://')) {
            return null;
        }

        return $this->send(
            fn () => $this->bare(120)->withOptions(['stream' => true])->get($url),
            'output.get',
        );
    }

    // ---- transport -------------------------------------------------------

    private function api(string $method, string $path, ?array $body, string $what): HiggsfieldReply
    {
        if (! $this->configured()) {
            return HiggsfieldReply::refused(401, 'Higgsfield is not configured on this server.');
        }

        $url = $this->base().$path;
        $response = $this->send(
            fn () => $method === 'get'
                ? $this->authed()->get($url)
                : $this->authed()->post($url, $body === null || $body === [] ? (object) [] : $body),
            $what,
        );

        if ($response === null || $response->serverError()) {
            return HiggsfieldReply::noAnswer($response?->status());
        }
        if (! $response->successful()) {
            return HiggsfieldReply::refused($response->status(), $this->detailOf($response));
        }

        $data = $response->json();

        return HiggsfieldReply::ok($response->status(), is_array($data) ? $data : []);
    }

    /**
     * Run one request; never throw. Null means the host was never reached.
     *
     * @param  callable(): Response  $call
     */
    private function send(callable $call, string $what): ?Response
    {
        try {
            $response = $call();
        } catch (ConnectionException $e) {
            Log::warning('Higgsfield is unreachable.', ['what' => $what, 'exception' => $this->scrub($e->getMessage())]);

            return null;
        } catch (\Throwable $e) {
            Log::warning('Higgsfield call failed.', [
                'what' => $what,
                'class' => $e::class,
                'exception' => $this->scrub($e->getMessage()),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Higgsfield refused a request.', [
                'what' => $what,
                'status' => $response->status(),
                // Higgsfield's own text, never the request (which carries the key).
                'detail' => $this->detailOf($response),
            ]);
        }

        return $response;
    }

    /**
     * Higgsfield's `detail`: a string, or a FastAPI-style list of
     * `{loc, msg}` for a body it could not validate.
     */
    private function detailOf(Response $response): ?string
    {
        $detail = $response->json('detail');
        $text = null;
        if (is_string($detail)) {
            $text = $detail;
        } elseif (is_array($detail)) {
            $parts = [];
            foreach ($detail as $item) {
                if (is_string($item)) {
                    $parts[] = $item;
                } elseif (is_array($item) && is_string($item['msg'] ?? null)) {
                    $loc = is_array($item['loc'] ?? null) ? implode('.', array_filter($item['loc'], 'is_string')) : '';
                    $parts[] = ($loc !== '' && $loc !== 'body' ? str_replace('body.', '', $loc).': ' : '').$item['msg'];
                }
            }
            $text = $parts !== [] ? implode('; ', $parts) : null;
        } else {
            foreach (['message', 'error'] as $key) {
                if (is_string($response->json($key))) {
                    $text = $response->json($key);
                    break;
                }
            }
        }

        if ($text === null || trim($text) === '') {
            return null;
        }

        return mb_substr($this->scrub(trim($text)), 0, 480);
    }

    /** The key out of any text, and query strings (presigned signatures) off any URL in it. */
    private function scrub(string $text): string
    {
        foreach ([$this->keySecret(), $this->keyId()] as $value) {
            if ($value !== '') {
                $text = str_replace($value, '[redacted]', $text);
            }
        }

        return preg_replace('#(https?://[^\s?"\'<>]+)\?[^\s"\'<>]*#i', '$1?[…]', $text) ?? $text;
    }

    /** The Higgsfield API, with the key. */
    private function authed(): PendingRequest
    {
        $conf = (array) config('services.higgsfield');

        return $this->withCa(
            Http::timeout((int) ($conf['timeout'] ?? 20))
                ->connectTimeout((int) ($conf['connect_timeout'] ?? 5))
                ->acceptJson()
                ->asJson()
                ->withHeaders(['Authorization' => 'Key '.$this->keyId().':'.$this->keySecret()])
        );
    }

    /** Any other host (S3 upload, output CDN): NO Authorization header, ever. */
    private function bare(int $timeout): PendingRequest
    {
        return $this->withCa(
            Http::timeout(max($timeout, (int) config('services.higgsfield.timeout', 20)))
                ->connectTimeout((int) config('services.higgsfield.connect_timeout', 5))
        );
    }

    private function withCa(PendingRequest $request): PendingRequest
    {
        $ca = (string) config('services.higgsfield.ca_bundle');

        return $ca !== '' ? $request->withOptions(['verify' => $ca]) : $request;
    }

    /** An endpoint from the preset registry, as a URL path (never client text). */
    private function path(string $endpoint): string
    {
        return implode('/', array_map('rawurlencode', explode('/', trim($endpoint, '/'))));
    }

    private function base(): string
    {
        $base = (string) config('services.higgsfield.base_url');

        return rtrim($base !== '' ? $base : self::DEFAULT_BASE, '/');
    }

    private function keyId(): string
    {
        return trim((string) config('services.higgsfield.key_id'));
    }

    private function keySecret(): string
    {
        return trim((string) config('services.higgsfield.key_secret'));
    }
}
