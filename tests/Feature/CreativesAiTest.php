<?php

namespace Tests\Feature;

use App\Models\CreativeAiCall;
use App\Models\CreativeCharacter;
use App\Models\CreativeGeneration;
use App\Models\CreativePlan;
use App\Models\CreativeSetting;
use App\Models\UserCredential;
use App\Services\Creatives\ClaudeWriter;
use App\Services\Creatives\CreativeAi;
use App\Services\Creatives\CreativeWriter;
use App\Services\Creatives\WriterReply;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Support\FakeCreativeWriter;

/**
 * Admin → Creatives' AI writer (Claude): drafts, ideas and the Saturday
 * weekly recap.
 *
 * Pinned here: who may use it (every staff role drafts; only full admins
 * switch the weekly recap); that it spends from the SAME monthly cap as
 * Higgsfield generations — refused before the call when under $0.50 is left,
 * the real cost recorded after it, refusals included; that the only figures
 * the model is ever shown are the published track record's, and nothing
 * private (no $ amount, email or uni_id) is ever in a prompt; and that the
 * PHP checks run on what comes back (unknown figures and over-long captions
 * are warnings, the disclaimer is appended, a malformed answer is
 * AI_BAD_OUTPUT).
 *
 * Claude is a FAKE bound over CreativeWriter — the suite never reaches
 * Anthropic (ANTHROPIC_API_KEY is pinned empty in phpunit.xml as well), and
 * Http::preventStrayRequests() is on. No ConnectionException is ever faked
 * (that crashes PHP natively on the WAMP box).
 */
class CreativesAiTest extends EngineTestCase
{
    private FakeCreativeWriter $claude;

    private string $admin;

    private string $collaborator;

    private string $user;

    private string $master;

    protected function setUp(): void
    {
        parent::setUp();
        // Sunday 15 Nov 2026, 20:00 in Manila: "this week" = 9–15 Nov.
        $this->travelTo(Carbon::parse('2026-11-15 12:00:00', 'UTC'));
        Cache::flush();  // the track record is cached for 5 minutes
        Http::preventStrayRequests();
        config([
            'services.track_record.timezone' => 'Asia/Manila',
            'services.higgsfield.key_id' => 'hf-test-id',
            'services.higgsfield.key_secret' => 'hf-test-secret',
            'services.higgsfield.ca_bundle' => null,
        ]);

        $this->claude = new FakeCreativeWriter;
        $this->claude->replies = [FakeCreativeWriter::json($this->draftJson())];
        $this->app->instance(CreativeWriter::class, $this->claude);

        $this->admin = $this->makeUser(['type' => 'admin', 'name' => 'Owner Admin']);
        $this->collaborator = $this->makeUser(['type' => 'collaborator', 'name' => 'Mara Marketing', 'email' => 'mara@pixel-alpha.test']);
        $this->user = $this->makeUser(['name' => 'Plain Customer']);
    }

    // ---- helpers ---------------------------------------------------------

