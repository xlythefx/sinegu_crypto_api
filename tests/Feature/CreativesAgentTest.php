<?php

namespace Tests\Feature;

use App\Models\CreativeAgentJob;
use App\Models\CreativeAgentMessage;
use App\Models\CreativeAgentSession;
use App\Models\CreativeAiCall;
use App\Models\CreativeCharacter;
use App\Models\CreativeCharacterRef;
use App\Models\CreativeGeneration;
use App\Models\CreativeMemory;
use App\Models\CreativePlan;
use App\Models\CreativeSetting;
use App\Models\CreativeStyle;
use App\Models\CreativeVideo;
use App\Models\CreativeVideoScene;
use App\Models\Invoice;
use App\Models\TradeLog;
use App\Models\UserCredential;
use App\Services\Ai\AiTools;
use App\Services\Creatives\AgentReply;
use App\Services\Creatives\ClaudeAgent;
use App\Services\Creatives\ClaudeClient;
use App\Services\Creatives\CreativeAgent;
use App\Services\Creatives\CreativeAgentContext;
use App\Services\Creatives\CreativeAgentModel;
use App\Services\Creatives\CreativeAgentPrompt;
use App\Services\Creatives\CreativeFacts;
use App\Services\Creatives\CreativeModels;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Support\FakeCreativeAgentModel;

/**
 * Admin → Creatives' chat agent (Claude with tools), client-stepped.
 *
 * Pinned here: who may chat (every staff role, collaborators included; a plain
 * user is refused) and that a chat is its owner's alone; the loop statuses
 * (continue / done / awaiting_approval / error), the 8-calls-per-message cap
 * and SESSION_BUSY; that the history is append-only and replayed byte for
 * byte, with ONE tools array in both modes (Ask refuses the Generate tools by
 * an error result); memory and brief, by tool and by REST; that propose_job
 * prices and validates but never spends, and only Go does — within the cap,
 * within the hour; how a job advances, fails and is cancelled; that every
 * model call is recorded as kind `agent` against the same cap; and the 7-day
 * prune. Creatives v2: the new tools in their strict shapes (results per
 * exchange and coin, query_data, ask_choices ending the turn, styles,
 * create_video / add_scenes / get_video / list_videos); that query_data never
 * carries a credential or an email; that a POST may never carry a money
 * amount or a customer's name (the model may read private data since
 * 2026-11-12; the server refuses to post it); the playbook and styles in the
 * [Context]; the read-only Context view; and that a chat from before the
 * tools changed is replaced.
 *
 * Claude is a FAKE bound over CreativeAgentModel (ANTHROPIC_API_KEY is pinned
 * empty in phpunit.xml as well). The one test of the real ClaudeAgent sends
 * through a Guzzle MockHandler — no request ever leaves the machine. Higgsfield
 * is one catch-all Http::fake (an unexpected URL fails the test), with
 * Http::preventStrayRequests() on. No ConnectionException is ever faked (that
 * crashes PHP natively on the WAMP box).
 */
class CreativesAgentTest extends EngineTestCase
{
    /** 1×1 PNG. */
    private const PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private FakeCreativeAgentModel $claude;

    private string $admin;

    private string $collaborator;

    private string $user;

    private ?string $master = null;

    /** @var list<string> */
    private array $unexpected = [];

    private int $submits = 0;

    private int $uploads = 0;

    /** @var array<string, array<string, mixed>> what /requests/{id}/status answers, per request id */
    private array $statuses = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Sunday 15 Nov 2026, 20:00 in Manila.
        $this->travelTo(Carbon::parse('2026-11-15 12:00:00', 'UTC'));
        Cache::flush();
        Storage::fake('local');
        config([
            'services.track_record.timezone' => 'Asia/Manila',
            'services.higgsfield.key_id' => 'hf-test-id',
            'services.higgsfield.key_secret' => 'hf-test-secret',
            'services.higgsfield.base_url' => 'https://api.higgsfield.ai',
            'services.higgsfield.ca_bundle' => null,
        ]);

        $this->claude = new FakeCreativeAgentModel;
        $this->app->instance(CreativeAgentModel::class, $this->claude);

        $this->admin = $this->makeUser(['type' => 'admin', 'name' => 'Owner Admin', 'email' => 'owner@pixel-alpha.test']);
        $this->collaborator = $this->makeUser(['type' => 'collaborator', 'name' => 'Mara Marketing', 'email' => 'mara@pixel-alpha.test']);
        $this->user = $this->makeUser(['name' => 'Plain Customer', 'email' => 'customer@pixel-alpha.test']);

