<?php

namespace App\Services\Creatives;

use App\Models\CreativeAgentJob;
use App\Models\CreativeAgentSession;
use App\Models\CreativeGeneration;
use App\Models\CreativePlan;
use App\Models\CreativeVideo;
use App\Models\CreativeVideoScene;
use App\Models\UserCredential;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The JOBS of Creatives: what the chat agent proposes in Generate mode or the
 * video editor proposes for a video's scenes, and how a job runs once a
 * person clicks Go. Nothing spends by itself:
 *
 *  - propose()  validates every step exactly like POST /generations (the model
 *               registry and the generator's own checks), prices each with
 *               Higgsfield's FREE price check, and stores the job `proposed`.
 *               Nothing is reserved and nothing is started. A job from the
 *               editor has no chat: `owner_uni_id` and `video_id` say whose
 *               and for what.
 *  - approve()  only a `proposed` job younger than an hour (else `expired`),
 *               and only when its whole total fits what is left of the month.
 *               Every step that does not wait for another starts at once
 *               through CreativeGenerator::create, which reserves its own
 *               price as every generation does.
 *  - advance()  BULK and dependency-aware (owner's correction, 2026-11-12: a
 *               video's clips are made together, not one by one). Each tick
 *               refreshes every running step, completes or fails it, then
 *               starts every waiting step whose `from_step` source (if any)
 *               has completed — at most MAX_IN_FLIGHT running per job, because
 *               Higgsfield's per-account limit is not known yet. A
 *               `from_step` source takes that step's first IMAGE. Run by
 *               `creatives:poll`, the session read and the video read.
 *  - cancel()   a proposal is dropped; a running job cancels its queued
 *               generations if Higgsfield still allows it, and otherwise just
 *               starts nothing further.
 *
 * Failure is per SCENE when the job makes a video: a failed step fails only
 * its own scene (the steps that wait for it are canceled, the scene's slot is
 * marked failed and keeps its prompt for a regenerate); the other scenes carry
 * on, and the job ends `completed_with_failures` — or `failed` when no clip at
 * all was made. A job with no scenes (the chat's "picture, then animate it")
 * fails as a whole, as it always did: nothing waiting starts after a failure.
 *
 * When a step that makes a scene's clip (`scene_id` on the step) completes,
 * the clip is attached to that scene at once, so the editor's timeline fills
 * in whatever order the clips arrive.
 *
 * When a job finishes nothing is added to the Claude conversation (it is
 * append-only and the model is not waiting); the next message's [Context]
 * lists the job and the generations.
 */
final class CreativeAgentJobs
{
    public const MAX_STEPS = 12;

    /** Running generations per job at once (4 → 6 on 2026-11-17: a 6-scene video
     *  ran in three waves; Higgsfield queues what it cannot start at once). */
    public const MAX_IN_FLIGHT = 6;

    /** The session / video read advances a job at most this often. */
    public const ADVANCE_EVERY_SECONDS = 4;

    /** A running generation is re-checked at Higgsfield when its last check is older than this. */
    private const STALE_SECONDS = 4;

    private const TERMINAL = ['completed', 'failed', 'canceled'];

    public function __construct(
        private CreativeGenerator $generator,
        private CreativeModels $models,
        private CreativeBudget $budget,
        private GenerationPoller $poller,
    ) {}

    // ---- proposing --------------------------------------------------------

    /**
     * Price and store a proposal. Throws a ValidationException or a
     * CreativeException the tool / controller answers as an error.
     *
     * `$options` comes from trusted server code only, never from the model:
     *  - video_id      the video whose scenes the job makes;
     *  - owner_uni_id  whose spend later steps are (default: the person the chat is answering);
     *  - scenes        true = keep each step's `scene_id` (CreativeVideos sets
     *                  them). A model-written step never carries one.
     *
     * @param  array<string, mixed>  $input  {title, plan_id?, steps: Step[]}
     * @param  array{video_id?: int|null, owner_uni_id?: string|null, scenes?: bool}  $options
     */
    public function propose(?CreativeAgentSession $session, array $input, array $options = []): CreativeAgentJob
    {
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 160) {
            throw ValidationException::withMessages(['title' => 'The job needs a title of at most 160 characters.']);
        }
        $planId = isset($input['plan_id']) ? (int) $input['plan_id'] : null;
        if ($planId !== null && ! CreativePlan::whereKey($planId)->exists()) {
            throw ValidationException::withMessages(['plan_id' => "There is no plan #{$planId}."]);
        }
        $rawSteps = $input['steps'] ?? null;
        if (! is_array($rawSteps) || ! array_is_list($rawSteps) || $rawSteps === [] || count($rawSteps) > self::MAX_STEPS) {
            throw ValidationException::withMessages(['steps' => 'A job has 1 to '.self::MAX_STEPS.' steps.']);
        }

        $steps = [];
        foreach ($rawSteps as $i => $raw) {
            $raw = is_array($raw) ? $raw : [];
            $step = $this->priceStep($raw, $i, $steps, $planId);
            if (($options['scenes'] ?? false) === true && isset($raw['scene_id']) && (int) $raw['scene_id'] > 0) {
                $step['scene_id'] = (int) $raw['scene_id'];
            }
            $steps[] = $step;
        }
        $total = round(array_sum(array_column($steps, 'usd')), 4);

        return CreativeAgentJob::create([
            'session_id' => $session?->id,
            'plan_id' => $planId,
            'video_id' => isset($options['video_id']) ? (int) $options['video_id'] : null,
            'owner_uni_id' => $options['owner_uni_id'] ?? $session?->actor(),
            'title' => $title,
            'status' => CreativeAgentJob::PROPOSED,
            'steps' => $steps,
            'estimated_usd' => $total,
        ]);
    }

    /**
     * One step, checked like POST /generations and priced.
     *
     * @param  list<array<string, mixed>>  $earlier  the steps already priced
     * @return array<string, mixed>
     */
    private function priceStep(array $raw, int $i, array $earlier, ?int $planId): array
    {
        $n = $i + 1;
        $key = (string) ($raw['model'] ?? '');
        if (! $this->models->has($key)) {
            throw ValidationException::withMessages(["steps.{$i}.model" => "Step {$n}: pick a model key from list_models."]);
        }
        $prompt = trim((string) ($raw['prompt'] ?? ''));
        if (mb_strlen($prompt) < 3 || mb_strlen($prompt) > 2000) {
            throw ValidationException::withMessages(["steps.{$i}.prompt" => "Step {$n}: the prompt must be 3 to 2000 characters."]);
        }
        $label = trim((string) ($raw['label'] ?? ''));
        $label = $label !== '' ? mb_substr($label, 0, 80) : $this->models->label($key);
        $params = $this->models->validateParams($key, $raw['params'] ?? []);
        $useCharacter = $this->models->requiresCharacter($key)
            || ! array_key_exists('use_character', $raw) || $raw['use_character'] === null || (bool) $raw['use_character'];

        $source = array_filter(is_array($raw['source'] ?? null) ? $raw['source'] : [], fn ($v) => $v !== null);
        $fromStep = null;
        if (! $this->models->needsSource($key)) {
            if ($source !== []) {
                throw ValidationException::withMessages(["steps.{$i}.source" => "Step {$n}: only the animate model takes a source image."]);
            }
            $source = null;
        } else {
            $kinds = (int) isset($source['from_step']) + (int) isset($source['generation_id']) + (int) isset($source['ref_id']);
            if ($kinds !== 1) {
                throw ValidationException::withMessages(["steps.{$i}.source" => "Step {$n}: give exactly one image to animate — from_step, generation_id (+ index) or ref_id."]);
            }
            if (isset($source['from_step'])) {
                $fromStep = (int) $source['from_step'];
                $from = $earlier[$fromStep] ?? null;
                if ($fromStep < 0 || $fromStep >= $i || $from === null) {
                    throw ValidationException::withMessages(["steps.{$i}.source" => "Step {$n}: from_step must name an EARLIER step of this job (0-based)."]);
                }
                if (($this->models->get($from['model'])['kind'] ?? null) !== 'image') {
                    throw ValidationException::withMessages(["steps.{$i}.source" => "Step {$n}: step {$fromStep} makes a video; only an image can be animated."]);
                }
                $source = ['from_step' => $fromStep];
            } elseif (isset($source['generation_id'])) {
                $source = ['generation_id' => (int) $source['generation_id'], 'index' => (int) ($source['index'] ?? 0)];
            } else {
                $source = ['ref_id' => (int) $source['ref_id']];
            }
        }

        $input = [
            'model' => $key,
            'prompt' => $prompt,
            'params' => $params,
            'use_character' => $useCharacter,
            'plan_id' => $planId,
        ];
        if ($source !== null && $fromStep === null) {
            $input['source'] = $source;
        }
        // An image made by an earlier step does not exist yet: price it with
        // the placeholder (the price check never fetches images).
        $quote = $this->generator->quote($input, placeholderSource: $fromStep !== null);

        return [
            'model' => $key,
            'label' => $label,
            'prompt' => $prompt,
            'full_prompt' => null,
            'params' => $params,
            'use_character' => $useCharacter,
            'source' => $source,
            'usd' => (float) $quote['usd'],
            'generation_id' => null,
            'status' => 'waiting',
            'error' => null,
        ];
    }

    // ---- Go -----------------------------------------------------------------

    /**
     * Go: the whole total must fit what is left this month; every step that
     * waits for nothing starts now (up to MAX_IN_FLIGHT). When nothing could
     * start the first refusal is thrown (with the job), so the click hears why.
     */
    public function approve(CreativeAgentJob $job, ?UserCredential $user): CreativeAgentJob
    {
        if ($job->isStale()) {
            $job->forceFill(['status' => CreativeAgentJob::EXPIRED, 'finished_at' => now()])->save();
            $this->releaseScenes($job, 'The price for this scene expired before Go. Propose it again.');

            throw new CreativeException('JOB_EXPIRED', 'This job was proposed more than an hour ago. Ask for it again to get a fresh price.', 409);
        }
        if ($job->status !== CreativeAgentJob::PROPOSED) {
            throw new CreativeException('JOB_NOT_PROPOSED', 'This job is no longer waiting for Go.', 409);
        }

        $budget = $this->budget->summary();
        if (self::units($job->estimated_usd) > self::units($budget['remaining_usd'])) {
            throw new CreativeException(
                'BUDGET_EXCEEDED',
                sprintf(
                    'This job would go over the monthly limit: it costs about $%s and $%s of $%s is left this month.',
                    number_format((float) $job->estimated_usd, 2), number_format($budget['remaining_usd'], 2), number_format($budget['cap_usd'], 2),
                ),
                422,
                ['budget' => $budget],
            );
        }

        // Claim it: two clicks (or two tabs) cannot both start it.
        $claimed = CreativeAgentJob::query()->whereKey($job->id)->where('status', CreativeAgentJob::PROPOSED)->update([
            'status' => CreativeAgentJob::RUNNING,
            'approved_by_name' => $user?->name,
            'approved_at' => now(),
            'updated_at' => now(),
        ]);
        if ($claimed !== 1) {
            throw new CreativeException('JOB_NOT_PROPOSED', 'This job is no longer waiting for Go.', 409);
        }
        $job->refresh();

        // The poller must not start the same steps while Go is starting them.
        $lock = Cache::lock(self::lockName($job->id), 120);
        try {
            $lock->block(10);
        } catch (LockTimeoutException) {
            // Nobody else can hold it for a job that was proposed a moment ago
            // for longer than a tick; start anyway rather than strand the job.
        }
        try {
            $this->startReady($job, $user, fromApproval: true);
            $this->finish($job);
        } finally {
            $lock->release();
        }

        return $job->refresh();
    }

    /** Stop: a proposal is dropped; a running job starts nothing further. */
    public function cancel(CreativeAgentJob $job): CreativeAgentJob
    {
        if ($job->status === CreativeAgentJob::PROPOSED) {
            $job->forceFill(['status' => CreativeAgentJob::CANCELED, 'finished_at' => now()])->save();
            $this->releaseScenes($job, 'This scene\'s job was cancelled.');

            return $job;
        }
        if ($job->status !== CreativeAgentJob::RUNNING) {
            throw new CreativeException('NOT_CANCELLABLE', 'This job has already finished.', 409);
        }

        $lock = Cache::lock(self::lockName($job->id), 60);
        try {
            $lock->block(5);
        } catch (LockTimeoutException) {
            throw new CreativeException('SESSION_BUSY', 'This job is being updated right now. Try again in a moment.', 409);
        }
        try {
            $job->refresh();
            $steps = $job->steps;
            foreach ($steps as $i => $step) {
                if (($step['status'] ?? 'waiting') === 'running' && ! empty($step['generation_id'])) {
                    $generation = CreativeGeneration::find($step['generation_id']);
                    if ($generation !== null && $generation->status === CreativeGeneration::QUEUED) {
                        try {
                            $this->generator->cancel($generation);
                        } catch (CreativeException) {
                            // Already started (or Higgsfield did not answer): it
                            // finishes on its own; we only stop what comes after.
                        }
                    }
                }
                if (($step['status'] ?? 'waiting') === 'waiting') {
                    $steps[$i]['status'] = 'canceled';
                }
            }
            $job->forceFill(['steps' => $steps, 'status' => CreativeAgentJob::CANCELED, 'finished_at' => now()])->save();
            $this->releaseScenes($job, 'This scene\'s job was cancelled.');
        } finally {
            $lock->release();
        }

        return $job;
    }

    // ---- running --------------------------------------------------------------

    /**
     * Move a running job on: refresh its running generations (when asked and
     * stale), complete or fail each, start what is now unblocked, and finish
     * the job once every step has ended. Never throws; one advance per job at
     * a time (a cache lock).
     */
    public function advance(CreativeAgentJob $job, bool $refresh = false): CreativeAgentJob
    {
        if ($job->status !== CreativeAgentJob::RUNNING) {
            return $job;
        }
        $lock = Cache::lock(self::lockName($job->id), 120);
        if (! $lock->get()) {
            return $job;
        }

        try {
            $job->refresh();
            if ($job->status !== CreativeAgentJob::RUNNING) {
                return $job;
            }

            foreach (array_keys($job->steps) as $i) {
                $step = $job->steps[$i];
                if (empty($step['generation_id']) || in_array($step['status'] ?? 'waiting', self::TERMINAL, true)) {
                    continue;
                }
                $generation = CreativeGeneration::find($step['generation_id']);
                if ($generation === null) {
                    $this->failStep($job, $i, 'The generation for this step is missing.');

                    continue;
                }
                if ($refresh && in_array($generation->status, [CreativeGeneration::QUEUED, CreativeGeneration::IN_PROGRESS], true)
                    && ($generation->last_checked_at === null || $generation->last_checked_at->lt(now()->subSeconds(self::STALE_SECONDS)))) {
                    $this->poller->refresh($generation);
                }

                switch ($generation->status) {
                    case CreativeGeneration::COMPLETED:
                    case CreativeGeneration::EXPIRED:
                        $this->completeStep($job, $i, $generation);
                        break;

                    case CreativeGeneration::FAILED:
                    case CreativeGeneration::NSFW:
                    case CreativeGeneration::CANCELED:
                        $this->failStep(
                            $job,
                            $i,
                            $generation->error ?? 'Higgsfield could not make this step.',
                            $generation->status === CreativeGeneration::CANCELED ? 'canceled' : 'failed',
                        );
                        break;

                    default:
                        if (($step['status'] ?? null) !== 'running') {
                            $steps = $job->steps;
                            $steps[$i]['status'] = 'running';
                            $job->forceFill(['steps' => $steps])->save();
                        }
                }
            }

            $this->startReady($job, $this->owner($job));
            $this->finish($job);
        } catch (Throwable $e) {
            Log::warning('Creatives agent job could not advance.', ['job' => $job->id, 'class' => $e::class]);
        } finally {
            $lock->release();
        }

        return $job->refresh();
    }

    /** `creatives:poll`, after the generations were refreshed: every running job. */
    public function advanceRunning(): int
    {
        $n = 0;
        foreach (CreativeAgentJob::query()->where('status', CreativeAgentJob::RUNNING)->orderBy('id')->get() as $job) {
            $this->advance($job);
            $n++;
        }

        return $n;
    }

    /**
     * The session read: expire this chat's stale proposals and advance its
     * running jobs — each at most every ADVANCE_EVERY_SECONDS.
     */
    public function refreshSession(CreativeAgentSession $session): void
    {
        $this->expireProposals(CreativeAgentJob::query()->where('session_id', $session->id));
        $this->advanceThrottled(CreativeAgentJob::query()->where('session_id', $session->id));
    }

    /**
     * The video read: bring the clips of this video up to date (its pending
     * generations not checked for $staleSeconds), expire its stale proposals
     * and advance its running jobs — throttled like the session read.
     */
    public function refreshVideo(CreativeVideo $video, int $staleSeconds = 20): void
    {
        $ids = CreativeVideoScene::query()->where('video_id', $video->id)->whereNotNull('generation_id')->pluck('generation_id');
        $pending = CreativeGeneration::query()
            ->whereIn('id', $ids)
            ->whereIn('status', [CreativeGeneration::QUEUED, CreativeGeneration::IN_PROGRESS])
            ->where(fn ($q) => $q->whereNull('last_checked_at')
                ->orWhere('last_checked_at', '<', now()->subSeconds($staleSeconds)->toDateTimeString()))
            ->limit(12)
            ->get();
        foreach ($pending as $generation) {
            try {
                $this->poller->refresh($generation);
            } catch (Throwable $e) {
                report($e);
            }
        }

        $this->expireProposals(CreativeAgentJob::query()->where('video_id', $video->id));
        $this->advanceThrottled(CreativeAgentJob::query()->where('video_id', $video->id));
    }

    public static function lockName(int $jobId): string
    {
        return 'creatives-agent-job:'.$jobId;
    }

    // ---- internals ------------------------------------------------------------

    /** @param  Builder<CreativeAgentJob>  $query */
    private function expireProposals(Builder $query): void
    {
        $stale = (clone $query)
            ->where('status', CreativeAgentJob::PROPOSED)
            ->where('created_at', '<=', now()->subMinutes(CreativeAgentJob::EXPIRES_MINUTES)->toDateTimeString())
            ->get();
        foreach ($stale as $job) {
            $updated = CreativeAgentJob::query()->whereKey($job->id)->where('status', CreativeAgentJob::PROPOSED)
                ->update(['status' => CreativeAgentJob::EXPIRED, 'finished_at' => now(), 'updated_at' => now()]);
            if ($updated === 1 && $job->video_id !== null) {
                $this->releaseScenes($job, 'The price for this scene expired before Go. Propose it again.');
            }
        }
    }

    /** @param  Builder<CreativeAgentJob>  $query */
    private function advanceThrottled(Builder $query): void
    {
        foreach ((clone $query)->where('status', CreativeAgentJob::RUNNING)->get() as $job) {
            if (Cache::add('creatives-agent-job-advanced:'.$job->id, 1, self::ADVANCE_EVERY_SECONDS)) {
                $this->advance($job, refresh: true);
            }
        }
    }

    /**
     * Start every waiting step whose source step (if any) has completed, in
     * order, while fewer than MAX_IN_FLIGHT run. From an approval, a budget
     * refusal on the very first start puts the job back to `proposed` (nothing
     * started: someone else spent in the meantime) and is thrown; any other
     * refusal is recorded on its step, and thrown only when nothing at all
     * could start.
     */
    private function startReady(CreativeAgentJob $job, ?UserCredential $user, bool $fromApproval = false): void
    {
        $firstError = null;
        $started = 0;
        $attempted = 0;

        while (true) {
            $steps = $job->steps;
            $running = count(array_filter($steps, fn (array $s) => ($s['status'] ?? 'waiting') === 'running'));
            if ($running >= self::MAX_IN_FLIGHT) {
                break;
            }
            $next = null;
            foreach ($steps as $i => $step) {
                if (($step['status'] ?? 'waiting') === 'waiting' && $this->ready($steps, (int) $i)) {
                    $next = (int) $i;
                    break;
                }
            }
            if ($next === null) {
                break;
            }

            $attempted++;
            $error = $this->startStep($job, $next, $user, revertOnBudget: $fromApproval && $attempted === 1);
            if ($error === null) {
                $started++;

                continue;
            }
            if ($error instanceof CreativeException && $error->errorCode === 'BUDGET_EXCEEDED' && $fromApproval && $attempted === 1
                && ($job->steps[$next]['status'] ?? null) === 'waiting') {
                // Reverted to proposed by startStep: nothing ran.
                throw $error;
            }
            $firstError ??= $error;
        }

        if ($fromApproval && $started === 0 && $firstError !== null) {
            $this->finish($job);
            throw $firstError;
        }
    }

    /** Its source step (if any) has completed. */
    private function ready(array $steps, int $i): bool
    {
        $source = is_array($steps[$i]['source'] ?? null) ? $steps[$i]['source'] : null;
        if (! isset($source['from_step'])) {
            return true;
        }

        return ($steps[(int) $source['from_step']]['status'] ?? null) === 'completed';
    }

    /**
     * Start one step as a normal generation linked to the job (and its plan).
     * Returns null on success, or the refusal (already recorded on the step,
     * which then fails with its scene). `$revertOnBudget`: a budget refusal
     * with nothing created puts the whole job back to `proposed` instead.
     */
    private function startStep(CreativeAgentJob $job, int $i, ?UserCredential $user, bool $revertOnBudget = false): ?Throwable
    {
        $steps = $job->steps;
        $step = $steps[$i];
        $input = [
            'model' => $step['model'],
            'prompt' => $step['prompt'],
            'params' => $step['params'] ?? [],
            'use_character' => (bool) ($step['use_character'] ?? true),
            'plan_id' => $job->plan_id,
        ];

        $source = is_array($step['source'] ?? null) ? $step['source'] : null;
        if (isset($source['from_step'])) {
            $from = $steps[(int) $source['from_step']] ?? null;
            $generation = isset($from['generation_id']) ? CreativeGeneration::find($from['generation_id']) : null;
            $index = null;
            foreach ((array) ($generation?->outputs ?? []) as $k => $output) {
                if (is_array($output) && ($output['type'] ?? null) === 'image') {
                    $index = (int) $k;
                    break;
                }
            }
            if ($generation === null || $index === null) {
                $message = 'The image from step '.((int) $source['from_step'] + 1).' is not available to animate.';
                $this->failStep($job, $i, $message);

                return new CreativeException('SOURCE_UNAVAILABLE', $message, 422);
            }
            $input['source'] = ['generation_id' => (int) $generation->id, 'index' => $index];
        } elseif ($source !== null) {
            $input['source'] = $source;
        }

        try {
            $generation = $this->generator->create($input, $user, (int) $job->id);
        } catch (CreativeException $e) {
            $generationId = $e->extra['generation']['id'] ?? null;
            if ($revertOnBudget && $e->errorCode === 'BUDGET_EXCEEDED' && $generationId === null) {
                // Someone else spent in the meantime: nothing started, so the
                // job is still the person's to approve later.
                CreativeAgentJob::query()->whereKey($job->id)->update([
                    'status' => CreativeAgentJob::PROPOSED, 'approved_by_name' => null, 'approved_at' => null, 'updated_at' => now(),
                ]);
                $job->refresh();

                return $e;
            }
            $this->failStep($job, $i, $e->getMessage(), 'failed', $generationId !== null ? (int) $generationId : null);

            return $e;
        } catch (ValidationException $e) {
            $this->failStep($job, $i, (string) collect($e->errors())->flatten()->first());

            return $e;
        }

        $steps = $job->steps;
        $steps[$i]['generation_id'] = (int) $generation->id;
        $steps[$i]['full_prompt'] = $generation->full_prompt;
        $steps[$i]['status'] = 'running';
        $steps[$i]['error'] = null;
        // The editor's history and timers (2026-11-17); older steps have none
        // and fall back to their generation's own times.
        $steps[$i]['started_at'] = now()->toIso8601String();
        $steps[$i]['ended_at'] = null;
        $job->forceFill(['steps' => $steps])->save();

        $sceneId = $this->sceneOf($steps, $i);
        if ($sceneId !== null) {
            $this->sceneQuery($job, $sceneId)
                ->whereIn('status', [CreativeVideoScene::PLANNED, CreativeVideoScene::EMPTY, CreativeVideoScene::FAILED])
                ->update(['status' => CreativeVideoScene::GENERATING, 'error' => null, 'updated_at' => now()]);
        }

        return null;
    }

    /** A step's generation completed: the step is done; a scene's clip lands on it. */
    private function completeStep(CreativeAgentJob $job, int $i, CreativeGeneration $generation): void
    {
        $steps = $job->steps;
        $steps[$i]['status'] = 'completed';
        $steps[$i]['ended_at'] = now()->toIso8601String();
        $job->forceFill(['steps' => $steps])->save();

        $sceneId = isset($steps[$i]['scene_id']) ? (int) $steps[$i]['scene_id'] : null;
        if ($sceneId !== null) {
            $this->sceneQuery($job, $sceneId)->update([
                'generation_id' => (int) $generation->id,
                'output_index' => 0,
                'status' => CreativeVideoScene::READY,
                'error' => null,
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Step $i failed (or was canceled). In a video job only its own scene
     * fails — the steps waiting for it are canceled and the scene's slot is
     * marked failed; in a job with no scenes every waiting step is canceled.
     */
    private function failStep(CreativeAgentJob $job, int $i, string $error, string $status = 'failed', ?int $generationId = null): void
    {
        $steps = $job->steps;
        $error = mb_substr($error, 0, 480);
        $steps[$i]['status'] = $status;
        $steps[$i]['error'] = $error;
        $steps[$i]['started_at'] ??= now()->toIso8601String();
        $steps[$i]['ended_at'] = now()->toIso8601String();
        if ($generationId !== null) {
            $steps[$i]['generation_id'] = $generationId;
        }

        $byScene = self::hasScenes($steps);
        $affected = [$i];
        if ($byScene) {
            foreach ($this->dependants($steps, $i) as $k) {
                if (($steps[$k]['status'] ?? 'waiting') === 'waiting') {
                    $steps[$k]['status'] = 'canceled';
                }
                $affected[] = $k;
            }
        } else {
            foreach ($steps as $k => $step) {
                if (($step['status'] ?? 'waiting') === 'waiting') {
                    $steps[$k]['status'] = 'canceled';
                }
            }
        }
        $job->forceFill(['steps' => $steps])->save();

        foreach (array_unique(array_filter(array_map(fn (int $k) => isset($steps[$k]['scene_id']) ? (int) $steps[$k]['scene_id'] : null, $affected))) as $sceneId) {
            $this->sceneQuery($job, $sceneId)->update([
                'status' => CreativeVideoScene::FAILED,
                'error' => mb_substr($status === 'canceled' ? 'The clip was cancelled at Higgsfield.' : $error, 0, 500),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Every step has ended: the job's verdict. No failure = completed. With
     * scenes, some clips made = completed_with_failures, none = failed.
     * Without scenes any failure fails the job, as before.
     */
    private function finish(CreativeAgentJob $job): void
    {
        $job->refresh();
        if ($job->status !== CreativeAgentJob::RUNNING) {
            return;
        }
        $steps = $job->steps;
        foreach ($steps as $step) {
            if (! in_array($step['status'] ?? 'waiting', self::TERMINAL, true)) {
                return;
            }
        }

        $failed = count(array_filter($steps, fn (array $s) => $s['status'] !== 'completed'));
        if ($failed === 0) {
            $status = CreativeAgentJob::COMPLETED;
        } elseif (! self::hasScenes($steps)) {
            $status = CreativeAgentJob::FAILED;
        } else {
            $clips = array_filter($steps, fn (array $s) => isset($s['scene_id']));
            $made = array_filter($clips, fn (array $s) => $s['status'] === 'completed');
            $status = $made === [] ? CreativeAgentJob::FAILED : CreativeAgentJob::COMPLETED_WITH_FAILURES;
        }

        $job->forceFill(['status' => $status, 'finished_at' => now()])->save();
    }

    /** The steps that wait for step $i, directly or through another. @return list<int> */
    private function dependants(array $steps, int $i): array
    {
        $out = [];
        $frontier = [$i];
        while ($frontier !== []) {
            $current = array_shift($frontier);
            foreach ($steps as $k => $step) {
                $source = is_array($step['source'] ?? null) ? $step['source'] : null;
                if (isset($source['from_step']) && (int) $source['from_step'] === $current && ! in_array((int) $k, $out, true)) {
                    $out[] = (int) $k;
                    $frontier[] = (int) $k;
                }
            }
        }

        return $out;
    }

    /** The scene a step works for (the editor's history reads it the same way). */
    public function sceneOfStep(array $steps, int $i): ?int
    {
        return $this->sceneOf($steps, $i);
    }

    /** The scene a step works for: its own `scene_id`, or that of a step waiting for it (a still's animation). */
    private function sceneOf(array $steps, int $i): ?int
    {
        if (isset($steps[$i]['scene_id'])) {
            return (int) $steps[$i]['scene_id'];
        }
        foreach ($this->dependants($steps, $i) as $k) {
            if (isset($steps[$k]['scene_id'])) {
                return (int) $steps[$k]['scene_id'];
            }
        }

        return null;
    }

    /**
     * A scene of THIS job: a slot re-planned since (another job now owns it)
     * is never written by the old one.
     *
     * @return Builder<CreativeVideoScene>
     */
    private function sceneQuery(CreativeAgentJob $job, int $sceneId): Builder
    {
        return CreativeVideoScene::query()->whereKey($sceneId)->where('agent_job_id', $job->id);
    }

    /** The job's scenes that never got a clip are marked failed (cancelled / expired). */
    private function releaseScenes(CreativeAgentJob $job, string $message): void
    {
        if ($job->video_id === null) {
            return;
        }
        CreativeVideoScene::query()
            ->where('agent_job_id', $job->id)
            ->whereIn('status', [CreativeVideoScene::PLANNED, CreativeVideoScene::GENERATING])
            ->update(['status' => CreativeVideoScene::FAILED, 'error' => $message, 'updated_at' => now()]);
    }

    private static function hasScenes(array $steps): bool
    {
        foreach ($steps as $step) {
            if (isset($step['scene_id'])) {
                return true;
            }
        }

        return false;
    }

    /** Whose spend a later step is: the job's owner, else the person whose chat it is. */
    private function owner(CreativeAgentJob $job): ?UserCredential
    {
        $uniId = $job->owner_uni_id ?? $job->session?->uni_id;

        return $uniId !== null ? UserCredential::find($uniId) : null;
    }

    private static function units(mixed $usd): int
    {
        return (int) round(((float) $usd) * 10000);
    }
}
