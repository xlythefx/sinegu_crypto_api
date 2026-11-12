<?php

namespace App\Services\Creatives;

use App\Http\Controllers\PublicStatsController;
use App\Http\Requests\CreativePlanRequest;
use App\Models\CreativeAgentJob;
use App\Models\CreativeAgentSession;
use App\Models\CreativeCharacter;
use App\Models\CreativeCharacterRef;
use App\Models\CreativeGeneration;
use App\Models\CreativeMemory;
use App\Models\CreativePlan;
use App\Models\CreativeStyle;
use App\Models\CreativeVideo;
use App\Models\CreativeVideoScene;
use App\Models\UserCredential;
use App\Services\Ai\AiTools;
use Illuminate\Support\Facades\DB;
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
 * for a created plan, a `video` chip for a created video, a `job` card for a
 * proposal, a `choices` row of buttons for ask_choices.
 *
 * Rules enforced HERE, not by the tool list (which is identical in both
 * modes):
 *  - Ask mode refuses create_plan / update_plan / propose_job / create_video
 *    / add_scenes with an error result ("Ask mode: switch to Generate to do
 *    this").
 *  - Two kinds of figures (owner's decision, 2026-11-12). The model may READ
 *    private data through query_data — the AI connector's own read-only
 *    tools, redacted of every credential and contact field
 *    ({@see AiTools}, the AiRedactor) — to understand
 *    and to answer the team. Anything meant to be POSTED (a plan, a video's
 *    title, prompts, labels, captions, template text) is refused with
 *    PRIVATE_IN_POST when it carries a money amount or a customer's name
 *    ({@see CreativeAiOutput::privateIn()}): the wall is here, server-side,
 *    not in the model's good manners. Public figures come only from the
 *    published track record ({@see CreativeFacts}).
 *  - Team-typed text the other tools return is still scrubbed
 *    ({@see CreativeAiPrompt::scrub()}); the Creatives budget is plain
 *    numbers.
 *  - propose_job, create_video and add_scenes never spend: they price and
 *    store; only Go starts anything. show_storyboard prices LOCALLY (it
 *    never calls Higgsfield) and stores nothing but its display row;
 *    edit_video's changes are free (text, lengths, order, captions, music).
 */
final class CreativeAgentTools
{
    public const ASK_REFUSAL = 'Ask mode: switch to Generate to do this';

    private const PLAN_FIELDS = ['title', 'status', 'platform', 'format', 'planned_for', 'hook', 'script', 'caption', 'hashtags', 'notes', 'video_prompt', 'posted_url'];

    /** Fields where an empty string from the model means "clear it". */
    private const NULLABLE_PLAN_FIELDS = ['planned_for', 'hook', 'script', 'caption', 'hashtags', 'notes', 'video_prompt', 'posted_url'];

    /** The plan fields a post shows (checked by the post guard). Notes are the team's own. */
    private const POSTED_PLAN_FIELDS = ['title', 'hook', 'script', 'caption', 'hashtags', 'video_prompt'];

    /** A query_data answer longer than this asks the model to narrow the question. */
    public const QUERY_MAX_BYTES = 60_000;

    public function __construct(
        private CreativeFacts $facts,
        private CreativeBudget $budget,
        private CreativeModels $models,
        private CreativeAgentJobs $jobs,
        private GenerationPoller $poller,
        private CreativeStyles $styles,
        private CreativeVideos $videos,
        private CreativeVideoPresenter $videoPresenter,
        private AiTools $data,
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
                'query_data' => $this->queryData($input),
                'ask_choices' => $this->askChoices($input),
                'get_style' => $this->getStyle($input),
                'create_video' => $this->createVideo($input, $session),
                'add_scenes' => $this->addScenes($input, $session),
                'get_video' => $this->getVideo($input),
                'list_videos' => $this->listVideos($input),
                'show_storyboard' => $this->showStoryboard($input),
                'edit_video' => $this->editVideo($input, $personName),
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
                $input = self::dropValues($input, ['exchange'], ['any', '']);
                $input = self::dropValues($input, ['coin'], ['', 'any', 'all']);
                break;

            case 'query_data':
                $arguments = $input['arguments'] ?? '{}';
                if (is_string($arguments)) {
                    $decoded = trim($arguments) === '' ? [] : json_decode($arguments, true);
                    if (! is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
                        throw ValidationException::withMessages(['arguments' => 'arguments is a JSON object written as text, e.g. "{\"coin\":\"LTC\"}" or "{}".']);
                    }
                    $input['arguments'] = $decoded;
                } elseif (! is_array($arguments)) {
                    $input['arguments'] = [];
                }
                break;

            case 'list_videos':
                $input = self::dropNonPositive($input, ['limit']);
                break;

            case 'show_storyboard':
                $input = self::dropNonPositive($input, ['video_id']);
                $input = self::dropValues($input, ['style'], ['', 'none']);
                break;

            case 'create_video':
            case 'add_scenes':
                $input = self::dropNonPositive($input, ['plan_id', 'replace_scene_id']);
                $input = self::dropValues($input, ['style'], ['', 'none']);
                if (($input['music'] ?? null) === 'none') {
                    $input['music'] = null;
                }
                if (is_array($input['scenes'] ?? null) && array_is_list($input['scenes'])) {
                    $input['scenes'] = array_map(fn ($scene) => is_array($scene) ? self::sceneDraft($scene) : $scene, $input['scenes']);
                }
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

    /**
     * One scene of create_video / add_scenes, from the strict schema's shape
     * (every field present, "" / "none" / false for nothing, the template as
     * four flat fields) to the SceneDraft contract. A draft already in the
     * contract's shape passes through.
     *
     * @param  array<string, mixed>  $scene
     * @return array<string, mixed>
     */
    private static function sceneDraft(array $scene): array
    {
        if (array_key_exists('template', $scene) || ! array_key_exists('template_key', $scene)) {
            return $scene;
        }
        $kind = $scene['kind'] ?? CreativeVideoScene::CLIP;
        $text = fn (string $key) => is_string($scene[$key] ?? null) ? $scene[$key] : '';

        return [
            'kind' => $kind,
            'label' => $text('label'),
            'prompt' => $kind === CreativeVideoScene::TEMPLATE ? null : $text('prompt'),
            'seconds' => $scene['seconds'] ?? null,
            'engine' => in_array($scene['engine'] ?? null, ['', 'none'], true) ? null : ($scene['engine'] ?? null),
            'with_character' => (bool) ($scene['with_pixel'] ?? false),
            'caption' => trim($text('caption')) !== '' ? $text('caption') : null,
            'template' => $kind === CreativeVideoScene::TEMPLATE
                ? [
                    'key' => ($scene['template_key'] ?? 'none') === 'none' ? null : $scene['template_key'],
                    'title' => $text('template_title'),
                    'value' => $text('template_value'),
                    'subtitle' => $text('template_subtitle'),
                ]
                : null,
        ];
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
        $exchange = $input['exchange'] ?? null;
        if ($exchange !== null && ! in_array($exchange, ['binance', 'bybit', 'mexc'], true)) {
            throw ValidationException::withMessages(['exchange' => 'exchange is one of: any, binance, bybit, mexc.']);
        }
        $coin = isset($input['coin']) ? self::coinKey((string) $input['coin']) : null;

        $performance = $this->facts->performance(null, $days, $exchange, $coin);
        $brand = $this->facts->forPrompt(null, null)['brand'];
        // Per coin: its share of the period's return, measured on the
        // account's whole capital (the recap's "assets" ranking).
        $performance['top_coins'] = array_map(
            fn (array $c) => ['symbol' => $c['symbol'], 'share_of_capital_pct' => $c['share_pct']],
            $performance['top_coins'],
        );

        $scope = trim(implode(' on ', array_filter([$coin, $exchange !== null ? ucfirst($exchange) : null])));
        $label = match (true) {
            $scope !== '' => "Read the public results: {$scope}, {$days} days",
            $days === CreativeFacts::WINDOW_DAYS => 'Read this week\'s public results',
            default => "Read the public results for {$days} days",
        };

        return self::ok('get_week_results', [
            'performance' => $performance + [
                'days' => $days,
                'source' => 'Pixel Alpha\'s published track record (the master account), days in Asia/Manila time. return_pct is the period\'s compounded return; daily[].pct and best_day.pct are each day\'s return; top_coins[].share_of_capital_pct is that coin\'s share of the return, as a percentage of the account\'s capital; trades counts closed trades. With an exchange, every figure is measured on that exchange\'s own capital; with a coin, only that coin\'s trades count.',
                'rules' => $performance['available']
                    ? 'Copy these figures exactly. Any caption citing a percentage ends with: '.CreativeAi::DISCLAIMER
                    : 'Nothing was published in this window: use no performance figures at all.',
            ],
            'brand' => $brand,
        ], $label);
    }

    /** "ltc" / "LTC_USDT" / "LTCUSDT" → "LTCUSDT" (a bare coin is quoted in USDT). */
    private static function coinKey(string $coin): string
    {
        $key = PublicStatsController::symbolKey($coin);
        if ($key !== '' && preg_match('/(USDT|USDC|USD|BUSD|FDUSD)$/', $key) !== 1) {
            $key .= 'USDT';
        }
        if (preg_match('/^[A-Z0-9]{3,20}$/', $key) !== 1) {
            throw ValidationException::withMessages(['coin' => 'coin is a ticker like "LTCUSDT" or "LTC", or "" for every coin.']);
        }

        return $key;
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
        $this->guardPost(array_intersect_key($data, array_flip(self::POSTED_PLAN_FIELDS)));
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
        $this->guardPost(array_intersect_key($data, array_flip(self::POSTED_PLAN_FIELDS)));
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

    // ---- data (both modes) --------------------------------------------------------

    /**
     * query_data: one of the AI connector's read-only tools (AiTools — the
     * same answers the admin pages give, every credential and contact field
     * redacted). The answer is private: for understanding, never for a post.
     */
    private function queryData(array $input): array
    {
        $tool = (string) ($input['tool'] ?? '');
        if (! in_array($tool, CreativeAgentPrompt::QUERY_TOOLS, true) || ! $this->data->has($tool)) {
            return self::error('query_data', 'tool is one of: '.implode(', ', CreativeAgentPrompt::QUERY_TOOLS).'.', 'Could not look that up');
        }
        $label = 'Looked up '.str_replace('_', ' ', $tool);

        [$ok, $text] = $this->data->run($tool, is_array($input['arguments'] ?? null) ? $input['arguments'] : []);
        if (! $ok) {
            return self::error('query_data', $text, $label);
        }
        if (strlen($text) > self::QUERY_MAX_BYTES) {
            return self::error('query_data', 'The answer is too large ('.(int) ceil(strlen($text) / 1024).' KB). Narrow it: a shorter date range, one coin or exchange, a lower limit, or a grouped count instead of rows.', $label);
        }

        return [
            'content' => '{"tool":'.json_encode($tool).',"private":"Internal figures: use them to understand and to answer the person. Never put a money amount, balance, user count or anyone\'s name in a plan, caption, prompt or video.","answer":'.$text.'}',
            'is_error' => false,
            'display' => [['kind' => 'activity', 'activity' => ['tool' => 'query_data', 'label' => $label, 'ok' => true]]],
            'job' => null,
        ];
    }

    /** ask_choices: buttons in the chat; the turn ends and the pick is the person's next message. */
    private function askChoices(array $input): array
    {
        $question = trim(is_string($input['question'] ?? null) ? $input['question'] : '');
        if ($question === '' || mb_strlen($question) > 300) {
            throw ValidationException::withMessages(['question' => 'The question is 1 to 300 characters.']);
        }
        $raw = $input['options'] ?? null;
        if (! is_array($raw) || ! array_is_list($raw) || count($raw) < 2 || count($raw) > 6) {
            throw ValidationException::withMessages(['options' => 'Give 2 to 6 options.']);
        }
        $options = [];
        foreach ($raw as $option) {
            $label = trim(is_array($option) && is_string($option['label'] ?? null) ? $option['label'] : '');
            $value = trim(is_array($option) && is_string($option['value'] ?? null) ? $option['value'] : '');
            if ($label === '' || mb_strlen($label) > 60 || $value === '' || mb_strlen($value) > 300) {
                throw ValidationException::withMessages(['options' => 'Each option has a label (1 to 60 characters) and a value (1 to 300).']);
            }
            $options[] = ['label' => $label, 'value' => $value];
        }
        $choices = ['question' => $question, 'options' => $options, 'allow_other' => (bool) ($input['allow_other'] ?? false)];

        return [
            'content' => self::json([
                'shown' => true,
                'instruction' => 'The buttons are on the person\'s screen. Stop here: their pick (or their own words) arrives as their next message.',
            ]),
            'is_error' => false,
            // The buttons ARE this call's line on the page.
            'display' => [['kind' => 'choices', 'choices' => $choices]],
            'job' => null,
        ];
    }

    private function getStyle(array $input): array
    {
        $slug = trim(is_string($input['slug'] ?? null) ? $input['slug'] : '');
        $style = $slug !== '' ? $this->styles->bySlug($slug) : null;
        if ($style === null || ! $style->enabled) {
            $valid = CreativeStyle::query()->where('enabled', true)->orderBy('sort')->orderBy('name')->pluck('slug')->all();

            return self::error('get_style', "There is no style \"{$slug}\". The styles are: ".implode(', ', $valid).'.', 'Could not open the style');
        }

        return self::ok('get_style', ['style' => [
            'slug' => $style->slug,
            'name' => self::clean($style->name),
            'summary' => self::clean($style->summary),
            'default_platform' => $style->default_platform,
            'default_aspect' => $style->default_aspect,
            'pixel_default' => $style->pixel_default,
            'default_engine' => $style->default_engine,
            'caption_style' => $style->caption_style,
            'template_family' => $style->template_family,
            'music_default' => $style->music_default,
            'pixel_look' => (bool) $style->pixel_look,
            'markdown' => self::clean($style->markdown),
        ]], 'Read the style: '.mb_substr((string) $style->name, 0, 60));
    }

    private function getVideo(array $input): array
    {
        $video = CreativeVideo::find((int) ($input['id'] ?? 0));
        if ($video === null) {
            return self::error('get_video', 'There is no video #'.(int) ($input['id'] ?? 0).'.', 'Could not open the video');
        }

        return self::ok('get_video', ['video' => $this->videoForModel($video)], 'Opened video: '.mb_substr((string) $video->title, 0, 80));
    }

    private function listVideos(array $input): array
    {
        $limit = self::clamp($input['limit'] ?? 10, 1, 20);
        $rows = CreativeVideo::query()->orderByDesc('updated_at')->orderByDesc('id')->limit($limit)
            ->withCount(['scenes', 'scenes as scenes_ready_count' => fn ($q) => $q->where('status', CreativeVideoScene::READY)])
            ->get()
            ->map(fn (CreativeVideo $v) => [
                'id' => (int) $v->id,
                'title' => self::clean($v->title),
                'scenes_total' => (int) $v->scenes_count,
                'scenes_ready' => (int) $v->scenes_ready_count,
                'status' => $v->status,
                'updated_at' => $v->updated_at?->toIso8601String(),
            ])->values()->all();

        return self::ok('list_videos', ['videos' => $rows], 'Looked at the video projects');
    }

    // ---- videos (Generate mode) ---------------------------------------------------

    private function createVideo(array $input, CreativeAgentSession $session): array
    {
        $user = UserCredential::find($session->uni_id) ?? throw new CreativeException('NOT_FOUND', 'The person of this chat no longer exists.', 404);
        $title = trim(is_string($input['title'] ?? null) ? $input['title'] : '');
        if ($title === '' || mb_strlen($title) > 160) {
            throw ValidationException::withMessages(['title' => 'The video needs a title of at most 160 characters.']);
        }
        foreach (['platform' => CreativeVideo::PLATFORMS, 'aspect' => CreativeVideo::ASPECTS, 'caption_style' => CreativeStyles::CAPTION_STYLES] as $field => $allowed) {
            if (! in_array($input[$field] ?? null, $allowed, true)) {
                throw ValidationException::withMessages([$field => "{$field} is one of: ".implode(', ', $allowed).'.']);
            }
        }
        $music = $input['music'] ?? null;
        if ($music !== null && ! in_array($music, CreativeStyles::MUSIC_PRESETS, true)) {
            throw ValidationException::withMessages(['music' => 'music is "none" or one of: '.implode(', ', CreativeStyles::MUSIC_PRESETS).'.']);
        }
        $style = null;
        if (isset($input['style'])) {
            $style = $this->styles->bySlug((string) $input['style']);
            if ($style === null || ! $style->enabled) {
                $valid = CreativeStyle::query()->where('enabled', true)->orderBy('sort')->pluck('slug')->all();
                throw ValidationException::withMessages(['style' => 'There is no style "'.$input['style'].'". The styles are: '.implode(', ', $valid).'.']);
            }
        }
        $planId = isset($input['plan_id']) ? (int) $input['plan_id'] : null;
        if ($planId !== null && ! CreativePlan::whereKey($planId)->exists()) {
            throw ValidationException::withMessages(['plan_id' => "There is no plan #{$planId}."]);
        }
        $drafts = $this->drafts($input, $style, $title);

        // A savepoint: a refusal while pricing leaves no half-made video.
        $made = DB::transaction(function () use ($input, $user, $title, $style, $planId, $music, $drafts, $session) {
            $video = $this->videos->create([
                'title' => $title,
                'platform' => $input['platform'],
                'aspect' => $input['aspect'],
                'style_id' => $style?->id,
                'plan_id' => $planId,
            ], $user, [
                'caption_style' => $input['caption_style'],
                'music' => $music,
                'pixel_look' => (bool) ($input['pixel_look'] ?? false),
            ]);

            return $this->videos->proposeScenes($video, $drafts, null, $user, $session);
        });

        return $this->videoAnswer('create_video', $made);
    }

    private function addScenes(array $input, CreativeAgentSession $session): array
    {
        $user = UserCredential::find($session->uni_id) ?? throw new CreativeException('NOT_FOUND', 'The person of this chat no longer exists.', 404);
        $video = CreativeVideo::find((int) ($input['video_id'] ?? 0));
        if ($video === null) {
            return self::error('add_scenes', 'There is no video #'.(int) ($input['video_id'] ?? 0).'.', 'Could not add the scenes');
        }
        $style = $video->style_id !== null ? CreativeStyle::find($video->style_id) : null;
        $drafts = $this->drafts($input, $style, null);
        $replace = isset($input['replace_scene_id']) ? (int) $input['replace_scene_id'] : null;

        $made = DB::transaction(fn () => $this->videos->proposeScenes($video, $drafts, $replace, $user, $session));

        return $this->videoAnswer('add_scenes', $made);
    }

    /**
     * show_storyboard (both modes, no money): the video's plan as a card —
     * each scene's length on the timeline and the length it is generated,
     * its engine, Pixel or not, what we see and why — priced LOCALLY with the
     * steps create_video would propose ({@see CreativeVideos::estimateSceneUsd()};
     * Higgsfield is never asked). Nothing is saved but the display row.
     */
    private function showStoryboard(array $input): array
    {
        $title = trim(is_string($input['title'] ?? null) ? $input['title'] : '');
        if ($title === '' || mb_strlen($title) > 160) {
            throw ValidationException::withMessages(['title' => 'The storyboard needs a title of at most 160 characters.']);
        }
        $videoId = isset($input['video_id']) ? (int) $input['video_id'] : null;
        if ($videoId !== null && ! CreativeVideo::whereKey($videoId)->exists()) {
            throw ValidationException::withMessages(['video_id' => "There is no video #{$videoId}; use 0 for a new video."]);
        }
        foreach (['platform' => CreativeVideo::PLATFORMS, 'aspect' => CreativeVideo::ASPECTS] as $field => $allowed) {
            if (! in_array($input[$field] ?? null, $allowed, true)) {
                throw ValidationException::withMessages([$field => "{$field} is one of: ".implode(', ', $allowed).'.']);
            }
        }
        $style = null;
        if (isset($input['style'])) {
            $style = $this->styles->bySlug((string) $input['style']);
            if ($style === null || ! $style->enabled) {
                $valid = CreativeStyle::query()->where('enabled', true)->orderBy('sort')->pluck('slug')->all();
                throw ValidationException::withMessages(['style' => 'There is no style "'.$input['style'].'". The styles are: '.implode(', ', $valid).'.']);
            }
        }
        $notes = trim(is_string($input['notes'] ?? null) ? $input['notes'] : '');
        if (mb_strlen($notes) > 600) {
            throw ValidationException::withMessages(['notes' => 'The notes are at most 600 characters.']);
        }

        $raw = $input['scenes'] ?? null;
        if (! is_array($raw) || ! array_is_list($raw) || $raw === [] || count($raw) > CreativeVideos::MAX_DRAFTS) {
            throw ValidationException::withMessages(['scenes' => 'Give 1 to '.CreativeVideos::MAX_DRAFTS.' scenes.']);
        }
        $scenes = [];
        $totalSeconds = 0;
        $totalUsd = 0.0;
        foreach ($raw as $i => $scene) {
            $n = $i + 1;
            $scene = is_array($scene) ? $scene : [];
            $kind = $scene['kind'] ?? null;
            if (! in_array($kind, CreativeVideoScene::KINDS, true)) {
                throw ValidationException::withMessages(["scenes.{$i}.kind" => "Scene {$n}: kind is clip or template."]);
            }
            $label = trim(is_string($scene['label'] ?? null) ? $scene['label'] : '');
            if (mb_strlen($label) > 80) {
                throw ValidationException::withMessages(["scenes.{$i}.label" => "Scene {$n}: the label is at most 80 characters."]);
            }
            $texts = [];
            foreach (['shows', 'why'] as $field) {
                $texts[$field] = trim(is_string($scene[$field] ?? null) ? $scene[$field] : '');
                if (mb_strlen($texts[$field]) > 300) {
                    throw ValidationException::withMessages(["scenes.{$i}.{$field}" => "Scene {$n}: {$field} is at most 300 characters."]);
                }
            }
            $seconds = filter_var($scene['seconds'] ?? null, FILTER_VALIDATE_INT);
            $seconds = $seconds === false ? 0 : (int) $seconds;

            $engine = null;
            $pixel = false;
            if ($kind === CreativeVideoScene::CLIP) {
                $engine = $scene['engine'] ?? null;
                if ($engine === null || $engine === '' || $engine === 'none') {
                    $engine = $style?->default_engine ?? CreativeVideoScene::KLING;
                }
                if (! in_array($engine, CreativeVideoScene::ENGINES, true)) {
                    throw ValidationException::withMessages(["scenes.{$i}.engine" => "Scene {$n}: the engine is kling or seedance."]);
                }
                $pixel = (bool) ($scene['with_pixel'] ?? false);
            }
            CreativeVideos::assertSeconds($engine ?? 'template', $seconds, "scenes.{$i}.seconds", $n);
            $generated = $engine !== null ? CreativeVideos::generatedSeconds($engine, $seconds) : $seconds;
            $usd = $this->videos->estimateSceneUsd($kind, $engine, $pixel, $generated);

            $totalSeconds += $seconds;
            $totalUsd += $usd;
            $scenes[] = [
                'n' => $n,
                'kind' => $kind,
                'label' => $label !== '' ? $label : 'Scene '.$n,
                'seconds' => $seconds,
                'generated_seconds' => $generated,
                'engine' => $engine ?? 'none',
                'engine_label' => $engine !== null ? CreativeVideos::ENGINE_LABELS[$engine] : null,
                'with_pixel' => $pixel,
                'shows' => $texts['shows'],
                'why' => $texts['why'],
                'est_usd' => $usd,
            ];
        }
        if ($totalSeconds > CreativeVideos::STORYBOARD_MAX_SECONDS) {
            throw ValidationException::withMessages(['scenes' => "These scenes add up to {$totalSeconds} seconds; a video is at most ".CreativeVideos::STORYBOARD_MAX_SECONDS.' seconds. Shorten or drop a scene.']);
        }

        $storyboard = [
            'title' => $title,
            'video_id' => $videoId,
            'platform' => $input['platform'],
            'aspect' => $input['aspect'],
            'style' => $style?->slug,
            'total_seconds' => $totalSeconds,
            'est_total_usd' => round($totalUsd, 4),
            'notes' => $notes,
            'scenes' => $scenes,
        ];

        return [
            'content' => self::json([
                'storyboard' => $storyboard,
                'instruction' => 'The storyboard is on the person\'s screen; nothing was made, saved or spent. Now ask "Build it?" with ask_choices (Yes / Change something). These prices are estimates: once built, state the prices create_video or add_scenes answer exactly.',
            ]),
            'is_error' => false,
            // The card IS this call's line on the page.
            'display' => [['kind' => 'storyboard', 'storyboard' => $storyboard]],
            'job' => null,
        ];
    }

    /**
     * edit_video (Generate mode, free): the person's video changed by chat —
     * labels, lengths (trims), order, card text, captions, removals, music,
     * caption style, pixel look — through {@see CreativeVideos::edit()} (the
     * editor's own checks, TOO_LONG included; all or nothing, except a
     * removal refused because its clip is being made). Every new text a post
     * shows passes the privacy wall first.
     */
    private function editVideo(array $input, ?string $personName): array
    {
        $video = CreativeVideo::find((int) ($input['video_id'] ?? 0));
        if ($video === null) {
            return self::error('edit_video', 'There is no video #'.(int) ($input['video_id'] ?? 0).'.', 'Could not edit the video');
        }
        $changes = $input['scene_changes'] ?? [];
        if (! is_array($changes) || ! array_is_list($changes)) {
            throw ValidationException::withMessages(['scene_changes' => 'scene_changes is a list of {scene_id, …}; [] for none.']);
        }

        $texts = [];
        foreach ($changes as $i => $change) {
            if (! is_array($change)) {
                continue;
            }
            $n = $i + 1;
            foreach (['label' => 'label', 'template_title' => 'card title', 'template_value' => 'card value', 'template_subtitle' => 'card subtitle'] as $field => $what) {
                if (is_string($change[$field] ?? null) && trim($change[$field]) !== '') {
                    $texts["change {$n} {$what}"] = $change[$field];
                }
            }
            if (is_string($change['caption'] ?? null) && ! in_array(trim($change['caption']), ['-', ''], true)) {
                $texts["change {$n} caption"] = $change['caption'];
            }
        }
        $this->guardPost($texts);

        $made = $this->videos->edit($video, $changes, [
            'music' => is_string($input['music'] ?? null) ? $input['music'] : 'keep',
            'caption_style' => is_string($input['caption_style'] ?? null) ? $input['caption_style'] : 'keep',
            'pixel_look' => is_string($input['pixel_look'] ?? null) ? $input['pixel_look'] : 'keep',
        ], $personName);

        if ($made['changed'] === 0) {
            return self::error('edit_video', 'Nothing was changed: '.implode(' ', array_map(fn ($r) => "Scene {$r['scene_id']}: {$r['reason']}", $made['refused'])), 'Could not edit the video');
        }

        $answer = ['video' => $this->videoForModel($made['video']->fresh())];
        if ($made['refused'] !== []) {
            $answer['refused'] = $made['refused'];
        }
        $answer['instruction'] = 'Saved in the editor; nothing was generated or spent. Tell the person what changed'.($made['refused'] !== [] ? ', and which removal was refused and why.' : '.');

        return [
            'content' => self::json($answer),
            'is_error' => false,
            'display' => [[
                'kind' => 'activity',
                'activity' => ['tool' => 'edit_video', 'label' => 'Edited the video', 'ok' => true],
                'video_id' => (int) $video->id,
            ]],
            'job' => null,
        ];
    }

    /**
     * The scenes checked as SceneDrafts, and the post guard over every text a
     * video shows (its title, the prompts, labels, captions and card text).
     *
     * @return list<array<string, mixed>>
     */
    private function drafts(array $input, ?CreativeStyle $style, ?string $title): array
    {
        $raw = $input['scenes'] ?? null;
        if (! is_array($raw) || ! array_is_list($raw) || $raw === [] || count($raw) > CreativeVideos::MAX_DRAFTS) {
            throw ValidationException::withMessages(['scenes' => 'Give 1 to '.CreativeVideos::MAX_DRAFTS.' scenes.']);
        }
        $drafts = [];
        $texts = $title !== null ? ['title' => $title] : [];
        foreach ($raw as $i => $scene) {
            $draft = CreativeVideos::validateDraft(is_array($scene) ? $scene : [], (int) $i, $style);
            $n = $i + 1;
            $texts["scene {$n} label"] = $draft['label'];
            $texts["scene {$n} prompt"] = $draft['prompt'];
            $texts["scene {$n} caption"] = $draft['caption'];
            foreach (['title', 'value', 'subtitle'] as $field) {
                $texts["scene {$n} card {$field}"] = $draft['template'][$field] ?? null;
            }
            $drafts[] = $draft;
        }
        $this->guardPost($texts);

        return $drafts;
    }

    /** create_video / add_scenes' answer: the video chip, the job card (when clips were proposed). */
    private function videoAnswer(string $tool, array $made): array
    {
        /** @var CreativeVideo $video */
        $video = $made['video'];
        /** @var CreativeAgentJob|null $job */
        $job = $made['job'];
        $budget = $this->budget->summary();
        $total = $job !== null ? round((float) $job->estimated_usd, 4) : 0.0;

        $display = [['kind' => 'video', 'video_id' => (int) $video->id, 'text' => (string) $video->title]];
        if ($job !== null) {
            $display[] = ['kind' => 'job', 'job_id' => (int) $job->id];
        }

        return [
            'content' => self::json([
                'video_id' => (int) $video->id,
                'editor_path' => CreativeVideo::editorPath((int) $video->id),
                'job_id' => $job !== null ? (int) $job->id : null,
                'usd' => $total,
                'steps' => $job !== null ? array_map(fn (array $s) => ['label' => $s['label'], 'model' => $s['model'], 'usd' => $s['usd']], $job->steps) : [],
                'scene_ids' => $made['scene_ids'],
                'fits_budget' => (int) round($total * 10000) <= (int) round($budget['remaining_usd'] * 10000),
                'budget_left_usd' => $budget['remaining_usd'],
                'instruction' => $job !== null
                    ? 'The video is in the editor and its clips are PROPOSED, not started; nothing was spent. Tell the person the total and that the clips are made together when they click Go on the job card, then land in the editor in order; it expires in '.CreativeAgentJob::EXPIRES_MINUTES.' minutes. Do not call more tools now.'
                    : 'The video is in the editor; its scenes are free template cards, ready now. Tell the person they can open it in the editor.',
            ]),
            'is_error' => false,
            'display' => $display,
            'job' => $job,
        ];
    }

    /** A video as the model sees it: scenes with their status, the time used and left. */
    private function videoForModel(CreativeVideo $video): array
    {
        $shape = $this->videoPresenter->video($video);
        $timeline = (array) $shape['timeline'];
        $music = is_array($timeline['music'] ?? null) ? $timeline['music'] : null;

        return [
            'id' => $shape['id'],
            'title' => self::clean($shape['title']),
            'platform' => $shape['platform'],
            'aspect' => $shape['aspect'],
            'style' => $shape['style']['slug'] ?? null,
            'plan_id' => $shape['plan_id'],
            'status' => $shape['status'],
            'editor_path' => CreativeVideo::editorPath((int) $shape['id']),
            'duration_ms' => $shape['duration_ms'],
            'seconds_used' => round($shape['duration_ms'] / 1000, 1),
            'seconds_left' => round(max(0, $shape['max_seconds'] * 1000 - $shape['duration_ms']) / 1000, 1),
            'scenes' => array_map(fn (array $s) => [
                'id' => $s['id'],
                'position' => $s['position'],
                'kind' => $s['kind'],
                'label' => self::clean($s['label']),
                'engine' => $s['engine'],
                'with_pixel' => $s['with_character'],
                'seconds' => $s['seconds'],
                'duration_ms' => $s['duration_ms'],
                'status' => $s['status'],
                'error' => $s['error'],
                'template' => $s['template'],
                'job_id' => $s['agent_job_id'],
            ], $shape['scenes']),
            'captions' => array_values(array_map(fn ($c) => [
                'text' => is_array($c) ? self::clean((string) ($c['text'] ?? '')) : '',
                'start_ms' => is_array($c) ? ($c['start_ms'] ?? null) : null,
                'end_ms' => is_array($c) ? ($c['end_ms'] ?? null) : null,
            ], is_array($timeline['captions'] ?? null) ? $timeline['captions'] : [])),
            'music' => $music === null ? 'none' : (($music['source'] ?? null) === 'chiptune' ? 'chiptune: '.($music['preset'] ?? '?') : 'an uploaded track'),
            'caption_style' => $timeline['caption_style'] ?? null,
        ];
    }

    /**
     * The privacy wall for anything meant to be posted: a money amount or a
     * customer's name refuses the whole call (PRIVATE_IN_POST) — nothing is
     * saved, and the model is told what to take out.
     *
     * @param  array<string, string|null>  $texts
     */
    private function guardPost(array $texts): void
    {
        $why = CreativeAiOutput::privateIn($texts);
        if ($why !== null) {
            throw new CreativeException(
                'PRIVATE_IN_POST',
                "PRIVATE_IN_POST: {$why}. Posts carry only public figures from get_week_results and never a money amount, a balance, a user count or a customer's name — rewrite it without them.",
                422,
            );
        }
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
            'create_video' => 'create the video',
            'add_scenes' => 'add the scenes',
            'query_data' => 'look that up',
            'ask_choices' => 'show the choices',
            'get_style' => 'open the style',
            'get_video' => 'open the video',
            'list_videos' => 'list the videos',
            'show_storyboard' => 'show the storyboard',
            'edit_video' => 'edit the video',
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
