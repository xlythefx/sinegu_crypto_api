<?php

namespace App\Services\Creatives;

use App\Models\CreativeAiCall;
use App\Models\CreativePlan;
use App\Models\CreativeSetting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Admin → Creatives' AI writer: a brief in, a DRAFT out (title, hook,
 * script, caption, hashtags, a Studio video prompt), a list of IDEAS, and the
 * Saturday weekly recap. Nothing is published and nothing generates a video:
 * the team edits and saves.
 *
 * Every call runs in an order that keeps the monthly cap true — the SAME cap
 * as Higgsfield generations (CreativeBudget sums both):
 *   1. refuse unless a key is set (AI_NOT_CONFIGURED);
 *   2. RESERVE {@see MAX_CALL_USD} under the settings-row lock — no room,
 *      no call (BUDGET_EXCEEDED);
 *   3. call Claude ({@see CreativeWriter}), with FACTS — public figures only;
 *   4. settle the row to the REAL cost from the response's usage, refusals
 *      included (tokens were spent). No answer and nothing billed: the row
 *      is removed. No answer but it may have run: the reservation is kept;
 *   5. read the stop reason BEFORE the content (refusal → AI_REFUSED,
 *      max_tokens → AI_BAD_OUTPUT), then check the JSON and the writing in
 *      PHP ({@see CreativeAiOutput}).
 */
final class CreativeAi
{
    /**
     * USD per million tokens: input, output (thinking is billed as output),
     * a 5-minute cache write, a cache read. Keyed by model; the two Opus
     * models after the first are where a server-side refusal fallback can
     * land, billed at their own rates. A model not listed is costed at the
     * dearest row, never at zero.
     */
    public const RATES = [
        'claude-opus-5-5' => ['input' => 4.00, 'output' => 20.00, 'cache_write' => 5.00, 'cache_read' => 0.20],
        'claude-opus-5' => ['input' => 5.00, 'output' => 25.00, 'cache_write' => 6.25, 'cache_read' => 0.50],
        'claude-opus-4-8' => ['input' => 5.00, 'output' => 25.00, 'cache_write' => 6.25, 'cache_read' => 0.50],
    ];

    /**
     * The most one call may cost — reserved before it and the floor of
     * "room left" (a draft is typically $0.03–0.10; the output budget keeps
     * even a maxed-out call under this).
     */
    public const MAX_CALL_USD = 0.50;

    /** Appended in PHP to the caption of any draft that cites a percentage. */
    public const DISCLAIMER = 'Past performance does not guarantee future results.';

    /** Who the Saturday recap plan is "created by". */
    public const AI_NAME = 'Pixel Alpha AI';

    public const DEFAULT_IDEAS = 5;

    private const MESSAGES = [
        'rate_limited' => 'Claude is busy right now. Try again in a minute.',
        'server_error' => 'Claude did not answer (a problem on its side). Try again in a minute.',
        'unreachable' => 'Claude could not be reached. Try again in a minute.',
        'auth' => 'Claude did not accept this server\'s key. An admin needs to check it.',
        'rejected' => 'Claude could not take this request. Try again; if it keeps happening, tell a developer.',
        'error' => 'Claude\'s answer could not be read. Try again in a minute.',
    ];

    public function __construct(
        private CreativeWriter $writer,
        private CreativeBudget $budget,
        private CreativeFacts $facts,
    ) {}

    /** The meta response's `ai` block. */
    public function meta(): array
    {
        return [
            'configured' => $this->writer->configured(),
            'model' => $this->writer->model(),
            'weekly_recap' => (bool) CreativeSetting::current()->ai_weekly_recap,
        ];
    }

    /**
     * Switch the Saturday recap. Leaves `updated_by_*` / `updated_at` alone:
     * those say who set the CAP.
     */
    public function setWeeklyRecap(bool $on): void
    {
        CreativeSetting::current();
        DB::table('creative_settings')->where('id', CreativeSetting::ID)->update(['ai_weekly_recap' => $on]);
    }

