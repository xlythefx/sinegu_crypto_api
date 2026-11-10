<?php

namespace App\Services\Creatives;

use App\Models\CreativeCharacter;
use App\Models\CreativeCharacterRef;
use App\Models\CreativeGeneration;
use App\Models\UserCredential;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Starts a Higgsfield generation, in an order that keeps the cap true:
 *
 *   1. resolve what the request names (model, params, character, references,
 *      the image to animate) — refusals cost nothing;
 *   2. ask Higgsfield the price (free). No price, no generation;
 *   3. RESERVE that price under the settings-row lock (CreativeBudget), which
 *      creates the row as `submitting`;
 *   4. upload the reference images (Higgsfield's uploads are temporary, so
 *      every generation re-sends them);
 *   5. submit — and record what Higgsfield said:
 *        accepted     → queued, reservation held until the poller settles it;
 *        4xx refusal  → failed, charged 0, Higgsfield's own words returned;
 *        no answer    → failed, charge KEPT: the job may exist and cost money.
 */
final class CreativeGenerator
{
    public function __construct(
        private HiggsfieldGateway $gateway,
        private CreativeModels $models,
        private CreativeBudget $budget,
        private CreativeReferences $references,
        private GenerationPoller $poller,
    ) {}

    /**
     * The price check behind the "Generate" button: `{usd, allowed}`.
     *
     * `$placeholderSource`: price an animation whose image does not exist yet
     * (the chat agent's "picture of Pixel, then animate it" job, whose second
     * step animates the first step's output). The price check never fetches
     * images, so the placeholder prices it exactly; a real submit always
     * resolves a real source.
     */
    public function quote(array $input, bool $placeholderSource = false): array
    {
        $this->assertConfigured();
        $job = $this->prepare($input, $placeholderSource);
        $usd = $this->price($job);

        return ['usd' => $usd, 'allowed' => $this->budget->allows($usd)];
    }

    /** @param  int|null  $agentJobId  the chat agent's job this generation is a step of */
    public function create(array $input, ?UserCredential $user, ?int $agentJobId = null): CreativeGeneration
    {
        $this->assertConfigured();
        $job = $this->prepare($input);
        $usd = $this->price($job);

        $generation = $this->budget->reserve($usd, fn () => CreativeGeneration::create([
            'agent_job_id' => $agentJobId,
            'plan_id' => $job['plan_id'],
            'character_id' => $job['used_character'] ? $job['character']->id : null,
            'kind' => $job['preset']['kind'],
            'model' => $job['key'],
            'endpoint' => $job['preset']['endpoint'],
            'prompt' => $job['prompt'],
            'full_prompt' => $job['full_prompt'],
            'params' => $job['params'],
            'used_character' => $job['used_character'],
            'status' => CreativeGeneration::SUBMITTING,
            'estimated_usd' => $usd,
            'charged_usd' => $usd,
            'created_by_uni_id' => $user?->uni_id,
            'created_by_name' => $user?->name,
        ]));

        if ($generation === null) {
            $budget = $this->budget->summary();
            throw new CreativeException(
                'BUDGET_EXCEEDED',
                sprintf(
                    'This would go over the monthly limit: it costs about $%s and $%s of $%s is left this month.',
                    self::usd($usd), self::usd($budget['remaining_usd']), self::usd($budget['cap_usd']),
                ),
                422,
                ['budget' => $budget],
            );
        }

        // 4. The images the model is given.
        $imageUrls = [];
        $imageUrl = $job['source_url'];
        $refsToSend = $job['source_ref'] !== null ? [$job['source_ref']] : $job['refs']->all();
        foreach ($refsToSend as $ref) {
            $uploaded = $this->upload($ref);
            if ($uploaded instanceof HiggsfieldReply) {
                $this->settle($generation, CreativeGeneration::FAILED, 0, 'Could not send the reference images to Higgsfield, so nothing was started.');

                throw ($uploaded->isRefused()
                    ? new CreativeException('HIGGSFIELD_REJECTED', $uploaded->detail ?? 'Higgsfield refused the reference image upload.', 422)
                    : new CreativeException('HIGGSFIELD_UNAVAILABLE', 'Higgsfield did not answer while the reference images were being sent. Nothing was started; try again in a minute.', 502)
                )->with($this->after($generation));
            }
            $imageUrls[] = $uploaded;
        }
        if ($job['source_ref'] !== null) {
            $imageUrl = $imageUrls[0];
            $imageUrls = [];
        }

        // 5. The paid request.
        $body = $this->models->body($job['key'], $job['full_prompt'], $job['params'], $imageUrls, $imageUrl);
        $reply = $this->gateway->submit($job['preset']['endpoint'], $body);
        $requestId = $reply->data['request_id'] ?? null;

        if ($reply->isOk() && HiggsfieldGateway::validRequestId($requestId)) {
            $status = $reply->data['status'] ?? null;
            $generation->forceFill([
                'request_id' => $requestId,
                'status' => in_array($status, [CreativeGeneration::QUEUED, CreativeGeneration::IN_PROGRESS], true)
                    ? $status
                    : CreativeGeneration::QUEUED,
                'last_checked_at' => now(),
            ])->save();

            return $generation;
        }

        if ($reply->isRefused()) {
            $detail = $reply->detail ?? 'Higgsfield refused this request.';
            $this->settle($generation, CreativeGeneration::FAILED, 0, $detail);

            throw (new CreativeException('HIGGSFIELD_REJECTED', $detail, 422))->with($this->after($generation));
        }

        // No answer (or an answer without a request id): the job may exist.
        $this->settle($generation, CreativeGeneration::FAILED, null, GenerationPoller::UNCONFIRMED);

        throw (new CreativeException('SUBMIT_UNCONFIRMED', GenerationPoller::UNCONFIRMED.'.', 502))->with($this->after($generation));
    }

