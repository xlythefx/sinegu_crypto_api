<?php

namespace App\Services\Ai\Tools;

use App\Models\AiAccessKey;
use App\Models\CreativeGeneration;
use App\Models\CreativePlan;
use App\Services\Creatives\CreativeAiOutput;
use App\Services\Creatives\CreativeBudget;
use App\Services\Creatives\CreativeException;
use App\Services\Creatives\CreativeGenerator;
use App\Services\Creatives\CreativeModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The connector's ONLY writes (owner, 2026-11-17): Grok makes images and
 * clips on Higgsfield and saves Idea plans in Admin → Creatives — on a key a
 * full admin switched on (`ai_access_keys.can_create`), never otherwise
 * (those keys do not even see these tools).
 *
 * Money moves in two steps, like the page's Go button, because "Shall I start
 * it?" in a chat is a promise, not a button:
 *  1. price_generation asks Higgsfield's FREE price check and answers a
 *     quote_id (valid QUOTE_MINUTES) with the price and what is left;
 *  2. start_generation starts exactly that quote, once (the quote is
 *     consumed), after the limits are checked again.
 * Limits, all checked before anything is sent: one generation ≤
 * MAX_PER_GENERATION_USD, one key ≤ MAX_PER_KEY_DAY_USD per UTC day, and the
 * team's monthly cap (CreativeBudget, under its row lock, as always).
 *
 * Everything Grok makes is labelled "Grok" in the gallery, the budget and the
 * editor's history, with the key it used (`ai_access_key_id`). Text that ends
 * up in a picture or a post may not carry a money amount or a customer's
 * name — Grok can READ those (PRIVATE_IN_POST, the Creatives rule).
 */
final class CreativeMakeTools implements KeyAwareToolset
{
    public const MAX_PER_GENERATION_USD = 1.00;

    public const MAX_PER_KEY_DAY_USD = 20.00;

    public const QUOTE_MINUTES = 15;

    /** What everything Grok makes is labelled. */
    public const LABEL = 'Grok';

    private ?AiAccessKey $key = null;

    public function allows(?AiAccessKey $key): bool
    {
        return $key !== null && (bool) $key->can_create;
    }

    public function withKey(?AiAccessKey $key): void
    {
        $this->key = $key;
    }

    public function definitions(): array
    {
        $usd = '$'.number_format(self::MAX_PER_GENERATION_USD, 2);
        $day = '$'.number_format(self::MAX_PER_KEY_DAY_USD, 0);

        return [
            'price_generation' => [
                'title' => 'Price an image or clip (free)',
                'description' => 'Step 1 of making something on Higgsfield — FREE, nothing is started. Give a model key from '
                    .'creative_models, a prompt, and its settings. Answers the price, a quote_id and what is left this month. '
                    ."Tell the person the price and ask before step 2 (start_generation). Limits: {$usd} per generation, "
                    ."{$day} per day for your key, and the team's monthly cap. To animate an image, give source.generation_id "
                    .'(a finished image from creative_generations). Never put a money amount or a customer\'s name in a prompt.',
                'writes' => true,
                'schema' => Props::object([
                    'model' => ['type' => 'string', 'description' => 'A key from creative_models (e.g. soul-image, grok-image, kling-animate, seedance-text-video).'],
                    'prompt' => ['type' => 'string', 'description' => 'What to make, 3–2000 characters.'],
                    'params' => ['type' => 'object', 'description' => 'The model\'s settings by name (from creative_models), e.g. {"aspect_ratio": "9:16", "duration": 5}. Omitted = defaults.'],
                    'use_character' => ['type' => 'boolean', 'description' => 'Draw the mascot Pixel from its reference images (models that use references). Default true.'],
                    'source' => [
                        'type' => 'object',
                        'description' => 'Animating an image only: {"generation_id": N, "index": 0}.',
                        'properties' => ['generation_id' => ['type' => 'integer'], 'index' => ['type' => 'integer']],
                    ],
                    'plan_id' => ['type' => 'integer', 'description' => 'Attach the result to this content plan (creative_plans).'],
                ], ['model', 'prompt']),
            ],
            'start_generation' => [
                'title' => 'Start a priced image or clip (spends)',
                'description' => 'Step 2: starts the generation priced by price_generation — SPENDS money, so only after the '
                    .'person said yes to that price. Give its quote_id (each quote starts once; it lapses after '
                    .self::QUOTE_MINUTES.' minutes). Images take under a minute, clips a few minutes: check with '
                    .'creative_generations {generation_id}. The result is labelled "Grok" for the team.',
                'writes' => true,
                'schema' => Props::object(['quote_id' => ['type' => 'string', 'description' => 'From price_generation.']], ['quote_id']),
            ],
            'save_plan_idea' => [
                'title' => 'Save a post idea (free)',
                'description' => 'Adds a plan to the content planner at the IDEA stage, labelled "Grok", for the team to review '
                    .'— nothing is posted. Never a money amount, a balance or a customer\'s name in it.',
                'writes' => true,
                'schema' => Props::object([
                    'title' => ['type' => 'string', 'description' => 'Up to 160 characters.'],
                    'platform' => ['type' => 'string', 'enum' => CreativePlan::PLATFORMS],
                    'format' => ['type' => 'string', 'enum' => CreativePlan::FORMATS],
                    'planned_for' => ['type' => 'string', 'description' => 'YYYY-MM-DD.'],
                    'hook' => ['type' => 'string'],
                    'script' => ['type' => 'string'],
                    'caption' => ['type' => 'string'],
                    'hashtags' => ['type' => 'string'],
                    'notes' => ['type' => 'string'],
                ], ['title']),
            ],
        ];
    }

