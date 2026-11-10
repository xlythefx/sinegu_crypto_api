<?php

namespace App\Services\Creatives;

use App\Http\Requests\CreativePlanRequest;
use App\Models\CreativeAgentJob;
use App\Models\CreativeAgentSession;
use App\Models\CreativeCharacter;
use App\Models\CreativeCharacterRef;
use App\Models\CreativeGeneration;
use App\Models\CreativeMemory;
use App\Models\CreativePlan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Runs the chat agent's tool calls — all fast DB reads/writes or FREE price
 * checks, never a wait on a generation.
 *
 * Every call answers one tool_result (JSON text, or an error the model can
 * act on) plus what the page shows for it: one `activity` line, a `plan` chip
 * for a created plan, a `job` card for a proposal.
 *
 * Rules enforced HERE, not by the tool list (which is identical in both
 * modes):
 *  - Ask mode refuses create_plan / update_plan / propose_job with an error
 *    result ("Ask mode: switch to Generate to do this").
 *  - Nothing private ever goes back to the model: no person's name, email or
 *    uni_id, no balance, no trading money figure. Performance figures come
 *    only from the public track record ({@see CreativeFacts}); team-typed
 *    text is scrubbed ({@see CreativeAiPrompt::scrub()}); the Creatives
 *    budget is plain numbers.
 *  - propose_job never spends: it prices and stores; only Go starts anything.
 */
final class CreativeAgentTools
{
    public const ASK_REFUSAL = 'Ask mode: switch to Generate to do this';

    private const PLAN_FIELDS = ['title', 'status', 'platform', 'format', 'planned_for', 'hook', 'script', 'caption', 'hashtags', 'notes', 'video_prompt', 'posted_url'];

    /** Fields where an empty string from the model means "clear it". */
    private const NULLABLE_PLAN_FIELDS = ['planned_for', 'hook', 'script', 'caption', 'hashtags', 'notes', 'video_prompt', 'posted_url'];

    public function __construct(
        private CreativeFacts $facts,
        private CreativeBudget $budget,
        private CreativeModels $models,
        private CreativeAgentJobs $jobs,
        private GenerationPoller $poller,
    ) {}

    /**
     * One tool call.
     *
     * @param  array<string, mixed>  $input
     * @return array{content: string, is_error: bool, display: list<array<string, mixed>>, job: ?CreativeAgentJob}
     */
    public function run(string $name, array $input, CreativeAgentSession $session, string $mode, ?string $personName): array
    {
        if (in_array($name, CreativeAgentPrompt::GENERATE_ONLY, true) && $mode !== CreativeAgentSession::GENERATE) {
            return self::error($name, self::ASK_REFUSAL.'.', 'Needs Generate mode: '.self::verb($name));
        }

        try {
            $input = $this->normalize($name, $input);

            return match ($name) {
                'list_plans' => $this->listPlans($input),
                'get_plan' => $this->getPlan($input),
                'list_generations' => $this->listGenerations($input),
                'get_character' => $this->getCharacter(),
                'get_week_results' => $this->getWeekResults($input),
                'get_budget' => $this->getBudget(),
                'list_models' => $this->listModels(),
                'remember' => $this->remember($input, $personName),
                'forget' => $this->forget($input),
                'update_memory' => $this->updateMemory($input, $personName),
                'create_plan' => $this->createPlan($input, $session, $personName),
                'update_plan' => $this->updatePlan($input, $personName),
                'propose_job' => $this->proposeJob($input, $session),
                default => self::error($name, "There is no tool called {$name}.", 'Unknown tool'),
            };
        } catch (ValidationException $e) {
            return self::error($name, implode(' ', collect($e->errors())->flatten()->all()), 'Could not '.self::verb($name));
        } catch (CreativeException $e) {
            return self::error($name, $e->getMessage(), 'Could not '.self::verb($name));
        } catch (Throwable $e) {
            Log::warning('Creatives agent tool failed.', ['tool' => $name, 'class' => $e::class]);

            return self::error($name, 'This tool failed on the server. Try once more or carry on without it.', 'Could not '.self::verb($name));
        }
    }

    // ---- the strict schemas' shapes → the contract's ----------------------------