    /** Cancel a request still waiting in Higgsfield's queue (refunded). */
    public function cancel(CreativeGeneration $generation): CreativeGeneration
    {
        if ($generation->status !== CreativeGeneration::QUEUED || $generation->request_id === null) {
            throw $this->notCancellable();
        }
        $this->assertConfigured();

        $reply = $this->gateway->cancel($generation->request_id);
        if ($reply->isOk()) {
            $this->settle($generation, CreativeGeneration::CANCELED, 0, null);

            return $generation;
        }
        if ($reply->isRefused()) {
            // Most likely it started in the meantime; show where it is now.
            $this->poller->refresh($generation);

            throw $this->notCancellable('Higgsfield has already started it, so it can no longer be cancelled.')
                ->with(['generation' => app(CreativePresenter::class)->generation($generation->fresh(['plan']))]);
        }

        throw new CreativeException('HIGGSFIELD_UNAVAILABLE', 'Higgsfield did not answer. Try again in a minute.', 502);
    }

    // ---- steps -----------------------------------------------------------

    /**
     * Everything the request names, resolved and checked.
     *
     * @return array{key: string, preset: array, prompt: string, full_prompt: string, params: array, used_character: bool, character: CreativeCharacter, plan_id: ?int, refs: Collection<int, CreativeCharacterRef>, source_url: ?string, source_ref: ?CreativeCharacterRef}
     */
    private function prepare(array $input, bool $placeholderSource = false): array
    {
        $key = (string) $input['model'];
        $preset = $this->models->get($key);
        $params = $this->models->validateParams($key, $input['params'] ?? []);
        // A model that cannot run without references ignores "off".
        $useCharacter = $this->models->requiresCharacter($key)
            || ! array_key_exists('use_character', $input)
            || $input['use_character'] === null
            || (bool) $input['use_character'];
        $character = CreativeCharacter::current();
        $prompt = trim((string) $input['prompt']);

        $refs = collect();
        if ($useCharacter && $this->models->usesReferences($key)) {
            $refs = $character->refs()->get()->filter(fn (CreativeCharacterRef $r) => CreativeReferences::disk()->exists($r->path))->values();
            if ($refs->isEmpty()) {
                throw new CreativeException('NO_REFERENCES', 'Add at least one reference image of the character first.', 422);
            }
        }

        [$sourceUrl, $sourceRef] = match (true) {
            ! $this->models->needsSource($key) => [null, null],
            $placeholderSource => [CreativeModels::PLACEHOLDER_IMAGE, null],
            default => $this->source($input['source'] ?? null),
        };

        return [
            'key' => $key,
            'preset' => $preset,
            'prompt' => $prompt,
            'full_prompt' => CreativePrompt::assemble($prompt, $character, $useCharacter),
            'params' => $params,
            'used_character' => $useCharacter,
            'character' => $character,
            'plan_id' => isset($input['plan_id']) ? (int) $input['plan_id'] : null,
            'refs' => $refs,
            'source_url' => $sourceUrl,
            'source_ref' => $sourceRef,
        ];
    }