    public function call(string $tool, array $arguments): array
    {
        if (! $this->allows($this->key)) {
            throw new ToolError('This key may not make anything in Creatives. A full admin can switch that on in Admin → AI Access.');
        }

        try {
            return match ($tool) {
                'price_generation' => $this->price($arguments),
                'start_generation' => $this->start($arguments),
                'save_plan_idea' => $this->savePlan($arguments),
            };
        } catch (CreativeException $e) {
            throw new ToolError($e->getMessage());
        } catch (ValidationException $e) {
            throw new ToolError((string) collect($e->errors())->flatten()->first());
        }
    }

    // ---- generations ------------------------------------------------------------

    private function price(array $arguments): array
    {
        $input = $this->generationInput($arguments);
        $quote = app(CreativeGenerator::class)->quote($input);
        $usd = round((float) $quote['usd'], 4);
        $refusal = $this->refusal($usd);

        $quoteId = (string) Str::uuid();
        Cache::put(self::quoteKey($quoteId), ['key_id' => $this->key->id, 'input' => $input, 'usd' => $usd], now()->addMinutes(self::QUOTE_MINUTES));

        return [
            'quote_id' => $refusal === null ? $quoteId : null,
            'usd' => $usd,
            'model' => app(CreativeModels::class)->label($input['model']),
            'can_start' => $refusal === null,
            'why_not' => $refusal,
            'left_this_month_usd' => app(CreativeBudget::class)->summary()['remaining_usd'],
            'left_today_for_this_key_usd' => round(max(0, self::MAX_PER_KEY_DAY_USD - $this->spentToday()), 2),
            'quote_valid_minutes' => self::QUOTE_MINUTES,
            'next' => $refusal === null
                ? 'Tell the person the price and ask; on yes, call start_generation with this quote_id.'
                : 'Nothing can be started at this price. Suggest something cheaper (a shorter clip, 480p, one image).',
        ];
    }

    private function start(array $arguments): array
    {
        $quoteId = (new Args($arguments))->str('quote_id', null, 64) ?? throw new ToolError('Give the quote_id from price_generation.');
        // Pulled, not read: one quote starts at most once, however often it is sent.
        $quote = Cache::pull(self::quoteKey($quoteId));
        if (! is_array($quote) || (int) ($quote['key_id'] ?? 0) !== (int) $this->key->id) {
            throw new ToolError('That quote is unknown, already used or older than '.self::QUOTE_MINUTES.' minutes. Price it again with price_generation.');
        }
        if (($why = $this->refusal((float) $quote['usd'])) !== null) {
            throw new ToolError($why);
        }

        $generation = app(CreativeGenerator::class)->create($quote['input'], null, null, [
            'name' => self::LABEL,
            'ai_access_key_id' => (int) $this->key->id,
        ]);

        return [
            'started' => true,
            'generation' => CreativeTools::generation($generation->fresh(['plan'])),
            'next' => 'Images take under a minute, clips a few minutes. Check with creative_generations {generation_id: '.$generation->id.'}.',
        ];
    }