    /**
     * One draft — NOT saved (the person fills the plan modal with it).
     *
     * @param  array{brief: string, platform: string, format?: string|null, plan_id?: int|null, use_facts?: bool|null}  $input
     * @return array{draft: array<string, mixed>, facts_used: array<string, mixed>|null, cost_usd: float, call: CreativeAiCall}
     */
    public function draft(
        array $input,
        ?string $uniId,
        ?string $name,
        string $kind = CreativeAiCall::DRAFT,
        ?CarbonImmutable $factsEnd = null,
    ): array {
        $this->assertConfigured();

        $brief = trim($input['brief']);
        $platform = $input['platform'];
        $format = $input['format'] ?? 'video';
        $performance = ($input['use_facts'] ?? true) ? $this->facts->performance($factsEnd) : null;
        $facts = $this->facts->forPrompt($performance, $platform, $format);

        [$reply, $call, $cost] = $this->call(
            kind: $kind,
            brief: $brief,
            planId: isset($input['plan_id']) ? (int) $input['plan_id'] : null,
            uniId: $uniId,
            name: $name,
            user: CreativeAiPrompt::draftMessage($brief, $platform, $format, $facts),
            schema: CreativeAiPrompt::draftSchema(),
            maxTokens: CreativeAiPrompt::DRAFT_MAX_TOKENS,
        );

        $draft = CreativeAiOutput::draft($reply->text, $platform, $performance, $facts);
        if ($draft === null) {
            throw $this->badOutput('Claude\'s draft did not come back in the expected shape. Try again.', $cost);
        }

        return ['draft' => $draft, 'facts_used' => $performance, 'cost_usd' => $cost, 'call' => $call];
    }

    /**
     * A list of post ideas (never saved here: "Add to planner" saves one).
     *
     * @param  array{count?: int|null, focus?: string|null, platform?: string|null}  $input
     * @return array{ideas: list<array<string, string>>, cost_usd: float}
     */
    public function ideas(array $input, ?string $uniId, ?string $name): array
    {
        $this->assertConfigured();

        $count = (int) ($input['count'] ?? self::DEFAULT_IDEAS);
        $focus = isset($input['focus']) && trim((string) $input['focus']) !== '' ? trim((string) $input['focus']) : null;
        $platform = $input['platform'] ?? null;
        $facts = $this->facts->forPrompt($this->facts->performance(), $platform, null);

        [$reply, , $cost] = $this->call(
            kind: CreativeAiCall::IDEAS,
            brief: $focus,
            planId: null,
            uniId: $uniId,
            name: $name,
            user: CreativeAiPrompt::ideasMessage($count, $focus, $platform, $facts),
            schema: CreativeAiPrompt::ideasSchema(),
            maxTokens: CreativeAiPrompt::IDEAS_MAX_TOKENS,
        );

        $ideas = CreativeAiOutput::ideas($reply->text, $count, $platform);
        if ($ideas === null) {
            throw $this->badOutput('Claude\'s ideas did not come back in the expected shape. Try again.', $cost);
        }

        return ['ideas' => $ideas, 'cost_usd' => $cost];
    }

    /**
     * The Saturday weekly recap (`creatives:weekly-recap`): a TikTok draft of
     * the week that just ended — the 7 Manila days ending YESTERDAY, which is
     * the window of the Friday 23:55 Telegram weekly recap — saved as an
     * `idea` plan for the coming Monday. One per week.
     *
     * @return array{outcome: string, message: string, plan?: CreativePlan}
     *                                                                      outcome: created | off | not_configured | no_facts | exists | over_budget | failed
     */
    public function weeklyRecap(): array
    {
        if (! CreativeSetting::current()->ai_weekly_recap) {
            return ['outcome' => 'off', 'message' => 'The weekly recap is switched off.'];
        }
        if (! $this->writer->configured()) {
            return ['outcome' => 'not_configured', 'message' => 'Claude is not connected on this server.'];
        }

        $end = $this->facts->today()->subDay();
        $performance = $this->facts->performance($end);
        if (($performance['available'] ?? false) !== true) {
            return ['outcome' => 'no_facts', 'message' => "Nothing was published for {$performance['period']}."];
        }

        $period = $performance['period'];
        $brief = "Weekly recap of the bot's results for {$period}";
        $title = self::weeklyTitle($period);
        $done = CreativePlan::where('title', $title)->exists()
            || CreativeAiCall::where('kind', CreativeAiCall::WEEKLY)->where('brief', $brief)->whereNotNull('plan_id')->exists();
        if ($done) {
            return ['outcome' => 'exists', 'message' => "The recap for {$period} is already in the planner."];
        }

        try {
            $result = $this->draft(
                ['brief' => $brief, 'platform' => 'tiktok', 'format' => 'video', 'use_facts' => true],
                null,
                self::AI_NAME,
                CreativeAiCall::WEEKLY,
                $end,
            );
        } catch (CreativeException $e) {
            return [
                'outcome' => $e->errorCode === 'BUDGET_EXCEEDED' ? 'over_budget' : 'failed',
                'message' => $e->errorCode.': '.$e->getMessage(),
            ];
        }

        $draft = $result['draft'];
        $notes = trim("AI title: {$draft['title']}\n\n{$draft['notes']}");
        if ($draft['warnings'] !== []) {
            $notes .= "\n\nCheck before posting:\n- ".implode("\n- ", $draft['warnings']);
        }

        $plan = DB::transaction(function () use ($title, $draft, $notes, $result) {
            $plan = new CreativePlan;
            $plan->forceFill([
                'title' => $title,
                'status' => 'idea',
                'platform' => 'tiktok',
                'format' => 'video',
                'planned_for' => CarbonImmutable::now((string) config('services.track_record.timezone', 'UTC'))
                    ->next(CarbonInterface::MONDAY)->toDateString(),
                'hook' => $draft['hook'],
                'script' => $draft['script'],
                'caption' => $draft['caption'],
                'hashtags' => $draft['hashtags'],
                'video_prompt' => $draft['video_prompt'],
                'notes' => mb_substr($notes, 0, 5000),
                'ai_drafted_at' => now(),
                'created_by_uni_id' => null,
                'created_by_name' => self::AI_NAME,
                'updated_by_name' => self::AI_NAME,
            ])->save();
            $result['call']->forceFill(['plan_id' => $plan->id])->save();

            return $plan;
        });

        return ['outcome' => 'created', 'message' => "Drafted \"{$title}\" (\${$result['cost_usd']}).", 'plan' => $plan];
    }