        $this->fakeHiggsfield();
    }

    protected function tearDown(): void
    {
        $this->assertSame([], $this->unexpected, 'A request went to a URL the test did not expect.');
        parent::tearDown();
    }

    // ---- helpers ---------------------------------------------------------

    private function fakeHiggsfield(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (ClientRequest $request) {
            $url = $request->url();
            if (str_starts_with($url, 'https://api.higgsfield.ai/estimate/')) {
                return Http::response(['type' => 'estimate', 'usd' => match (true) {
                    str_contains($url, 'kling') => '0.595',
                    str_contains($url, 'grok') => '0.070',
                    default => '0.004',
                }, 'discount' => null]);
            }
            if (str_starts_with($url, 'https://api.higgsfield.ai/files/generate-upload-url')) {
                $n = ++$this->uploads;

                return Http::response([
                    'public_url' => "https://files.higgsfield.test/ref-{$n}.png",
                    'upload_url' => "https://upload.s3.test/put/{$n}",
                    'content_type' => 'image/png',
                    'upload_headers' => ['Content-Type' => 'image/png'],
                ]);
            }
            if (preg_match('#^https://api\.higgsfield\.ai/requests/([^/]+)/status$#', $url, $m) === 1) {
                return Http::response($this->statuses[$m[1]] ?? ['status' => 'queued', 'request_id' => $m[1]]);
            }
            if (preg_match('#^https://api\.higgsfield\.ai/requests/([^/]+)/cancel$#', $url) === 1) {
                return Http::response(['status' => 'canceled'], 202);
            }
            if (str_starts_with($url, 'https://upload.s3.test/')) {
                return Http::response('', 200);
            }
            if ($url === 'http://127.0.0.1:5010/health') {
                // query_data's admin tools ask the local engine which venues
                // it trades; silent = unknown, which every caller tolerates.
                return Http::response(['detail' => 'engine not running in tests'], 503);
            }
            if (str_starts_with($url, 'https://api.higgsfield.ai/')) {
                $n = ++$this->submits;

                return Http::response(['request_id' => "req-{$n}", 'status' => 'queued']);
            }
            $this->unexpected[] = $url;

            return Http::response(['detail' => 'unexpected'], 418);
        });
    }

    /** @return list<array{0: ClientRequest, 1: mixed}> */
    private function sent(string $kind): array
    {
        return Http::recorded(fn (ClientRequest $r) => match ($kind) {
            'estimate' => str_starts_with($r->url(), 'https://api.higgsfield.ai/estimate/'),
            'submit' => str_starts_with($r->url(), 'https://api.higgsfield.ai/')
                && ! str_contains($r->url(), '/estimate/') && ! str_contains($r->url(), '/requests/') && ! str_contains($r->url(), '/files/'),
            'cancel' => str_ends_with($r->url(), '/cancel'),
            default => false,
        })->values()->all();
    }

    private function headers(string $uniId): array
    {
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.UserCredential::findOrFail($uniId)->createToken('test')->plainTextToken];
    }

    private function api(string $method, string $uri, array $data = [], ?string $as = null): TestResponse
    {
        return $this->json($method, $uri, $data, $this->headers($as ?? $this->collaborator));
    }

    private function sessionId(?string $as = null): string
    {
        return (string) $this->api('GET', '/api/admin/creatives/agent/session', [], $as)->assertOk()->json('session.id');
    }

    private function turn(string $session, string $mode, ?string $text = null, ?string $as = null): TestResponse
    {
        $body = ['session_id' => $session, 'mode' => $mode];
        if ($text !== null) {
            $body['text'] = $text;
        }

        return $this->api('POST', '/api/admin/creatives/agent/turn', $body, $as);
    }

    /** The stored tool_result blocks of the newest tool-results message. */
    private function lastResults(string $session): array
    {
        $row = CreativeAgentMessage::query()->where('session_id', $session)->where('role', 'user')->orderByDesc('id')->get()
            ->first(fn (CreativeAgentMessage $m) => ! $m->isPerson());

        return array_values(array_filter($row?->blocks() ?? [], fn ($b) => ($b['type'] ?? null) === 'tool_result'));
    }

    private function setCap(float $usd): void
    {
        $this->api('PUT', '/api/admin/creatives/budget', ['monthly_cap_usd' => $usd], $this->admin)->assertOk();
    }

    private function makeRef(): CreativeCharacterRef
    {
        $path = 'creatives/refs/'.bin2hex(random_bytes(8)).'.png';
        Storage::disk('local')->put($path, base64_decode(self::PNG_B64));

        return CreativeCharacterRef::create([
            'character_id' => CreativeCharacter::current()->id,
            'label' => 'Front', 'path' => $path, 'mime' => 'image/png', 'bytes' => 70, 'source' => 'upload',
        ]);
    }

    /** The "picture of Pixel, then animate it" job. */
    private function recipeJob(array $over = []): array
    {
        return array_merge([
            'title' => 'Weekly recap video',
            'steps' => [
                ['model' => 'grok-image', 'label' => 'Pixel at the desk', 'prompt' => 'Pixel at a trading desk, a rising chart behind.', 'params' => ['aspect_ratio' => '9:16']],
                ['model' => 'kling-animate', 'label' => 'Animate it', 'prompt' => 'The chart climbs, Pixel nods.', 'params' => ['duration' => 10], 'source' => ['from_step' => 0]],
            ],
        ], $over);
    }

    /** A proposed recipe job in the collaborator's chat (Generate mode), through the agent. */
    private function proposeRecipe(array $over = []): array
    {
        $this->makeRef();
        $session = $this->sessionId();
        $this->claude->replies = [FakeCreativeAgentModel::tools([['propose_job', $this->recipeJob($over)]], 'Here is the job.')];
        $res = $this->turn($session, 'generate', 'Make this week\'s recap video')->assertOk()->assertJsonPath('status', 'awaiting_approval');

        return [$session, (int) $res->json('jobs.0.id')];
    }

    /**
     * The master's published track record — three Manila days, chained 6.03%.
     * Its private side (name, email, account name, balance, $ P&L) must never
     * reach the model.
     */
    private function publishWeek(): void
    {
        $this->master = $this->makeUser(['type' => 'master', 'name' => 'Masterly Person', 'email' => 'boss@pixel-alpha.test']);
        $this->makeAccount($this->master, ['api_key' => 'master-key', 'initial_deposit' => 1000, 'name' => 'Secret Master Account', 'balance' => 1060.29]);
        foreach ([['2026-11-10 04:00:00', 20.00, 'LTCUSDT'], ['2026-11-11 04:00:00', -10.20, 'BTCUSDT'], ['2026-11-12 04:00:00', 50.49, 'LTCUSDT']] as [$at, $pnl, $symbol]) {
            DB::table('binance_pastpositions')->insert([
                'uni_id' => $this->master, 'api_key' => 'master-key', 'symbol' => $symbol, 'position_side' => 'LONG',
                'position_amt' => 1, 'entry_price' => 100, 'exit_price' => 101, 'realized_pnl' => $pnl,
                'exchange_fee' => 0.0, 'side' => 'BUY', 'closed_at' => $at,
            ]);
        }
        Cache::flush();
    }

    // ---- who ------------------------------------------------------------------

    public function test_every_staff_role_chats_and_a_plain_user_is_refused(): void
    {
        foreach ([
            ['GET', '/api/admin/creatives/agent/session'],
            ['POST', '/api/admin/creatives/agent/sessions'],
            ['POST', '/api/admin/creatives/agent/turn'],
            ['POST', '/api/admin/creatives/agent/jobs/1/approve'],
            ['POST', '/api/admin/creatives/agent/jobs/1/cancel'],
            ['GET', '/api/admin/creatives/brief'],
            ['PUT', '/api/admin/creatives/brief'],
            ['GET', '/api/admin/creatives/memory'],
            ['POST', '/api/admin/creatives/memory'],
        ] as [$method, $uri]) {
            $this->api($method, $uri, [], $this->user)->assertForbidden()->assertJsonPath('error_code', 'FORBIDDEN');
        }

        $res = $this->api('GET', '/api/admin/creatives/agent/session')->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('session.mode', 'ask')
            ->assertJsonPath('messages', [])
            ->assertJsonPath('jobs', [])
            ->assertJsonPath('budget.cap_usd', 100);
        $this->assertTrue(\Illuminate\Support\Str::isUuid($res->json('session.id')));

        $this->claude->replies = [FakeCreativeAgentModel::text('Hello!')];
        $this->turn($res->json('session.id'), 'generate', 'Hi')->assertOk()->assertJsonPath('status', 'done');
        $this->turn($this->sessionId($this->admin), 'ask', 'Hi', $this->admin)->assertOk()->assertJsonPath('status', 'done');
        $this->assertCount(2, $this->claude->calls);
    }

    public function test_a_chat_is_its_owners_and_one_older_than_seven_days_is_replaced(): void
    {
        $first = $this->sessionId();
        $this->assertSame($first, $this->sessionId(), 'The newest live chat is offered again.');

        // Someone else's chat: 404 everywhere.
        $this->api('GET', '/api/admin/creatives/agent/session?id='.$first, [], $this->admin)->assertNotFound()->assertJsonPath('error_code', 'NOT_FOUND');
        $this->turn($first, 'ask', 'Hi', $this->admin)->assertNotFound()->assertJsonPath('error_code', 'NOT_FOUND');
        $this->assertCount(0, $this->claude->calls);

        $this->travel(1)->minute();
        $second = (string) $this->api('POST', '/api/admin/creatives/agent/sessions')->assertOk()
            ->assertJsonPath('session.mode', 'ask')->assertJsonPath('messages', [])->assertJsonPath('jobs', [])
            ->json('session.id');
        $this->assertNotSame($first, $second);
        // A NEW chat is the newest; the old one can still be opened by id.
        $this->travel(1)->minute();
        $this->claude->replies = [FakeCreativeAgentModel::text('Hi.')];
        $this->turn($second, 'ask', 'Hello')->assertOk();
        $this->assertSame($second, $this->sessionId());
        $this->api('GET', '/api/admin/creatives/agent/session?id='.$first)->assertOk()->assertJsonPath('session.id', $first);
        $this->api('GET', '/api/admin/creatives/agent/session?id=00000000-0000-4000-8000-000000000000')->assertOk()->assertJsonPath('session.id', $second);

        // Eight days later both are over: a fresh chat, and a turn on the old one is refused.
        $this->travel(8)->days();
        $third = (string) $this->api('GET', '/api/admin/creatives/agent/session?id='.$second)->assertOk()->assertJsonPath('messages', [])->json('session.id');
        $this->assertNotContains($third, [$first, $second]);
        $this->turn($second, 'ask', 'Still there?')->assertNotFound();
    }

    // ---- the loop ---------------------------------------------------------------

    public function test_tool_calls_continue_and_an_answer_without_tools_is_done(): void
    {
        CreativePlan::create(['title' => 'Meet Pixel', 'platform' => 'tiktok', 'format' => 'video']);
        $session = $this->sessionId();
        $this->claude->replies = [
            FakeCreativeAgentModel::tools([['list_plans', []], ['get_budget', []]], 'Let me look at the planner.'),
            FakeCreativeAgentModel::text('You have **one** plan: Meet Pixel.'),
        ];

        $res = $this->turn($session, 'ask', 'What is in the planner?')->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'continue')
            ->assertJsonPath('jobs', [])
            ->assertJsonPath('budget.ai_calls', 1);
        $kinds = array_column($res->json('messages'), 'kind');
        $this->assertSame(['user', 'assistant', 'activity', 'activity'], $kinds);
        $this->assertSame('What is in the planner?', $res->json('messages.0.text'));
        $this->assertSame('Let me look at the planner.', $res->json('messages.1.text'));
        $this->assertSame(['tool' => 'list_plans', 'label' => 'Read the planner', 'ok' => true], $res->json('messages.2.activity'));
        $this->assertSame('Checked the budget', $res->json('messages.3.activity.label'));
        $this->assertSame('ask', $res->json('messages.0.mode'));
        $this->assertGreaterThan(0, $res->json('cost_usd'));
        // Both results went back in ONE user message.
        $results = $this->lastResults($session);
        $this->assertCount(2, $results);
        $this->assertStringContainsString('Meet Pixel', $results[0]['content']);

        $done = $this->turn($session, 'ask')->assertOk()->assertJsonPath('status', 'done');
        $this->assertSame(['assistant'], array_column($done->json('messages'), 'kind'));
        $this->assertSame('You have **one** plan: Meet Pixel.', $done->json('messages.0.text'));
        $this->assertSame(FakeCreativeAgentModel::results($this->claude->calls[1]), $results);

        // Nothing is owed now: a step without words is refused.
        $this->turn($session, 'ask')->assertStatus(422)->assertJsonValidationErrors('text');
        $this->assertCount(2, $this->claude->calls);

        // The session read shows the whole chat, thinking and tool blocks hidden.
        $all = $this->api('GET', '/api/admin/creatives/agent/session?id='.$session)->assertOk()->json('messages');
        $this->assertSame(['user', 'assistant', 'activity', 'activity', 'assistant'], array_column($all, 'kind'));
        $ids = array_column($all, 'id');
        $sorted = $ids;
        sort($sorted);
        $this->assertSame($sorted, $ids);
        $this->assertSame(count($ids), count(array_unique($ids)));
    }

    public function test_the_history_is_append_only_and_replayed_byte_for_byte(): void
    {
        $session = $this->sessionId();
        $first = FakeCreativeAgentModel::tools([['get_character', []]], 'Checking Pixel.');
        $this->claude->replies = [$first, FakeCreativeAgentModel::text('Pixel is a robot.'), FakeCreativeAgentModel::text('Sure.')];

        $this->turn($session, 'ask', 'Who is Pixel?')->assertOk()->assertJsonPath('status', 'continue');
        $this->turn($session, 'ask')->assertOk()->assertJsonPath('status', 'done');
        $this->turn($session, 'ask', 'Thanks')->assertOk()->assertJsonPath('status', 'done');
        [$c0, $c1, $c2] = $this->claude->calls;

        // The assistant's blocks (thinking + signature included) are stored
        // exactly as returned, and the next call carries them unchanged.
        $stored = CreativeAgentMessage::query()->where('session_id', $session)->where('role', 'assistant')->orderBy('id')->first();
        $this->assertSame($first->content, $stored->content);
        $this->assertSame($first->content, json_encode($c1['objects'][1]->content, FakeCreativeAgentModel::FLAGS));
        $this->assertStringContainsString('"input":{}', $stored->content);
        $this->assertSame('thinking', $c1['decoded'][1]['content'][0]['type']);
        $this->assertNotSame('', $c1['decoded'][1]['content'][0]['signature']);

        // Each call's messages START with the previous call's, byte for byte.
        $prefix = fn (array $a, array $b) => json_encode(array_slice($b['objects'], 0, count($a['objects'])), FakeCreativeAgentModel::FLAGS);
        $this->assertSame($c0['messages'], $prefix($c0, $c1));
        $this->assertSame($c1['messages'], $prefix($c1, $c2));
        $this->assertSame(['user', 'assistant', 'user', 'assistant', 'user'], array_column($c2['decoded'], 'role'));

        // The tool result answers the tool_use id; the person's message leads with [Context].
        $toolUseId = $c1['decoded'][1]['content'][2]['id'];
        $this->assertSame($toolUseId, $c1['decoded'][2]['content'][0]['tool_use_id']);
        $this->assertStringStartsWith('[Context]', $c0['decoded'][0]['content'][0]['text']);
        $this->assertStringEndsWith('[/Context]', $c0['decoded'][0]['content'][0]['text']);
        $this->assertSame('Who is Pixel?', $c0['decoded'][0]['content'][1]['text']);

        // The static half never changes; no call ever edits it.
        $this->assertSame($c0['system'], $c2['system']);
        $this->assertSame(CreativeAgentPrompt::SYSTEM, $c0['system']);
        $this->assertSame(CreativeAgentPrompt::MAX_TOKENS, $c0['max_tokens']);
    }

    public function test_one_tools_array_in_both_modes_and_ask_refuses_the_generate_tools(): void
    {
        $session = $this->sessionId();
        $calls = [
            ['create_plan', ['title' => 'Meet Pixel']],
            ['update_plan', ['id' => 1, 'hook' => 'Hi']],
            ['propose_job', $this->recipeJob()],
            ['list_models', []],
        ];
        $this->claude->replies = [
            FakeCreativeAgentModel::tools($calls),
            FakeCreativeAgentModel::tools([['create_plan', ['title' => 'Sneaky']]]),
            FakeCreativeAgentModel::text('Switch to Generate.'),
        ];

        $res = $this->turn($session, 'ask', 'Plan a Meet Pixel post')->assertOk()->assertJsonPath('status', 'continue');
        $results = $this->lastResults($session);
        foreach ([0, 1, 2] as $i) {
            $this->assertTrue($results[$i]['is_error']);
            $this->assertSame('Ask mode: switch to Generate to do this.', $results[$i]['content']);
        }
        $this->assertArrayNotHasKey('is_error', $results[3]);
        $this->assertSame([false, false, false, true], array_column(array_column(array_slice($res->json('messages'), 1), 'activity'), 'ok'));
        $this->assertSame(0, CreativePlan::count());
        $this->assertSame(0, CreativeAgentJob::count());
        $this->assertCount(0, $this->sent('estimate'));

        // A continue step keeps the mode the model was told, even if the switch flips mid-loop.
        $this->turn($session, 'generate')->assertOk()->assertJsonPath('status', 'continue');
        $this->assertTrue($this->lastResults($session)[0]['is_error']);
        $this->assertSame(0, CreativePlan::count());
        $this->turn($session, 'generate')->assertOk()->assertJsonPath('status', 'done');

        // Generate mode: the same tools, byte for byte; now create_plan works.
        $this->claude->replies = [FakeCreativeAgentModel::tools([['create_plan', ['title' => 'Meet Pixel', 'hook' => 'Up 6.03% this week', 'platform' => 'x']]]), FakeCreativeAgentModel::text('Done.')];
        $res = $this->turn($session, 'generate', 'OK, Generate now')->assertOk()->assertJsonPath('status', 'continue');
        $plan = CreativePlan::firstOrFail();
        $this->assertSame('Meet Pixel', $plan->title);
        $this->assertSame('x', $plan->platform);
        $this->assertNotNull($plan->ai_drafted_at);
        $this->assertSame($this->collaborator, $plan->created_by_uni_id);
        // A percentage anywhere → the disclaimer, fixed in PHP.
        $this->assertStringEndsWith('Past performance does not guarantee future results.', (string) $plan->caption);
        $chip = collect($res->json('messages'))->firstWhere('kind', 'plan');
        $this->assertSame($plan->id, $chip['plan_id']);
        $this->assertSame('Meet Pixel', $chip['text']);

        $tools = array_unique(array_column($this->claude->calls, 'tools'));
        $this->assertCount(1, $tools, 'The tools array changed between calls or modes.');
        $decoded = json_decode($tools[0], true);
        $this->assertSame(
            ['list_plans', 'get_plan', 'list_generations', 'get_character', 'get_week_results', 'get_budget', 'list_models', 'remember', 'forget', 'update_memory', 'create_plan', 'update_plan', 'propose_job',
                'query_data', 'ask_choices', 'get_style', 'create_video', 'add_scenes', 'get_video', 'list_videos'],
            array_column($decoded, 'name'),
        );
        foreach ($decoded as $tool) {
            $this->assertTrue($tool['strict']);
            $this->assertFalse($tool['inputSchema']['additionalProperties']);
        }
        // Strict schemas allow few optional parameters per request: at ANY
        // depth, every object requires all of its properties and allows no other.
        $walk = function (array $schema, string $where) use (&$walk) {
            if (($schema['type'] ?? null) === 'object') {
                $this->assertFalse($schema['additionalProperties'], $where);
                $this->assertSame(array_keys((array) ($schema['properties'] ?? [])), $schema['required'], $where);
            }
            foreach ((array) ($schema['properties'] ?? []) as $name => $property) {
                $walk((array) $property, "{$where}.{$name}");
            }
            if (is_array($schema['items'] ?? null)) {
                $walk($schema['items'], "{$where}[]");
            }
        };
        foreach ($decoded as $tool) {
            $walk($tool['inputSchema'], $tool['name']);
        }
        $this->assertSame(CreativeAgentPrompt::TOOLS_VERSION, CreativeAgentSession::find($session)->tools_version);
    }

    public function test_at_most_eight_model_calls_per_person_message(): void
    {
        $session = $this->sessionId();
        $this->claude->replies = [FakeCreativeAgentModel::tools([['get_budget', []]])];

        $this->turn($session, 'ask', 'Keep checking the budget')->assertOk()->assertJsonPath('status', 'continue');
        for ($i = 2; $i <= CreativeAgent::MAX_CALLS; $i++) {
            $this->turn($session, 'ask')->assertOk()->assertJsonPath('status', 'continue');
        }
        $this->assertCount(8, $this->claude->calls);

        // The 9th: no model call, a short note, done.
        $res = $this->turn($session, 'ask')->assertOk()->assertJsonPath('status', 'done')->assertJsonPath('cost_usd', 0);
        $this->assertCount(8, $this->claude->calls);
        $this->assertSame('notice', $res->json('messages.0.kind'));
        $this->assertStringContainsString('I stopped here', $res->json('messages.0.text'));
        $this->assertSame(8, CreativeAiCall::where('kind', 'agent')->count());

        // A new message from the person starts a new count; the notice is never sent.
        $this->claude->replies = [FakeCreativeAgentModel::text('Carrying on.')];
        $this->turn($session, 'ask', 'carry on')->assertOk()->assertJsonPath('status', 'done');
        $this->assertCount(9, $this->claude->calls);
        $this->assertStringNotContainsString('I stopped here', $this->claude->calls[8]['messages']);
    }

    public function test_a_second_turn_on_a_busy_chat_is_session_busy(): void
    {
        $session = $this->sessionId();
        $lock = Cache::lock(CreativeAgent::lockName($session), 150);
        $this->assertTrue($lock->get());

        $this->turn($session, 'ask', 'Hi')->assertStatus(409)->assertJsonPath('error_code', 'SESSION_BUSY');
        $this->assertCount(0, $this->claude->calls);
        $this->assertSame(0, CreativeAgentMessage::count());

        $lock->release();
        $this->claude->replies = [FakeCreativeAgentModel::text('Hi.')];
        $this->turn($session, 'ask', 'Hi')->assertOk();
    }

    // ---- money ------------------------------------------------------------------

    public function test_every_model_call_is_recorded_as_agent_and_counts_in_the_cap(): void
    {
        $session = $this->sessionId();
        // 10k input + 2k output + 1k cache write + 4k cache read at Opus 5.5:
        // 0.04 + 0.04 + 0.005 + 0.0008 = 0.0858
        $this->claude->replies = [
            FakeCreativeAgentModel::answer([FakeCreativeAgentModel::thinking(), ['type' => 'tool_use', 'id' => 'toolu_cost', 'name' => 'get_budget', 'input' => new \stdClass, 'caller' => ['type' => 'direct']]], 'tool_use', 10000, 2000, 1000, 4000),
            FakeCreativeAgentModel::text('Plenty left.', 1000, 100),  // 0.004 + 0.002
        ];
        $this->turn($session, 'ask', 'How much is left?')->assertOk()->assertJsonPath('cost_usd', 0.0858);
        $res = $this->turn($session, 'ask')->assertOk()->assertJsonPath('cost_usd', 0.006);

        $calls = CreativeAiCall::orderBy('id')->get();
        $this->assertSame(['agent', 'agent'], $calls->pluck('kind')->all());
        $this->assertSame(['tool_use', 'end_turn'], $calls->pluck('stop_reason')->all());
        $this->assertSame([$this->collaborator, $this->collaborator], $calls->pluck('created_by_uni_id')->all());
        $this->assertSame(15000, $calls[0]->input_tokens);
        $res->assertJsonPath('budget.ai_usd', 0.0918)
            ->assertJsonPath('budget.ai_calls', 2)
            ->assertJsonPath('budget.spent_usd', 0.0918)
            ->assertJsonPath('budget.remaining_usd', 99.9082);
        // The call is settled before its tools run: the budget tool saw the real cost.
        $this->assertStringContainsString('"spent":0.0858', $this->lastResults($session)[0]['content']);
    }

    public function test_the_cap_refuses_a_call_before_anything_is_stored(): void
    {
        $session = $this->sessionId();
        $this->setCap(0.4);  // under the $0.50 reservation

        $this->turn($session, 'ask', 'Hi')->assertStatus(422)
            ->assertJsonPath('error_code', 'BUDGET_EXCEEDED')
            ->assertJsonPath('budget.cap_usd', 0.4);
        $this->assertCount(0, $this->claude->calls);
        $this->assertSame(0, CreativeAgentMessage::count());
        $this->assertSame(0, CreativeAiCall::count());

        $this->claude->configured = false;
        $this->turn($session, 'ask', 'Hi')->assertStatus(503)->assertJsonPath('error_code', 'AI_NOT_CONFIGURED');
    }

    public function test_no_answer_refusal_and_max_tokens(): void
    {
        $session = $this->sessionId();

        // No answer, nothing billed: the reservation goes, the message stays (a retry needs no words).
        $this->claude->replies = [AgentReply::unanswered('claude-opus-5-5', 'server_error')];
        $res = $this->turn($session, 'ask', 'Hi')->assertStatus(502)
            ->assertJsonPath('error_code', 'AI_UNAVAILABLE')
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('messages.0.kind', 'user');
        $this->assertSame(0, CreativeAiCall::count());

        // It may have run: the reservation is kept.
        $this->claude->replies = [AgentReply::unanswered('claude-opus-5-5', 'unreachable', maybeBilled: true)];
        $this->turn($session, 'ask')->assertStatus(502);
        $this->assertSame('unconfirmed', CreativeAiCall::sole()->stop_reason);
        $this->assertEquals(0.5, CreativeAiCall::sole()->cost_usd);

        // A refusal costs its tokens and is never stored (its tools never run).
        $refusal = FakeCreativeAgentModel::answer([['type' => 'tool_use', 'id' => 'toolu_r', 'name' => 'create_plan', 'input' => ['title' => 'x'], 'caller' => ['type' => 'direct']]], 'refusal', 1000, 100);
        $this->claude->replies = [$refusal];
        $this->turn($session, 'generate')->assertStatus(422)
            ->assertJsonPath('error_code', 'AI_REFUSED')
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('cost_usd', 0.006)
            ->assertJsonPath('messages.0.kind', 'notice');
        $this->assertSame(0, CreativePlan::count());

        // Cut off at max_tokens: stored nowhere, a notice instead.
        $this->claude->replies = [FakeCreativeAgentModel::answer([['type' => 'text', 'text' => 'Half an ans']], 'max_tokens')];
        $this->turn($session, 'ask')->assertOk()->assertJsonPath('status', 'error')->assertJsonPath('messages.0.kind', 'notice');

        $this->assertSame(0, CreativeAgentMessage::where('role', 'assistant')->count());
        $this->assertSame(['refusal', 'max_tokens'], CreativeAiCall::where('stop_reason', '!=', 'unconfirmed')->orderBy('id')->pluck('stop_reason')->all());
        // The notices never reach Claude.
        $this->claude->replies = [FakeCreativeAgentModel::text('Hello again.')];
        $this->turn($session, 'ask', 'Hello?')->assertOk()->assertJsonPath('status', 'done');
        $last = $this->claude->calls[count($this->claude->calls) - 1];
        $this->assertSame(['user'], array_column($last['decoded'], 'role'));
        $this->assertStringNotContainsString('declined', $last['messages']);
    }

    // ---- memory and brief ---------------------------------------------------------

    public function test_memory_by_rest(): void
    {
        $this->api('POST', '/api/admin/creatives/memory', ['text' => '', 'category' => 'brand'])->assertStatus(422);
        $this->api('POST', '/api/admin/creatives/memory', ['text' => str_repeat('a', 501)])->assertStatus(422);
        $this->api('POST', '/api/admin/creatives/memory', ['text' => 'Hooks with a question work.', 'category' => 'nope'])->assertStatus(422);

        $a = $this->api('POST', '/api/admin/creatives/memory', ['text' => 'Always end with pixel-alpha.com', 'category' => 'brand'])->assertCreated()
            ->assertJsonPath('memory.source', 'person')
            ->assertJsonPath('memory.category', 'brand')
            ->assertJsonPath('memory.created_by_name', 'Mara Marketing')
            ->json('memory.id');
        $this->travel(1)->second();
        $b = $this->api('POST', '/api/admin/creatives/memory', ['text' => 'Questions in hooks work.'], $this->admin)->assertCreated()
            ->assertJsonPath('memory.category', 'other')->json('memory.id');

        $this->api('GET', '/api/admin/creatives/memory')->assertOk()
            ->assertJsonPath('memories.0.id', $b)->assertJsonPath('memories.1.id', $a);
        $this->api('PUT', "/api/admin/creatives/memory/{$a}", ['category' => 'process'])->assertOk()
            ->assertJsonPath('memory.category', 'process')
            ->assertJsonPath('memory.text', 'Always end with pixel-alpha.com');
        $this->api('PUT', "/api/admin/creatives/memory/{$a}", ['text' => 'End with the site name.'])->assertOk()->assertJsonPath('memory.text', 'End with the site name.');
        $this->api('DELETE', "/api/admin/creatives/memory/{$a}")->assertOk()->assertJsonPath('success', true);
        $this->api('DELETE', "/api/admin/creatives/memory/{$a}")->assertNotFound()->assertJsonPath('error_code', 'NOT_FOUND');
        $this->api('PUT', '/api/admin/creatives/memory/999', ['text' => 'x'])->assertNotFound();
        $this->assertSame([$b], CreativeMemory::pluck('id')->all());
    }

    public function test_memory_tools_work_in_ask_mode_and_reach_the_context(): void
    {
        $session = $this->sessionId();
        $this->claude->replies = [
            FakeCreativeAgentModel::tools([
                ['remember', ['text' => 'TikTok hooks that ask a question do best.', 'category' => 'audience']],
                ['remember', ['text' => 'Email the boss at boss@example.com']],
                ['remember', ['text' => 'Our ad spend is $400 a week']],
            ]),
            FakeCreativeAgentModel::text('Saved.'),
        ];
        $res = $this->turn($session, 'ask', 'Remember that question hooks do best')->assertOk();
        $memory = CreativeMemory::sole();
        $this->assertSame('agent', $memory->source);
        $this->assertSame('audience', $memory->category);
        $this->assertSame('Mara Marketing', $memory->created_by_name);
        $this->assertSame('Saved to memory: TikTok hooks that ask a question do best.', $res->json('messages.1.activity.label'));
        $results = $this->lastResults($session);
        $this->assertArrayNotHasKey('is_error', $results[0]);
        $this->assertTrue($results[1]['is_error']);
        $this->assertTrue($results[2]['is_error']);
        $this->turn($session, 'ask')->assertOk()->assertJsonPath('status', 'done');

        // The next message's [Context] carries it; unchanged memory is not repeated.
        $this->claude->replies = [FakeCreativeAgentModel::tools([['update_memory', ['id' => $memory->id, 'text' => 'Question hooks do best on TikTok.']]]), FakeCreativeAgentModel::text('Updated.')];
        $this->turn($session, 'ask', 'Make that shorter')->assertOk();
        $context = $this->claude->calls[2]['decoded'][4]['content'][0]['text'];
        $this->assertStringContainsString("#{$memory->id} [audience] TikTok hooks that ask a question do best.", $context);
        $this->assertSame('Question hooks do best on TikTok.', $memory->fresh()->text);
        $this->turn($session, 'ask')->assertOk();

        $this->claude->replies = [FakeCreativeAgentModel::tools([['forget', ['id' => $memory->id]], ['forget', ['id' => 999]]]), FakeCreativeAgentModel::text('Gone.')];
        $this->turn($session, 'ask', 'Forget it')->assertOk();
        $this->assertSame(0, CreativeMemory::count());
        $this->assertTrue($this->lastResults($session)[1]['is_error']);
        $context = $this->claude->calls[4]['decoded'][count($this->claude->calls[4]['decoded']) - 1]['content'];
        $text = collect($context)->firstWhere(fn ($b) => str_starts_with($b['text'] ?? '', '[Context]'))['text'];
        $this->assertStringContainsString('Question hooks do best on TikTok.', $text, 'Changed memory is sent again in full.');
        $this->assertStringContainsString('BRIEF: unchanged', $text);
    }

    public function test_the_brief_is_seeded_editable_and_sent_once_until_it_changes(): void
    {
        $brief = $this->api('GET', '/api/admin/creatives/brief')->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('brief.updated_by_name', null)
            ->assertJsonPath('brief.updated_at', null)
            ->json('brief.markdown');
        foreach (['## Who we are', '## Who we talk to', '## Voice', '## Pixel', '## Always / Never', '## Formats per platform', '## Recipes', 'Weekly recap video', 'Meet Pixel', 'How the bot works in 20 seconds'] as $section) {
            $this->assertStringContainsString($section, $brief);
        }
        $this->assertStringNotContainsString('$', $brief);
        $this->assertDoesNotMatchRegularExpression('/\d\s?%/', $brief, 'The seeded brief carries no figures.');

        $session = $this->sessionId();
        $this->claude->replies = [FakeCreativeAgentModel::text('Got it.')];
        $this->turn($session, 'ask', 'Hi')->assertOk();
        $this->turn($session, 'ask', 'Again')->assertOk();
        $this->assertStringContainsString('## Who we talk to', $this->claude->calls[0]['decoded'][0]['content'][0]['text']);
        $second = $this->claude->calls[1]['decoded'][2]['content'][0]['text'];
        $this->assertStringContainsString('BRIEF: unchanged', $second);
        $this->assertStringNotContainsString('## Who we talk to', $second);

        $this->api('PUT', '/api/admin/creatives/brief', ['markdown' => str_repeat('x', 20001)])->assertStatus(422);
        $this->api('PUT', '/api/admin/creatives/brief', ['markdown' => "# Brief\n\nShort and friendly."])->assertOk()
            ->assertJsonPath('brief.markdown', "# Brief\n\nShort and friendly.")
            ->assertJsonPath('brief.updated_by_name', 'Mara Marketing');
        $this->api('GET', '/api/admin/creatives/brief')->assertJsonPath('brief.updated_by_name', 'Mara Marketing');
        $this->assertNotNull(CreativeSetting::current()->brief_updated_at);
        // The cap's "set by" is a different thing.
        $this->api('GET', '/api/admin/creatives/budget')->assertJsonPath('budget.updated_by_name', null);

        $this->turn($session, 'ask', 'And now?')->assertOk();
        $this->assertStringContainsString('Short and friendly.', $this->claude->calls[2]['decoded'][4]['content'][0]['text']);
        $this->api('PUT', '/api/admin/creatives/brief', ['markdown' => ''])->assertOk()->assertJsonPath('brief.markdown', '');
    }

    // ---- jobs ---------------------------------------------------------------------

    public function test_propose_job_prices_every_step_and_spends_nothing(): void
    {
        $plan = CreativePlan::create(['title' => 'Weekly recap', 'platform' => 'tiktok', 'format' => 'video']);
        [$session, $jobId] = $this->proposeRecipe(['plan_id' => $plan->id]);

        $res = $this->api('GET', '/api/admin/creatives/agent/session?id='.$session)->assertOk()
            ->assertJsonPath('jobs.0.id', $jobId)
            ->assertJsonPath('jobs.0.status', 'proposed')
            ->assertJsonPath('jobs.0.title', 'Weekly recap video')
            ->assertJsonPath('jobs.0.plan_id', $plan->id)
            ->assertJsonPath('jobs.0.plan_title', 'Weekly recap')
            ->assertJsonPath('jobs.0.estimated_usd', 0.665)
            ->assertJsonPath('jobs.0.steps.0.label', 'Pixel at the desk')
            ->assertJsonPath('jobs.0.steps.0.model', 'grok-image')
            ->assertJsonPath('jobs.0.steps.0.model_label', 'Character image (with references)')
            ->assertJsonPath('jobs.0.steps.0.usd', 0.07)
            ->assertJsonPath('jobs.0.steps.0.status', 'waiting')
            ->assertJsonPath('jobs.0.steps.0.generation', null)
            ->assertJsonPath('jobs.0.steps.1.usd', 0.595)
            ->assertJsonPath('jobs.0.approved_by_name', null)
            ->assertJsonPath('jobs.0.expires_at', '2026-11-15T13:00:00+00:00');
        $job = collect($res->json('messages'))->firstWhere('kind', 'job');
        $this->assertSame($jobId, $job['job_id']);

        // What the model was told: the id, the total, the steps, "wait for Go".
        $result = json_decode($this->lastResults($session)[0]['content'], true);
        $this->assertSame($jobId, $result['job_id']);
        $this->assertSame(0.665, $result['total_usd']);
        $this->assertSame([['label' => 'Pixel at the desk', 'usd' => 0.07], ['label' => 'Animate it', 'usd' => 0.595]], $result['steps']);
        $this->assertTrue($result['fits_budget']);
        $this->assertStringContainsString('click Go', $result['instruction']);

        // Priced with free price checks only; the animation of an image that
        // does not exist yet is priced with the placeholder.
        $estimates = $this->sent('estimate');
        $this->assertCount(2, $estimates);
        $this->assertSame(CreativeModels::PLACEHOLDER_IMAGE, $estimates[1][0]->data()['image_url']);
        $this->assertSame(10, $estimates[1][0]->data()['duration']);
        $this->assertCount(0, $this->sent('submit'));
        $this->assertSame(0, CreativeGeneration::count());
        $this->assertSame(0.0, (float) $this->api('GET', '/api/admin/creatives/budget')->json('budget.reserved_usd'));
    }

    public function test_propose_job_validates_each_step_like_a_generation(): void
    {
        $this->makeRef();
        $session = $this->sessionId();
        $grok = ['model' => 'grok-image', 'prompt' => 'Pixel waving at the camera.'];
        $bad = [
            'self' => ['title' => 'A', 'steps' => [['model' => 'kling-animate', 'prompt' => 'Move it.', 'source' => ['from_step' => 0]]]],
            'later' => ['title' => 'B', 'steps' => [$grok, ['model' => 'kling-animate', 'prompt' => 'Move it.', 'source' => ['from_step' => 2]]]],
            'video' => ['title' => 'C', 'steps' => [['model' => 'seedance-reference', 'prompt' => 'Pixel dances.'], ['model' => 'kling-animate', 'prompt' => 'Move it.', 'source' => ['from_step' => 0]]]],
            'nosource' => ['title' => 'D', 'steps' => [['model' => 'kling-animate', 'prompt' => 'Move it.']]],
            'two' => ['title' => 'E', 'steps' => [$grok, ['model' => 'kling-animate', 'prompt' => 'Move it.', 'source' => ['from_step' => 0, 'ref_id' => 1]]]],
            'stray' => ['title' => 'F', 'steps' => [['model' => 'soul-image', 'prompt' => 'Pixel.', 'source' => ['from_step' => 0]]]],
            'param' => ['title' => 'G', 'steps' => [['model' => 'soul-image', 'prompt' => 'Pixel.', 'params' => ['aspect_ratio' => 'square']]]],
            'prompt' => ['title' => 'H', 'steps' => [['model' => 'soul-image', 'prompt' => 'P']]],
            'many' => ['title' => 'I', 'steps' => array_fill(0, 13, $grok)],
            'plan' => ['title' => 'J', 'plan_id' => 999, 'steps' => [$grok]],
            'model' => ['title' => 'K', 'steps' => [['model' => 'dall-e', 'prompt' => 'Pixel.']]],
        ];
        $good = ['title' => 'Meet Pixel', 'steps' => [['model' => 'soul-image', 'prompt' => 'Pixel waving.', 'params' => ['resolution' => '1080p', 'batch_size' => 4]]]];
        $this->claude->replies = [FakeCreativeAgentModel::tools([...array_map(fn ($in) => ['propose_job', $in], array_values($bad)), ['propose_job', $good]])];

        $res = $this->turn($session, 'generate', 'Make things')->assertOk()->assertJsonPath('status', 'awaiting_approval');
        $results = $this->lastResults($session);
        foreach (array_keys($bad) as $i => $case) {
            $this->assertTrue($results[$i]['is_error'] ?? false, "Case {$case} was accepted.");
        }
        $this->assertStringContainsString('EARLIER step', $results[0]['content']);
        $this->assertStringContainsString('only an image can be animated', $results[2]['content']);
        $this->assertStringContainsString('exactly one image', $results[3]['content']);
        $this->assertStringContainsString('only the animate model', $results[5]['content']);
        $this->assertStringContainsString('Shape must be one of', $results[6]['content']);
        $this->assertStringContainsString('1 to 12 steps', $results[8]['content']);
        $this->assertStringContainsString('no plan #999', $results[9]['content']);
        $this->assertArrayNotHasKey('is_error', $results[count($bad)]);

        $job = CreativeAgentJob::sole();
        $this->assertSame('Meet Pixel', $job->title);
        $this->assertSame(['aspect_ratio' => '9:16', 'resolution' => '1080p', 'batch_size' => 4], $job->steps[0]['params']);
        $this->assertSame([$job->id], array_column($res->json('jobs'), 'id'));
        $this->assertCount(0, $this->sent('submit'));
    }

    /**
     * What the REAL model sends. The strict schemas make every property
     * required, so "nothing" arrives as a value ("any", 0, source type "none",
     * "" for an unset field) and params / plan changes as name–value lists.
     * Every other test speaks the contract's shapes; this one speaks the
     * schemas', so the two cannot drift apart again.
     */
    public function test_tool_inputs_in_the_strict_schema_shapes(): void
    {
        $this->makeRef();
        $plan = CreativePlan::create(['title' => 'Meet Pixel', 'platform' => 'tiktok', 'format' => 'video', 'planned_for' => '2026-11-20']);
        CreativePlan::create(['title' => 'Weekly recap', 'platform' => 'x', 'format' => 'image']);
        $g = new CreativeGeneration;
        $g->forceFill([
            'kind' => 'image', 'model' => 'soul-image', 'endpoint' => 'higgsfield-ai/soul/v2/standard',
            'prompt' => 'Pixel waving', 'full_prompt' => 'p', 'params' => [], 'used_character' => true,
            'status' => 'completed', 'outputs' => [['type' => 'image', 'url' => 'https://cdn.higgsfield.test/img/a.png']],
            'expires_at' => now()->addDays(3), 'estimated_usd' => 0.004, 'charged_usd' => 0.004,
        ])->save();

        $none = ['type' => 'none', 'id' => 0, 'index' => 0];
        $still = [
            'model' => 'grok-image', 'label' => '', 'prompt' => 'Pixel at a trading desk, a rising chart behind.',
            'params' => [['name' => 'aspect_ratio', 'value' => '9:16'], ['name' => 'quality', 'value' => 'low']],
            'use_character' => true, 'source' => $none,
        ];
        $calls = [
            ['list_plans', ['stage' => 'any', 'platform' => 'any', 'limit' => 15]],
            ['list_plans', ['stage' => 'any', 'platform' => 'x', 'limit' => 0]],
            ['list_generations', ['status' => 'any', 'limit' => 10]],
            ['get_week_results', ['days' => 0]],
            ['update_plan', ['id' => $plan->id, 'changes' => [['field' => 'hook', 'value' => 'Hi, I am Pixel'], ['field' => 'planned_for', 'value' => '']]]],
            ['update_plan', ['id' => $plan->id, 'changes' => [['field' => 'hook', 'value' => 'A'], ['field' => 'hook', 'value' => 'B']]]],
            ['propose_job', ['title' => 'Bad param', 'plan_id' => 0, 'steps' => [
                ['model' => 'soul-image', 'label' => '', 'prompt' => 'Pixel waving.', 'params' => [['name' => 'duration', 'value' => '10']], 'use_character' => true, 'source' => $none],
            ]]],
            ['propose_job', ['title' => 'Weekly recap video', 'plan_id' => 0, 'steps' => [
                $still,
                ['model' => 'kling-animate', 'label' => 'Animate it', 'prompt' => 'The chart climbs, Pixel nods.',
                    'params' => [['name' => 'duration', 'value' => '10'], ['name' => 'negative_prompt', 'value' => '']],
                    'use_character' => true, 'source' => ['type' => 'from_step', 'id' => 0, 'index' => 0]],
            ]]],
        ];
        $this->claude->replies = [FakeCreativeAgentModel::tools($calls)];
        $this->turn($this->sessionId(), 'generate', 'Plan and make the recap')->assertOk()->assertJsonPath('status', 'awaiting_approval');

        $results = $this->lastResults(CreativeAgentSession::sole()->id);
        $this->assertCount(count($calls), $results);
        $content = fn (int $i) => json_decode($results[$i]['content'], true);

        // "any" filters nothing; 0 means the default.
        $this->assertArrayNotHasKey('is_error', $results[0], $results[0]['content']);
        $this->assertCount(2, $content(0)['plans']);
        $this->assertSame(['Weekly recap'], array_column($content(1)['plans'], 'title'));
        $this->assertSame([$g->id], array_column($content(2)['generations'], 'id'));
        $this->assertSame(CreativeFacts::WINDOW_DAYS, $content(3)['performance']['days']);

        // update_plan's changes list sets those fields; "" clears one.
        $this->assertArrayNotHasKey('is_error', $results[4], $results[4]['content']);
        $plan->refresh();
        $this->assertSame('Hi, I am Pixel', $plan->hook);
        $this->assertNull($plan->planned_for);
        $this->assertTrue($results[5]['is_error']);
        $this->assertStringContainsString('Change hook once', $results[5]['content']);

        // A param the model does not take is refused, not dropped.
        $this->assertTrue($results[6]['is_error']);
        $this->assertStringContainsString('soul-image has no param duration', $results[6]['content']);

        // The recipe: plan_id 0 = none, source "none" = no source, the
        // from_step source and the params as the contract's shapes.
        $this->assertArrayNotHasKey('is_error', $results[7], $results[7]['content']);
        $job = CreativeAgentJob::sole();
        $this->assertNull($job->plan_id);
        $this->assertSame('Character image (with references)', $job->steps[0]['label']);
        $this->assertSame(['aspect_ratio' => '9:16', 'resolution' => '1k', 'quality' => 'low'], $job->steps[0]['params']);
        $this->assertNull($job->steps[0]['source']);
        $this->assertSame(['duration' => 10, 'negative_prompt' => null], $job->steps[1]['params']);
        $this->assertSame(['from_step' => 0], $job->steps[1]['source']);
        $this->assertSame(0.665, (float) $job->estimated_usd);
        $this->assertCount(0, $this->sent('submit'));
    }

    public function test_go_refuses_over_budget_and_expired_jobs_and_starts_step_zero(): void
    {
        [$session, $jobId] = $this->proposeRecipe();
        $approve = fn (?string $as = null) => $this->api('POST', "/api/admin/creatives/agent/jobs/{$jobId}/approve", [], $as);

        // Someone else's chat: not found.
        $approve($this->admin)->assertNotFound()->assertJsonPath('error_code', 'NOT_FOUND');

        // The whole total must fit what is left.
        $spent = (float) $this->api('GET', '/api/admin/creatives/budget')->json('budget.spent_usd');
        $this->setCap(round($spent + 0.66, 2));
        $approve()->assertStatus(422)
            ->assertJsonPath('error_code', 'BUDGET_EXCEEDED')
            ->assertJsonPath('job.status', 'proposed')
            ->assertJsonPath('budget.cap_usd', round($spent + 0.66, 2));
        $this->assertCount(0, $this->sent('submit'));
        $this->setCap(100);

        $res = $approve()->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('job.status', 'running')
            ->assertJsonPath('job.approved_by_name', 'Mara Marketing')
            ->assertJsonPath('job.expires_at', null)
            ->assertJsonPath('job.steps.0.status', 'running')
            ->assertJsonPath('job.steps.0.generation.status', 'queued')
            ->assertJsonPath('job.steps.0.generation.model', 'grok-image')
            ->assertJsonPath('job.steps.1.status', 'waiting')
            ->assertJsonPath('job.steps.1.generation', null)
            ->assertJsonPath('budget.reserved_usd', 0.07);
        $generation = CreativeGeneration::sole();
        $this->assertSame($jobId, $generation->agent_job_id);
        $this->assertSame($this->collaborator, $generation->created_by_uni_id);
        $this->assertStringContainsString('Character "Pixel"', $generation->full_prompt);
        $this->assertCount(1, $this->sent('submit'));
        $this->assertSame((int) $res->json('job.steps.0.generation.id'), $generation->id);

        $approve()->assertStatus(409)->assertJsonPath('error_code', 'JOB_NOT_PROPOSED');
        $this->assertCount(1, $this->sent('submit'));
    }

    public function test_a_proposal_expires_after_an_hour(): void
    {
        [$session, $jobId] = $this->proposeRecipe();
        $this->travel(61)->minutes();

        $this->api('POST', "/api/admin/creatives/agent/jobs/{$jobId}/approve")->assertStatus(409)
            ->assertJsonPath('error_code', 'JOB_EXPIRED')
            ->assertJsonPath('job.status', 'expired');
        $this->assertSame('expired', CreativeAgentJob::find($jobId)->status);
        $this->api('POST', "/api/admin/creatives/agent/jobs/{$jobId}/approve")->assertStatus(409)->assertJsonPath('error_code', 'JOB_NOT_PROPOSED');
        $this->assertCount(0, $this->sent('submit'));
    }

    public function test_a_job_advances_step_by_step_and_the_animation_takes_the_first_image(): void
    {
        [$session, $jobId] = $this->proposeRecipe();
        $this->api('POST', "/api/admin/creatives/agent/jobs/{$jobId}/approve")->assertOk();
        $stored = CreativeAgentMessage::count();

        // Still queued: the session read checks Higgsfield (after 4 s) and waits.
        $this->travel(5)->seconds();
        $this->api('GET', '/api/admin/creatives/agent/session?id='.$session)->assertOk()
            ->assertJsonPath('jobs.0.status', 'running')
            ->assertJsonPath('jobs.0.steps.1.status', 'waiting');
        $this->assertCount(1, $this->sent('submit'));

        // Step 0 completes (a video-free image list; the first IMAGE is the source).
        $this->statuses['req-1'] = [
            'status' => 'completed', 'request_id' => 'req-1',
            'images' => [['url' => 'https://cdn.higgsfield.test/img/pixel-desk.png'], ['url' => 'https://cdn.higgsfield.test/img/second.png']],
            'expires_at' => '2026-11-22T12:00:00Z',
        ];
        $this->travel(5)->seconds();
        $res = $this->api('GET', '/api/admin/creatives/agent/session?id='.$session)->assertOk()
            ->assertJsonPath('jobs.0.status', 'running')
            ->assertJsonPath('jobs.0.steps.0.status', 'completed')
            ->assertJsonPath('jobs.0.steps.0.generation.outputs.0.url', 'https://cdn.higgsfield.test/img/pixel-desk.png')
            ->assertJsonPath('jobs.0.steps.1.status', 'running')
            ->assertJsonPath('jobs.0.steps.1.generation.model', 'kling-animate');
        $submits = $this->sent('submit');
        $this->assertCount(2, $submits);
        $this->assertSame('https://cdn.higgsfield.test/img/pixel-desk.png', $submits[1][0]->data()['image_url']);
        $this->assertSame(10, $submits[1][0]->data()['duration']);
        $kling = CreativeGeneration::where('model', 'kling-animate')->sole();
        $this->assertSame($jobId, $kling->agent_job_id);

        // The last step completes through the minute poll: the job is done.
        $this->statuses['req-2'] = ['status' => 'completed', 'request_id' => 'req-2', 'video' => ['url' => 'https://cdn.higgsfield.test/vid/recap.mp4'], 'expires_at' => '2026-11-22T12:05:00Z'];
        $this->travel(1)->minute();
        $this->artisan('creatives:poll')->assertSuccessful();
        $job = CreativeAgentJob::find($jobId);
        $this->assertSame('completed', $job->status);
        $this->assertNotNull($job->finished_at);
        $this->api('GET', '/api/admin/creatives/agent/session?id='.$session)
            ->assertJsonPath('jobs.0.status', 'completed')
            ->assertJsonPath('jobs.0.steps.1.status', 'completed')
            ->assertJsonPath('jobs.0.steps.1.generation.outputs.0.type', 'video');
        // Nothing was appended to the Claude history when the job finished.
        $this->assertSame($stored, CreativeAgentMessage::count());
        $this->assertCount(2, $this->sent('submit'));
    }

    public function test_a_failed_step_fails_the_job_and_later_steps_never_start(): void
    {
        [$session, $jobId] = $this->proposeRecipe();
        $this->api('POST', "/api/admin/creatives/agent/jobs/{$jobId}/approve")->assertOk();
        $this->statuses['req-1'] = ['status' => 'nsfw', 'request_id' => 'req-1'];

        $this->travel(1)->minute();
        $this->artisan('creatives:poll')->assertSuccessful();

        $this->api('GET', '/api/admin/creatives/agent/session?id='.$session)->assertOk()
            ->assertJsonPath('jobs.0.status', 'failed')
            ->assertJsonPath('jobs.0.steps.0.status', 'failed')
            ->assertJsonPath('jobs.0.steps.1.status', 'canceled')
            ->assertJsonPath('jobs.0.steps.1.generation', null);
        $this->assertStringContainsString('moderation', (string) CreativeAgentJob::find($jobId)->steps[0]['error']);
        $this->assertSame(0.0, (float) CreativeGeneration::sole()->charged_usd);
        $this->travel(1)->minute();
        $this->artisan('creatives:poll')->assertSuccessful();
        $this->assertCount(1, $this->sent('submit'));
    }

    public function test_cancel(): void
    {
        [$session, $jobId] = $this->proposeRecipe();
        $cancel = fn (int $id, ?string $as = null) => $this->api('POST', "/api/admin/creatives/agent/jobs/{$id}/cancel", [], $as);

        $cancel($jobId, $this->admin)->assertNotFound();
        // A proposal is simply dropped.
        $cancel($jobId)->assertOk()->assertJsonPath('job.status', 'canceled')->assertJsonPath('job.steps.0.status', 'canceled');
        $this->api('POST', "/api/admin/creatives/agent/jobs/{$jobId}/approve")->assertStatus(409)->assertJsonPath('error_code', 'JOB_NOT_PROPOSED');
        $cancel($jobId)->assertStatus(409)->assertJsonPath('error_code', 'NOT_CANCELLABLE');

        // A running job: its queued generation is cancelled at Higgsfield (refunded).
        $this->claude->replies = [FakeCreativeAgentModel::tools([['propose_job', $this->recipeJob()]])];
        $second = (int) $this->turn($session, 'generate', 'Again please')->assertOk()->json('jobs.0.id');
        $this->api('POST', "/api/admin/creatives/agent/jobs/{$second}/approve")->assertOk();
        $cancel($second)->assertOk()
            ->assertJsonPath('job.status', 'canceled')
            ->assertJsonPath('job.steps.0.status', 'canceled')
            ->assertJsonPath('job.steps.1.status', 'canceled');
        $this->assertCount(1, $this->sent('cancel'));
        $this->assertSame('canceled', CreativeGeneration::sole()->status);
        $this->assertSame(0.0, (float) CreativeGeneration::sole()->charged_usd);

        // Nothing more ever starts for it.
        $this->travel(1)->minute();
        $this->artisan('creatives:poll')->assertSuccessful();
        $this->assertCount(1, $this->sent('submit'));
    }

    // ---- privacy: the post guard (Creatives v2) ---------------------------------------

    /**
     * Since 2026-11-12 the model may READ private data (query_data, owner's
     * decision); what it POSTS may not carry it. A money amount or a
     * customer's name in a plan or a video is refused server-side and nothing
     * is saved.
     */
    public function test_the_post_guard_refuses_money_and_customer_names_and_saves_nothing(): void
    {
        $this->makeUser(['name' => 'Juan Dela Cruz']);
        $plan = CreativePlan::create(['title' => 'Weekly recap', 'platform' => 'tiktok', 'format' => 'video']);
        $scene = fn (array $over = []) => array_merge([
            'kind' => 'clip', 'label' => 'Screens', 'prompt' => 'Slow push-in on glowing trading screens', 'seconds' => 5, 'engine' => 'kling',
            'with_pixel' => false, 'caption' => '', 'template_key' => 'none', 'template_title' => '', 'template_value' => '', 'template_subtitle' => '',
        ], $over);
        $video = fn (array $scenes, string $title = 'Recap') => [
            'title' => $title, 'plan_id' => 0, 'style' => '', 'platform' => 'tiktok', 'aspect' => '9:16',
            'caption_style' => 'bold', 'music' => 'none', 'pixel_look' => false, 'scenes' => $scenes,
        ];
        $calls = [
            ['create_plan', ['title' => 'Big week', 'caption' => 'We made $120 for our users this week']],
            ['create_plan', ['title' => 'Shout-out', 'hook' => 'Thanks to juan dela cruz for trading with us']],
            ['update_plan', ['id' => $plan->id, 'changes' => [['field' => 'script', 'value' => '1. Juan Dela Cruz smiles at the camera']]]],
            ['create_video', $video([$scene(['caption' => 'Up 300 USDT'])])],
            ['create_video', $video([$scene()], 'Juan Dela Cruz recap')],
            ['create_video', $video([$scene(['kind' => 'template', 'prompt' => '', 'seconds' => 3, 'engine' => 'none', 'template_key' => 'clean-stat', 'template_title' => 'Profit', 'template_value' => '$5k'])])],
            ['create_plan', ['title' => 'Clean one', 'hook' => 'Pixel explains trade-only keys']],
        ];
        $this->claude->replies = [FakeCreativeAgentModel::tools($calls), FakeCreativeAgentModel::text('Done.')];
        $this->turn($this->sessionId(), 'generate', 'Draft these')->assertOk()->assertJsonPath('status', 'continue');

        $results = $this->lastResults(CreativeAgentSession::sole()->id);
        foreach (range(0, 5) as $i) {
            $this->assertTrue($results[$i]['is_error'] ?? false, "Call {$i} was accepted: {$results[$i]['content']}");
            $this->assertStringStartsWith('PRIVATE_IN_POST:', $results[$i]['content']);
        }
        $this->assertStringContainsString('money amount ($120)', $results[0]['content']);
        $this->assertStringContainsString('names a customer (juan dela cruz)', $results[1]['content']);
        $this->assertStringContainsString('names a customer (Juan Dela Cruz)', $results[2]['content']);
        $this->assertStringContainsString('300 USDT', $results[3]['content']);
        $this->assertStringContainsString('the title names a customer', $results[4]['content']);
        $this->assertStringContainsString('$5k', $results[5]['content']);
        $this->assertArrayNotHasKey('is_error', $results[6]);

        // Nothing private was saved: one clean plan, the old plan untouched, no video, no job.
        $this->assertSame(['Weekly recap', 'Clean one'], CreativePlan::orderBy('id')->pluck('title')->all());
        $this->assertNull($plan->fresh()->script);
        $this->assertSame(0, CreativeVideo::count());
        $this->assertSame(0, CreativeVideoScene::count());
        $this->assertSame(0, CreativeAgentJob::count());
        $this->assertCount(0, $this->sent('estimate'));
    }

    /**
     * query_data runs the AI connector's read-only tools: names and dollar
     * figures may come back (owner's decision), credentials and contact
     * details never — whatever the tool. The list is pinned to AiTools.
     */
    public function test_query_data_answers_every_data_tool_with_no_credential_or_email(): void
    {
        $this->assertSame(CreativeAgentPrompt::QUERY_TOOLS, array_column(app(AiTools::class)->list(), 'name'));

        $secretKey = 'SEEKRIT-secret-key-value-123';
        $customer = $this->makeUser(['name' => 'Juan Dela Cruz', 'email' => 'juan@customer.test']);
        $master = $this->makeUser(['type' => 'master', 'name' => 'House Master']);
        $custAcct = $this->makeAccount($customer, ['name' => 'Juan Futures', 'balance' => 2500, 'initial_deposit' => 2000, 'secret_key' => $secretKey]);
        $this->makeAccount($master, ['name' => 'master main', 'secret_key' => $secretKey]);
        $apiKey = DB::table('binance_accounts')->find($custAcct)->api_key;
        $passwordHash = DB::table('user_credentials')->where('uni_id', $customer)->value('password');
        DB::table('binance_pastpositions')->insert([
            'api_key' => $apiKey, 'uni_id' => $customer, 'symbol' => 'LTCUSDT', 'position_side' => 'LONG', 'position_amt' => 1.5,
            'entry_price' => 90, 'exit_price' => 95, 'realized_pnl' => 30.0, 'exchange_fee' => 0.14, 'side' => 'SELL', 'order_id' => 1,
            'closed_at' => now()->subDay()->toDateTimeString(), 'created_at' => now(),
        ]);
        $log = TradeLog::create([
            'exchange' => 'binance', 'feed' => 'main', 'action' => 'BUY', 'ticker' => 'LTCUSDT', 'success' => true, 'price' => 90,
            'category' => 'signal', 'target_count' => 1, 'filled' => 1, 'skipped' => 0, 'failed' => 0, 'ts' => now()->subDay()->toDateTimeString(),
            'details' => [['account' => 'Juan Futures', 'uni_id' => $customer, 'status' => 'filled', 'quantity' => 1.5]],
        ]);
        Invoice::create([
            'user_id' => $customer, 'account_id' => $custAcct, 'exchange' => 'binance', 'api_key' => $apiKey,
            'month_year' => '2026-10', 'total_fee' => 42.5, 'status' => 'pending', 'due_date' => '2026-11-04',
        ]);

        $arguments = [
            'search_signals' => ['include_accounts' => true], 'get_signal' => ['id' => $log->id],
            'accounts' => ['status' => 'all'], 'invoices' => ['status' => 'all'],
        ];
        $calls = array_map(fn (string $tool) => ['query_data', ['tool' => $tool, 'arguments' => json_encode((object) ($arguments[$tool] ?? []))]], CreativeAgentPrompt::QUERY_TOOLS);
        $calls[] = ['query_data', ['tool' => 'users', 'arguments' => 'not json']];
        $calls[] = ['query_data', ['tool' => 'drop_tables', 'arguments' => '{}']];
        $this->claude->replies = [FakeCreativeAgentModel::tools($calls), FakeCreativeAgentModel::text('Done.')];
        $res = $this->turn($this->sessionId(), 'ask', 'Look at everything')->assertOk()->assertJsonPath('status', 'continue');

        $results = $this->lastResults(CreativeAgentSession::sole()->id);
        $this->assertCount(count($calls), $results);
        foreach (CreativeAgentPrompt::QUERY_TOOLS as $i => $tool) {
            $this->assertArrayNotHasKey('is_error', $results[$i], "{$tool} failed: {$results[$i]['content']}");
            $text = $results[$i]['content'];
            $this->assertSame($tool, json_decode($text, true)['tool']);
            foreach ([$apiKey, $secretKey, 'juan@customer.test', $passwordHash] as $secret) {
                $this->assertStringNotContainsString($secret, $text, "{$tool} leaked a credential");
            }
            $this->assertDoesNotMatchRegularExpression('/"(api_key|secret_key|email|password)"/', $text, "{$tool} carries a forbidden field");
        }
        // Names may come back (the owner's decision); the answer says they are private.
        $this->assertStringContainsString('Juan Dela Cruz', $results[array_search('users', CreativeAgentPrompt::QUERY_TOOLS, true)]['content']);
        $this->assertStringContainsString('Never put a money amount', $results[0]['content']);
        $n = count(CreativeAgentPrompt::QUERY_TOOLS);
        $this->assertTrue($results[$n]['is_error']);
        $this->assertStringContainsString('JSON object', $results[$n]['content']);
        $this->assertTrue($results[$n + 1]['is_error']);
        $this->assertSame('Looked up closed trades', collect($res->json('messages'))->firstWhere('activity.label', 'Looked up closed trades')['activity']['label']);
    }

    // ---- new tools, in the strict schemas' shapes ----------------------------------------

    /**
     * What the REAL model sends to the v2 tools: every property present,
     * "nothing" as a value ("any", "", 0, "none"). Exactly the shapes
     * CreativeAgentPrompt::tools() declares.
     */
    public function test_v2_tool_inputs_in_the_strict_schema_shapes(): void
    {
        $this->publishWeek();
        $this->makeRef();
        $plan = CreativePlan::create(['title' => 'Arcade week', 'platform' => 'tiktok', 'format' => 'video']);
        $clip = fn (array $over = []) => array_merge([
            'kind' => 'clip', 'label' => 'Arcade city', 'prompt' => 'A pixel-art city at night, 8-bit chiptune music and arcade sound effects',
            'seconds' => 6, 'engine' => 'seedance', 'with_pixel' => false, 'caption' => 'Level up', 'template_key' => 'none',
            'template_title' => '', 'template_value' => '', 'template_subtitle' => '',
        ], $over);
        $card = [
            'kind' => 'template', 'label' => '', 'prompt' => '', 'seconds' => 3, 'engine' => 'none', 'with_pixel' => false, 'caption' => '',
            'template_key' => 'pixel-scoreboard', 'template_title' => 'HIGH SCORE', 'template_value' => '+6.03%', 'template_subtitle' => '',
        ];
        $calls = [
            ['get_week_results', ['days' => 0, 'exchange' => 'any', 'coin' => '']],
            ['get_week_results', ['days' => 7, 'exchange' => 'binance', 'coin' => 'LTC']],
            ['get_week_results', ['days' => 7, 'exchange' => 'mexc', 'coin' => '']],
            ['get_week_results', ['days' => 7, 'exchange' => 'any', 'coin' => '$$']],
            ['get_style', ['slug' => 'pixel-arcade']],
            ['get_style', ['slug' => 'nope']],
            ['list_videos', ['limit' => 0]],
            ['create_video', [
                'title' => 'Arcade recap', 'plan_id' => $plan->id, 'style' => 'pixel-arcade', 'platform' => 'tiktok', 'aspect' => '9:16',
                'caption_style' => 'pixel', 'music' => 'arcade-upbeat', 'pixel_look' => true,
                'scenes' => [
                    $clip(['engine' => 'none', 'caption' => 'Player 1 ready']),
                    $clip(['label' => 'Pixel cameo', 'prompt' => 'Pixel waves from an arcade cabinet', 'seconds' => 5, 'engine' => 'kling', 'with_pixel' => true, 'caption' => '']),
                    $card,
                ],
            ]],
            ['create_video', [
                'title' => 'Bad', 'plan_id' => 0, 'style' => 'nope', 'platform' => 'tiktok', 'aspect' => '9:16',
                'caption_style' => 'bold', 'music' => 'none', 'pixel_look' => false, 'scenes' => [$clip()],
            ]],
        ];
        $this->claude->replies = [FakeCreativeAgentModel::tools($calls)];
        $res = $this->turn($this->sessionId(), 'generate', 'Make the arcade recap')->assertOk()->assertJsonPath('status', 'awaiting_approval');
        $session = CreativeAgentSession::sole();
        $results = $this->lastResults($session->id);
        $this->assertCount(count($calls), $results);
        $content = fn (int $i) => json_decode($results[$i]['content'], true);
        foreach ([0, 1, 2, 4, 6, 7] as $i) {
            $this->assertArrayNotHasKey('is_error', $results[$i], "Call {$i} failed: {$results[$i]['content']}");
        }

        // get_week_results: "any" / "" = everything; daily figures; per-coin share of capital.
        $week = $content(0)['performance'];
        $this->assertSame([7, 6.03, 'any', null], [$week['days'], $week['return_pct'], $week['exchange'], $week['coin']]);
        $this->assertEquals([
            ['date' => '2026-11-10', 'pct' => 2.0, 'trades' => 1],
            ['date' => '2026-11-11', 'pct' => -1.0, 'trades' => 1],
            ['date' => '2026-11-12', 'pct' => 5.0, 'trades' => 1],
        ], $week['daily']);
        $this->assertEquals(['symbol' => 'LTCUSDT', 'share_of_capital_pct' => 7.0], $week['top_coins'][0]);
        $this->assertArrayNotHasKey('share_pct', $week['top_coins'][0]);
        // One exchange, one coin ("LTC" is LTCUSDT): only its trades, over the whole capital.
        $ltc = $content(1)['performance'];
        // The coin's record walks the deposits plus that coin's own P&L (the
        // public ?symbols= rule): 10 Nov 20.00 / 1000 = 2%, 12 Nov 50.49 / 1020
        // = 4.95%; chained 1.02 × 1.0495 = 1.07049 → 7.05 — and the same
        // figure the public route publishes for that slice.
        $this->assertSame(['binance', 'LTCUSDT', 2, 7.05], [$ltc['exchange'], $ltc['coin'], $ltc['trading_days'], $ltc['return_pct']]);
        $this->assertEquals([2.0, 4.95], array_column($ltc['daily'], 'pct'));
        $published = $this->getJson('/api/public/track-record/binance?symbols=LTCUSDT')->assertOk()->json('series');
        $this->assertEquals(array_map(fn ($p) => round($p['pct'], 2), $published), array_column($ltc['daily'], 'pct'));
        $this->assertSame(['2026-11-10', '2026-11-12'], array_column($ltc['daily'], 'date'));
        $this->assertSame('Read the public results: LTCUSDT on Binance, 7 days', $res->json('messages.2.activity.label'));
        // No master on MEXC: nothing published there.
        $this->assertFalse($content(2)['performance']['available']);
        $this->assertSame([], $content(2)['performance']['daily']);
        $this->assertTrue($results[3]['is_error']);
        $this->assertStringContainsString('ticker', $results[3]['content']);

        // get_style: the full style; an unknown slug lists the valid ones.
        $style = $content(4)['style'];
        $this->assertSame(['pixel-arcade', 'seedance', 'arcade-upbeat', true], [$style['slug'], $style['default_engine'], $style['music_default'], $style['pixel_look']]);
        $this->assertStringContainsString('HIGH SCORE', $style['markdown']);
        $this->assertTrue($results[5]['is_error']);
        $this->assertStringContainsString('data-recap, pixel-arcade, pixel-storytime, cinematic-broll, fast-cut', $results[5]['content']);
        $this->assertSame([], $content(6)['videos']);

        // create_video: the video, its scenes in order, ONE job with the session and the video set.
        $video = CreativeVideo::sole();
        $made = $content(7);
        $this->assertSame([$video->id, "/admin/creatives/editor/{$video->id}"], [$made['video_id'], $made['editor_path']]);
        $this->assertSame([$plan->id, 'tiktok', '9:16', 'pixel'], [$video->plan_id, $video->platform, $video->aspect, $video->timeline['caption_style']]);
        $this->assertSame('pixel-arcade', CreativeStyle::find($video->style_id)->slug);
        $this->assertSame(['source' => 'chiptune', 'preset' => 'arcade-upbeat'], array_intersect_key($video->timeline['music'], ['source' => 1, 'preset' => 1]));
        $this->assertSame(['pixel_size' => 10, 'scanlines' => true], $video->timeline['look']);
        $scenes = CreativeVideoScene::query()->where('video_id', $video->id)->orderBy('position')->get();
        $this->assertSame(['clip', 'clip', 'template'], $scenes->pluck('kind')->all());
        // "none" took the style's default engine (seedance).
        $this->assertSame(['seedance', 'kling', null], $scenes->pluck('engine')->all());
        $this->assertSame([false, true, false], $scenes->pluck('with_character')->all());
        $this->assertSame(['planned', 'planned', 'ready'], $scenes->pluck('status')->all());
        $this->assertSame(['key' => 'pixel-scoreboard', 'title' => 'HIGH SCORE', 'value' => '+6.03%', 'subtitle' => ''], $scenes[2]->template);
        $this->assertSame(['Player 1 ready', 'top', 'hook', 0, 6000], [$video->timeline['captions'][0]['text'], $video->timeline['captions'][0]['position'], $video->timeline['captions'][0]['style'], $video->timeline['captions'][0]['start_ms'], $video->timeline['captions'][0]['end_ms']]);

        $job = CreativeAgentJob::sole();
        $this->assertSame([$session->id, $video->id, $this->collaborator, 'proposed'], [$job->session_id, $job->video_id, $job->owner_uni_id, $job->status]);
        $this->assertSame(['seedance-text-video', 'grok-image', 'kling-animate'], array_column($job->steps, 'model'));
        $this->assertSame($made['job_id'], $job->id);
        // This file's fake prices every Seedance step 0.004 and Kling 0.595: 0.004 + 0.07 + 0.595.
        $this->assertSame(0.669, $made['usd']);
        $this->assertCount(0, $this->sent('submit'));
        // The chips: the video, then the job card.
        $kinds = array_column($res->json('messages'), 'kind');
        $this->assertContains('video', $kinds);
        $videoChip = collect($res->json('messages'))->firstWhere('kind', 'video');
        $this->assertSame([$video->id, 'Arcade recap'], [$videoChip['video_id'], $videoChip['text']]);
        $res->assertJsonPath('jobs.0.id', $job->id)->assertJsonPath('jobs.0.video_id', $video->id)->assertJsonPath('jobs.0.editor_path', "/admin/creatives/editor/{$video->id}");
        $this->assertTrue($results[8]['is_error']);
        $this->assertStringContainsString('There is no style "nope"', $results[8]['content']);
        $this->assertSame(1, CreativeVideo::count(), 'A refused create_video leaves no video behind.');

        // The next message: get_video, list_videos and add_scenes (append, then replace).
        $this->travel(1)->minute();
        $calls = [
            ['get_video', ['id' => $video->id]],
            ['list_videos', ['limit' => 5]],
            ['add_scenes', ['video_id' => $video->id, 'replace_scene_id' => 0, 'scenes' => [[
                'kind' => 'template', 'label' => '', 'prompt' => '', 'seconds' => 2, 'engine' => 'none', 'with_pixel' => false, 'caption' => '',
                'template_key' => 'disclaimer', 'template_title' => '', 'template_value' => '', 'template_subtitle' => 'Past performance does not guarantee future results.',
            ]]]],
            ['add_scenes', ['video_id' => $video->id, 'replace_scene_id' => $scenes[1]->id, 'scenes' => [$clip(['label' => 'Coins', 'prompt' => 'Spinning pixel coins', 'seconds' => 4, 'caption' => ''])]]],
            ['get_video', ['id' => 999]],
        ];
        $this->claude->replies = [FakeCreativeAgentModel::tools($calls)];
        $this->turn($session->id, 'generate', 'Add the disclaimer and swap the Pixel scene')->assertOk()->assertJsonPath('status', 'awaiting_approval');
        $results = $this->lastResults($session->id);
        $content = fn (int $i) => json_decode($results[$i]['content'], true);
        foreach ([0, 1, 2, 3] as $i) {
            $this->assertArrayNotHasKey('is_error', $results[$i], "Call {$i} failed: {$results[$i]['content']}");
        }
        $got = $content(0)['video'];
        $this->assertEquals([3, 14.0, 16.0], [count($got['scenes']), $got['seconds_used'], $got['seconds_left']]);
        $this->assertSame(['planned', 'planned', 'ready'], array_column($got['scenes'], 'status'));
        $this->assertSame('chiptune: arcade-upbeat', $got['music']);
        $this->assertSame('Player 1 ready', $got['captions'][0]['text']);
        $this->assertArrayNotHasKey('created_by_name', $got);
        $this->assertSame([[$video->id, 'Arcade recap', 3, 1, 'draft']], array_map(fn ($v) => [$v['id'], $v['title'], $v['scenes_total'], $v['scenes_ready'], $v['status']], $content(1)['videos']));
        $this->assertNull($content(2)['job_id'], 'A template scene needs no job.');
        $this->assertNotNull($content(3)['job_id']);
        $scenes = CreativeVideoScene::query()->where('video_id', $video->id)->orderBy('position')->get();
        $this->assertSame([4, 'seedance', 'Coins', 1], [$scenes[1]->seconds, $scenes[1]->engine, $scenes[1]->label, $scenes[1]->position]);
        $this->assertSame('disclaimer', $scenes[3]->template['key']);
        $this->assertTrue($results[4]['is_error']);

        // Ask mode refuses the Generate tools, and list/get still work.
        $this->claude->replies = [FakeCreativeAgentModel::tools([
            ['create_video', ['title' => 'x', 'plan_id' => 0, 'style' => '', 'platform' => 'x', 'aspect' => '16:9', 'caption_style' => 'clean', 'music' => 'none', 'pixel_look' => false, 'scenes' => [$clip()]]],
            ['add_scenes', ['video_id' => $video->id, 'replace_scene_id' => 0, 'scenes' => [$clip()]]],
            ['list_videos', ['limit' => 0]],
        ])];
        $this->turn($session->id, 'ask', 'And in Ask?')->assertOk();
        $results = $this->lastResults($session->id);
        $this->assertSame('Ask mode: switch to Generate to do this.', $results[0]['content']);
        $this->assertSame('Ask mode: switch to Generate to do this.', $results[1]['content']);
        $this->assertArrayNotHasKey('is_error', $results[2]);
        $this->assertSame(1, CreativeVideo::count());
    }

    public function test_ask_choices_shows_buttons_and_ends_the_turn(): void
    {
        $session = $this->sessionId();
        $this->claude->replies = [
            FakeCreativeAgentModel::tools([['ask_choices', [
                'question' => 'Which platform is it for?',
                'options' => [['label' => 'TikTok', 'value' => 'TikTok'], ['label' => 'X', 'value' => 'X (Twitter)']],
                'allow_other' => false,
            ]]], 'Happy to.'),
            FakeCreativeAgentModel::text('TikTok it is. Which shape?'),
        ];

        $res = $this->turn($session, 'generate', 'Make me a video about this week')->assertOk()->assertJsonPath('status', 'done');
        $this->assertSame(['user', 'assistant', 'choices'], array_column($res->json('messages'), 'kind'));
        $this->assertSame([
            'question' => 'Which platform is it for?',
            'options' => [['label' => 'TikTok', 'value' => 'TikTok'], ['label' => 'X', 'value' => 'X (Twitter)']],
            'allow_other' => false,
        ], $res->json('messages.2.choices'));
        $this->assertStringContainsString('Stop here', $this->lastResults($session)[0]['content']);
        $this->assertCount(1, $this->claude->calls);
        // Nothing is owed until the person picks: a wordless step... would call the model (a tool result is pending),
        // so the client stops at `done`; the session read shows the buttons.
        $this->api('GET', '/api/admin/creatives/agent/session?id='.$session)->assertOk()
            ->assertJsonPath('messages.2.kind', 'choices')
            ->assertJsonPath('messages.2.choices.question', 'Which platform is it for?');

        // The pick is the next message: one user message carrying the result, the [Context] and the value.
        $this->turn($session, 'generate', 'TikTok')->assertOk()->assertJsonPath('status', 'done')->assertJsonPath('messages.1.text', 'TikTok it is. Which shape?');
        $last = $this->claude->calls[1]['decoded'];
        $this->assertSame(['user', 'assistant', 'user'], array_column($last, 'role'));
        $this->assertSame(['tool_result', 'text', 'text'], array_column($last[2]['content'], 'type'));
        $this->assertSame('TikTok', $last[2]['content'][2]['text']);

        // Bad buttons are an error the model can fix, and do not end the turn.
        $this->claude->replies = [FakeCreativeAgentModel::tools([['ask_choices', ['question' => 'Pick', 'options' => [['label' => 'Only one', 'value' => 'one']], 'allow_other' => true]]])];
        $this->turn($session, 'ask', 'Again')->assertOk()->assertJsonPath('status', 'continue');
        $this->assertTrue($this->lastResults($session)[0]['is_error']);
    }

    // ---- the playbook, the styles and the Context view --------------------------------

    public function test_styles_and_the_playbook_reach_the_context_and_are_sent_once(): void
    {
        $session = $this->sessionId();
        $this->claude->replies = [FakeCreativeAgentModel::text('Got it.')];
        $this->turn($session, 'ask', 'Hi')->assertOk();
        $first = $this->claude->calls[0]['decoded'][0]['content'][0]['text'];
        $this->assertMatchesRegularExpression('/PLAYBOOK \(version [0-9a-f]{10}\)/', $first);
        $this->assertStringContainsString('## The asking routine', $first);
        $this->assertLessThan(strpos($first, 'PLAYBOOK ('), strpos($first, 'BRIEF ('), 'The playbook follows the brief.');
        $state = json_decode(explode("\n", $first)[1], true);
        $this->assertSame(['data-recap', 'pixel-arcade', 'pixel-storytime', 'cinematic-broll', 'fast-cut'], array_column($state['styles'], 'slug'));
        $this->assertSame(['slug', 'name', 'summary', 'default_platform', 'default_aspect', 'pixel_default', 'default_engine'], array_keys($state['styles'][0]));
        $this->assertSame([], $state['videos_in_progress']);
        $this->assertSame(['brief', 'playbook', 'memory'], array_keys(CreativeAgentSession::find($session)->sent_context));

        // Unchanged: not repeated. A disabled style leaves the list; a new video is "in progress".
        CreativeStyle::query()->where('slug', 'fast-cut')->update(['enabled' => false]);
        $this->api('POST', '/api/admin/creatives/videos', ['title' => 'Recap draft', 'platform' => 'tiktok', 'aspect' => '9:16'])->assertCreated();
        $this->turn($session, 'ask', 'Again')->assertOk();
        $second = $this->claude->calls[1]['decoded'][2]['content'][0]['text'];
        $this->assertStringContainsString('PLAYBOOK: unchanged', $second);
        $this->assertStringNotContainsString('## The asking routine', $second);
        $state = json_decode(explode("\n", $second)[1], true);
        $this->assertNotContains('fast-cut', array_column($state['styles'], 'slug'));
        $this->assertSame('Recap draft', $state['videos_in_progress'][0]['title']);
        $this->assertSame([0, 0], [$state['videos_in_progress'][0]['scenes_ready'], $state['videos_in_progress'][0]['scenes_total']]);

        // Edited: the new version in full.
        $this->api('PUT', '/api/admin/creatives/playbook', ['markdown' => "# Playbook\n\nNo more than four scenes."])->assertOk();
        $this->turn($session, 'ask', 'And now?')->assertOk();
        $third = $this->claude->calls[2]['decoded'][4]['content'][0]['text'];
        $this->assertStringContainsString('No more than four scenes.', $third);
        $this->assertStringContainsString('BRIEF: unchanged', $third);
    }

    public function test_the_context_view_shows_exactly_what_the_model_is_told_and_writes_nothing(): void
    {
        $session = $this->sessionId();
        $this->claude->replies = [FakeCreativeAgentModel::text('Hi.')];
        $this->turn($session, 'ask', 'Hi')->assertOk();
        $sent = CreativeAgentSession::find($session)->sent_context;
        $sessions = CreativeAgentSession::count();

        $view = $this->api('GET', '/api/admin/creatives/agent/context?session_id='.$session)->assertOk()->assertJsonPath('success', true)->json('context');
        $this->assertSame(CreativeAgentPrompt::SYSTEM, $view['system']);
        $this->assertSame($this->claude->calls[0]['system'], $view['system']);
        $tools = json_decode($this->claude->calls[0]['tools'], true);
        $this->assertSame(array_column($tools, 'name'), array_column($view['tools'], 'name'));
        $this->assertSame(array_column($tools, 'description'), array_column($view['tools'], 'description'));
        $this->assertSame(array_column($tools, 'inputSchema'), array_column($view['tools'], 'input_schema'));
        // The documents as the model reads them, in full in the block even though this chat already had them.
        $this->assertStringContainsString('## The asking routine', $view['playbook']['markdown']);
        $this->assertStringContainsString('## Who we are', $view['brief']['markdown']);
        $this->assertSame('(No memory notes yet.)', $view['memory']['text']);
        $this->assertSame($sent['playbook'], $view['playbook']['version']);
        $this->assertSame($sent['brief'], $view['brief']['version']);
        $this->assertSame(['slug', 'name', 'summary'], array_keys($view['styles'][0]));
        $this->assertStringContainsString("PLAYBOOK (version {$sent['playbook']})", $view['context_block']);
        $this->assertStringContainsString("BRIEF (version {$sent['brief']})", $view['context_block']);
        $this->assertSame(
            app(CreativeAgentContext::class)->build(CreativeAgentSession::findOrFail($session), 'ask', full: true)['text'],
            $view['context_block'],
        );
        // Read-only: the chat's sent versions are untouched, no chat was opened.
        $this->assertSame($sent, CreativeAgentSession::find($session)->sent_context);
        $this->api('GET', '/api/admin/creatives/agent/context')->assertOk()->assertJsonPath('context.system', CreativeAgentPrompt::SYSTEM);
        $this->assertSame($sessions, CreativeAgentSession::count());
        $this->api('GET', '/api/admin/creatives/agent/context?session_id='.$session, [], $this->admin)->assertNotFound();
        $this->api('GET', '/api/admin/creatives/agent/context', [], $this->user)->assertForbidden();
    }

    public function test_a_chat_from_before_the_new_tools_is_replaced_by_a_fresh_one_with_a_notice(): void
    {
        $old = CreativeAgentSession::create(['uni_id' => $this->collaborator, 'user_name' => 'Mara Marketing', 'last_mode' => 'generate']);
        $old->forceFill(['tools_version' => 1])->save();
        $this->assertFalse($old->fresh()->hasCurrentTools());

        $res = $this->api('GET', '/api/admin/creatives/agent/session?id='.$old->id)->assertOk()
            ->assertJsonPath('notice', 'new_tools')
            ->assertJsonPath('messages', []);
        $fresh = (string) $res->json('session.id');
        $this->assertNotSame($old->id, $fresh);
        $this->assertSame(CreativeAgentPrompt::TOOLS_VERSION, CreativeAgentSession::find($fresh)->tools_version);

        // Once: the next read answers the fresh chat without a notice.
        $again = $this->api('GET', '/api/admin/creatives/agent/session')->assertOk()->assertJsonPath('session.id', $fresh)->json();
        $this->assertArrayNotHasKey('notice', $again);
        $this->api('GET', '/api/admin/creatives/agent/session?id='.$fresh)->assertOk()->assertJsonMissingPath('notice');

        // The old chat cannot take a turn; "New chat" is stamped with the current version.
        $this->turn($old->id, 'ask', 'Still there?')->assertNotFound()->assertJsonPath('error_code', 'NOT_FOUND');
        $this->assertCount(0, $this->claude->calls);
        $new = (string) $this->api('POST', '/api/admin/creatives/agent/sessions')->assertOk()->json('session.id');
        $this->assertSame(CreativeAgentPrompt::TOOLS_VERSION, CreativeAgentSession::find($new)->tools_version);
    }

    // ---- the real client, on a mocked transport -------------------------------------

    public function test_the_real_agent_sends_the_documented_request_and_replays_the_reply_unchanged(): void
    {
        config(['services.anthropic.key' => 'sk-ant-test-not-a-real-key', 'services.anthropic.model' => 'claude-opus-5-5', 'services.anthropic.effort' => 'medium']);
        $first = [
            'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5',
            'content' => [
                ['type' => 'thinking', 'thinking' => '', 'signature' => 'Sig/With+Slashes=='],
                ['type' => 'text', 'text' => 'Let me check — “quotes” é.'],
                ['type' => 'tool_use', 'id' => 'toolu_real1', 'name' => 'get_budget', 'input' => new \stdClass, 'caller' => ['type' => 'direct']],
            ],
            'stop_reason' => 'tool_use', 'stop_sequence' => null,
            'usage' => ['input_tokens' => 1000, 'output_tokens' => 100, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0],
        ];
        $second = ['id' => 'msg_2', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5',
            'content' => [['type' => 'text', 'text' => 'Plenty left.']], 'stop_reason' => 'end_turn', 'stop_sequence' => null,
            'usage' => ['input_tokens' => 50, 'output_tokens' => 10, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 1200]];
        $history = new \ArrayObject;
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($first)),
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($second)),
        ]);
        // The real client and SDK, on a transport that answers from memory.
        $client = new class($mock, $history) extends ClaudeClient
        {
            public function __construct(private MockHandler $mock, private \ArrayObject $history) {}

            protected function transport(): GuzzleClient
            {
                $stack = HandlerStack::create($this->mock);
                $stack->push(Middleware::history($this->history));

                return new GuzzleClient(['handler' => $stack]);
            }
        };
        $this->app->instance(CreativeAgentModel::class, new ClaudeAgent($client));

        $session = $this->sessionId();
        $this->turn($session, 'ask', 'Budget?')->assertOk()->assertJsonPath('status', 'continue')->assertJsonPath('cost_usd', 0.006);
        $this->turn($session, 'ask')->assertOk()->assertJsonPath('status', 'done')->assertJsonPath('messages.0.text', 'Plenty left.');

        $this->assertCount(2, $history);
        [$r1, $r2] = array_map(fn ($h) => json_decode((string) $h['request']->getBody(), true), $history->getArrayCopy());
        $this->assertSame('https://api.anthropic.com/v1/messages', (string) $history[0]['request']->getUri());
        foreach ([$r1, $r2] as $body) {
            $this->assertSame('claude-opus-5-5', $body['model']);
            $this->assertSame(['effort' => 'medium'], $body['output_config']);
            $this->assertSame(['type' => 'ephemeral'], $body['cache_control']);
            $this->assertSame(['type' => 'ephemeral'], $body['system'][0]['cache_control']);
            $this->assertSame(CreativeAgentPrompt::SYSTEM, $body['system'][0]['text']);
            $this->assertSame(CreativeAgentPrompt::MAX_TOKENS, $body['max_tokens']);
            $this->assertArrayNotHasKey('thinking', $body);
            $this->assertArrayNotHasKey('tool_choice', $body);
            $this->assertTrue($body['tools'][0]['strict']);
            $this->assertArrayHasKey('input_schema', $body['tools'][0]);
        }
        $this->assertSame(json_encode($r1['tools']), json_encode($r2['tools']));
        // The reply's blocks go back exactly as they came ({} stays {}).
        $o2 = json_decode((string) $history[1]['request']->getBody());
        $this->assertSame(json_encode($first['content']), json_encode($o2->messages[1]->content));
        $this->assertSame('{}', json_encode($o2->messages[1]->content[2]->input));
        $this->assertSame(['type' => 'tool_result', 'tool_use_id' => 'toolu_real1'], array_intersect_key($r2['messages'][2]['content'][0], ['type' => 1, 'tool_use_id' => 1]));
        $this->assertSame(json_encode($r1['messages'][0]), json_encode($r2['messages'][0]));
        $this->assertSame(2, CreativeAiCall::where('kind', 'agent')->count());
    }

    // ---- prune ----------------------------------------------------------------------

    public function test_idle_chats_are_pruned_after_seven_days_and_the_prune_is_scheduled_daily(): void
    {
        [$old, $jobId] = $this->proposeRecipe();
        $this->api('POST', "/api/admin/creatives/agent/jobs/{$jobId}/approve")->assertOk();
        $this->statuses['req-1'] = ['status' => 'completed', 'request_id' => 'req-1', 'images' => [['url' => 'https://cdn.higgsfield.test/img/x.png']]];
        $this->travel(1)->minute();
        $this->artisan('creatives:poll')->assertSuccessful();

        // A chat with a job still running is kept; a busy one too.
        $runningChat = CreativeAgentSession::create(['uni_id' => $this->admin, 'user_name' => 'Owner Admin']);
        CreativeAgentJob::create(['session_id' => $runningChat->id, 'title' => 'Running', 'status' => 'running', 'steps' => [], 'estimated_usd' => 0]);
        $this->travel(8)->days();
        $recent = $this->sessionId($this->admin);
        $this->assertNotSame($runningChat->id, $recent);
        // The old chat's job ended (its last step was asked about before the travel).
        CreativeAgentJob::query()->whereKey($jobId)->update(['status' => 'completed']);

        $this->artisan('creatives:agent-prune')->assertSuccessful();

        $this->assertNull(CreativeAgentSession::find($old));
        $this->assertSame(0, CreativeAgentMessage::where('session_id', $old)->count());
        $this->assertNull(CreativeAgentJob::find($jobId));
        $this->assertNotNull(CreativeAgentSession::find($runningChat->id));
        $this->assertNotNull(CreativeAgentSession::find($recent));
        // Generations (the month's spend) are never deleted; they only lose the link.
        $this->assertSame(2, CreativeGeneration::count());
        $this->assertSame(0, CreativeGeneration::whereNotNull('agent_job_id')->count());

        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'creatives:agent-prune'));
        $this->assertNotNull($event, 'creatives:agent-prune is not scheduled.');
        $this->assertSame('20 1 * * *', $event->expression);
    }
}