    /** Why this price cannot start now, or null. The monthly cap is held again, under its lock, by the generator. */
    private function refusal(float $usd): ?string
    {
        if ($usd > self::MAX_PER_GENERATION_USD) {
            return sprintf('It costs about $%.2f, over the $%.2f limit for one generation made through Grok.', $usd, self::MAX_PER_GENERATION_USD);
        }
        $today = $this->spentToday();
        if ($today + $usd > self::MAX_PER_KEY_DAY_USD) {
            return sprintf('This key has spent $%.2f today; this would pass its $%.0f daily limit. Try again tomorrow (UTC).', $today, self::MAX_PER_KEY_DAY_USD);
        }
        $budget = app(CreativeBudget::class)->summary();
        if ($usd > (float) $budget['remaining_usd']) {
            return sprintf('Only $%.2f of the team\'s $%.2f this month is left.', $budget['remaining_usd'], $budget['cap_usd']);
        }

        return null;
    }

    private function spentToday(): float
    {
        return (float) CreativeGeneration::query()
            ->where('ai_access_key_id', $this->key->id)
            ->where('created_at', '>=', now()->utc()->startOfDay()->toDateTimeString())
            ->sum('charged_usd');
    }

    /** @return array{model: string, prompt: string, params: array, use_character: bool, plan_id: ?int, source?: array} */
    private function generationInput(array $arguments): array
    {
        $models = app(CreativeModels::class);
        $model = is_string($arguments['model'] ?? null) ? trim($arguments['model']) : '';
        if (! $models->has($model)) {
            throw new ToolError('model must be a key from creative_models.');
        }
        $prompt = is_string($arguments['prompt'] ?? null) ? trim($arguments['prompt']) : '';
        if (mb_strlen($prompt) < 3 || mb_strlen($prompt) > 2000) {
            throw new ToolError('prompt must be 3 to 2000 characters.');
        }
        $this->guardPrivate(['prompt' => $prompt]);

        $planId = isset($arguments['plan_id']) && is_numeric($arguments['plan_id']) ? (int) $arguments['plan_id'] : null;
        if ($planId !== null && ! CreativePlan::whereKey($planId)->exists()) {
            throw new ToolError("There is no plan #{$planId}.");
        }

        $input = [
            'model' => $model,
            'prompt' => $prompt,
            'params' => $models->validateParams($model, is_array($arguments['params'] ?? null) ? $arguments['params'] : []),
            'use_character' => array_key_exists('use_character', $arguments) ? (bool) $arguments['use_character'] : true,
            'plan_id' => $planId,
        ];
        $source = is_array($arguments['source'] ?? null) ? $arguments['source'] : null;
        if ($models->needsSource($model)) {
            if (! isset($source['generation_id']) || ! is_numeric($source['generation_id'])) {
                throw new ToolError('This model animates an image: give source.generation_id (a finished image from creative_generations).');
            }
            $input['source'] = ['generation_id' => (int) $source['generation_id'], 'index' => (int) ($source['index'] ?? 0)];
        }

        return $input;
    }

    // ---- plans ----------------------------------------------------------------

    private function savePlan(array $arguments): array
    {
        $args = new Args($arguments);
        $title = $args->str('title', null, 160) ?? throw new ToolError('Give the plan a title.');
        $fields = [
            'title' => $title,
            'hook' => $args->str('hook', null, 2000),
            'script' => $args->str('script', null, 10000),
            'caption' => $args->str('caption', null, 5000),
            'hashtags' => $args->str('hashtags', null, 500),
        ];
        $this->guardPrivate($fields);

        $plan = new CreativePlan;
        $plan->forceFill([
            ...$fields,
            'caption' => CreativeAiOutput::ensureDisclaimer($fields['caption'], $fields['hook'], $fields['script']),
            'notes' => $args->str('notes', null, 5000),
            'status' => 'idea',
            'platform' => $args->enum('platform', CreativePlan::PLATFORMS, 'tiktok'),
            'format' => $args->enum('format', CreativePlan::FORMATS, 'video'),
            'planned_for' => $args->date('planned_for'),
            'ai_drafted_at' => now(),
            'created_by_uni_id' => null,
            'created_by_name' => self::LABEL,
            'updated_by_name' => self::LABEL,
        ])->save();

        return ['saved' => true, 'plan' => app(\App\Services\Creatives\CreativePresenter::class)->plan($plan->fresh())];
    }

    private function guardPrivate(array $texts): void
    {
        $why = CreativeAiOutput::privateIn($texts);
        if ($why !== null) {
            throw new ToolError("Not allowed: {$why}. What is made or posted may carry public percentages only — never a money amount, a balance or a customer's name. Rewrite it without them.");
        }
    }

    private static function quoteKey(string $id): string
    {
        return 'ai-creative-quote:'.$id;
    }
}