    /** The weekly plan's title — fixed, so a second run can tell the week is done. */
    public static function weeklyTitle(string $period): string
    {
        return "Weekly recap · {$period}";
    }

    /** What a reply cost, from its usage, at each attempt's model's rates. */
    public static function cost(WriterReply $reply): float
    {
        return self::usageCost($reply->usage);
    }

    /**
     * What a call cost from its usage, one entry per attempt, each at its own
     * model's rates — the writer's replies and the chat agent's alike.
     *
     * @param  list<array{model: string, input: int, output: int, cache_write: int, cache_read: int}>  $usage
     */
    public static function usageCost(array $usage): float
    {
        $usd = 0.0;
        foreach ($usage as $u) {
            $r = self::ratesFor($u['model']);
            $usd += ($u['input'] * $r['input'] + $u['output'] * $r['output']
                + $u['cache_write'] * $r['cache_write'] + $u['cache_read'] * $r['cache_read']) / 1_000_000;
        }

        return round($usd, 4);
    }

    /** @return array{input: float, output: float, cache_write: float, cache_read: float} */
    public static function ratesFor(string $model): array
    {
        // Longest key first, so "claude-opus-5-5" never matches "claude-opus-5".
        $keys = array_keys(self::RATES);
        usort($keys, fn ($a, $b) => strlen($b) <=> strlen($a));
        foreach ($keys as $key) {
            if ($model === $key || str_starts_with($model, $key.'-')) {
                return self::RATES[$key];
            }
        }

        $dearest = self::RATES[array_key_first(self::RATES)];
        foreach (self::RATES as $rates) {
            if ($rates['output'] > $dearest['output']) {
                $dearest = $rates;
            }
        }

        return $dearest;
    }

    // ---- the call ----------------------------------------------------------

    /**
     * RESERVE {@see MAX_CALL_USD} for one Claude call under the settings-row
     * lock (CreativeBudget), by creating its `creative_ai_calls` row with that
     * figure and `stop_reason` NULL ("in flight"). No room: BUDGET_EXCEEDED,
     * and nothing is created. The writer and the chat agent both reserve here,
     * so they share one cap with Higgsfield generations.
     *
     * @param  string  $what  how the refusal names the call ("a draft", "one assistant step")
     */
    public function reserveCall(
        string $kind,
        string $model,
        ?string $uniId,
        ?string $name,
        ?string $brief = null,
        ?int $planId = null,
        string $what = 'AI writing: a draft',
    ): CreativeAiCall {
        $call = $this->budget->reserveAiCall(self::MAX_CALL_USD, fn () => CreativeAiCall::create([
            'kind' => $kind,
            'model' => mb_substr($model, 0, 40),
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cost_usd' => self::MAX_CALL_USD,
            'stop_reason' => null,
            'plan_id' => $planId,
            'brief' => $brief !== null ? mb_substr($brief, 0, 500) : null,
            'created_by_uni_id' => $uniId,
            'created_by_name' => $name,
        ]));
        if ($call === null) {
            $budget = $this->budget->summary();

            throw new CreativeException(
                'BUDGET_EXCEEDED',
                sprintf(
                    'Not enough of the monthly limit is left for %s needs up to $%s and $%s of $%s is left this month.',
                    $what, self::usd(self::MAX_CALL_USD), self::usd($budget['remaining_usd']), self::usd($budget['cap_usd']),
                ),
                422,
                ['budget' => $budget],
            );
        }

        return $call;
    }

