<?php

namespace Tests\Feature;

use App\Models\CreativeCharacter;
use App\Models\CreativeCharacterRef;
use App\Models\CreativeGeneration;
use App\Models\CreativePlan;
use App\Models\CreativeSetting;
use App\Models\UserCredential;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin → Creatives: the marketing team's content plan and Higgsfield
 * generations.
 *
 * Pinned here: who may do what (collaborators write Creatives, only full
 * admins set the cap); that the monthly cap holds (no price → no generation,
 * a refused submit costs nothing, an unanswered one keeps its reservation);
 * that results are only ever fetched from the URL Higgsfield gave us, through
 * a signed link; and that the Higgsfield key never appears in a response or
 * a log line.
 *
 * Every request is faked through ONE catch-all closure (an unexpected URL is
 * recorded and answered 418, never let through) — Http::preventStrayRequests()
 * is on as well. No ConnectionException is ever faked: that crashes PHP
 * natively on the WAMP box; "no answer" is a 5xx here.
 */
class CreativesTest extends EngineTestCase
{
    private const KEY_ID = 'hf-test-key-id-7c1e';

    private const SECRET = 'HF-SEEKRIT-key-secret-0042';

    /** 1×1 PNG. */
    private const PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private const VIDEO_BYTES = "\x00\x00\x00\x18ftypmp42 fake video bytes for the stream test";

    private string $admin;

    private string $collaborator;

    private string $user;

    /** @var list<string> */
    private array $unexpected = [];

    /** @var list<string> */
    private array $logged = [];

    private int $uploads = 0;

    /** @var array<string, callable> */
    private array $routes = [];

    private int $submits = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-15 12:00:00', 'UTC'));

