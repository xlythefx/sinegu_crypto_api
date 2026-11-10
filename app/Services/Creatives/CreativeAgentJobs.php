<?php

namespace App\Services\Creatives;

use App\Models\CreativeAgentJob;
use App\Models\CreativeAgentSession;
use App\Models\CreativeGeneration;
use App\Models\CreativePlan;
use App\Models\UserCredential;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The chat agent's JOBS: what it proposes in Generate mode, and how a job runs
 * once a person clicks Go. The agent never spends by itself:
 *
 *  - propose()  validates every step exactly like POST /generations (the model
 *               registry and the generator's own checks), prices each with
 *               Higgsfield's FREE price check, and stores the job `proposed`.
 *               Nothing is reserved and nothing is started.
 *  - approve()  only a `proposed` job younger than an hour (else `expired`),
 *               and only when its whole total fits what is left of the month.
 *               Step 0 starts at once through CreativeGenerator::create, which
 *               reserves its own price as every generation does.
 *  - advance()  starts the next step once the previous one's generation is
 *               completed (a `from_step` source takes that step's first IMAGE);
 *               a failed / nsfw / canceled step fails the job and later steps
 *               never start. Run by `creatives:poll` and the session read.
 *  - cancel()   a proposal is dropped; a running job cancels its queued
 *               generation if Higgsfield still allows it, and otherwise just
 *               starts nothing further.
 *
 * When a job finishes nothing is added to the Claude conversation (it is
 * append-only and the model is not waiting); the next message's [Context]
 * lists the job and the generations.
 */
final class CreativeAgentJobs
{
    public const MAX_STEPS = 6;

    /** The session read advances a job at most this often. */
    public const ADVANCE_EVERY_SECONDS = 4;

    /** A running generation is re-checked at Higgsfield when its last check is older than this. */
    private const STALE_SECONDS = 4;

    public function __construct(
        private CreativeGenerator $generator,
        private CreativeModels $models,
        private CreativeBudget $budget,
        private GenerationPoller $poller,
    ) {}

    // ---- proposing --------------------------------------------------------

    /**
     * Price and store a proposal. Throws a ValidationException or a
     * CreativeException the tool answers as an error result.
     *
     * @param  array<string, mixed>  $input  {title, plan_id?, steps: Step[]}
     */
    public function propose(CreativeAgentSession $session, array $input): CreativeAgentJob
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
            $steps[] = $this->priceStep(is_array($raw) ? $raw : [], $i, $steps, $planId);
        }
        $total = round(array_sum(array_column($steps, 'usd')), 4);

        return CreativeAgentJob::create([
            'session_id' => $session->id,
            'plan_id' => $planId,
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
     * Go: the whole total must fit what is left this month; step 0 starts now.
     * A failed start throws its own error (with the job), the job failed.
     */
    public function approve(CreativeAgentJob $job, ?UserCredential $user): CreativeAgentJob
    {
        if ($job->isStale()) {
            $job->forceFill(['status' => CreativeAgentJob::EXPIRED, 'finished_at' => now()])->save();

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

        $this->startStep($job, 0, $user, fromApproval: true);

        return $job->refresh();
    }

    /** Stop: a proposal is dropped; a running job starts nothing further. */
    public function cancel(CreativeAgentJob $job): CreativeAgentJob
    {
        if ($job->status === CreativeAgentJob::PROPOSED) {
            $job->forceFill(['status' => CreativeAgentJob::CANCELED, 'finished_at' => now()])->save();

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
            $current = self::currentStep($steps);
            if ($current !== null) {
                $generation = CreativeGeneration::find($steps[$current]['generation_id']);
                if ($generation !== null && $generation->status === CreativeGeneration::QUEUED) {
                    try {
                        $this->generator->cancel($generation);
                    } catch (CreativeException) {
                        // Already started (or Higgsfield did not answer): it
                        // finishes on its own; we only stop what comes after.
                    }
                }
            }
            foreach ($steps as $i => $step) {
                if (($step['status'] ?? 'waiting') === 'waiting') {
                    $steps[$i]['status'] = 'canceled';
                }
            }
            $job->forceFill(['steps' => $steps, 'status' => CreativeAgentJob::CANCELED, 'finished_at' => now()])->save();
        } finally {
            $lock->release();
        }

        return $job;
    }

    // ---- running --------------------------------------------------------------

    /**
     * Move a running job on: refresh its current generation (when asked and
     * stale), start the next step once it completed, finish or fail the job.
     * Never throws; one advance per job at a time (a cache lock).
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
            $steps = $job->steps;
            $current = self::currentStep($steps);
            if ($current === null) {
                $this->startStep($job, 0, $this->owner($job));

                return $job->refresh();
            }

            $generation = CreativeGeneration::find($steps[$current]['generation_id']);
            if ($generation === null) {
                $this->fail($job, $current, 'The generation for this step is missing.');

                return $job;
            }
            if ($refresh && in_array($generation->status, [CreativeGeneration::QUEUED, CreativeGeneration::IN_PROGRESS], true)
                && ($generation->last_checked_at === null || $generation->last_checked_at->lt(now()->subSeconds(self::STALE_SECONDS)))) {
                $this->poller->refresh($generation);
            }

            switch ($generation->status) {
                case CreativeGeneration::COMPLETED:
                case CreativeGeneration::EXPIRED:
                    $steps[$current]['status'] = 'completed';
                    $job->forceFill(['steps' => $steps])->save();
                    if ($current === count($steps) - 1) {
                        $job->forceFill(['status' => CreativeAgentJob::COMPLETED, 'finished_at' => now()])->save();
                    } else {
                        $this->startStep($job, $current + 1, $this->owner($job));
                    }
                    break;

                case CreativeGeneration::FAILED:
                case CreativeGeneration::NSFW:
                case CreativeGeneration::CANCELED:
                    $this->fail(
                        $job,
                        $current,
                        $generation->error ?? 'Higgsfield could not make this step.',
                        $generation->status === CreativeGeneration::CANCELED ? 'canceled' : 'failed',
                    );
                    break;

                default:
                    if (($steps[$current]['status'] ?? null) !== 'running') {
                        $steps[$current]['status'] = 'running';
                        $job->forceFill(['steps' => $steps])->save();
                    }
            }
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
        CreativeAgentJob::query()
            ->where('session_id', $session->id)
            ->where('status', CreativeAgentJob::PROPOSED)
            ->where('created_at', '<=', now()->subMinutes(CreativeAgentJob::EXPIRES_MINUTES)->toDateTimeString())
            ->update(['status' => CreativeAgentJob::EXPIRED, 'finished_at' => now(), 'updated_at' => now()]);

        $running = CreativeAgentJob::query()->where('session_id', $session->id)->where('status', CreativeAgentJob::RUNNING)->get();
        foreach ($running as $job) {
            if (Cache::add('creatives-agent-job-advanced:'.$job->id, 1, self::ADVANCE_EVERY_SECONDS)) {
                $this->advance($job, refresh: true);
            }
        }
    }

    public static function lockName(int $jobId): string
    {
        return 'creatives-agent-job:'.$jobId;
    }

    // ---- internals ------------------------------------------------------------

    /**
     * Start one step as a normal generation linked to the job (and its plan).
     * Throws only from an approval (so the Go click hears why); from the
     * poller a failure is recorded on the job.
     */
    private function startStep(CreativeAgentJob $job, int $i, ?UserCredential $user, bool $fromApproval = false): void
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
                $this->fail($job, $i, 'The image from step '.((int) $source['from_step'] + 1).' is not available to animate.');

                return;
            }
            $input['source'] = ['generation_id' => (int) $generation->id, 'index' => $index];
        } elseif ($source !== null) {
            $input['source'] = $source;
        }

        try {
            $generation = $this->generator->create($input, $user, (int) $job->id);
        } catch (CreativeException $e) {
            $generationId = $e->extra['generation']['id'] ?? null;
            if ($fromApproval && $i === 0 && $e->errorCode === 'BUDGET_EXCEEDED' && $generationId === null) {
                // Someone else spent in the meantime: nothing started, so the
                // job is still the person's to approve later.
                CreativeAgentJob::query()->whereKey($job->id)->update([
                    'status' => CreativeAgentJob::PROPOSED, 'approved_by_name' => null, 'approved_at' => null, 'updated_at' => now(),
                ]);

                throw $e;
            }
            $this->fail($job, $i, $e->getMessage(), 'failed', $generationId !== null ? (int) $generationId : null);
            if ($fromApproval) {
                throw $e;
            }

            return;
        } catch (ValidationException $e) {
            $this->fail($job, $i, (string) collect($e->errors())->flatten()->first());
            if ($fromApproval) {
                throw $e;
            }

            return;
        }

        $steps[$i]['generation_id'] = (int) $generation->id;
        $steps[$i]['full_prompt'] = $generation->full_prompt;
        $steps[$i]['status'] = 'running';
        $steps[$i]['error'] = null;
        $job->forceFill(['steps' => $steps])->save();
    }

    /** The job fails at step $i; the steps after it never start. */
    private function fail(CreativeAgentJob $job, int $i, string $error, string $status = 'failed', ?int $generationId = null): void
    {
        $steps = $job->steps;
        $steps[$i]['status'] = $status;
        $steps[$i]['error'] = mb_substr($error, 0, 480);
        if ($generationId !== null) {
            $steps[$i]['generation_id'] = $generationId;
        }
        foreach ($steps as $k => $step) {
            if ($k > $i && ($step['status'] ?? 'waiting') === 'waiting') {
                $steps[$k]['status'] = 'canceled';
            }
        }
        $job->forceFill(['steps' => $steps, 'status' => CreativeAgentJob::FAILED, 'finished_at' => now()])->save();
    }

    /** Index of the last step that has a generation, or null. */
    private static function currentStep(array $steps): ?int
    {
        $current = null;
        foreach ($steps as $i => $step) {
            if (! empty($step['generation_id'])) {
                $current = (int) $i;
            }
        }

        return $current;
    }

    /** Whose spend a later step is: the person whose chat it is. */
    private function owner(CreativeAgentJob $job): ?UserCredential
    {
        $uniId = $job->session?->uni_id;

        return $uniId !== null ? UserCredential::find($uniId) : null;
    }

    private static function units(mixed $usd): int
    {
        return (int) round(((float) $usd) * 10000);
    }
}