    /**
     * Claude answered: the row takes the REAL cost from the usage, whatever the
     * stop reason (refusals spent tokens too). Returns that cost.
     *
     * @param  list<array{model: string, input: int, output: int, cache_write: int, cache_read: int}>  $usage
     */
    public function settleCall(CreativeAiCall $call, string $model, ?string $stopReason, array $usage): float
    {
        $cost = self::usageCost($usage);
        $input = array_sum(array_map(fn (array $u) => $u['input'] + $u['cache_write'] + $u['cache_read'], $usage));
        $call->forceFill([
            'model' => mb_substr($model, 0, 40),
            'input_tokens' => $input,
            'output_tokens' => array_sum(array_column($usage, 'output')),
            'cost_usd' => $cost,
            // Never NULL once answered: NULL is what "in flight" means.
            'stop_reason' => mb_substr($stopReason ?? 'unknown', 0, 24),
        ])->save();

        return $cost;
    }

    /**
     * No answer. Nothing billed: the reservation row is removed. It may have
     * run: the reservation is KEPT (stop_reason `unconfirmed`) — the cap errs
     * on the side of having spent.
     */
    public function abandonCall(CreativeAiCall $call, bool $maybeBilled): void
    {
        if ($maybeBilled) {
            $call->forceFill(['stop_reason' => CreativeAiCall::UNCONFIRMED])->save();
        } else {
            $call->delete();
        }
    }

    /** The message an unanswered call shows (AI_UNAVAILABLE). */
    public static function unavailableMessage(?string $failure): string
    {
        return self::MESSAGES[$failure] ?? self::MESSAGES['error'];
    }

    /**
     * Reserve, call, settle. Throws a CreativeException for every answer that
     * is not usable JSON-to-be (budget, no answer, refusal, out of room).
     *
     * @return array{0: WriterReply, 1: CreativeAiCall, 2: float}
     */
    private function call(
        string $kind,
        ?string $brief,
        ?int $planId,
        ?string $uniId,
        ?string $name,
        string $user,
        array $schema,
        int $maxTokens,
    ): array {
        $call = $this->reserveCall($kind, $this->writer->model(), $uniId, $name, $brief, $planId);

        try {
            $reply = $this->writer->write(CreativeAiPrompt::SYSTEM, $user, $schema, $maxTokens);
        } catch (\Throwable $e) {
            // The writer's contract is to never throw; if one does, the
            // request may have run — keep the reservation.
            Log::warning('Creatives AI writer threw.', ['class' => $e::class]);
            $reply = WriterReply::unanswered($this->writer->model(), 'error', maybeBilled: true);
        }

        if (! $reply->answered) {
            $this->abandonCall($call, $reply->maybeBilled);

            throw new CreativeException(
                'AI_UNAVAILABLE',
                self::unavailableMessage($reply->failure),
                502,
                ['budget' => $this->budget->summary()],
            );
        }

        $cost = $this->settleCall($call, $reply->model, $reply->stopReason, $reply->usage);

        if ($reply->stopReason === 'refusal') {
            throw new CreativeException(
                'AI_REFUSED',
                'Claude declined to write this one. Try rewording the brief.',
                422,
                ['cost_usd' => $cost, 'budget' => $this->budget->summary()],
            );
        }
        if (in_array($reply->stopReason, ['max_tokens', 'model_context_window_exceeded'], true)) {
            throw $this->badOutput('Claude ran out of room before finishing. Try a shorter brief.', $cost);
        }

        return [$reply, $call, $cost];
    }

    private function badOutput(string $message, float $cost): CreativeException
    {
        return new CreativeException('AI_BAD_OUTPUT', $message, 502, [
            'cost_usd' => $cost,
            'budget' => $this->budget->summary(),
        ]);
    }

    private function assertConfigured(): void
    {
        if (! $this->writer->configured()) {
            throw new CreativeException('AI_NOT_CONFIGURED', 'Claude isn\'t connected on this server yet.', 503);
        }
    }

    private static function usd(float|int|string $value): string
    {
        return number_format((float) $value, 2);
    }
}