    /**
     * The image Kling animates: exactly one of a completed, unexpired IMAGE
     * generation's output (its Higgsfield URL is public and passed as-is), or
     * one of the character's reference images (uploaded first).
     *
     * @return array{0: ?string, 1: ?CreativeCharacterRef}
     */
    private function source(mixed $source): array
    {
        $source = is_array($source) ? $source : [];
        $hasGeneration = isset($source['generation_id']);
        $hasRef = isset($source['ref_id']);
        if ($hasGeneration === $hasRef) {
            throw ValidationException::withMessages([
                'source' => 'Choose the image to animate: one generated image or one reference image.',
            ]);
        }

        if ($hasGeneration) {
            $from = CreativeGeneration::find((int) $source['generation_id']);
            $output = $from?->kind === 'image' ? $from->liveOutput((int) ($source['index'] ?? 0)) : null;
            if ($output === null || $output['type'] !== 'image') {
                throw new CreativeException(
                    'SOURCE_UNAVAILABLE',
                    'That image cannot be animated: it is not a finished image, or its 7 days at Higgsfield are over.',
                    422,
                );
            }

            return [$output['url'], null];
        }

        $ref = CreativeCharacterRef::find((int) $source['ref_id']);
        if ($ref === null || ! CreativeReferences::disk()->exists($ref->path)) {
            throw new CreativeException('SOURCE_UNAVAILABLE', 'That reference image no longer exists.', 422);
        }

        return [null, $ref];
    }

    /** The price in USD — Higgsfield's own figure, or Seedance's per-second table. */
    private function price(array $job): float
    {
        $placeholders = [];
        $count = $job['source_ref'] !== null || $job['source_url'] !== null ? 1 : $job['refs']->count();
        for ($i = 0; $i < $count; $i++) {
            $placeholders[] = $i === 0 ? CreativeModels::PLACEHOLDER_IMAGE : str_replace('.png', "-{$i}.png", CreativeModels::PLACEHOLDER_IMAGE);
        }
        $body = $this->models->body(
            $job['key'],
            $job['full_prompt'],
            $job['params'],
            $this->models->usesReferences($job['key']) ? $placeholders : [],
            $this->models->needsSource($job['key']) ? ($placeholders[0] ?? CreativeModels::PLACEHOLDER_IMAGE) : null,
        );

        $reply = $this->gateway->estimate($job['preset']['endpoint'], $body);
        if ($reply->isOk()) {
            $usd = $reply->data['usd'] ?? null;
            if (is_numeric($usd) && (float) $usd >= 0) {
                return round((float) $usd, 4);
            }
            if (($reply->data['type'] ?? null) === 'description') {
                $fallback = $this->models->fallbackUsd($job['key'], $job['params']);
                if ($fallback !== null) {
                    return $fallback;
                }
            }
        }

        throw new CreativeException(
            'ESTIMATE_UNAVAILABLE',
            $reply->isRefused() && $reply->detail !== null
                ? 'Higgsfield could not price this: '.$reply->detail
                : 'Higgsfield did not give a price for this, so it was not started. Try again in a minute.',
            502,
        );
    }

    /** One reference image onto Higgsfield: its public URL, or the failed reply. */
    private function upload(CreativeCharacterRef $ref): string|HiggsfieldReply
    {
        $bytes = $this->references->bytes($ref);
        if ($bytes === null) {
            return HiggsfieldReply::refused(400, 'A reference image is missing on the server.');
        }

        $reply = $this->gateway->generateUploadUrl($ref->mime);
        if (! $reply->isOk()) {
            return $reply;
        }
        $publicUrl = $reply->data['public_url'] ?? null;
        $uploadUrl = $reply->data['upload_url'] ?? null;
        if (! is_string($publicUrl) || ! str_starts_with($publicUrl, 'https://') || ! is_string($uploadUrl)) {
            return HiggsfieldReply::noAnswer($reply->status);
        }

        return $this->gateway->putUpload($uploadUrl, (array) ($reply->data['upload_headers'] ?? []), $bytes)
            ? $publicUrl
            : HiggsfieldReply::noAnswer();
    }

    /** Final state for a row; null $charged keeps the reservation. */
    private function settle(CreativeGeneration $generation, string $status, ?float $charged, ?string $error): void
    {
        $generation->status = $status;
        if ($charged !== null) {
            $generation->charged_usd = $charged;
        }
        $generation->error = $error !== null ? mb_substr($error, 0, 480) : null;
        $generation->last_checked_at = now();
        $generation->save();
    }

    /** What a failure body carries besides the message: the row and the budget. */
    private function after(CreativeGeneration $generation): array
    {
        return [
            'generation' => app(CreativePresenter::class)->generation($generation->fresh(['plan'])),
            'budget' => $this->budget->summary(),
        ];
    }

    private function assertConfigured(): void
    {
        if (! $this->gateway->configured()) {
            throw CreativeException::notConfigured();
        }
    }

    private function notCancellable(string $message = 'Only a request still waiting in the queue can be cancelled.'): CreativeException
    {
        return new CreativeException('NOT_CANCELLABLE', $message, 409);
    }

    private static function usd(float|int $value): string
    {
        return number_format((float) $value, $value > 0 && $value < 0.1 ? 3 : 2);
    }
}