    private function headers(string $uniId): array
    {
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.UserCredential::findOrFail($uniId)->createToken('test')->plainTextToken];
    }

    private function api(string $method, string $uri, array $data = [], ?string $as = null): TestResponse
    {
        return $this->json($method, $uri, $data, $this->headers($as ?? $this->collaborator));
    }

    /** @return array<string, mixed> */
    private function draftJson(array $over = []): array
    {
        return array_merge([
            'title' => 'Pixel recaps a green week',
            'hook' => 'Pixel had a week worth talking about.',
            'script' => "1. (0–3 s) Pixel bursts through a gold doorway.\n2. (3–8 s) A chart climbs behind Pixel.",
            'caption' => 'Pixel Alpha closed the week up 6.03%. Trade-only keys, and it only takes 20% of the profit.',
            'hashtags' => '#crypto #tradingbot pixelalpha',
            'video_prompt' => 'Pixel stands on a glowing pixel chart that rises, camera pushes in, on-screen text "Week recap".',
            'notes' => 'A recap angle while the week is fresh. Check the figure against the landing page.',
            'numbers_used' => ['6.03%'],
        ], $over);
    }

    /**
     * The master's published track record: three trading days in Manila
     * 10–12 Nov on a 1,000 opening capital —
     *   10 Nov LTC +20.00 on 1,000.00  = +2%
     *   11 Nov BTC −10.20 on 1,020.00  = −1%
     *   12 Nov LTC +50.49 on 1,009.80  = +5%
     * chained 1.02 × 0.99 × 1.05 − 1 = 6.029% → 6.03; LTC 2 + 5 = 7, BTC −1.
     */
    private function publishWeek(): void
    {
        $this->master = $this->makeUser(['type' => 'master', 'name' => 'Masterly Person', 'email' => 'boss@pixel-alpha.test']);
        $this->makeAccount($this->master, ['api_key' => 'master-key', 'initial_deposit' => 1000, 'name' => 'Secret Master Account', 'balance' => 1060.29]);
        foreach ([
            ['2026-11-10 04:00:00', 20.00, 'LTCUSDT'],
            ['2026-11-11 04:00:00', -10.20, 'BTCUSDT'],
            ['2026-11-12 04:00:00', 50.49, 'LTCUSDT'],
        ] as [$at, $pnl, $symbol]) {
            DB::table('binance_pastpositions')->insert([
                'uni_id' => $this->master,
                'api_key' => 'master-key',
                'symbol' => $symbol,
                'position_side' => 'LONG',
                'position_amt' => 1,
                'entry_price' => 100,
                'exit_price' => 101,
                'realized_pnl' => $pnl,
                'exchange_fee' => 0.0,
                'side' => 'BUY',
                'closed_at' => $at,
            ]);
        }
        Cache::flush();
    }

    private function setCap(float $usd): void
    {
        $this->api('PUT', '/api/admin/creatives/budget', ['monthly_cap_usd' => $usd], $this->admin)->assertOk();
    }

    private function makeGeneration(float $usd): void
    {
        $g = new CreativeGeneration;
        $g->forceFill([
            'kind' => 'image', 'model' => 'soul-image', 'endpoint' => 'higgsfield-ai/soul/v2/standard',
            'prompt' => 'p', 'full_prompt' => 'p', 'params' => [], 'used_character' => false,
            'status' => 'completed', 'estimated_usd' => $usd, 'charged_usd' => $usd,
            'created_by_uni_id' => $this->collaborator, 'created_by_name' => 'Mara Marketing',
        ])->save();
    }

    private function draft(array $body = [], ?string $as = null): TestResponse
    {
        return $this->api('POST', '/api/admin/creatives/ai/draft', array_merge(['brief' => 'bot recap this week', 'platform' => 'tiktok'], $body), $as);
    }

    // ---- access ----------------------------------------------------------

    public function test_every_staff_role_drafts_and_only_full_admins_switch_the_weekly_recap(): void
    {
        $this->draft([], $this->user)->assertForbidden()->assertJsonPath('error_code', 'FORBIDDEN');
        $this->api('POST', '/api/admin/creatives/ai/ideas', [], $this->user)->assertForbidden();
        $this->assertCount(0, $this->claude->calls);

        $this->draft()->assertOk()->assertJsonPath('success', true)->assertJsonPath('draft.title', 'Pixel recaps a green week');
        $this->draft([], $this->admin)->assertOk();

        $this->api('GET', '/api/admin/creatives')->assertOk()
            ->assertJsonPath('ai.configured', true)
            ->assertJsonPath('ai.model', 'claude-opus-5-5')
            ->assertJsonPath('ai.weekly_recap', true);

        $this->api('PUT', '/api/admin/creatives/ai/settings', ['weekly_recap' => false])->assertForbidden();
        $this->api('PUT', '/api/admin/creatives/ai/settings', ['weekly_recap' => false], $this->user)->assertForbidden();
        $this->assertTrue(CreativeSetting::current()->ai_weekly_recap);

        $this->api('PUT', '/api/admin/creatives/ai/settings', ['weekly_recap' => 'maybe'], $this->admin)->assertStatus(422);
        $this->api('PUT', '/api/admin/creatives/ai/settings', ['weekly_recap' => false], $this->admin)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('ai.weekly_recap', false)
            ->assertJsonPath('ai.configured', true);
        $this->assertFalse(CreativeSetting::current()->ai_weekly_recap);

        // The switch is not the cap: "set by" stays untouched.
        $this->api('GET', '/api/admin/creatives')->assertJsonPath('ai.weekly_recap', false)
            ->assertJsonPath('budget.updated_by_name', null)
            ->assertJsonPath('budget.updated_at', null);
    }

    public function test_no_key_means_ai_not_configured_and_no_call(): void
    {
        $this->claude->configured = false;

        $this->draft()->assertStatus(503)->assertJsonPath('success', false)->assertJsonPath('error_code', 'AI_NOT_CONFIGURED');
        $this->api('POST', '/api/admin/creatives/ai/ideas')->assertStatus(503)->assertJsonPath('error_code', 'AI_NOT_CONFIGURED');
        $this->api('GET', '/api/admin/creatives')->assertJsonPath('ai.configured', false);

        $this->assertCount(0, $this->claude->calls);
        $this->assertSame(0, CreativeAiCall::count());
    }

    public function test_the_real_writer_is_off_in_the_suite_and_never_calls_out_without_a_key(): void
    {
        // phpunit.xml pins ANTHROPIC_API_KEY empty.
        $writer = new ClaudeWriter;
        $this->assertFalse($writer->configured());
        $this->assertSame('claude-opus-5-5', $writer->model());
        $reply = $writer->write('s', 'u', ['type' => 'object'], 100);
        $this->assertFalse($reply->answered);
        $this->assertFalse($reply->maybeBilled);
        $this->assertSame(['key_set' => false, 'ca_bundle_set' => (string) config('services.anthropic.ca_bundle') !== '', 'model' => 'claude-opus-5-5'], $writer->diagnostics());
    }

    // ---- the cap ---------------------------------------------------------

    public function test_budget_exceeded_when_less_than_fifty_cents_is_left(): void
    {
        $this->setCap(1);
        $this->makeGeneration(0.51);  // 0.49 left

        $res = $this->draft()->assertStatus(422)
            ->assertJsonPath('error_code', 'BUDGET_EXCEEDED')
            ->assertJsonPath('budget.cap_usd', 1)
            ->assertJsonPath('budget.remaining_usd', 0.49);
        $this->assertStringContainsString('monthly limit', $res->json('message'));
        $this->api('POST', '/api/admin/creatives/ai/ideas')->assertStatus(422)->assertJsonPath('error_code', 'BUDGET_EXCEEDED');
        $this->assertCount(0, $this->claude->calls);
        $this->assertSame(0, CreativeAiCall::count());

        // Exactly 0.50 left is enough (compared in integer units).
        DB::table('creative_generations')->update(['charged_usd' => 0.50, 'estimated_usd' => 0.50]);
        $this->draft()->assertOk();
        $this->assertCount(1, $this->claude->calls);
    }

    public function test_a_zero_cap_switches_ai_writing_off(): void
    {
        $this->setCap(0);

        $this->draft()->assertStatus(422)->assertJsonPath('error_code', 'BUDGET_EXCEEDED')->assertJsonPath('budget.cap_usd', 0);
        $this->assertCount(0, $this->claude->calls);
        $this->assertSame(0, CreativeAiCall::count());
    }

    public function test_the_real_cost_is_recorded_and_counts_in_the_same_month_total_as_generations(): void
    {
        $plan = CreativePlan::create(['title' => 'Recap', 'platform' => 'tiktok', 'format' => 'video']);
        // 10k input, 2k output, 1k cache write, 4k cache read at Opus 5.5:
        // 0.04 + 0.04 + 0.005 + 0.0008 = 0.0858
        $this->claude->replies = [WriterReply::answered('claude-opus-5-5', 'end_turn', (string) json_encode($this->draftJson()), [
            ['model' => 'claude-opus-5-5', 'input' => 10000, 'output' => 2000, 'cache_write' => 1000, 'cache_read' => 4000],
        ])];

        $this->draft(['plan_id' => $plan->id])->assertOk()
            ->assertJsonPath('cost_usd', 0.0858)
            ->assertJsonPath('budget.ai_usd', 0.0858)
            ->assertJsonPath('budget.ai_calls', 1)
            ->assertJsonPath('budget.spent_usd', 0.0858)
            ->assertJsonPath('budget.reserved_usd', 0)
            ->assertJsonPath('budget.generations', 0)
            ->assertJsonPath('budget.by_person.0.name', 'Mara Marketing')
            ->assertJsonPath('budget.by_person.0.ai_calls', 1)
            ->assertJsonPath('budget.by_person.0.generations', 0)
            ->assertJsonPath('budget.by_person.0.spent_usd', 0.0858);

        $call = CreativeAiCall::sole();
        $this->assertSame('draft', $call->kind);
        $this->assertSame('claude-opus-5-5', $call->model);
        $this->assertSame(15000, $call->input_tokens);
        $this->assertSame(2000, $call->output_tokens);
        $this->assertSame('end_turn', $call->stop_reason);
        $this->assertSame($plan->id, $call->plan_id);
        $this->assertSame('bot recap this week', $call->brief);
        $this->assertSame($this->collaborator, $call->created_by_uni_id);

        // The prompt was capped and asked for the draft schema.
        $this->assertSame(12000, $this->claude->calls[0]['max_tokens']);
        $this->assertSame(['title', 'hook', 'script', 'caption', 'hashtags', 'video_prompt', 'notes', 'numbers_used'], $this->claude->calls[0]['schema']['required']);

        // A generation that fits the cap on its own is blocked by the AI spend:
        // 0.0858 + 0.298 = 0.3838 > 0.38.
        $this->setCap(0.38);
        Http::fake(['https://api.higgsfield.ai/estimate/*' => Http::response(['type' => 'estimate', 'usd' => '0.298'])]);
        $this->api('POST', '/api/admin/creatives/generations', ['model' => 'soul-image', 'prompt' => 'Pixel at a desk', 'params' => []])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'BUDGET_EXCEEDED')
            ->assertJsonPath('budget.spent_usd', 0.0858)
            ->assertJsonPath('budget.ai_usd', 0.0858);
        $this->api('POST', '/api/admin/creatives/generations/estimate', ['model' => 'soul-image', 'prompt' => 'Pixel at a desk', 'params' => []])
            ->assertOk()->assertJsonPath('allowed', false);
        $this->assertSame(0, CreativeGeneration::count());

        // Last month's calls are last month's.
        CreativeAiCall::query()->update(['created_at' => '2026-10-31 23:59:59']);
        $this->api('GET', '/api/admin/creatives/budget')->assertJsonPath('budget.ai_usd', 0)->assertJsonPath('budget.ai_calls', 0);
    }

    public function test_cost_is_per_attempt_at_each_models_rate(): void
    {
        // A refusal on Opus 5.5 re-run server-side on Opus 4.8:
        // (1000 × 4 + 50 × 20) / 1e6 + (1000 × 5 + 1000 × 25) / 1e6 = 0.005 + 0.03
        $reply = WriterReply::answered('claude-opus-4-8', 'end_turn', '{}', [
            ['model' => 'claude-opus-5-5', 'input' => 1000, 'output' => 50, 'cache_write' => 0, 'cache_read' => 0],
            ['model' => 'claude-opus-4-8', 'input' => 1000, 'output' => 1000, 'cache_write' => 0, 'cache_read' => 0],
        ]);
        $this->assertSame(0.035, CreativeAi::cost($reply));
        $this->assertSame(1050, $reply->outputTokens());
        $this->assertSame(2000, $reply->inputTokens());

        // A model not in the table is costed at the dearest rates, never free.
        $this->assertSame(CreativeAi::RATES['claude-opus-5'], CreativeAi::ratesFor('claude-mystery-9'));
        $this->assertSame(CreativeAi::RATES['claude-opus-5-5'], CreativeAi::ratesFor('claude-opus-5-5'));
        $this->assertSame(CreativeAi::RATES['claude-opus-5'], CreativeAi::ratesFor('claude-opus-5'));
    }

    public function test_a_refusal_is_recorded_with_its_cost(): void
    {
        // 3000 × 4e-6 + 100 × 20e-6 = 0.014
        $this->claude->replies = [WriterReply::answered('claude-opus-5-5', 'refusal', null, [
            ['model' => 'claude-opus-5-5', 'input' => 3000, 'output' => 100, 'cache_write' => 0, 'cache_read' => 0],
        ], 'general_harms')];

        $this->draft()->assertStatus(422)
            ->assertJsonPath('error_code', 'AI_REFUSED')
            ->assertJsonPath('cost_usd', 0.014)
            ->assertJsonPath('budget.ai_usd', 0.014)
            ->assertJsonPath('budget.ai_calls', 1);

        $call = CreativeAiCall::sole();
        $this->assertSame('refusal', $call->stop_reason);
        $this->assertSame(0.014, (float) $call->cost_usd);
    }

    public function test_malformed_answers_are_ai_bad_output_and_still_cost(): void
    {
        $this->claude->replies = [
            FakeCreativeWriter::text('this is not JSON', 1000, 500),
            FakeCreativeWriter::json(array_diff_key($this->draftJson(), ['caption' => true]), 1000, 500),
            FakeCreativeWriter::json($this->draftJson(['numbers_used' => 'six']), 1000, 500),
            FakeCreativeWriter::text('{"title": "cut off', 1000, 12000, 'max_tokens'),
            FakeCreativeWriter::json(['ideas' => []], 1000, 500),
        ];

        foreach (range(1, 4) as $i) {
            $this->draft()->assertStatus(503)->assertJsonPath('error_code', 'AI_BAD_OUTPUT')->assertJsonStructure(['cost_usd', 'budget']);
        }
        $this->api('POST', '/api/admin/creatives/ai/ideas')->assertStatus(503)->assertJsonPath('error_code', 'AI_BAD_OUTPUT');

        // 1000 × 4e-6 + 500 × 20e-6 = 0.014 each; the max_tokens one 0.244.
        $this->assertSame(5, CreativeAiCall::count());
        $this->assertSame(['end_turn', 'end_turn', 'end_turn', 'max_tokens', 'end_turn'], CreativeAiCall::orderBy('id')->pluck('stop_reason')->all());
        $this->assertEqualsWithDelta(0.014 * 4 + 0.244, (float) CreativeAiCall::sum('cost_usd'), 0.00001);
    }

    public function test_no_answer_records_nothing_unless_it_may_have_been_billed(): void
    {
        $this->claude->replies = [
            WriterReply::unanswered('claude-opus-5-5', 'server_error'),
            WriterReply::unanswered('claude-opus-5-5', 'unreachable', maybeBilled: true),
        ];

        $this->draft()->assertStatus(503)->assertJsonPath('error_code', 'AI_UNAVAILABLE')->assertJsonPath('budget.ai_calls', 0);
        $this->assertSame(0, CreativeAiCall::count());

        // A timed-out request may have run: the reservation stays, conservatively.
        $this->draft()->assertStatus(503)->assertJsonPath('error_code', 'AI_UNAVAILABLE')->assertJsonPath('budget.ai_usd', 0.5);
        $this->assertSame(CreativeAiCall::UNCONFIRMED, CreativeAiCall::sole()->stop_reason);
    }

    // ---- what comes back --------------------------------------------------

    public function test_facts_are_the_published_track_record_and_the_disclaimer_is_appended(): void
    {
        $this->publishWeek();

        $res = $this->draft()->assertOk()
            ->assertJsonPath('facts_used.available', true)
            ->assertJsonPath('facts_used.period', '9–15 Nov 2026')
            ->assertJsonPath('facts_used.return_pct', 6.03)
            ->assertJsonPath('facts_used.trades', 3)
            ->assertJsonPath('facts_used.trading_days', 3)
            ->assertJsonPath('facts_used.green_days', 2)
            ->assertJsonPath('facts_used.best_day', ['date' => '2026-11-12', 'pct' => 5])
            ->assertJsonPath('facts_used.top_coins', [['symbol' => 'LTCUSDT', 'share_pct' => 7], ['symbol' => 'BTCUSDT', 'share_pct' => -1]])
            ->assertJsonPath('draft.warnings', [])
            ->assertJsonPath('warnings', [])
            ->assertJsonPath('draft.numbers_used', ['6.03%'])
            // "pixelalpha" gained its #.
            ->assertJsonPath('draft.hashtags', '#crypto #tradingbot #pixelalpha');

        $caption = $res->json('draft.caption');
        $this->assertStringEndsWith("\n\n".CreativeAi::DISCLAIMER, $caption);
        $this->assertStringStartsWith('Pixel Alpha closed the week up 6.03%.', $caption);

        // The model was given exactly those figures.
        $facts = FakeCreativeWriter::factsOf($this->claude->lastUser());
        $this->assertSame(6.03, $facts['performance']['return_pct']);
        $this->assertSame('Pixel', $facts['character']['name']);
        $this->assertSame('tiktok', $facts['platform']['key']);
        $this->assertSame(2200, $facts['platform']['caption_max_chars']);

        // Already there: not appended twice.
        $this->claude->replies = [FakeCreativeWriter::json($this->draftJson([
            'caption' => 'Up 6.03% this week. Past performance does not guarantee future results.',
        ]))];
        $caption = $this->draft()->assertOk()->json('draft.caption');
        $this->assertSame(1, substr_count($caption, 'Past performance does not guarantee'));

        // No percentage, no disclaimer.
        $this->claude->replies = [FakeCreativeWriter::json($this->draftJson(['caption' => 'Meet Pixel.', 'numbers_used' => []]))];
        $this->assertSame('Meet Pixel.', $this->draft()->assertOk()->json('draft.caption'));
    }

    public function test_a_figure_not_in_facts_is_a_warning_not_a_failure(): void
    {
        $this->publishWeek();
        $this->claude->replies = [FakeCreativeWriter::json($this->draftJson([
            'hook' => 'Pixel made 4.2% while you slept.',
            'caption' => 'Up 6.03% on the week and $500 richer.',
            'numbers_used' => ['4.2%', '6.03%', '3 trades', '9 trades'],
        ]))];

        $warnings = $this->draft()->assertOk()->json('draft.warnings');
        $this->assertContains("The draft cites 4.2%, which is not in this week's figures — check it.", $warnings);
        $this->assertContains("The draft cites 9 trades, which is not in this week's figures — check it.", $warnings);
        $this->assertNotContains("The draft cites 6.03%, which is not in this week's figures — check it.", $warnings);
        $this->assertNotContains("The draft cites 3 trades, which is not in this week's figures — check it.", $warnings);
        $this->assertContains('The draft mentions a money amount ($500). Pixel Alpha posts never show money amounts — take it out.', $warnings);
        // Not stripped: the person decides.
        $this->assertSame(1, CreativeAiCall::count());

        // Over X's 280 characters (caption + hashtags) is a warning too.
        $this->claude->replies = [FakeCreativeWriter::json($this->draftJson(['caption' => str_repeat('Pixel trades. ', 20), 'numbers_used' => []]))];
        $warnings = $this->draft(['platform' => 'x'])->assertOk()->json('warnings');
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('X allows 280', $warnings[0]);
    }

    public function test_no_figures_are_sent_when_nothing_was_published_or_facts_are_off(): void
    {
        // No master account: nothing published.
        $this->draft()->assertOk()
            ->assertJsonPath('facts_used.available', false)
            ->assertJsonPath('facts_used.return_pct', null)
            ->assertJsonPath('facts_used.trades', 0)
            ->assertJsonPath('facts_used.best_day', null)
            ->assertJsonPath('facts_used.top_coins', []);
        $facts = FakeCreativeWriter::factsOf($this->claude->lastUser());
        $this->assertFalse($facts['performance']['available']);
        $this->assertNull($facts['performance']['return_pct']);

        // …so the draft's 6.03% is flagged as unsupported.
        $this->assertContains('The draft cites 6.03%, but it was given no figures — check it or take it out.', $this->draft()->json('warnings'));

        // Published, but "use this week's results" off: no figure leaves.
        $this->publishWeek();
        $this->draft(['use_facts' => false])->assertOk()->assertJsonPath('facts_used', null);
        $user = $this->claude->lastUser();
        $facts = FakeCreativeWriter::factsOf($user);
        $this->assertSame(['available' => false, 'note' => 'No figures for this draft: write it without any performance figure.'], $facts['performance']);
        $this->assertStringNotContainsString('6.03', $user);
        $this->assertStringNotContainsString('LTCUSDT', $user);
    }

    public function test_nothing_private_is_ever_in_a_prompt(): void
    {
        $this->publishWeek();
        CreativePlan::create(['title' => 'Call mara@pixel-alpha.test about the $2,500 promo', 'platform' => 'x', 'format' => 'image']);
        $character = CreativeCharacter::current();
        $character->forceFill(['rules' => 'Never show money like $1,000.50 or 300 USDT.'])->save();
        $this->claude->replies = [
            FakeCreativeWriter::json($this->draftJson()),
            FakeCreativeWriter::json(['ideas' => [['title' => 'Idea', 'hook' => 'Hook', 'why' => 'Why', 'platform' => 'x', 'format' => 'image']]]),
        ];

        $this->draft([
            'brief' => "Recap for {$this->master} — we made \$5,000 and 1,200 USD; ask boss@pixel-alpha.test",
        ])->assertOk();
        $this->api('POST', '/api/admin/creatives/ai/ideas', ['focus' => "the \$5k week, ping {$this->collaborator}"])->assertOk();

        $this->assertCount(2, $this->claude->calls);
        $uniIds = DB::table('user_credentials')->pluck('uni_id')->all();
        foreach ($this->claude->calls as $call) {
            $prompt = $call['system']."\n".$call['user'];
            $this->assertDoesNotMatchRegularExpression('/\$\s?\d/', $prompt, 'A dollar amount reached the prompt.');
            $this->assertDoesNotMatchRegularExpression('/\d[\d,.]*\s?(USD|USDT)\b/i', $prompt);
            $this->assertDoesNotMatchRegularExpression('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $prompt, 'An email reached the prompt.');
            foreach ($uniIds as $uniId) {
                $this->assertStringNotContainsString($uniId, $prompt, 'A uni_id reached the prompt.');
            }
            // Account data the track record is built from never appears.
            $this->assertStringNotContainsString('Secret Master Account', $prompt);
            $this->assertStringNotContainsString('1060.29', $prompt);
            $this->assertStringNotContainsString('master-key', $prompt);
            $this->assertStringContainsString('[amount removed]', $prompt);
        }
        $this->assertStringContainsString('[email removed]', $this->claude->calls[0]['user']);
        $this->assertStringContainsString('[id removed]', $this->claude->calls[0]['user']);
        // The system prompt is static (cacheable): identical on every call.
        $this->assertSame($this->claude->calls[0]['system'], $this->claude->calls[1]['system']);
    }

    // ---- ideas -----------------------------------------------------------

    public function test_ideas(): void
    {
        $idea = fn (int $n, string $platform = 'tiktok') => ['title' => "Idea {$n}", 'hook' => "Hook {$n}", 'why' => "Why {$n}", 'platform' => $platform, 'format' => 'video'];
        $this->claude->replies = [FakeCreativeWriter::json(['ideas' => [$idea(1), $idea(2, 'x'), $idea(3), $idea(4), $idea(5)]], 1500, 800)];

        // Asked for 3 on X: trimmed to 3, every one for X.
        $res = $this->api('POST', '/api/admin/creatives/ai/ideas', ['count' => 3, 'platform' => 'x', 'focus' => 'how the bot works'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(3, 'ideas')
            ->assertJsonPath('ideas.0', ['title' => 'Idea 1', 'hook' => 'Hook 1', 'why' => 'Why 1', 'platform' => 'x', 'format' => 'video'])
            ->assertJsonPath('ideas.2.platform', 'x')
            // 1500 × 4e-6 + 800 × 20e-6 = 0.022
            ->assertJsonPath('cost_usd', 0.022)
            ->assertJsonPath('budget.ai_usd', 0.022);
        $this->assertStringContainsString('Suggest 3 IDEAS', $this->claude->lastUser());
        $this->assertStringContainsString('FOCUS: how the bot works', $this->claude->lastUser());
        $this->assertSame(['ideas'], $this->claude->calls[0]['schema']['required']);

        $call = CreativeAiCall::sole();
        $this->assertSame('ideas', $call->kind);
        $this->assertSame('how the bot works', $call->brief);
        $this->assertNull($call->plan_id);

        // Default count 5, any platform.
        $this->api('POST', '/api/admin/creatives/ai/ideas')->assertOk()->assertJsonCount(5, 'ideas')->assertJsonPath('ideas.1.platform', 'x');
        $this->assertStringContainsString('Suggest 5 IDEAS', $this->claude->lastUser());
        $this->assertSame('any', FakeCreativeWriter::factsOf($this->claude->lastUser())['platform']['key']);

        $this->api('POST', '/api/admin/creatives/ai/ideas', ['count' => 2])->assertStatus(422);
        $this->api('POST', '/api/admin/creatives/ai/ideas', ['count' => 9])->assertStatus(422);
        $this->api('POST', '/api/admin/creatives/ai/ideas', ['focus' => str_repeat('a', 301)])->assertStatus(422);
        $this->api('POST', '/api/admin/creatives/ai/ideas', ['platform' => 'instagram'])->assertStatus(422);
        $this->assertCount(2, $this->claude->calls);
    }

    public function test_draft_validation(): void
    {
        $this->draft(['brief' => 'ab'])->assertStatus(422);
        $this->draft(['brief' => str_repeat('a', 501)])->assertStatus(422);
        $this->draft(['platform' => 'instagram'])->assertStatus(422);
        $this->draft(['format' => 'reel'])->assertStatus(422);
        $this->draft(['plan_id' => 999])->assertStatus(422);
        $this->assertCount(0, $this->claude->calls);

        $this->draft(['format' => 'carousel', 'platform' => 'x'])->assertOk();
        $this->assertStringContainsString("PLATFORM: x\nFORMAT: carousel", $this->claude->lastUser());
    }

    // ---- plans -------------------------------------------------------------

    public function test_plans_carry_the_video_prompt_and_when_they_were_ai_drafted(): void
    {
        $plain = $this->api('POST', '/api/admin/creatives/plans', ['title' => 'Hand-written'])->assertStatus(201)
            ->assertJsonPath('plan.video_prompt', null)
            ->assertJsonPath('plan.ai_drafted_at', null)
            ->json('plan.id');

        $id = $this->api('POST', '/api/admin/creatives/plans', [
            'title' => 'Pixel recaps a green week',
            'video_prompt' => 'Pixel on a rising chart',
            'ai_drafted' => true,
        ])->assertStatus(201)
            ->assertJsonPath('plan.video_prompt', 'Pixel on a rising chart')
            ->assertJsonPath('plan.ai_drafted_at', '2026-11-15T12:00:00+00:00')
            ->json('plan.id');

        // Stamped once: a later save keeps the first time.
        $this->travel(2)->hours();
        $this->api('PUT', "/api/admin/creatives/plans/{$id}", ['video_prompt' => 'Edited', 'ai_drafted' => true])->assertOk()
            ->assertJsonPath('plan.video_prompt', 'Edited')
            ->assertJsonPath('plan.ai_drafted_at', '2026-11-15T12:00:00+00:00');
        $this->api('PUT', "/api/admin/creatives/plans/{$id}", ['ai_drafted' => false, 'video_prompt' => null])->assertOk()
            ->assertJsonPath('plan.video_prompt', null)
            ->assertJsonPath('plan.ai_drafted_at', '2026-11-15T12:00:00+00:00');

        $this->api('PUT', "/api/admin/creatives/plans/{$plain}", ['ai_drafted' => true])->assertOk()
            ->assertJsonPath('plan.ai_drafted_at', '2026-11-15T14:00:00+00:00');

        $this->api('PUT', "/api/admin/creatives/plans/{$id}", ['video_prompt' => str_repeat('a', 4001)])->assertStatus(422);
        $this->api('PUT', "/api/admin/creatives/plans/{$id}", ['ai_drafted' => 'yes please'])->assertStatus(422);
        $this->assertSame(['Pixel recaps a green week', 'Hand-written'], array_column($this->api('GET', '/api/admin/creatives/plans')->json('plans'), 'title'));
    }

    // ---- the Saturday recap -------------------------------------------------

    public function test_weekly_recap_drafts_one_idea_plan_per_week(): void
    {
        $this->publishWeek();
        // Saturday 14 Nov, 00:15 in Manila: the week that ended is 7–13 Nov.
        $this->travelTo(Carbon::parse('2026-11-13 16:15:00', 'UTC'));
        Cache::flush();

        $this->artisan('creatives:weekly-recap')->assertSuccessful();

        $plan = CreativePlan::sole();
        $this->assertSame('Weekly recap · 7–13 Nov 2026', $plan->title);
        $this->assertSame('idea', $plan->status);
        $this->assertSame('tiktok', $plan->platform);
        $this->assertSame('video', $plan->format);
        $this->assertSame('2026-11-16', $plan->plannedFor());
        $this->assertSame(CreativeAi::AI_NAME, $plan->created_by_name);
        $this->assertNull($plan->created_by_uni_id);
        $this->assertNotNull($plan->ai_drafted_at);
        $this->assertSame('Pixel had a week worth talking about.', $plan->hook);
        $this->assertStringEndsWith(CreativeAi::DISCLAIMER, $plan->caption);
        $this->assertSame('#crypto #tradingbot #pixelalpha', $plan->hashtags);
        $this->assertStringStartsWith('Pixel stands on a glowing pixel chart', $plan->video_prompt);
        $this->assertStringContainsString('AI title: Pixel recaps a green week', $plan->notes);

        $call = CreativeAiCall::sole();
        $this->assertSame('weekly', $call->kind);
        $this->assertSame($plan->id, $call->plan_id);
        $this->assertSame("Weekly recap of the bot's results for 7–13 Nov 2026", $call->brief);
        $this->assertSame(CreativeAi::AI_NAME, $call->created_by_name);

        $facts = FakeCreativeWriter::factsOf($this->claude->lastUser());
        $this->assertSame('7–13 Nov 2026', $facts['performance']['period']);
        $this->assertSame(6.03, $facts['performance']['return_pct']);
        $this->assertStringContainsString('PLATFORM: tiktok', $this->claude->lastUser());

        // The page shows it as AI-drafted and the budget names the AI.
        $this->api('GET', '/api/admin/creatives/plans')->assertJsonPath('plans.0.created_by_name', CreativeAi::AI_NAME);
        $this->api('GET', '/api/admin/creatives/budget')
            ->assertJsonPath('budget.ai_calls', 1)
            ->assertJsonPath('budget.by_person.0.name', CreativeAi::AI_NAME)
            ->assertJsonPath('budget.by_person.0.uni_id', null);

        // Idempotent: a second run (or a rename of the plan) drafts nothing.
        $this->artisan('creatives:weekly-recap')->assertSuccessful();
        $plan->forceFill(['title' => 'Renamed by the team'])->save();
        $this->artisan('creatives:weekly-recap')->assertSuccessful();
        $this->assertSame(1, CreativePlan::count());
        $this->assertCount(1, $this->claude->calls);

        // Next Saturday is a new week (and nothing was published in it).
        $this->travelTo(Carbon::parse('2026-11-20 16:15:00', 'UTC'));
        Cache::flush();
        $this->artisan('creatives:weekly-recap')->assertSuccessful()->expectsOutputToContain('no_facts');
        $this->assertCount(1, $this->claude->calls);
    }

    public function test_weekly_recap_skips_when_off_unconfigured_without_facts_or_over_budget(): void
    {
        $this->travelTo(Carbon::parse('2026-11-13 16:15:00', 'UTC'));

        // Nothing published that week.
        $this->artisan('creatives:weekly-recap')->assertSuccessful()->expectsOutputToContain('no_facts');

        $this->publishWeek();

        CreativeSetting::query()->update(['ai_weekly_recap' => false]);
        $this->artisan('creatives:weekly-recap')->assertSuccessful()->expectsOutputToContain('off');
        CreativeSetting::query()->update(['ai_weekly_recap' => true]);

        $this->claude->configured = false;
        $this->artisan('creatives:weekly-recap')->assertSuccessful()->expectsOutputToContain('not_configured');
        $this->claude->configured = true;

        $this->setCap(0.4);
        $this->artisan('creatives:weekly-recap')->assertSuccessful()->expectsOutputToContain('over_budget');

        $this->assertCount(0, $this->claude->calls);
        $this->assertSame(0, CreativePlan::count());
        $this->assertSame(0, CreativeAiCall::count());

        // A bad answer fails the run without throwing; the tokens are recorded, no plan.
        $this->setCap(100);
        $this->claude->replies = [FakeCreativeWriter::text('nope')];
        $this->artisan('creatives:weekly-recap')->assertFailed();
        $this->assertSame(0, CreativePlan::count());
        $this->assertSame(1, CreativeAiCall::count());
    }

    public function test_weekly_recap_is_scheduled_saturdays_after_the_telegram_recap(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'creatives:weekly-recap'));

        $this->assertNotNull($event, 'creatives:weekly-recap is not scheduled.');
        $this->assertSame('15 0 * * 6', $event->expression);
        $this->assertSame('Asia/Manila', $event->timezone);
    }
}