        config([
            'services.higgsfield.key_id' => self::KEY_ID,
            'services.higgsfield.key_secret' => self::SECRET,
            'services.higgsfield.base_url' => 'https://api.higgsfield.ai',
            'services.higgsfield.ca_bundle' => null,
        ]);
        Storage::fake('local');
        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logged[] = $e->message.' '.json_encode($e->context);
        });

        $this->admin = $this->makeUser(['type' => 'admin', 'name' => 'Owner Admin']);
        $this->collaborator = $this->makeUser(['type' => 'collaborator', 'name' => 'Mara Marketing']);
        $this->user = $this->makeUser(['name' => 'Plain Customer']);

        $this->fakeHiggsfield();
        $this->registerFake();
    }

    protected function tearDown(): void
    {
        $this->assertSame([], $this->unexpected, 'A request went to a URL the test did not expect.');
        foreach ($this->logged as $line) {
            $this->assertStringNotContainsString(self::SECRET, $line);
            $this->assertStringNotContainsString(self::KEY_ID, $line);
        }
        parent::tearDown();
    }

    // ---- helpers ---------------------------------------------------------

    /**
     * Sets what each Higgsfield route answers (defaults + $over). The fake
     * itself is registered once, in setUp(): Http::fake() stubs stack and the
     * first one that answers wins, so re-faking would never take effect.
     *
     * @param  array<string, callable>  $over  route => Closure(ClientRequest)|ResponseSequence
     */
    private function fakeHiggsfield(array $over = []): void
    {
        $this->routes = array_merge([
            'estimate' => fn () => Http::response(['type' => 'estimate', 'credits' => '4.760', 'usd' => '0.298', 'discount' => null]),
            'submit' => fn () => Http::response([
                'request_id' => 'req-'.(++$this->submits),
                'status' => 'queued',
                'status_url' => 'https://api.higgsfield.ai/requests/req-'.$this->submits.'/status',
                'cancel_url' => 'https://api.higgsfield.ai/requests/req-'.$this->submits.'/cancel',
            ]),
            'status' => fn () => Http::response(['status' => 'queued', 'request_id' => 'req-1']),
            'cancel' => fn () => Http::response(['status' => 'canceled'], 202),
            'upload-url' => function () {
                $n = ++$this->uploads;

                return Http::response([
                    'public_url' => "https://files.higgsfield.test/ref-{$n}.png",
                    'upload_url' => "https://upload.s3.test/put/{$n}?X-Amz-Signature=presigned{$n}",
                    'content_type' => 'image/png',
                    'upload_headers' => ['Content-Type' => 'image/png', 'x-amz-tagging' => 'retention=temporary'],
                ]);
            },
            'put' => fn () => Http::response('', 200),
            'cdn' => fn () => Http::response(self::VIDEO_BYTES, 200, ['Content-Type' => 'video/mp4', 'Content-Length' => (string) strlen(self::VIDEO_BYTES)]),
        ], $over);
    }

    private function registerFake(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (ClientRequest $request) {
            $url = $request->url();
            $route = match (true) {
                str_starts_with($url, 'https://api.higgsfield.ai/estimate/') => 'estimate',
                str_starts_with($url, 'https://api.higgsfield.ai/files/generate-upload-url') => 'upload-url',
                preg_match('#^https://api\.higgsfield\.ai/requests/[^/]+/status$#', $url) === 1 => 'status',
                preg_match('#^https://api\.higgsfield\.ai/requests/[^/]+/cancel$#', $url) === 1 => 'cancel',
                str_starts_with($url, 'https://api.higgsfield.ai/') => 'submit',
                str_starts_with($url, 'https://upload.s3.test/') => 'put',
                str_starts_with($url, 'https://cdn.higgsfield.test/') => 'cdn',
                default => null,
            };
            if ($route === null) {
                $this->unexpected[] = $url;

                return Http::response(['detail' => 'unexpected'], 418);
            }

            return ($this->routes[$route])($request);
        });
    }

    private function headers(string $uniId): array
    {
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.UserCredential::findOrFail($uniId)->createToken('test')->plainTextToken];
    }

    /** Every call goes through here, so every body is checked for the key. */
    private function api(string $method, string $uri, array $data = [], ?string $as = null): TestResponse
    {
        return $this->noKey($this->json($method, $uri, $data, $this->headers($as ?? $this->collaborator)));
    }

    private function noKey(TestResponse $response): TestResponse
    {
        $content = $response->baseResponse instanceof StreamedResponse
            ? (string) $response->streamedContent()
            : (string) $response->getContent();
        $this->assertStringNotContainsString(self::SECRET, $content);
        $this->assertStringNotContainsString(self::KEY_ID, $content);

        return $response;
    }

    /** @return list<array{0: ClientRequest, 1: mixed}> the requests one route received, in order */
    private function sent(string $route): array
    {
        return Http::recorded(fn (ClientRequest $r) => match ($route) {
            'estimate' => str_starts_with($r->url(), 'https://api.higgsfield.ai/estimate/'),
            'submit' => str_starts_with($r->url(), 'https://api.higgsfield.ai/')
                && ! str_contains($r->url(), '/estimate/')
                && ! str_contains($r->url(), '/requests/')
                && ! str_contains($r->url(), '/files/'),
            'status' => str_ends_with($r->url(), '/status'),
            'put' => str_starts_with($r->url(), 'https://upload.s3.test/'),
            'cdn' => str_starts_with($r->url(), 'https://cdn.higgsfield.test/'),
            default => false,
        })->values()->all();
    }

    private function makeRef(array $overrides = []): CreativeCharacterRef
    {
        $path = 'creatives/refs/'.bin2hex(random_bytes(8)).'.png';
        Storage::disk('local')->put($path, base64_decode(self::PNG_B64));

        return CreativeCharacterRef::create(array_merge([
            'character_id' => CreativeCharacter::current()->id,
            'label' => 'Front',
            'path' => $path,
            'mime' => 'image/png',
            'bytes' => 70,
            'source' => 'upload',
        ], $overrides));
    }

    private function makeGeneration(array $overrides = []): CreativeGeneration
    {
        $g = new CreativeGeneration;
        $g->forceFill(array_merge([
            'kind' => 'image',
            'model' => 'grok-image',
            'endpoint' => 'xai/grok-imagine-image-2.0',
            'prompt' => 'Pixel waving',
            'full_prompt' => 'Pixel waving',
            'params' => ['aspect_ratio' => '9:16'],
            'used_character' => true,
            'status' => 'completed',
            'outputs' => [['type' => 'image', 'url' => 'https://cdn.higgsfield.test/img/a.png']],
            'estimated_usd' => 0.07,
            'charged_usd' => 0.07,
            'expires_at' => now()->addDays(6),
            'completed_at' => now()->subDay(),
            'created_by_uni_id' => $this->collaborator,
            'created_by_name' => 'Mara Marketing',
        ], $overrides))->save();

        return $g;
    }

    private function soulBody(array $extra = []): array
    {
        return array_merge(['model' => 'soul-image', 'prompt' => 'Pixel at a trading desk', 'params' => ['aspect_ratio' => '9:16']], $extra);
    }

    // ---- access ----------------------------------------------------------

    public function test_a_plain_user_is_refused_and_a_collaborator_manages_plans(): void
    {
        $this->api('GET', '/api/admin/creatives', [], $this->user)->assertForbidden()->assertJsonPath('error_code', 'FORBIDDEN');
        $this->api('POST', '/api/admin/creatives/plans', ['title' => 'Nope'], $this->user)->assertForbidden();
        $this->api('GET', '/api/admin/creatives/plans', [], $this->user)->assertForbidden();

        $created = $this->api('POST', '/api/admin/creatives/plans', [
            'title' => 'Pixel explains stop losses',
            'platform' => 'tiktok',
            'format' => 'video',
            'planned_for' => '2026-11-20',
            'hook' => 'What if the bot just... stopped?',
        ])->assertStatus(201)
            ->assertJsonPath('plan.title', 'Pixel explains stop losses')
            ->assertJsonPath('plan.status', 'idea')
            ->assertJsonPath('plan.planned_for', '2026-11-20')
            ->assertJsonPath('plan.generations_count', 0)
            ->assertJsonPath('plan.created_by_name', 'Mara Marketing');
        $id = $created->json('plan.id');

        // Partial update: absent keys unchanged, null clears.
        $this->api('PUT', "/api/admin/creatives/plans/{$id}", ['status' => 'script', 'hook' => null, 'caption' => 'Watch to the end'])
            ->assertOk()
            ->assertJsonPath('plan.status', 'script')
            ->assertJsonPath('plan.hook', null)
            ->assertJsonPath('plan.caption', 'Watch to the end')
            ->assertJsonPath('plan.title', 'Pixel explains stop losses')
            ->assertJsonPath('plan.platform', 'tiktok');

        $this->api('PUT', "/api/admin/creatives/plans/{$id}", ['status' => 'viral'])->assertStatus(422);
        $this->api('PUT', '/api/admin/creatives/plans/999', ['status' => 'script'])->assertNotFound()->assertJsonPath('error_code', 'NOT_FOUND');

        // Ordering: dated soonest first, undated last, then newest.
        $undated = $this->api('POST', '/api/admin/creatives/plans', ['title' => 'Someday', 'platform' => 'x', 'format' => 'image'])->json('plan.id');
        $sooner = $this->api('POST', '/api/admin/creatives/plans', ['title' => 'Sooner', 'platform' => 'x', 'format' => 'carousel', 'planned_for' => '2026-11-16'])->json('plan.id');
        $this->assertSame([$sooner, $id, $undated], array_column($this->api('GET', '/api/admin/creatives/plans')->assertOk()->json('plans'), 'id'));
        $this->assertSame([$sooner, $undated], array_column($this->api('GET', '/api/admin/creatives/plans?platform=x')->json('plans'), 'id'));
        $this->assertSame([$id], array_column($this->api('GET', '/api/admin/creatives/plans?status=script')->json('plans'), 'id'));

        // Deleting a plan keeps its generations' records (and their spend).
        $generation = $this->makeGeneration(['plan_id' => $id]);
        $this->assertSame(1, $this->api('GET', '/api/admin/creatives/plans?status=script')->json('plans.0.generations_count'));
        $this->api('DELETE', "/api/admin/creatives/plans/{$id}")->assertOk()->assertJsonPath('success', true);
        $this->assertDatabaseMissing('creative_plans', ['id' => $id]);
        $this->assertNull($generation->fresh()->plan_id);
        $this->assertSame(0.07, (float) $generation->fresh()->charged_usd);
    }

    public function test_only_full_admins_set_the_budget(): void
    {
        $this->api('GET', '/api/admin/creatives')->assertOk()
            ->assertJsonPath('can_edit_budget', false)
            ->assertJsonPath('budget.cap_usd', 100)
            ->assertJsonPath('budget.updated_by_name', null);
        $this->api('GET', '/api/admin/creatives', [], $this->admin)->assertJsonPath('can_edit_budget', true);

        $this->api('PUT', '/api/admin/creatives/budget', ['monthly_cap_usd' => 500])->assertForbidden();
        $this->api('PUT', '/api/admin/creatives/budget', ['monthly_cap_usd' => 500], $this->user)->assertForbidden();
        $this->assertSame(100.0, (float) CreativeSetting::current()->monthly_cap_usd);

        $this->api('PUT', '/api/admin/creatives/budget', ['monthly_cap_usd' => 10001], $this->admin)->assertStatus(422);
        $this->api('PUT', '/api/admin/creatives/budget', ['monthly_cap_usd' => -1], $this->admin)->assertStatus(422);

        $this->api('PUT', '/api/admin/creatives/budget', ['monthly_cap_usd' => 250.5], $this->admin)
            ->assertOk()
            ->assertJsonPath('budget.cap_usd', 250.5)
            ->assertJsonPath('budget.month', '2026-11')
            ->assertJsonPath('budget.updated_by_name', 'Owner Admin');
        $this->assertSame($this->admin, CreativeSetting::current()->updated_by_uni_id);

        $this->api('GET', '/api/admin/creatives/budget')->assertOk()->assertJsonPath('budget.cap_usd', 250.5);
    }

    public function test_meta_describes_the_models(): void
    {
        $meta = $this->api('GET', '/api/admin/creatives')->assertOk()
            ->assertJsonPath('configured', true)
            ->assertJsonPath('retention_days', 7)
            ->assertJsonPath('max_references', 8)
            ->json();

        $models = collect($meta['models'])->keyBy('key');
        $this->assertSame(['soul-image', 'grok-image', 'kling-animate', 'seedance-reference', 'seedance-text-video'], $models->keys()->all());
        // Seedance from text: no references, no mascot required, exactly four params.
        $this->assertFalse($models['seedance-text-video']['uses_references']);
        $this->assertFalse($models['seedance-text-video']['requires_character']);
        $this->assertSame(['duration', 'resolution', 'aspect_ratio', 'generate_audio'], array_column($models['seedance-text-video']['params'], 'name'));
        $this->assertTrue($models['grok-image']['uses_references']);
        $this->assertTrue($models['seedance-reference']['uses_references']);
        $this->assertTrue($models['seedance-reference']['requires_character']);
        $this->assertFalse($models['grok-image']['requires_character']);
        $this->assertTrue($models['kling-animate']['needs_source']);
        $this->assertFalse($models['soul-image']['uses_references']);
        $duration = collect($models['seedance-reference']['params'])->firstWhere('name', 'duration');
        $this->assertSame(['name' => 'duration', 'label' => 'Length (seconds)', 'type' => 'integer', 'min' => 4, 'max' => 15, 'default' => 5], $duration);
        $this->assertSame(null, collect($models['kling-animate']['params'])->firstWhere('name', 'negative_prompt')['default']);
        // No endpoint or internal pricing leaks into the picker.
        $this->assertArrayNotHasKey('endpoint', $models['soul-image']);
    }

    // ---- budget ----------------------------------------------------------

    public function test_budget_exceeded_refuses_before_anything_is_submitted(): void
    {
        $this->api('PUT', '/api/admin/creatives/budget', ['monthly_cap_usd' => 1], $this->admin)->assertOk();
        $this->makeGeneration(['charged_usd' => 0.9, 'estimated_usd' => 0.9]);
        // Last month's spend is last month's.
        $this->makeGeneration(['charged_usd' => 99, 'estimated_usd' => 99, 'created_at' => '2026-10-31 23:59:59']);

        $res = $this->api('POST', '/api/admin/creatives/generations', $this->soulBody())
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'BUDGET_EXCEEDED')
            ->assertJsonPath('budget.cap_usd', 1)
            ->assertJsonPath('budget.spent_usd', 0.9)
            ->assertJsonPath('budget.remaining_usd', 0.1)
            ->assertJsonPath('budget.generations', 1);
        $this->assertStringContainsString('monthly limit', $res->json('message'));

        $this->assertCount(0, $this->sent('submit'));
        $this->assertSame(2, CreativeGeneration::count());

        // The same price check says so before the button is pressed.
        $this->api('POST', '/api/admin/creatives/generations/estimate', $this->soulBody())
            ->assertOk()
            ->assertJsonPath('usd', 0.298)
            ->assertJsonPath('allowed', false);

        // Exactly at the cap is allowed (0.9 + 0.1 = 1.0, compared in integer units).
        $this->fakeHiggsfield(['estimate' => fn () => Http::response(['type' => 'estimate', 'usd' => '0.1'])]);
        $this->api('POST', '/api/admin/creatives/generations', $this->soulBody())->assertStatus(201)
            ->assertJsonPath('budget.spent_usd', 1)
            ->assertJsonPath('budget.remaining_usd', 0)
            ->assertJsonPath('budget.reserved_usd', 0.1);
    }

    public function test_a_zero_cap_switches_generation_off(): void
    {
        $this->api('PUT', '/api/admin/creatives/budget', ['monthly_cap_usd' => 0], $this->admin)->assertOk();
        $this->fakeHiggsfield(['estimate' => fn () => Http::response(['type' => 'estimate', 'usd' => '0.000'])]);

        $this->api('POST', '/api/admin/creatives/generations', $this->soulBody())
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'BUDGET_EXCEEDED')
            ->assertJsonPath('budget.cap_usd', 0);
        $this->api('POST', '/api/admin/creatives/generations/estimate', $this->soulBody())->assertJsonPath('allowed', false);
        $this->assertCount(0, $this->sent('submit'));
        $this->assertSame(0, CreativeGeneration::count());
    }

    public function test_no_price_means_no_generation(): void
    {
        $this->fakeHiggsfield(['estimate' => fn () => Http::response(['detail' => 'upstream'], 503)]);
        $this->api('POST', '/api/admin/creatives/generations', $this->soulBody())
            ->assertStatus(502)
            ->assertJsonPath('error_code', 'ESTIMATE_UNAVAILABLE');

        // A description with no fallback rate (only the video models have one) is no price either.
        $this->fakeHiggsfield(['estimate' => fn () => Http::response(['type' => 'description', 'pricing_description' => 'depends'])]);
        $this->api('POST', '/api/admin/creatives/generations', $this->soulBody())
            ->assertStatus(502)
            ->assertJsonPath('error_code', 'ESTIMATE_UNAVAILABLE');

        $this->assertCount(0, $this->sent('submit'));
        $this->assertSame(0, CreativeGeneration::count());
    }

    public function test_seedance_description_estimate_falls_back_to_per_second_pricing(): void
    {
        $this->makeRef();
        $this->fakeHiggsfield(['estimate' => fn () => Http::response(['type' => 'description', 'pricing_description' => '$0.2647 per second at 720p'])]);

        // 6 s × 0.2647 = 1.5882 (rates corrected 2026-11-12 to Higgsfield's live answer).
        $this->api('POST', '/api/admin/creatives/generations/estimate', [
            'model' => 'seedance-reference', 'prompt' => 'Pixel dances', 'params' => ['duration' => 6, 'resolution' => '720p'],
        ])->assertOk()->assertJsonPath('usd', 1.5882)->assertJsonPath('allowed', true);

        // 4 s × 0.1177 = 0.4708.
        $this->api('POST', '/api/admin/creatives/generations/estimate', [
            'model' => 'seedance-reference', 'prompt' => 'Pixel dances', 'params' => ['duration' => '4', 'resolution' => '480p'],
        ])->assertOk()->assertJsonPath('usd', 0.4708);

        // The text-to-video preset uses the same table: 10 s × 0.2647 = 2.647.
        $this->api('POST', '/api/admin/creatives/generations/estimate', [
            'model' => 'seedance-text-video', 'prompt' => 'A neon city at night', 'params' => ['duration' => 10],
        ])->assertOk()->assertJsonPath('usd', 2.647);

        // The default (5 s, 720p) is what gets reserved on submit: 5 × 0.2647 = 1.3235.
        $created = $this->api('POST', '/api/admin/creatives/generations', ['model' => 'seedance-reference', 'prompt' => 'Pixel dances'])
            ->assertStatus(201)
            ->assertJsonPath('generation.estimated_usd', 1.3235)
            ->assertJsonPath('generation.charged_usd', 1.3235)
            ->assertJsonPath('generation.params.generate_audio', true);
        $this->assertSame(5, $created->json('generation.params.duration'));

        $this->api('POST', '/api/admin/creatives/generations/estimate', [
            'model' => 'seedance-reference', 'prompt' => 'Pixel dances', 'params' => ['duration' => 16],
        ])->assertStatus(422)->assertJsonValidationErrors('params.duration');
    }

    public function test_kling_description_estimate_falls_back_to_its_per_second_rate(): void
    {
        // Seen live 2026-11-11: Kling's price check answers a description, not
        // a number, which left every animate step "not priced".
        $ref = $this->makeRef();
        $this->fakeHiggsfield(['estimate' => fn () => Http::response([
            'type' => 'description',
            'pricing_description' => 'Standard self-serve rates in USD per video second: 1080p: $0.0525.',
        ])]);

        $this->api('POST', '/api/admin/creatives/generations/estimate', [
            'model' => 'kling-animate', 'prompt' => 'Pixel waves', 'source' => ['ref_id' => $ref->id], 'params' => ['duration' => 10],
        ])->assertOk()->assertJsonPath('usd', 0.525)->assertJsonPath('allowed', true);

        // The default (5 s) is what gets reserved on submit.
        $this->api('POST', '/api/admin/creatives/generations', [
            'model' => 'kling-animate', 'prompt' => 'Pixel waves', 'source' => ['ref_id' => $ref->id],
        ])->assertStatus(201)
            ->assertJsonPath('generation.estimated_usd', 0.2625)
            ->assertJsonPath('generation.charged_usd', 0.2625);
    }

    public function test_seedance_always_uses_the_character_even_when_switched_off(): void
    {
        // Higgsfield refuses reference-to-video with no references at all
        // ("'audio_urls' is a required property" — seen live 2026-11-10), so
        // "off" is ignored for this model rather than priced into a refusal.
        $this->makeRef();
        $this->fakeHiggsfield(['estimate' => fn () => Http::response(['type' => 'description', 'pricing_description' => 'per second'])]);

        $this->api('POST', '/api/admin/creatives/generations/estimate', [
            'model' => 'seedance-reference', 'prompt' => 'Pixel dances', 'use_character' => false,
        ])->assertOk()->assertJsonPath('usd', 1.3235);

        $body = $this->sent('estimate')[0][0]->data();
        $this->assertCount(1, $body['image_urls']);
        $this->assertStringContainsString('Character "Pixel"', $body['prompt']);
    }

    public function test_params_are_checked_against_the_registry(): void
    {
        $this->api('POST', '/api/admin/creatives/generations/estimate', $this->soulBody(['params' => ['aspect_ratio' => '5:4']]))
            ->assertStatus(422)->assertJsonValidationErrors('params.aspect_ratio');
        $this->api('POST', '/api/admin/creatives/generations/estimate', ['model' => 'midjourney', 'prompt' => 'Pixel'])
            ->assertStatus(422)->assertJsonValidationErrors('model');
        $this->api('POST', '/api/admin/creatives/generations/estimate', $this->soulBody(['prompt' => 'no']))
            ->assertStatus(422)->assertJsonValidationErrors('prompt');

        // Unknown params never reach Higgsfield's body; select values keep their type.
        $this->api('POST', '/api/admin/creatives/generations/estimate', $this->soulBody(['params' => ['batch_size' => '4', 'webhook' => 'https://evil.test']]))
            ->assertOk();
        $body = $this->sent('estimate')[0][0]->data();
        $this->assertSame(4, $body['batch_size']);
        $this->assertArrayNotHasKey('webhook', $body);
        $this->assertSame('720p', $body['resolution']);
    }

    // ---- references --------------------------------------------------------

    public function test_reference_models_need_a_reference_image(): void
    {
        foreach (['grok-image', 'seedance-reference'] as $model) {
            $this->api('POST', '/api/admin/creatives/generations', ['model' => $model, 'prompt' => 'Pixel smiling'])
                ->assertStatus(422)
                ->assertJsonPath('error_code', 'NO_REFERENCES')
                ->assertJsonPath('message', 'Add at least one reference image of the character first.');
        }
        $this->assertCount(0, $this->sent('estimate'));

        // Without the character, the prompt alone is sent — no references needed.
        $this->api('POST', '/api/admin/creatives/generations/estimate', ['model' => 'grok-image', 'prompt' => 'A neon chart', 'use_character' => false])
            ->assertOk();
        $body = $this->sent('estimate')[0][0]->data();
        $this->assertSame('A neon chart', $body['prompt']);
        $this->assertArrayNotHasKey('image_urls', $body);
    }

    public function test_a_reference_upload_is_stored_counted_and_deleted_with_its_file(): void
    {
        $this->api('POST', '/api/admin/creatives/character/refs', [], $this->user)->assertForbidden();

        $res = $this->noKey($this->post('/api/admin/creatives/character/refs', [
            'image' => UploadedFile::fake()->image('pixel-front.png', 32, 32),
            'label' => 'Front view',
        ], $this->headers($this->collaborator) + ['Accept' => 'application/json']))
            ->assertStatus(201)
            ->assertJsonPath('character.name', 'Pixel')
            ->assertJsonPath('character.refs.0.label', 'Front view')
            ->assertJsonPath('character.refs.0.source', 'upload')
            ->assertJsonPath('character.refs.0.mime', 'image/png');

        $ref = CreativeCharacterRef::firstOrFail();
        $this->assertMatchesRegularExpression('#^creatives/refs/[A-Za-z0-9]{40}\.png$#', $ref->path);
        $this->assertStringNotContainsString('pixel-front', $ref->path);
        Storage::disk('local')->assertExists($ref->path);
        $this->assertSame(Storage::disk('local')->size($ref->path), $ref->bytes);
        $this->assertStringStartsWith("/api/creatives/refs/{$ref->id}/image?expires=", $res->json('character.refs.0.image_path'));

        // Only png / jpg / webp.
        foreach ([UploadedFile::fake()->image('a.gif'), UploadedFile::fake()->create('notes.txt', 1, 'text/plain')] as $bad) {
            $this->post('/api/admin/creatives/character/refs', ['image' => $bad], $this->headers($this->collaborator) + ['Accept' => 'application/json'])
                ->assertStatus(422)->assertJsonValidationErrors('image');
        }

        // At most 8.
        for ($i = 2; $i <= 8; $i++) {
            $this->post('/api/admin/creatives/character/refs', ['image' => UploadedFile::fake()->image("p{$i}.jpg")], $this->headers($this->collaborator) + ['Accept' => 'application/json'])
                ->assertStatus(201);
        }
        $this->post('/api/admin/creatives/character/refs', ['image' => UploadedFile::fake()->image('p9.png')], $this->headers($this->collaborator) + ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'TOO_MANY_REFERENCES');
        $this->assertSame(8, CreativeCharacterRef::count());
        $this->assertCount(8, Storage::disk('local')->files('creatives/refs'));

        // Delete takes the file with it.
        $this->api('DELETE', "/api/admin/creatives/character/refs/{$ref->id}")
            ->assertOk()
            ->assertJsonCount(7, 'character.refs');
        Storage::disk('local')->assertMissing($ref->path);
        $this->assertDatabaseMissing('creative_character_refs', ['id' => $ref->id]);
        $this->api('DELETE', "/api/admin/creatives/character/refs/{$ref->id}")->assertNotFound()->assertJsonPath('error_code', 'NOT_FOUND');
    }

    public function test_the_character_text_is_editable_and_goes_into_the_prompt(): void
    {
        $this->api('GET', '/api/admin/creatives/character')->assertOk()
            ->assertJsonPath('character.name', 'Pixel')
            ->assertJsonPath('character.refs', []);

        $this->api('PUT', '/api/admin/creatives/character', ['name' => 'P'])->assertStatus(422);
        $this->api('PUT', '/api/admin/creatives/character', ['name' => 'Pixel', 'rules' => null, 'look' => 'A tiny gold robot.'])
            ->assertOk()
            ->assertJsonPath('character.rules', null)
            ->assertJsonPath('character.look', 'A tiny gold robot.')
            ->assertJsonPath('character.updated_by_name', 'Mara Marketing');
        $this->assertNotNull(CreativeCharacter::current()->style_prompt);

        $this->api('POST', '/api/admin/creatives/generations', $this->soulBody())->assertStatus(201)
            ->assertJsonPath('generation.prompt', 'Pixel at a trading desk');
        $sent = $this->sent('submit')[0][0]->data()['prompt'];
        $this->assertStringStartsWith("Pixel at a trading desk\n\nCharacter \"Pixel\": A tiny gold robot.\nStyle: 16-bit pixel art", $sent);
        $this->assertStringNotContainsString('Rules:', $sent);
        $this->assertSame($sent, CreativeGeneration::firstOrFail()->full_prompt);
    }

    public function test_a_reference_can_be_copied_from_a_live_generated_image(): void
    {
        $png = base64_decode(self::PNG_B64);
        $this->fakeHiggsfield(['cdn' => fn () => Http::response($png, 200, ['Content-Type' => 'image/png'])]);
        $image = $this->makeGeneration();

        $this->api('POST', '/api/admin/creatives/character/refs/from-generation', ['generation_id' => $image->id, 'index' => 0, 'label' => 'Best one'])
            ->assertStatus(201)
            ->assertJsonPath('character.refs.0.source', 'generation')
            ->assertJsonPath('character.refs.0.label', 'Best one');
        $ref = CreativeCharacterRef::firstOrFail();
        $this->assertSame($image->id, $ref->generation_id);
        $this->assertSame($png, Storage::disk('local')->get($ref->path));
        // Fetched from the recorded URL, without our key.
        $this->assertSame('https://cdn.higgsfield.test/img/a.png', $this->sent('cdn')[0][0]->url());
        $this->assertFalse($this->sent('cdn')[0][0]->hasHeader('Authorization'));

        $expired = $this->makeGeneration(['expires_at' => now()->subMinute()]);
        $video = $this->makeGeneration(['kind' => 'video', 'outputs' => [['type' => 'video', 'url' => 'https://cdn.higgsfield.test/v.mp4']]]);
        foreach ([[$expired->id, 0], [$video->id, 0], [$image->id, 3], [9999, 0]] as [$id, $index]) {
            $this->api('POST', '/api/admin/creatives/character/refs/from-generation', ['generation_id' => $id, 'index' => $index])
                ->assertStatus(422)->assertJsonPath('error_code', 'SOURCE_UNAVAILABLE');
        }

        // Not an image (by its bytes) is refused.
        $this->fakeHiggsfield(['cdn' => fn () => Http::response('<html>nope</html>', 200, ['Content-Type' => 'image/png'])]);
        $this->api('POST', '/api/admin/creatives/character/refs/from-generation', ['generation_id' => $image->id, 'index' => 0])
            ->assertStatus(422)->assertJsonPath('error_code', 'SOURCE_UNAVAILABLE');
        $this->assertSame(1, CreativeCharacterRef::count());
    }

    // ---- submit ----------------------------------------------------------

    public function test_a_submit_uploads_the_references_and_reserves_the_estimate(): void
    {
        $this->makeRef(['label' => 'Front']);
        $this->makeRef(['label' => 'Side']);

        $res = $this->api('POST', '/api/admin/creatives/generations', ['model' => 'grok-image', 'prompt' => 'Pixel holding a coin', 'params' => ['quality' => 'low']])
            ->assertStatus(201)
            ->assertJsonPath('generation.status', 'queued')
            ->assertJsonPath('generation.model', 'grok-image')
            ->assertJsonPath('generation.model_label', 'Character image (with references)')
            ->assertJsonPath('generation.kind', 'image')
            ->assertJsonPath('generation.used_character', true)
            ->assertJsonPath('generation.estimated_usd', 0.298)
            ->assertJsonPath('generation.charged_usd', 0.298)
            ->assertJsonPath('generation.outputs', [])
            ->assertJsonPath('generation.created_by_name', 'Mara Marketing')
            ->assertJsonPath('budget.spent_usd', 0.298)
            ->assertJsonPath('budget.reserved_usd', 0.298)
            ->assertJsonPath('budget.by_person.0.name', 'Mara Marketing')
            ->assertJsonPath('budget.by_person.0.generations', 1);
        $this->assertSame('req-1', CreativeGeneration::firstOrFail()->request_id);

        // The price check used placeholders — references go up only for the real submit.
        $estimate = $this->sent('estimate')[0][0]->data();
        $this->assertCount(2, $estimate['image_urls']);
        $this->assertStringStartsWith('https://pixel-alpha.com/', $estimate['image_urls'][0]);

        $submit = $this->sent('submit')[0][0];
        $this->assertSame('https://api.higgsfield.ai/xai/grok-imagine-image-2.0', $submit->url());
        $this->assertSame(['https://files.higgsfield.test/ref-1.png', 'https://files.higgsfield.test/ref-2.png'], $submit->data()['image_urls']);
        $this->assertSame('low', $submit->data()['quality']);
        $this->assertSame('Key '.self::KEY_ID.':'.self::SECRET, $submit->header('Authorization')[0]);

        // Each upload: every header Higgsfield returned, and never our key.
        $puts = $this->sent('put');
        $this->assertCount(2, $puts);
        foreach ($puts as [$put]) {
            $this->assertSame('PUT', $put->method());
            $this->assertFalse($put->hasHeader('Authorization'));
            $this->assertSame('retention=temporary', $put->header('x-amz-tagging')[0]);
            $this->assertSame('image/png', $put->header('Content-Type')[0]);
            $this->assertSame(base64_decode(self::PNG_B64), $put->body());
        }
        $this->assertNotNull($res->json('generation.created_at'));
    }

    public function test_a_refused_submit_fails_and_charges_nothing(): void
    {
        $this->fakeHiggsfield(['submit' => fn () => Http::response(['detail' => 'Prompt violates the content policy'], 422)]);

        $this->api('POST', '/api/admin/creatives/generations', $this->soulBody())
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'HIGGSFIELD_REJECTED')
            ->assertJsonPath('message', 'Prompt violates the content policy')
            ->assertJsonPath('generation.status', 'failed')
            ->assertJsonPath('generation.charged_usd', 0)
            ->assertJsonPath('budget.spent_usd', 0);

        $row = CreativeGeneration::firstOrFail();
        $this->assertSame('failed', $row->status);
        $this->assertSame(0.0, (float) $row->charged_usd);
        $this->assertSame(0.298, (float) $row->estimated_usd);
        $this->assertSame('Prompt violates the content policy', $row->error);
        $this->assertNull($row->request_id);
    }

    public function test_an_unanswered_submit_keeps_the_charge(): void
    {
        $this->fakeHiggsfield(['submit' => fn () => Http::response('<html>Bad gateway</html>', 503)]);

        $this->api('POST', '/api/admin/creatives/generations', $this->soulBody())
            ->assertStatus(502)
            ->assertJsonPath('error_code', 'SUBMIT_UNCONFIRMED')
            ->assertJsonPath('generation.status', 'failed')
            ->assertJsonPath('generation.charged_usd', 0.298)
            ->assertJsonPath('budget.spent_usd', 0.298);

        $row = CreativeGeneration::firstOrFail();
        $this->assertSame('Higgsfield did not confirm this request — check its usage page', $row->error);
        $this->assertSame(0.298, (float) $row->charged_usd);

        // A 2xx without a request id is no confirmation either.
        $this->fakeHiggsfield(['submit' => fn () => Http::response(['status' => 'queued'])]);
        $this->api('POST', '/api/admin/creatives/generations', $this->soulBody())
            ->assertStatus(502)->assertJsonPath('error_code', 'SUBMIT_UNCONFIRMED');
        $this->assertSame(0.596, round((float) CreativeGeneration::sum('charged_usd'), 4));
    }

    public function test_a_failed_reference_upload_starts_nothing_and_charges_nothing(): void
    {
        $this->makeRef();
        $this->fakeHiggsfield(['put' => fn () => Http::response('', 500)]);

        $this->api('POST', '/api/admin/creatives/generations', ['model' => 'grok-image', 'prompt' => 'Pixel waving'])
            ->assertStatus(502)
            ->assertJsonPath('error_code', 'HIGGSFIELD_UNAVAILABLE')
            ->assertJsonPath('generation.status', 'failed')
            ->assertJsonPath('generation.charged_usd', 0);
        $this->assertCount(0, $this->sent('submit'));
    }

    // ---- polling ---------------------------------------------------------

    public function test_polling_to_completed_fills_outputs_and_expiry(): void
    {
        $this->fakeHiggsfield(['status' => Http::sequence()
            ->push(['status' => 'in_progress', 'request_id' => 'req-1'])
            ->push([
                'status' => 'completed',
                'request_id' => 'req-1',
                'images' => [['url' => 'https://cdn.higgsfield.test/img/1.png'], ['url' => 'http://insecure.test/2.png']],
                'expires_at' => '2026-11-22T12:00:10Z',
            ])
            // Never let a sequence run dry: no exception is ever thrown inside the fake on this box.
            ->whenEmpty(Http::response(['status' => 'completed', 'request_id' => 'req-1'])),
        ]);
        $id = $this->api('POST', '/api/admin/creatives/generations', $this->soulBody())->assertStatus(201)->json('generation.id');

        // Checked at submit: not stale yet, so the list does not ask.
        $this->api('GET', '/api/admin/creatives/generations')->assertOk()->assertJsonPath('generations.0.status', 'queued');
        $this->assertCount(0, $this->sent('status'));

        $this->travel(5)->seconds();
        $this->api('GET', '/api/admin/creatives/generations')->assertJsonPath('generations.0.status', 'in_progress');

        $this->travel(5)->seconds();
        $list = $this->api('GET', '/api/admin/creatives/generations')->assertOk()
            ->assertJsonPath('generations.0.id', $id)
            ->assertJsonPath('generations.0.status', 'completed')
            ->assertJsonPath('generations.0.charged_usd', 0.298)
            ->assertJsonPath('generations.0.expires_at', '2026-11-22T12:00:10+00:00')
            ->assertJsonPath('has_more', false);
        // Only the https URL Higgsfield gave is kept.
        $outputs = $list->json('generations.0.outputs');
        $this->assertCount(1, $outputs);
        $this->assertSame('image', $outputs[0]['type']);
        $this->assertSame('https://cdn.higgsfield.test/img/1.png', $outputs[0]['url']);
        $this->assertStringStartsWith("/api/creatives/generations/{$id}/download/0?expires=", $outputs[0]['download_path']);
        $this->assertNotNull($list->json('generations.0.completed_at'));
        $this->assertSame('GET', $this->sent('status')[0][0]->method());
        $this->assertSame('https://api.higgsfield.ai/requests/req-1/status', $this->sent('status')[0][0]->url());

        // Completed rows are not asked about again.
        $this->travel(1)->minute();
        $this->artisan('creatives:poll')->assertSuccessful();
        $this->assertCount(2, $this->sent('status'));
    }

    public function test_failed_and_nsfw_give_the_reservation_back(): void
    {
        $failed = $this->makeGeneration(['status' => 'queued', 'request_id' => 'req-f', 'outputs' => null, 'expires_at' => null, 'completed_at' => null]);
        $nsfw = $this->makeGeneration(['status' => 'in_progress', 'request_id' => 'req-n', 'outputs' => null, 'expires_at' => null, 'completed_at' => null]);
        $this->fakeHiggsfield(['status' => fn (ClientRequest $r) => str_contains($r->url(), 'req-f')
            ? Http::response(['status' => 'failed', 'request_id' => 'req-f', 'error' => 'Model timed out'])
            : Http::response(['status' => 'nsfw', 'request_id' => 'req-n']),
        ]);

        $this->artisan('creatives:poll')->assertSuccessful();

        $failed->refresh();
        $nsfw->refresh();
        $this->assertSame('failed', $failed->status);
        $this->assertSame(0.0, (float) $failed->charged_usd);
        $this->assertSame('Model timed out', $failed->error);
        $this->assertSame('nsfw', $nsfw->status);
        $this->assertSame(0.0, (float) $nsfw->charged_usd);
        $this->assertNotNull($nsfw->error);
        $this->api('GET', '/api/admin/creatives/budget')->assertJsonPath('budget.spent_usd', 0)->assertJsonPath('budget.generations', 2);
    }

    public function test_a_completed_result_past_its_expiry_becomes_expired(): void
    {
        $live = $this->makeGeneration();
        $old = $this->makeGeneration(['expires_at' => now()->subSecond()]);

        $this->artisan('creatives:poll')->assertSuccessful();

        $this->assertSame('expired', $old->fresh()->status);
        $this->assertNull($old->fresh()->outputs);
        $this->assertSame(0.07, (float) $old->fresh()->charged_usd);
        $this->assertSame('completed', $live->fresh()->status);

        $rows = collect($this->api('GET', '/api/admin/creatives/generations')->json('generations'))->keyBy('id');
        $this->assertSame([], $rows[$old->id]['outputs']);
        $this->assertCount(1, $rows[$live->id]['outputs']);
        $this->assertCount(0, Http::recorded());
    }

    public function test_poll_sends_nothing_when_nothing_is_pending(): void
    {
        $this->makeGeneration();
        $this->makeGeneration(['status' => 'failed', 'charged_usd' => 0]);

        $this->artisan('creatives:poll')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_an_unanswered_status_check_stops_the_batch_and_changes_nothing(): void
    {
        $a = $this->makeGeneration(['status' => 'queued', 'request_id' => 'req-a', 'outputs' => null, 'expires_at' => null]);
        $b = $this->makeGeneration(['status' => 'in_progress', 'request_id' => 'req-b', 'outputs' => null, 'expires_at' => null]);
        $this->fakeHiggsfield(['status' => fn () => Http::response('upstream down', 503)]);

        $this->artisan('creatives:poll')->assertSuccessful();

        // One timeout, not one per row; both keep their status and their reservation.
        $this->assertCount(1, $this->sent('status'));
        $this->assertSame('queued', $a->fresh()->status);
        $this->assertSame('in_progress', $b->fresh()->status);
        $this->assertSame(0.07, (float) $a->fresh()->charged_usd);
    }

    public function test_a_submit_that_never_came_back_is_recorded_as_unconfirmed(): void
    {
        $stuck = $this->makeGeneration(['status' => 'submitting', 'outputs' => null, 'expires_at' => null, 'created_at' => now()->subMinutes(11)]);
        $fresh = $this->makeGeneration(['status' => 'submitting', 'outputs' => null, 'expires_at' => null]);

        $this->artisan('creatives:poll')->assertSuccessful();

        $this->assertSame('failed', $stuck->fresh()->status);
        $this->assertSame(0.07, (float) $stuck->fresh()->charged_usd);
        $this->assertSame('submitting', $fresh->fresh()->status);
        Http::assertNothingSent();
    }

    // ---- cancel ----------------------------------------------------------

    public function test_cancel_works_only_while_queued(): void
    {
        $id = $this->api('POST', '/api/admin/creatives/generations', $this->soulBody())->json('generation.id');

        $this->api('POST', "/api/admin/creatives/generations/{$id}/cancel", [], $this->user)->assertForbidden();
        $this->api('POST', "/api/admin/creatives/generations/{$id}/cancel")
            ->assertOk()
            ->assertJsonPath('generation.status', 'canceled')
            ->assertJsonPath('generation.charged_usd', 0)
            ->assertJsonPath('budget.spent_usd', 0);
        Http::assertSent(fn (ClientRequest $r) => $r->url() === 'https://api.higgsfield.ai/requests/req-1/cancel' && $r->method() === 'POST');

        $this->api('POST', "/api/admin/creatives/generations/{$id}/cancel")->assertStatus(409)->assertJsonPath('error_code', 'NOT_CANCELLABLE');

        $running = $this->makeGeneration(['status' => 'in_progress', 'request_id' => 'req-run', 'outputs' => null]);
        $before = count(Http::recorded());
        $this->api('POST', "/api/admin/creatives/generations/{$running->id}/cancel")->assertStatus(409)->assertJsonPath('error_code', 'NOT_CANCELLABLE');
        $this->assertCount($before, Http::recorded());
        $this->assertSame(0.07, (float) $running->fresh()->charged_usd);

        $this->api('POST', '/api/admin/creatives/generations/9999/cancel')->assertNotFound()->assertJsonPath('error_code', 'NOT_FOUND');

        // Higgsfield refuses because it already started: still NOT_CANCELLABLE, and the row catches up.
        $queued = $this->makeGeneration(['status' => 'queued', 'request_id' => 'req-late', 'outputs' => null, 'expires_at' => null]);
        $this->fakeHiggsfield([
            'cancel' => fn () => Http::response(['detail' => 'Request is not in queue'], 400),
            'status' => fn () => Http::response(['status' => 'in_progress', 'request_id' => 'req-late']),
        ]);
        $this->api('POST', "/api/admin/creatives/generations/{$queued->id}/cancel")
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'NOT_CANCELLABLE')
            ->assertJsonPath('generation.status', 'in_progress');
        $this->assertSame(0.07, (float) $queued->fresh()->charged_usd);
    }

    // ---- kling source ------------------------------------------------------

    public function test_the_kling_source_must_be_a_completed_unexpired_image(): void
    {
        $body = fn (array $source) => ['model' => 'kling-animate', 'prompt' => 'Pixel starts walking', 'params' => ['duration' => 10], 'source' => $source];

        $this->api('POST', '/api/admin/creatives/generations', ['model' => 'kling-animate', 'prompt' => 'Pixel walks'])
            ->assertStatus(422)->assertJsonValidationErrors('source');
        $this->api('POST', '/api/admin/creatives/generations', $body(['generation_id' => 1, 'ref_id' => 1]))
            ->assertStatus(422)->assertJsonValidationErrors('source');

        $video = $this->makeGeneration(['kind' => 'video', 'outputs' => [['type' => 'video', 'url' => 'https://cdn.higgsfield.test/v.mp4']]]);
        $expired = $this->makeGeneration(['expires_at' => now()->subMinute()]);
        $failed = $this->makeGeneration(['status' => 'failed', 'outputs' => null]);
        foreach ([$video->id, $expired->id, $failed->id, 9999] as $id) {
            $this->api('POST', '/api/admin/creatives/generations', $body(['generation_id' => $id, 'index' => 0]))
                ->assertStatus(422)->assertJsonPath('error_code', 'SOURCE_UNAVAILABLE');
        }
        $this->api('POST', '/api/admin/creatives/generations', $body(['ref_id' => 9999]))
            ->assertStatus(422)->assertJsonPath('error_code', 'SOURCE_UNAVAILABLE');
        $this->assertCount(0, $this->sent('submit'));

        $image = $this->makeGeneration();
        $this->api('POST', '/api/admin/creatives/generations', $body(['generation_id' => $image->id, 'index' => 0]))
            ->assertStatus(201)
            ->assertJsonPath('generation.kind', 'video');
        $submit = $this->sent('submit')[0][0];
        $this->assertSame('https://api.higgsfield.ai/kling-video/v2.5-turbo/pro/image-to-video', $submit->url());
        $this->assertSame('https://cdn.higgsfield.test/img/a.png', $submit->data()['image_url']);
        $this->assertSame(10, $submit->data()['duration']);
        $this->assertArrayNotHasKey('aspect_ratio', $submit->data());
        $this->assertCount(0, $this->sent('put'));
    }

    public function test_kling_can_animate_a_reference_image(): void
    {
        $ref = $this->makeRef();

        $this->api('POST', '/api/admin/creatives/generations', [
            'model' => 'kling-animate', 'prompt' => 'Pixel jumps', 'source' => ['ref_id' => $ref->id], 'params' => ['negative_prompt' => 'blur'],
        ])->assertStatus(201);

        $this->assertCount(1, $this->sent('put'));
        $data = $this->sent('submit')[0][0]->data();
        $this->assertSame('https://files.higgsfield.test/ref-1.png', $data['image_url']);
        $this->assertSame('blur', $data['negative_prompt']);
        $this->assertArrayNotHasKey('image_urls', $data);
    }

    // ---- signed files ----------------------------------------------------

    public function test_the_signed_download_refuses_bad_links_and_streams_with_a_good_one(): void
    {
        $g = $this->makeGeneration(['kind' => 'video', 'model' => 'kling-animate', 'outputs' => [['type' => 'video', 'url' => 'https://cdn.higgsfield.test/v/abc.mp4']]]);
        $path = $this->api('GET', '/api/admin/creatives/generations')->json('generations.0.outputs.0.download_path');
        $this->assertStringStartsWith("/api/creatives/generations/{$g->id}/download/0?expires=", $path);

        $this->get("/api/creatives/generations/{$g->id}/download/0")->assertForbidden();
        $this->get(substr($path, 0, -1).(str_ends_with($path, 'a') ? 'b' : 'a'))->assertForbidden();
        $this->get(str_replace('/download/0?', '/download/1?', $path))->assertForbidden();
        $other = $this->makeGeneration(['kind' => 'video', 'outputs' => [['type' => 'video', 'url' => 'https://cdn.higgsfield.test/v/other.mp4']]]);
        $this->get(str_replace("/generations/{$g->id}/", "/generations/{$other->id}/", $path))->assertForbidden();
        $this->assertCount(0, $this->sent('cdn'));

        $res = $this->noKey($this->get($path))->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="pixel-alpha-'.$g->id.'-1.mp4"')
            ->assertHeader('X-Accel-Buffering', 'no')
            ->assertHeader('Content-Type', 'video/mp4');
        $this->assertSame(self::VIDEO_BYTES, $res->streamedContent());
        $this->assertCount(1, $this->sent('cdn'));
        $this->assertSame('https://cdn.higgsfield.test/v/abc.mp4', $this->sent('cdn')[0][0]->url());
        $this->assertFalse($this->sent('cdn')[0][0]->hasHeader('Authorization'));
        $this->assertSame([], Storage::disk('local')->allFiles());

        // A link is good for 30 minutes.
        $this->travel(31)->minutes();
        $this->get($path)->assertForbidden();
    }

    public function test_an_expired_result_answers_410_even_with_a_valid_link(): void
    {
        $g = $this->makeGeneration();
        $path = $this->api('GET', '/api/admin/creatives/generations')->json('generations.0.outputs.0.download_path');
        $this->assertNotNull($path);

        $g->forceFill(['expires_at' => now()->subSecond()])->save();
        $this->get($path)->assertStatus(410)->assertJsonPath('error_code', 'EXPIRED');

        // Higgsfield already deleted it (its CDN says 403): also gone.
        $g2 = $this->makeGeneration();
        $path2 = collect($this->api('GET', '/api/admin/creatives/generations')->json('generations'))->firstWhere('id', $g2->id)['outputs'][0]['download_path'];
        $this->fakeHiggsfield(['cdn' => fn () => Http::response('AccessDenied', 403)]);
        $this->get($path2)->assertStatus(410)->assertJsonPath('error_code', 'EXPIRED');
    }

    public function test_a_reference_image_is_served_only_through_its_signed_path(): void
    {
        $ref = $this->makeRef();
        $path = $this->api('GET', '/api/admin/creatives/character')->json('character.refs.0.image_path');
        $this->assertStringStartsWith("/api/creatives/refs/{$ref->id}/image?expires=", $path);

        $this->get("/api/creatives/refs/{$ref->id}/image")->assertForbidden();
        $this->get(str_replace('signature=', 'signature=0', $path))->assertForbidden();

        $res = $this->get($path)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertStringContainsString('max-age=3600', (string) $res->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $res->headers->get('Cache-Control'));
        $this->assertSame(base64_decode(self::PNG_B64), $res->streamedContent());

        $this->travel(121)->minutes();
        $this->get($path)->assertForbidden();
    }

    // ---- configuration and the key ---------------------------------------

    public function test_without_a_key_nothing_is_called(): void
    {
        // phpunit.xml pins both halves empty: a developer's real key never reaches the suite.
        $this->assertSame('', (string) env('HIGGSFIELD_KEY_ID'));
        $this->assertSame('', (string) env('HIGGSFIELD_KEY_SECRET'));

        config(['services.higgsfield.key_secret' => '']);

        $this->api('GET', '/api/admin/creatives')->assertOk()->assertJsonPath('configured', false);
        $this->api('POST', '/api/admin/creatives/generations', $this->soulBody())->assertStatus(503)->assertJsonPath('error_code', 'NOT_CONFIGURED');
        $this->api('POST', '/api/admin/creatives/generations/estimate', $this->soulBody())->assertStatus(503)->assertJsonPath('error_code', 'NOT_CONFIGURED');
        $this->makeGeneration(['status' => 'queued', 'request_id' => 'req-x', 'outputs' => null]);
        $this->api('GET', '/api/admin/creatives/generations')->assertOk();
        $this->artisan('creatives:poll')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_no_response_ever_contains_the_key(): void
    {
        // Higgsfield echoing the key back in an error must not carry it through.
        $this->fakeHiggsfield([
            'submit' => fn () => Http::response(['detail' => 'Invalid credentials for '.self::KEY_ID.':'.self::SECRET], 401),
        ]);
        $this->makeRef();

        $responses = [
            $this->api('GET', '/api/admin/creatives'),
            $this->api('GET', '/api/admin/creatives', [], $this->admin),
            $this->api('GET', '/api/admin/creatives/budget'),
            $this->api('GET', '/api/admin/creatives/character'),
            $this->api('POST', '/api/admin/creatives/generations/estimate', $this->soulBody()),
            $this->api('POST', '/api/admin/creatives/generations', $this->soulBody()),
            $this->api('POST', '/api/admin/creatives/generations', ['model' => 'grok-image', 'prompt' => 'Pixel waving']),
            $this->api('GET', '/api/admin/creatives/generations'),
            $this->api('GET', '/api/admin/creatives/plans'),
        ];

        $this->assertSame('HIGGSFIELD_REJECTED', $responses[5]->json('error_code'));
        $this->assertStringContainsString('[redacted]', $responses[5]->json('message'));
        $this->assertStringContainsString('[redacted]', (string) CreativeGeneration::firstOrFail()->error);
        foreach ($responses as $res) {
            $this->assertStringNotContainsString(self::SECRET, (string) $res->getContent());
        }
        // The log lines are checked in tearDown(); there must have been some.
        $this->assertNotEmpty($this->logged);
    }
}