    /**
     * The tool schemas ({@see CreativeAgentPrompt::tools()}) make EVERY
     * property required, so Claude states "nothing" as a value: "any" for no
     * filter, 0 for no plan or no count, source type "none", params as a
     * name/value list, update_plan's fields as a `changes` list. Turn those
     * into the contract's shapes here, before any tool reads them. An input
     * already in the contract's shape passes through unchanged.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function normalize(string $name, array $input): array
    {
        switch ($name) {
            case 'list_plans':
                $input = self::dropValues($input, ['stage', 'platform'], ['any', '']);
                $input = self::dropNonPositive($input, ['limit']);
                break;

            case 'list_generations':
                $input = self::dropValues($input, ['status'], ['any', '']);
                $input = self::dropNonPositive($input, ['limit']);
                break;

            case 'get_week_results':
                $input = self::dropNonPositive($input, ['days']);
                break;

            case 'update_plan':
                if (array_key_exists('changes', $input)) {
                    $changes = $input['changes'];
                    unset($input['changes']);
                    if (! is_array($changes) || ! array_is_list($changes)) {
                        throw ValidationException::withMessages(['changes' => 'changes is a list of {field, value}.']);
                    }
                    $seen = [];
                    foreach ($changes as $change) {
                        $field = is_array($change) ? ($change['field'] ?? null) : null;
                        if (! is_string($field) || ! in_array($field, self::PLAN_FIELDS, true)) {
                            throw ValidationException::withMessages(['changes' => 'Each change names one plan field: '.implode(', ', self::PLAN_FIELDS).'.']);
                        }
                        if (isset($seen[$field])) {
                            throw ValidationException::withMessages(['changes' => "Change {$field} once."]);
                        }
                        $seen[$field] = true;
                        $value = $change['value'] ?? null;
                        $input[$field] = is_scalar($value) ? (string) $value : '';
                    }
                }
                break;

            case 'propose_job':
                $input = self::dropNonPositive($input, ['plan_id']);
                if (is_array($input['steps'] ?? null) && array_is_list($input['steps'])) {
                    foreach ($input['steps'] as $i => $step) {
                        if (is_array($step)) {
                            $input['steps'][$i] = $this->normalizeStep($step, (int) $i);
                        }
                    }
                }
                break;
        }

        return $input;
    }

    /**
     * One job step: `params` from [{name, value}] to {name: value} (a name the
     * step's model does not take is refused, never dropped silently), and
     * `source` from {type, id, index} to the contract's one-key forms.
     *
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    private function normalizeStep(array $step, int $i): array
    {
        $n = $i + 1;
        $key = is_string($step['model'] ?? null) ? $step['model'] : '';

        $params = $step['params'] ?? null;
        if (is_array($params) && $params !== [] && array_is_list($params)) {
            $allowed = $this->models->has($key) ? array_column($this->models->get($key)['params'] ?? [], 'name') : null;
            $out = [];
            foreach ($params as $param) {
                $name = is_array($param) ? ($param['name'] ?? null) : null;
                if (! is_string($name) || $name === '') {
                    throw ValidationException::withMessages(["steps.{$i}.params" => "Step {$n}: each param is {name, value}."]);
                }
                if ($allowed !== null && ! in_array($name, $allowed, true)) {
                    throw ValidationException::withMessages(["steps.{$i}.params" => "Step {$n}: {$key} has no param {$name}; its params are: ".implode(', ', $allowed).'.']);
                }
                if (array_key_exists($name, $out)) {
                    throw ValidationException::withMessages(["steps.{$i}.params" => "Step {$n}: give {$name} once."]);
                }
                $value = $param['value'] ?? null;
                $out[$name] = is_string($value) ? trim($value) : $value;
            }
            $step['params'] = $out;
        }

        $source = $step['source'] ?? null;
        if (is_array($source) && array_key_exists('type', $source)) {
            $id = (int) ($source['id'] ?? 0);
            $step['source'] = match ($source['type']) {
                'none', '', null => null,
                'from_step' => ['from_step' => $id],
                'generation' => ['generation_id' => $id, 'index' => (int) ($source['index'] ?? 0)],
                'ref' => ['ref_id' => $id],
                default => throw ValidationException::withMessages(["steps.{$i}.source" => "Step {$n}: source type is one of: ".implode(', ', CreativeAgentPrompt::SOURCE_TYPES).'.']),
            };
        }

        return $step;
    }

    /** Unset the keys whose value is one of the "nothing" values. */
    private static function dropValues(array $input, array $keys, array $nothing): array
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $input) && in_array($input[$key], $nothing, true)) {
                unset($input[$key]);
            }
        }

        return $input;
    }

    /** Unset the keys holding 0 or less (the schemas' "none"; a default applies). */
    private static function dropNonPositive(array $input, array $keys): array
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $input) && (! is_numeric($input[$key]) || (int) $input[$key] <= 0)) {
                unset($input[$key]);
            }
        }

        return $input;
    }

    // ---- reads ------------------------------------------------------------------

    private function listPlans(array $input): array
    {
        $limit = self::clamp($input['limit'] ?? 15, 1, 30);
        $q = CreativePlan::query()->withCount('generations')
            ->orderByRaw('planned_for IS NULL')->orderBy('planned_for')->orderByDesc('id');
        if (isset($input['stage']) && in_array($input['stage'], CreativePlan::STATUSES, true)) {
            $q->where('status', $input['stage']);
        }
        if (isset($input['platform']) && in_array($input['platform'], CreativePlan::PLATFORMS, true)) {
            $q->where('platform', $input['platform']);
        }
        $plans = $q->limit($limit)->get()->map(fn (CreativePlan $p) => [
            'id' => (int) $p->id,
            'title' => self::clean($p->title),
            'stage' => $p->status,
            'platform' => $p->platform,
            'format' => $p->format,
            'planned_for' => $p->plannedFor(),
            'hook' => self::clean($p->hook, 300),
            'has_script' => trim((string) $p->script) !== '',
            'has_video_prompt' => trim((string) $p->video_prompt) !== '',
            'ai_drafted' => $p->ai_drafted_at !== null,
            'generations' => (int) $p->generations_count,
        ])->values()->all();

        return self::ok('list_plans', ['plans' => $plans, 'total_plans' => CreativePlan::count()], 'Read the planner');
    }

    private function getPlan(array $input): array
    {
        $plan = CreativePlan::query()->withCount('generations')->find((int) ($input['id'] ?? 0));
        if ($plan === null) {
            return self::error('get_plan', 'There is no plan #'.(int) ($input['id'] ?? 0).'.', 'Could not open the plan');
        }

        return self::ok('get_plan', ['plan' => self::planForModel($plan)], 'Opened plan: '.mb_substr((string) $plan->title, 0, 80));
    }

    private function listGenerations(array $input): array
    {
        $this->poller->expireFinished();
        $limit = self::clamp($input['limit'] ?? 10, 1, 20);
        $q = CreativeGeneration::query()->orderByDesc('id');
        $statuses = [...CreativeGeneration::PENDING, CreativeGeneration::COMPLETED, ...CreativeGeneration::REFUNDED, CreativeGeneration::EXPIRED];
        if (isset($input['status']) && in_array($input['status'], $statuses, true)) {
            $q->where('status', $input['status']);
        }
        $rows = $q->limit($limit)->get()->map(fn (CreativeGeneration $g) => [
            'id' => (int) $g->id,
            'plan_id' => $g->plan_id !== null ? (int) $g->plan_id : null,
            'job_id' => $g->agent_job_id !== null ? (int) $g->agent_job_id : null,
            'kind' => $g->kind,
            'model' => $g->model,
            'model_label' => $this->models->label($g->model),
            'prompt' => self::clean($g->prompt, 400),
            'params' => (object) ($g->params ?? []),
            'status' => $g->status,
            'error' => $g->error,
            'outputs' => $g->hasLiveOutputs()
                ? array_values(array_map(fn ($o, $i) => ['index' => $i, 'type' => $o['type'] ?? 'image', 'url' => $o['url'] ?? null], $g->outputs, array_keys($g->outputs)))
                : [],
            'expires_at' => $g->expires_at?->toIso8601String(),
            'created_at' => $g->created_at?->toIso8601String(),
        ])->values()->all();

        return self::ok('list_generations', ['generations' => $rows], 'Looked at recent generations');
    }

    private function getCharacter(): array
    {
        $character = CreativeCharacter::current();

        return self::ok('get_character', ['character' => [
            'name' => self::clean($character->name),
            'look' => self::clean($character->look),
            'style' => self::clean($character->style_prompt),
            'rules' => self::clean($character->rules),
            'reference_images' => $character->refs()->get()
                ->map(fn (CreativeCharacterRef $r) => ['ref_id' => (int) $r->id, 'label' => self::clean($r->label)])
                ->values()->all(),
            'note' => 'Models with use_character on add this look, style and rules to the prompt automatically.',
        ]], 'Looked at '.mb_substr((string) $character->name, 0, 40));
    }

    private function getWeekResults(array $input): array
    {
        $days = self::clamp($input['days'] ?? CreativeFacts::WINDOW_DAYS, 1, CreativeFacts::MAX_WINDOW_DAYS);
        $performance = $this->facts->performance(null, $days);
        $brand = $this->facts->forPrompt(null, null)['brand'];

        return self::ok('get_week_results', [
            'performance' => $performance + [
                'days' => $days,
                'source' => 'Pixel Alpha\'s published track record (the master account), days in Asia/Manila time. return_pct is the period\'s compounded return; best_day.pct and top_coins[].share_pct are percentages of the account; trades counts closed trades.',
                'rules' => $performance['available']
                    ? 'Copy these figures exactly. Any caption citing a percentage ends with: '.CreativeAi::DISCLAIMER
                    : 'Nothing was published in this window: use no performance figures at all.',
            ],
            'brand' => $brand,
        ], $days === CreativeFacts::WINDOW_DAYS ? 'Read this week\'s public results' : "Read the public results for {$days} days");
    }

    private function getBudget(): array
    {
        $b = $this->budget->summary();

        return self::ok('get_budget', ['budget_usd' => [
            'month' => $b['month'],
            'cap' => $b['cap_usd'],
            'spent' => $b['spent_usd'],
            'reserved' => $b['reserved_usd'],
            'left' => $b['remaining_usd'],
            'generations' => $b['generations'],
            'ai_calls' => $b['ai_calls'],
            'note' => 'Creatives tooling spend only (Higgsfield and Claude). A job needs its whole total left to start.',
        ]], 'Checked the budget');
    }

    private function listModels(): array
    {
        $models = array_map(fn (array $m) => [
            'key' => $m['key'],
            'label' => $m['label'],
            'kind' => $m['kind'],
            'description' => $m['description'],
            'uses_references' => $m['uses_references'],
            'requires_character' => $m['requires_character'],
            'needs_source' => $m['needs_source'],
            // Tool prices, as words: "about USD 0.30 for 5 s".
            'price_hint' => str_replace('$', 'USD ', $m['price_hint']),
            'params' => $m['params'],
        ], $this->models->all());

        return self::ok('list_models', [
            'models' => $models,
            'note' => 'Exact prices come from propose_job, which asks Higgsfield for free. Animate a still made in the same job with source {"from_step": i}.',
        ], 'Checked the models and prices');
    }

    // ---- memory ---------------------------------------------------------------

    private function remember(array $input, ?string $personName): array
    {
        $text = $this->memoryText($input['text'] ?? null);
        $category = $this->category($input['category'] ?? null);
        if (CreativeMemory::count() >= CreativeMemory::MAX_ROWS) {
            return self::error('remember', 'Memory is full ('.CreativeMemory::MAX_ROWS.' notes). Ask the person which note to forget first.', 'Could not save to memory');
        }
        $memory = CreativeMemory::create([
            'text' => $text,
            'category' => $category,
            'source' => CreativeMemory::SOURCE_AGENT,
            'created_by_name' => $personName,
            'updated_by_name' => $personName,
        ]);

        return self::ok('remember', ['saved' => ['id' => (int) $memory->id, 'category' => $category, 'text' => $text]], 'Saved to memory: '.self::short($text));
    }

    private function forget(array $input): array
    {
        $memory = CreativeMemory::find((int) ($input['id'] ?? 0));
        if ($memory === null) {
            return self::error('forget', 'There is no memory note #'.(int) ($input['id'] ?? 0).'.', 'Could not remove from memory');
        }
        $text = (string) $memory->text;
        $memory->delete();

        return self::ok('forget', ['forgotten' => (int) ($input['id'])], 'Removed from memory: '.self::short($text));
    }

    private function updateMemory(array $input, ?string $personName): array
    {
        $memory = CreativeMemory::find((int) ($input['id'] ?? 0));
        if ($memory === null) {
            return self::error('update_memory', 'There is no memory note #'.(int) ($input['id'] ?? 0).'.', 'Could not update memory');
        }
        $text = $this->memoryText($input['text'] ?? null);
        $memory->forceFill(['text' => $text, 'updated_by_name' => $personName])->save();

        return self::ok('update_memory', ['updated' => ['id' => (int) $memory->id, 'category' => $memory->category, 'text' => $text]], 'Updated memory: '.self::short($text));
    }

    /** A note the agent saves: 1–500 characters, and nothing private in it. */
    private function memoryText(mixed $raw): string
    {
        $text = trim(is_string($raw) ? $raw : '');
        if ($text === '' || mb_strlen($text) > CreativeMemory::MAX_TEXT) {
            throw ValidationException::withMessages(['text' => 'A memory note is 1 to '.CreativeMemory::MAX_TEXT.' characters.']);
        }
        if (CreativeAiPrompt::scrub($text) !== $text || CreativeAiOutput::moneyIn($text) !== null) {
            throw ValidationException::withMessages(['text' => 'Memory may not hold emails, ids or money amounts. Save the lasting idea without them.']);
        }

        return $text;
    }

    private function category(mixed $raw): string
    {
        return is_string($raw) && in_array($raw, CreativeMemory::CATEGORIES, true) ? $raw : 'other';
    }

    // ---- plans (Generate mode) ------------------------------------------------

    private function createPlan(array $input, CreativeAgentSession $session, ?string $personName): array
    {
        $data = $this->planInput($input, partial: false);
        $data['caption'] = CreativeAiOutput::ensureDisclaimer($data['caption'] ?? null, $data['hook'] ?? null, $data['script'] ?? null);

        $plan = new CreativePlan;
        $plan->forceFill([
            ...$data,
            'status' => $data['status'] ?? 'idea',
            'platform' => $data['platform'] ?? 'tiktok',
            'format' => $data['format'] ?? 'video',
            'ai_drafted_at' => now(),
            'created_by_uni_id' => $session->uni_id,
            'created_by_name' => $personName,
            'updated_by_name' => $personName,
        ])->save();
        $plan->loadCount('generations');

        return [
            'content' => self::json(['created' => self::planForModel($plan)] + $this->warnings($plan)),
            'is_error' => false,
            // The chip IS this call's line on the page.
            'display' => [['kind' => 'plan', 'plan_id' => (int) $plan->id, 'text' => (string) $plan->title]],
            'job' => null,
        ];
    }

    private function updatePlan(array $input, ?string $personName): array
    {
        $plan = CreativePlan::find((int) ($input['id'] ?? 0));
        if ($plan === null) {
            return self::error('update_plan', 'There is no plan #'.(int) ($input['id'] ?? 0).'.', 'Could not update the plan');
        }
        unset($input['id']);
        $data = $this->planInput($input, partial: true);
        if ($data === []) {
            return self::error('update_plan', 'Name at least one field to change.', 'Could not update the plan');
        }
        $plan->fill($data);
        $plan->caption = CreativeAiOutput::ensureDisclaimer($plan->caption, $plan->hook, $plan->script);
        $plan->ai_drafted_at ??= now();
        $plan->updated_by_name = $personName;
        $plan->save();
        $plan->loadCount('generations');

        return self::ok('update_plan', ['updated' => self::planForModel($plan)] + $this->warnings($plan), 'Updated plan: '.mb_substr((string) $plan->title, 0, 80));
    }

    /** The plan fields the model sent, checked by the planner form's own rules. */
    private function planInput(array $input, bool $partial): array
    {
        $data = array_intersect_key($input, array_flip(self::PLAN_FIELDS));
        foreach (self::NULLABLE_PLAN_FIELDS as $field) {
            if (array_key_exists($field, $data) && is_string($data[$field]) && trim($data[$field]) === '') {
                $data[$field] = null;
            }
        }
        if (isset($data['title']) && is_string($data['title'])) {
            $data['title'] = trim($data['title']);
        }

        return Validator::make($data, CreativePlanRequest::fields($partial))->validate();
    }

    /** What a human should check, as the AI writer's warnings do — never silently fixed. */
    private function warnings(CreativePlan $plan): array
    {
        $warnings = [];
        foreach (['title', 'hook', 'script', 'caption', 'video_prompt', 'notes'] as $field) {
            $money = CreativeAiOutput::moneyIn((string) $plan->{$field});
            if ($money !== null) {
                $warnings[] = "The {$field} mentions a money amount ({$money}); posts never show money amounts — take it out with update_plan.";
                break;
            }
        }
        $limit = CreativeFacts::PLATFORMS[$plan->platform]['caption_max_chars'] ?? null;
        if ($limit !== null) {
            $length = mb_strlen((string) $plan->caption) + (trim((string) $plan->hashtags) !== '' ? 1 + mb_strlen((string) $plan->hashtags) : 0);
            if ($length > $limit) {
                $warnings[] = "The caption plus hashtags is {$length} characters; the platform allows {$limit}.";
            }
        }

        return $warnings === [] ? [] : ['warnings' => $warnings];
    }

    // ---- jobs (Generate mode) ---------------------------------------------------

    private function proposeJob(array $input, CreativeAgentSession $session): array
    {
        $job = $this->jobs->propose($session, $input);
        $budget = $this->budget->summary();
        $total = round((float) $job->estimated_usd, 4);

        return [
            'content' => self::json([
                'job_id' => (int) $job->id,
                'total_usd' => $total,
                'steps' => array_map(fn (array $s) => ['label' => $s['label'], 'usd' => $s['usd']], $job->steps),
                'fits_budget' => (int) round($total * 10000) <= (int) round($budget['remaining_usd'] * 10000),
                'budget_left_usd' => $budget['remaining_usd'],
                'instruction' => 'The job is PROPOSED, not started, and nothing was spent. Tell the person the total and that it starts when they click Go on the job card; it expires in '.CreativeAgentJob::EXPIRES_MINUTES.' minutes. Do not call more tools now.',
            ]),
            'is_error' => false,
            'display' => [['kind' => 'job', 'job_id' => (int) $job->id]],
            'job' => $job,
        ];
    }

    // ---- shapes ---------------------------------------------------------------

    /** A plan as the model sees it: the team's text scrubbed, no person's name. */
    private static function planForModel(CreativePlan $plan): array
    {
        return [
            'id' => (int) $plan->id,
            'title' => self::clean($plan->title),
            'stage' => $plan->status,
            'platform' => $plan->platform,
            'format' => $plan->format,
            'planned_for' => $plan->plannedFor(),
            'hook' => self::clean($plan->hook),
            'script' => self::clean($plan->script),
            'caption' => self::clean($plan->caption),
            'hashtags' => self::clean($plan->hashtags),
            'video_prompt' => self::clean($plan->video_prompt),
            'notes' => self::clean($plan->notes),
            'posted_url' => self::clean($plan->posted_url),
            'ai_drafted' => $plan->ai_drafted_at !== null,
            'generations' => (int) ($plan->generations_count ?? 0),
        ];
    }

    private static function ok(string $tool, array $data, string $label): array
    {
        return [
            'content' => self::json($data),
            'is_error' => false,
            'display' => [['kind' => 'activity', 'activity' => ['tool' => $tool, 'label' => $label, 'ok' => true]]],
            'job' => null,
        ];
    }

    private static function error(string $tool, string $message, string $label): array
    {
        return [
            'content' => $message,
            'is_error' => true,
            'display' => [['kind' => 'activity', 'activity' => ['tool' => $tool, 'label' => $label, 'ok' => false]]],
            'job' => null,
        ];
    }

    private static function verb(string $tool): string
    {
        return match ($tool) {
            'create_plan' => 'create the plan',
            'update_plan' => 'update the plan',
            'propose_job' => 'prepare the job',
            'remember' => 'save to memory',
            'forget' => 'remove from memory',
            'update_memory' => 'update memory',
            default => 'run '.str_replace('_', ' ', $tool),
        };
    }

    /** Team-typed text on its way to the model: scrubbed, optionally shortened. */
    private static function clean(?string $text, ?int $max = null): ?string
    {
        if ($text === null) {
            return null;
        }
        $text = CreativeAiPrompt::scrub($text);

        return $max !== null && mb_strlen($text) > $max ? mb_substr($text, 0, $max).'…' : $text;
    }

    private static function short(string $text): string
    {
        return mb_strlen($text) > 80 ? mb_substr($text, 0, 80).'…' : $text;
    }

    private static function clamp(mixed $value, int $min, int $max): int
    {
        return max($min, min($max, is_numeric($value) ? (int) $value : $min));
    }

    private static function json(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
