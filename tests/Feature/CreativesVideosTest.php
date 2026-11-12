<?php

namespace Tests\Feature;

use App\Models\CreativeAgentJob;
use App\Models\CreativeAgentSession;
use App\Models\CreativeCharacter;
use App\Models\CreativeCharacterRef;
use App\Models\CreativeGeneration;
use App\Models\CreativeStyle;
use App\Models\CreativeVideo;
use App\Models\CreativeVideoScene;
use App\Models\UserCredential;
use App\Services\Creatives\CreativeAgentJobs;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/**
 * Admin → Creatives v2: the video editor's projects and the job that makes a
 * video's clips.
 *
 * Pinned here: every staff role makes and edits videos (collaborators
 * included), a plain user is refused; a PUT never writes a column the server
 * owns; the 30-second cap (TOO_LONG) and the shape lock (ASPECT_LOCKED); a
 * clip added by hand is a finished, live VIDEO generation; template scenes
 * are free and ready; proposing scenes prices one job, creates planned slots
 * and spends nothing; any staff member may Go a job with no chat; and the
 * BULK run — independent steps start in the same tick (at most four), an
 * animation only after its still, every step in the video's shape, each clip
 * landing on its own scene, one failure failing only its scene.
 *
 * Higgsfield is one catch-all Http::fake (an unexpected URL fails the test),
 * with Http::preventStrayRequests() on. No ConnectionException is ever faked
 * (that crashes PHP natively on the WAMP box).
 */
class CreativesVideosTest extends EngineTestCase
{
    /** 1×1 PNG. */
    private const PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private string $admin;

    private string $collaborator;

    private string $user;

    /** @var list<string> */
    private array $unexpected = [];

    private int $submits = 0;

    private int $uploads = 0;

    /** @var array<string, array<string, mixed>> what /requests/{id}/status answers, per request id */
    private array $statuses = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-15 12:00:00', 'UTC'));
        Cache::flush();
        Storage::fake('local');
        config([
            'services.higgsfield.key_id' => 'hf-test-id',
            'services.higgsfield.key_secret' => 'hf-test-secret',
            'services.higgsfield.base_url' => 'https://api.higgsfield.ai',
            'services.higgsfield.ca_bundle' => null,
        ]);

        $this->admin = $this->makeUser(['type' => 'admin', 'name' => 'Owner Admin']);
        $this->collaborator = $this->makeUser(['type' => 'collaborator', 'name' => 'Mara Marketing']);
        $this->user = $this->makeUser(['name' => 'Plain Customer']);

        $this->fakeHiggsfield();
    }

    protected function tearDown(): void
    {
        $this->assertSame([], $this->unexpected, 'A request went to a URL the test did not expect.');
        parent::tearDown();
    }

    // ---- helpers ---------------------------------------------------------

    /**
     * Prices: Soul 0.004 and Grok 0.07 (a number); Kling and Seedance answer a
     * DESCRIPTION, as they do live, so their per-second rates are what is
     * reserved: Kling 0.0525/s, Seedance 0.2647/s at 720p.
     */
    private function fakeHiggsfield(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (ClientRequest $request) {
            $url = $request->url();
            if (str_starts_with($url, 'https://api.higgsfield.ai/estimate/')) {
                if (str_contains($url, 'kling') || str_contains($url, 'seedance')) {
                    return Http::response(['type' => 'description', 'pricing_description' => 'per video second']);
                }

                return Http::response(['type' => 'estimate', 'usd' => str_contains($url, 'grok') ? '0.070' : '0.004']);
            }
            if (str_starts_with($url, 'https://api.higgsfield.ai/files/generate-upload-url')) {
                $n = ++$this->uploads;

                return Http::response([
                    'public_url' => "https://files.higgsfield.test/ref-{$n}.png",
                    'upload_url' => "https://upload.s3.test/put/{$n}",
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
            if (str_starts_with($url, 'https://api.higgsfield.ai/')) {
                $n = ++$this->submits;

                return Http::response(['request_id' => "req-{$n}", 'status' => 'queued']);
            }
            $this->unexpected[] = $url;

            return Http::response(['detail' => 'unexpected'], 418);
        });
    }

    /** @return list<ClientRequest> the paid submits, in order */
    private function submitted(): array
    {
        return Http::recorded(fn (ClientRequest $r) => str_starts_with($r->url(), 'https://api.higgsfield.ai/')
            && ! str_contains($r->url(), '/estimate/') && ! str_contains($r->url(), '/requests/') && ! str_contains($r->url(), '/files/'))
            ->map(fn ($pair) => $pair[0])->values()->all();
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

    private function makeRef(): CreativeCharacterRef
    {
        $path = 'creatives/refs/'.bin2hex(random_bytes(8)).'.png';
        Storage::disk('local')->put($path, base64_decode(self::PNG_B64));

        return CreativeCharacterRef::create([
            'character_id' => CreativeCharacter::current()->id,
            'label' => 'Front', 'path' => $path, 'mime' => 'image/png', 'bytes' => 70, 'source' => 'upload',
        ]);
    }

    private function makeVideo(array $body = []): int
    {
        return (int) $this->api('POST', '/api/admin/creatives/videos', array_merge(['title' => 'Weekly recap', 'platform' => 'tiktok', 'aspect' => '9:16'], $body))
            ->assertCreated()->json('video.id');
    }

    private function generation(array $over = []): CreativeGeneration
    {
        $g = new CreativeGeneration;
        $g->forceFill(array_merge([
            'kind' => 'video', 'model' => 'kling-animate', 'endpoint' => 'kling-video/v2.5-turbo/pro/image-to-video',
            'prompt' => 'A chart line rising on a dark screen', 'full_prompt' => 'p', 'params' => ['duration' => 5], 'used_character' => false,
            'status' => 'completed', 'outputs' => [['type' => 'video', 'url' => 'https://cdn.higgsfield.test/vid/a.mp4']],
            'expires_at' => now()->addDays(5), 'estimated_usd' => 0.2625, 'charged_usd' => 0.2625,
        ], $over))->save();

        return $g;
    }

    private function template(string $key = 'clean-stat', array $over = []): array
    {
        return array_merge(['key' => $key, 'title' => 'This week', 'value' => '+6.03%', 'subtitle' => 'Published results'], $over);
    }

    /** The three scenes of the bulk test: Kling without Pixel, Kling with Pixel, Seedance without Pixel. */
    private function threeScenes(): array
    {
        return [
            ['kind' => 'clip', 'label' => 'Screens at night', 'prompt' => 'Slow push-in on glowing trading screens at night', 'seconds' => 5, 'engine' => 'kling', 'with_character' => false, 'caption' => 'Another week', 'template' => null],
            ['kind' => 'clip', 'label' => 'Pixel waves', 'prompt' => 'Pixel waves at the camera from a desk', 'seconds' => 5, 'engine' => 'kling', 'with_character' => true, 'caption' => null, 'template' => null],
            ['kind' => 'clip', 'label' => 'Arcade city', 'prompt' => 'A pixel-art city at night, 8-bit chiptune music and arcade sound effects', 'seconds' => 6, 'engine' => 'seedance', 'with_character' => false, 'caption' => 'Level up', 'template' => null],
        ];
    }

    private function complete(string $requestId, array $output): void
    {
        $this->statuses[$requestId] = ['status' => 'completed', 'request_id' => $requestId, 'expires_at' => '2026-11-22T12:00:00Z'] + $output;
    }

    // ---- who --------------------------------------------------------------------

    public function test_every_staff_role_manages_videos_and_a_plain_user_is_refused(): void
    {
        foreach ([
            ['GET', '/api/admin/creatives/videos'],
            ['POST', '/api/admin/creatives/videos'],
            ['GET', '/api/admin/creatives/videos/1'],
            ['PUT', '/api/admin/creatives/videos/1'],
            ['DELETE', '/api/admin/creatives/videos/1'],
            ['POST', '/api/admin/creatives/videos/1/scenes'],
            ['POST', '/api/admin/creatives/videos/1/scenes/propose'],
            ['DELETE', '/api/admin/creatives/videos/1/scenes/1'],
            ['POST', '/api/admin/creatives/videos/1/exported'],
        ] as [$method, $uri]) {
            $this->api($method, $uri, [], $this->user)->assertForbidden()->assertJsonPath('error_code', 'FORBIDDEN');
        }

        // A collaborator (the marketing team) creates one; the style seeds the timeline.
        $style = CreativeStyle::query()->where('slug', 'pixel-arcade')->firstOrFail();
        $res = $this->api('POST', '/api/admin/creatives/videos', ['title' => 'Arcade recap', 'platform' => 'tiktok', 'aspect' => '9:16', 'style_id' => $style->id])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('video.title', 'Arcade recap')
            ->assertJsonPath('video.style.slug', 'pixel-arcade')
            ->assertJsonPath('video.style.name', 'Pixel arcade')
            ->assertJsonPath('video.max_seconds', 30)
            ->assertJsonPath('video.fps', 30)
            ->assertJsonPath('video.status', 'draft')
            ->assertJsonPath('video.created_by_name', 'Mara Marketing')
            ->assertJsonPath('video.duration_ms', 0)
            ->assertJsonPath('video.scenes', [])
            ->assertJsonPath('video.jobs', [])
            ->assertJsonPath('video.timeline.version', 1)
            ->assertJsonPath('video.timeline.caption_style', 'pixel')
            ->assertJsonPath('video.timeline.look', ['pixel_size' => 10, 'scanlines' => true])
            ->assertJsonPath('video.timeline.music.source', 'chiptune')
            ->assertJsonPath('video.timeline.music.preset', 'arcade-upbeat')
            ->assertJsonPath('video.timeline.music.volume', 0.6)
            ->assertJsonPath('video.timeline.music.fade_out_ms', 1500)
            ->assertJsonPath('video.timeline.voiceover', null)
            ->assertJsonPath('video.timeline.sfx', [])
            ->assertJsonPath('video.timeline.clip_audio_volume', 1)
            ->assertJsonPath('video.timeline.ducking', ['enabled' => true, 'music_under_voice' => 0.25]);
        $seed = $res->json('video.timeline.music.seed');
        $this->assertIsInt($seed);
        $this->assertGreaterThanOrEqual(1, $seed);
        $this->assertLessThanOrEqual(99999, $seed);
        $id = (int) $res->json('video.id');

        // No style: the plain seed.
        $plain = $this->api('POST', '/api/admin/creatives/videos', ['title' => 'Plain', 'platform' => 'x', 'aspect' => '16:9'], $this->admin)->assertCreated();
        $plain->assertJsonPath('video.style', null)
            ->assertJsonPath('video.timeline.caption_style', 'bold')
            ->assertJsonPath('video.timeline.music', null)
            ->assertJsonPath('video.timeline.look', ['pixel_size' => 0, 'scanlines' => false]);

        $this->api('POST', '/api/admin/creatives/videos', ['title' => 'Bad', 'platform' => 'youtube', 'aspect' => '4:3'])->assertStatus(422)
            ->assertJsonValidationErrors(['platform', 'aspect']);

        // The list: newest first, the summary shape.
        $this->travel(1)->second();
        $this->api('PUT', "/api/admin/creatives/videos/{$id}", ['title' => 'Arcade recap v2'], $this->admin)->assertOk()
            ->assertJsonPath('video.title', 'Arcade recap v2')
            ->assertJsonPath('video.updated_by_name', 'Owner Admin');
        $this->api('GET', '/api/admin/creatives/videos?limit=5')->assertOk()
            ->assertJsonPath('videos.0.id', $id)
            ->assertJsonPath('videos.0.style_name', 'Pixel arcade')
            ->assertJsonPath('videos.0.scenes_total', 0)
            ->assertJsonPath('videos.0.scenes_ready', 0)
            ->assertJsonPath('videos.0.scenes_generating', 0)
            ->assertJsonPath('videos.0.duration_ms', 0)
            ->assertJsonPath('videos.0.created_by_name', 'Mara Marketing')
            ->assertJsonPath('videos.1.title', 'Plain');
        $this->api('GET', '/api/admin/creatives/videos?limit=99')->assertStatus(422);

        $this->api('GET', "/api/admin/creatives/videos/{$id}", [], $this->admin)->assertOk()->assertJsonPath('video.id', $id);
        $this->api('DELETE', "/api/admin/creatives/videos/{$id}")->assertOk()->assertJsonPath('success', true);
        $this->api('GET', "/api/admin/creatives/videos/{$id}")->assertNotFound()->assertJsonPath('error_code', 'NOT_FOUND');
    }

    // ---- editing ------------------------------------------------------------------

    public function test_put_saves_client_fields_and_ignores_the_servers(): void
    {
        $id = $this->makeVideo();
        $clip = $this->generation();
        $other = $this->generation(['outputs' => [['type' => 'video', 'url' => 'https://cdn.higgsfield.test/vid/b.mp4']]]);
        $this->api('POST', "/api/admin/creatives/videos/{$id}/scenes", ['kind' => 'clip', 'generation_id' => $clip->id, 'output_index' => 0])->assertOk();
        $this->api('POST', "/api/admin/creatives/videos/{$id}/scenes", ['kind' => 'template', 'template' => $this->template(), 'seconds' => 4])->assertOk();
        [$a, $b] = CreativeVideoScene::query()->where('video_id', $id)->orderBy('position')->get()->all();

        $timeline = [
            'version' => 1, 'caption_style' => 'clean',
            'captions' => [['id' => 'c1', 'text' => 'Hello', 'start_ms' => 0, 'end_ms' => 1500, 'style' => 'hook', 'position' => 'top']],
            'look' => ['pixel_size' => 0, 'scanlines' => false], 'music' => null, 'voiceover' => null,
            'sfx' => [['id' => 's1', 'sound' => 'coin', 'at_ms' => 600, 'volume' => 0.8]],
            'clip_audio_volume' => 0.5, 'ducking' => ['enabled' => false, 'music_under_voice' => 0.25],
        ];
        $res = $this->api('PUT', "/api/admin/creatives/videos/{$id}", [
            'timeline' => $timeline,
            'scenes' => [
                // Server-owned keys are ignored.
                ['id' => $a->id, 'position' => 1, 'trim_in_ms' => 1000, 'trim_out_ms' => 4000, 'label' => 'Rising chart',
                    'generation_id' => $other->id, 'status' => 'failed', 'error' => 'hacked', 'agent_job_id' => 99, 'step_index' => 3, 'output_index' => 2],
                ['id' => $b->id, 'position' => 0, 'template' => $this->template('clean-title', ['title' => 'Pixel Alpha', 'value' => '', 'subtitle' => 'This week'])],
            ],
        ])->assertOk();

        $res->assertJsonPath('video.timeline.caption_style', 'clean')
            ->assertJsonPath('video.timeline.sfx.0.sound', 'coin')
            ->assertJsonPath('video.timeline.clip_audio_volume', 0.5)
            ->assertJsonPath('video.scenes.0.id', $b->id)
            ->assertJsonPath('video.scenes.0.template.key', 'clean-title')
            ->assertJsonPath('video.scenes.0.status', 'ready')
            ->assertJsonPath('video.scenes.1.id', $a->id)
            ->assertJsonPath('video.scenes.1.label', 'Rising chart')
            ->assertJsonPath('video.scenes.1.trim_in_ms', 1000)
            ->assertJsonPath('video.scenes.1.trim_out_ms', 4000)
            ->assertJsonPath('video.scenes.1.duration_ms', 3000)
            ->assertJsonPath('video.scenes.1.engine', 'kling')
            ->assertJsonPath('video.scenes.1.engine_label', 'Kling (silent)')
            ->assertJsonPath('video.scenes.1.generation.id', $clip->id)
            ->assertJsonPath('video.scenes.1.generation.outputs.0.url', 'https://cdn.higgsfield.test/vid/a.mp4')
            ->assertJsonPath('video.scenes.1.status', 'ready')
            ->assertJsonPath('video.scenes.1.error', null)
            ->assertJsonPath('video.scenes.1.agent_job_id', null)
            ->assertJsonPath('video.duration_ms', 7000);
        $this->assertNotNull($res->json('video.scenes.1.generation.outputs.0.download_path'));
        $a->refresh();
        $this->assertSame($clip->id, $a->generation_id);
        $this->assertSame(0, $a->output_index);
        $this->assertNull($a->step_index);

        // A trim must keep part of the scene; a scene of another video is refused.
        $this->api('PUT', "/api/admin/creatives/videos/{$id}", ['scenes' => [['id' => $a->id, 'trim_in_ms' => 4000, 'trim_out_ms' => 4000]]])->assertStatus(422);
        $this->api('PUT', "/api/admin/creatives/videos/{$id}", ['scenes' => [['id' => $a->id, 'trim_out_ms' => 6000]]])->assertStatus(422);
        $this->api('PUT', "/api/admin/creatives/videos/{$id}", ['scenes' => [['id' => $a->id, 'template' => $this->template()]]])->assertStatus(422);
        $otherVideo = $this->makeVideo(['title' => 'Other']);
        $this->api('PUT', "/api/admin/creatives/videos/{$otherVideo}", ['scenes' => [['id' => $a->id, 'label' => 'Moved']]])->assertStatus(422);
        $this->assertSame('Rising chart', $a->fresh()->label);

        // The TimelineDoc: an object, version 1, ≤ 64 KB, audio that exists.
        $this->api('PUT', "/api/admin/creatives/videos/{$id}", ['timeline' => ['version' => 2]])->assertStatus(422);
        $this->api('PUT', "/api/admin/creatives/videos/{$id}", ['timeline' => ['version' => 1, 'pad' => str_repeat('x', 70000)]])->assertStatus(422);
        $this->api('PUT', "/api/admin/creatives/videos/{$id}", ['timeline' => ['version' => 1, 'voiceover' => ['asset_id' => 999, 'volume' => 1, 'start_ms' => 0]]])->assertStatus(422);
        $this->api('GET', "/api/admin/creatives/videos/{$id}")->assertJsonPath('video.timeline.caption_style', 'clean');

        // Removing a scene closes the gap.
        $this->api('DELETE', "/api/admin/creatives/videos/{$id}/scenes/{$b->id}")->assertOk()
            ->assertJsonPath('video.scenes.0.id', $a->id)
            ->assertJsonPath('video.scenes.0.position', 0);
        $this->api('DELETE', "/api/admin/creatives/videos/{$id}/scenes/{$b->id}")->assertNotFound();

        // Deleting the video leaves the generations (the month's spend).
        $this->api('DELETE', "/api/admin/creatives/videos/{$id}")->assertOk();
        $this->assertSame(0, CreativeVideoScene::query()->where('video_id', $id)->count());
        $this->assertSame(2, CreativeGeneration::count());
        $this->assertCount(0, $this->submitted());
    }

    public function test_a_video_is_at_most_thirty_seconds(): void
    {
        $id = $this->makeVideo();
        for ($i = 0; $i < 5; $i++) {
            $this->api('POST', "/api/admin/creatives/videos/{$id}/scenes", ['kind' => 'template', 'template' => $this->template(), 'seconds' => 6])->assertOk();
        }
        $this->api('GET', "/api/admin/creatives/videos/{$id}")->assertJsonPath('video.duration_ms', 30000);

        $this->api('POST', "/api/admin/creatives/videos/{$id}/scenes", ['kind' => 'template', 'template' => $this->template(), 'seconds' => 2])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'TOO_LONG')
            ->assertJsonPath('duration_ms', 32000)
            ->assertJsonPath('max_ms', 30000);
        $clip = $this->generation();
        $this->api('POST', "/api/admin/creatives/videos/{$id}/scenes", ['kind' => 'clip', 'generation_id' => $clip->id, 'output_index' => 0])
            ->assertStatus(422)->assertJsonPath('error_code', 'TOO_LONG');
        $this->api('POST', "/api/admin/creatives/videos/{$id}/scenes/propose", ['scenes' => [
            ['kind' => 'clip', 'label' => 'More', 'prompt' => 'A rocket launches at dawn', 'seconds' => 5, 'engine' => 'kling', 'with_character' => false, 'caption' => null, 'template' => null],
        ]])->assertStatus(422)->assertJsonPath('error_code', 'TOO_LONG');
        $this->assertSame(5, CreativeVideoScene::query()->where('video_id', $id)->count());
        $this->assertSame(0, CreativeAgentJob::count());
        $this->assertCount(0, Http::recorded());

        // Trimming makes room.
        $first = CreativeVideoScene::query()->where('video_id', $id)->orderBy('position')->firstOrFail();
        $this->api('PUT', "/api/admin/creatives/videos/{$id}", ['scenes' => [['id' => $first->id, 'trim_out_ms' => 4000]]])->assertOk()->assertJsonPath('video.duration_ms', 28000);
        $this->api('POST', "/api/admin/creatives/videos/{$id}/scenes", ['kind' => 'template', 'template' => $this->template('disclaimer'), 'seconds' => 2])->assertOk()
            ->assertJsonPath('video.duration_ms', 30000);
    }

    public function test_platform_and_shape_are_locked_once_a_clip_is_on_the_video(): void
    {
        $id = $this->makeVideo();
        // Template scenes only: the shape may still change.
        $this->api('POST', "/api/admin/creatives/videos/{$id}/scenes", ['kind' => 'template', 'template' => $this->template(), 'seconds' => 3])->assertOk();
        $this->api('PUT', "/api/admin/creatives/videos/{$id}", ['aspect' => '1:1', 'platform' => 'x'])->assertOk()->assertJsonPath('video.aspect', '1:1');

        $clip = $this->generation();
        $this->api('POST', "/api/admin/creatives/videos/{$id}/scenes", ['kind' => 'clip', 'generation_id' => $clip->id, 'output_index' => 0, 'position' => 0])->assertOk()
            ->assertJsonPath('video.scenes.0.generation.id', $clip->id);
        $this->api('PUT', "/api/admin/creatives/videos/{$id}", ['aspect' => '9:16'])->assertStatus(409)->assertJsonPath('error_code', 'ASPECT_LOCKED');
        $this->api('PUT', "/api/admin/creatives/videos/{$id}", ['platform' => 'tiktok'])->assertStatus(409)->assertJsonPath('error_code', 'ASPECT_LOCKED');
        // The same values (an autosave) and other fields still save.
        $this->api('PUT', "/api/admin/creatives/videos/{$id}", ['aspect' => '1:1', 'platform' => 'x', 'title' => 'Square recap'])->assertOk()
            ->assertJsonPath('video.title', 'Square recap');
        $this->assertSame('1:1', CreativeVideo::find($id)->aspect);
    }

    public function test_a_clip_added_by_hand_must_be_a_finished_live_video(): void
    {
        $id = $this->makeVideo();
        $image = $this->generation(['kind' => 'image', 'model' => 'soul-image', 'outputs' => [['type' => 'image', 'url' => 'https://cdn.higgsfield.test/img/a.png']]]);
        $expired = $this->generation(['status' => 'expired', 'outputs' => null, 'expires_at' => now()->subDay()]);
        $queued = $this->generation(['status' => 'queued', 'outputs' => null, 'expires_at' => null]);
        $old = $this->generation(['expires_at' => now()->subMinute()]);
        foreach ([$image, $expired, $queued, $old] as $g) {
            $this->api('POST', "/api/admin/creatives/videos/{$id}/scenes", ['kind' => 'clip', 'generation_id' => $g->id, 'output_index' => 0])
                ->assertStatus(422)->assertJsonPath('error_code', 'CLIP_UNAVAILABLE');
        }
        $good = $this->generation(['model' => 'seedance-text-video', 'params' => ['duration' => 8], 'prompt' => 'A neon arcade']);
        $this->api('POST', "/api/admin/creatives/videos/{$id}/scenes", ['kind' => 'clip', 'generation_id' => $good->id, 'output_index' => 1])
            ->assertStatus(422)->assertJsonPath('error_code', 'CLIP_UNAVAILABLE');
        $this->api('POST', "/api/admin/creatives/videos/{$id}/scenes", ['kind' => 'clip', 'output_index' => 0])->assertStatus(422);

        $this->api('POST', "/api/admin/creatives/videos/{$id}/scenes", ['kind' => 'clip', 'generation_id' => $good->id, 'output_index' => 0])->assertOk()
            ->assertJsonPath('video.scenes.0.kind', 'clip')
            ->assertJsonPath('video.scenes.0.label', 'A neon arcade')
            ->assertJsonPath('video.scenes.0.engine', 'seedance')
            ->assertJsonPath('video.scenes.0.engine_label', 'Seedance (with sound)')
            ->assertJsonPath('video.scenes.0.seconds', 8)
            ->assertJsonPath('video.scenes.0.duration_ms', 8000)
            ->assertJsonPath('video.scenes.0.status', 'ready');

        // Once Higgsfield's 7 days are over, the scene reads expired.
        $this->travel(6)->days();
        $this->api('GET', "/api/admin/creatives/videos/{$id}")->assertJsonPath('video.scenes.0.status', 'expired');
        $this->assertCount(0, $this->submitted());
    }

    public function test_template_scenes_are_free_and_ready(): void
    {
        $id = $this->makeVideo();
        $this->api('POST', "/api/admin/creatives/videos/{$id}/scenes", ['kind' => 'template', 'template' => $this->template('nope'), 'seconds' => 3])->assertStatus(422);
        $this->api('POST', "/api/admin/creatives/videos/{$id}/scenes", ['kind' => 'template', 'template' => $this->template(), 'seconds' => 7])->assertStatus(422);
        $this->api('POST', "/api/admin/creatives/videos/{$id}/scenes", ['kind' => 'template', 'template' => $this->template('clean-stat', ['value' => str_repeat('9', 41)]), 'seconds' => 3])->assertStatus(422);

        $this->api('POST', "/api/admin/creatives/videos/{$id}/scenes", ['kind' => 'template', 'template' => $this->template('pixel-scoreboard', ['title' => 'HIGH SCORE']), 'seconds' => 3])->assertOk()
            ->assertJsonPath('video.scenes.0.kind', 'template')
            ->assertJsonPath('video.scenes.0.label', 'HIGH SCORE')
            ->assertJsonPath('video.scenes.0.template', ['key' => 'pixel-scoreboard', 'title' => 'HIGH SCORE', 'value' => '+6.03%', 'subtitle' => 'Published results'])
            ->assertJsonPath('video.scenes.0.engine', null)
            ->assertJsonPath('video.scenes.0.generation', null)
            ->assertJsonPath('video.scenes.0.status', 'ready')
            ->assertJsonPath('video.scenes.0.duration_ms', 3000);

        // Drafted through propose: a ready slot, no job, no request to Higgsfield.
        $res = $this->api('POST', "/api/admin/creatives/videos/{$id}/scenes/propose", ['scenes' => [
            ['kind' => 'template', 'label' => '', 'prompt' => null, 'seconds' => 2, 'engine' => null, 'with_character' => false, 'caption' => null,
                'template' => ['key' => 'disclaimer', 'title' => '', 'value' => '', 'subtitle' => 'Past performance does not guarantee future results.']],
        ]])->assertOk()->assertJsonPath('job', null)
            ->assertJsonPath('video.scenes.1.template.key', 'disclaimer')
            ->assertJsonPath('video.scenes.1.status', 'ready');
        $this->assertSame('Disclaimer', $res->json('video.scenes.1.label'));
        $this->assertSame(0, CreativeAgentJob::count());
        $this->assertCount(0, Http::recorded());
    }

    // ---- proposing --------------------------------------------------------------------

    public function test_propose_prices_one_job_creates_planned_slots_and_spends_nothing(): void
    {
        $id = $this->makeVideo(['aspect' => '16:9', 'platform' => 'x']);
        $this->api('POST', "/api/admin/creatives/videos/{$id}/scenes", ['kind' => 'template', 'template' => $this->template('clean-title'), 'seconds' => 3])->assertOk();

        $res = $this->api('POST', "/api/admin/creatives/videos/{$id}/scenes/propose", ['scenes' => [
            ['kind' => 'clip', 'label' => 'Screens', 'prompt' => 'Slow push-in on glowing trading screens', 'seconds' => 10, 'engine' => 'kling', 'with_character' => false, 'caption' => 'Calm and steady', 'template' => null],
            ['kind' => 'template', 'label' => 'Stat', 'prompt' => null, 'seconds' => 3, 'engine' => null, 'with_character' => false, 'caption' => 'Up this week', 'template' => $this->template()],
        ]])->assertOk();

        // One job: Soul still (0.004) + Kling 10 s (10 × 0.0525 = 0.525) = 0.529.
        $res->assertJsonPath('success', true)
            ->assertJsonPath('job.status', 'proposed')
            ->assertJsonPath('job.title', 'Video: Weekly recap')
            ->assertJsonPath('job.video_id', $id)
            ->assertJsonPath('job.editor_path', "/admin/creatives/editor/{$id}")
            ->assertJsonPath('job.estimated_usd', 0.529)
            ->assertJsonPath('job.steps.0.model', 'soul-image')
            ->assertJsonPath('job.steps.0.usd', 0.004)
            ->assertJsonPath('job.steps.1.model', 'kling-animate')
            ->assertJsonPath('job.steps.1.usd', 0.525)
            ->assertJsonPath('video.scenes.1.status', 'planned')
            ->assertJsonPath('video.scenes.1.engine', 'kling')
            ->assertJsonPath('video.scenes.1.step_index', 1)
            ->assertJsonPath('video.scenes.2.status', 'ready')
            ->assertJsonPath('video.scenes.2.agent_job_id', null)
            ->assertJsonPath('video.jobs.0.id', $res->json('job.id'))
            ->assertJsonPath('budget.reserved_usd', 0);
        $jobId = (int) $res->json('job.id');
        $this->assertSame($jobId, $res->json('video.scenes.1.agent_job_id'));
        $job = CreativeAgentJob::findOrFail($jobId);
        $this->assertNull($job->session_id);
        $this->assertSame($this->collaborator, $job->owner_uni_id);
        $this->assertSame(['aspect_ratio' => '16:9', 'resolution' => '720p', 'batch_size' => 1], $job->steps[0]['params']);
        $this->assertFalse($job->steps[0]['use_character']);
        $this->assertStringEndsWith(', wide composition, first frame of the shot', $job->steps[0]['prompt']);
        $this->assertSame(['from_step' => 0], $job->steps[1]['source']);
        $this->assertSame((int) $res->json('video.scenes.1.id'), $job->steps[1]['scene_id']);
        $this->assertArrayNotHasKey('scene_id', $job->steps[0]);

        // A caption per scene that has one, over that scene's slot (3 s title, then 10 s, then 3 s).
        $captions = $res->json('video.timeline.captions');
        $this->assertCount(2, $captions);
        $this->assertSame(['Calm and steady', 3000, 13000, 'body', 'bottom'], [$captions[0]['text'], $captions[0]['start_ms'], $captions[0]['end_ms'], $captions[0]['style'], $captions[0]['position']]);
        $this->assertSame(['Up this week', 13000, 16000], [$captions[1]['text'], $captions[1]['start_ms'], $captions[1]['end_ms']]);

        $this->assertCount(0, $this->submitted());
        $this->assertSame(0, CreativeGeneration::count());

        // Bad drafts: nothing is kept.
        $count = CreativeVideoScene::count();
        foreach ([
            ['kind' => 'clip', 'label' => 'x', 'prompt' => 'A rocket', 'seconds' => 7, 'engine' => 'kling', 'with_character' => false, 'caption' => null, 'template' => null],
            ['kind' => 'clip', 'label' => 'x', 'prompt' => 'A rocket', 'seconds' => 16, 'engine' => 'seedance', 'with_character' => false, 'caption' => null, 'template' => null],
            ['kind' => 'clip', 'label' => 'x', 'prompt' => 'A', 'seconds' => 5, 'engine' => 'kling', 'with_character' => false, 'caption' => null, 'template' => null],
            ['kind' => 'clip', 'label' => 'x', 'prompt' => 'A rocket', 'seconds' => 5, 'engine' => 'sora', 'with_character' => false, 'caption' => null, 'template' => null],
            ['kind' => 'video', 'label' => 'x', 'prompt' => 'A rocket', 'seconds' => 5, 'engine' => 'kling', 'with_character' => false, 'caption' => null, 'template' => null],
        ] as $bad) {
            $this->api('POST', "/api/admin/creatives/videos/{$id}/scenes/propose", ['scenes' => [$bad]])->assertStatus(422);
        }
        $this->api('POST', "/api/admin/creatives/videos/{$id}/scenes/propose", ['scenes' => array_fill(0, 7, $this->threeScenes()[0])])->assertStatus(422);
        $this->assertSame($count, CreativeVideoScene::count());
        $this->assertSame(1, CreativeAgentJob::count());

        // Replacing a slot re-plans it in place: same position, trims reset, a new job.
        $slot = (int) $res->json('video.scenes.1.id');
        CreativeVideoScene::query()->whereKey($slot)->update(['trim_in_ms' => 500, 'trim_out_ms' => 4000]);
        $re = $this->api('POST', "/api/admin/creatives/videos/{$id}/scenes/propose", ['replace_scene_id' => $slot, 'scenes' => [
            ['kind' => 'clip', 'label' => 'Screens, with sound', 'prompt' => 'Glowing screens, soft keyboard clicks', 'seconds' => 5, 'engine' => 'seedance', 'with_character' => false, 'caption' => null, 'template' => null],
        ]])->assertOk()
            ->assertJsonPath('job.steps.0.model', 'seedance-text-video')
            ->assertJsonPath('job.estimated_usd', 1.3235)
            ->assertJsonPath('video.scenes.1.id', $slot)
            ->assertJsonPath('video.scenes.1.position', 1)
            ->assertJsonPath('video.scenes.1.engine', 'seedance')
            ->assertJsonPath('video.scenes.1.trim_in_ms', null)
            ->assertJsonPath('video.scenes.1.trim_out_ms', null)
            ->assertJsonPath('video.scenes.1.status', 'planned')
            ->assertJsonPath('video.scenes.1.step_index', 0);
        $this->assertSame((int) $re->json('job.id'), $re->json('video.scenes.1.agent_job_id'));
        $this->api('POST', "/api/admin/creatives/videos/{$id}/scenes/propose", ['replace_scene_id' => 99999, 'scenes' => [$this->threeScenes()[0]]])->assertStatus(422);
        $this->api('POST', "/api/admin/creatives/videos/{$id}/scenes/propose", ['replace_scene_id' => $slot, 'scenes' => [$this->threeScenes()[0], $this->threeScenes()[0]]])->assertStatus(422);
        $this->assertCount(0, $this->submitted());
    }

    // ---- Go, and the bulk run ----------------------------------------------------------------

    public function test_a_collaborator_goes_a_job_with_no_chat_and_the_clips_are_made_together(): void
    {
        $this->makeRef();
        $id = $this->makeVideo(['aspect' => '16:9', 'platform' => 'x']);
        $res = $this->api('POST', "/api/admin/creatives/videos/{$id}/scenes/propose", ['scenes' => $this->threeScenes()], $this->admin)->assertOk();
        $jobId = (int) $res->json('job.id');
        $sceneIds = array_column($res->json('video.scenes'), 'id');

        // 5 steps: soul → kling (scene 1), grok → kling (scene 2), seedance-text (scene 3).
        $job = CreativeAgentJob::findOrFail($jobId);
        $this->assertSame(['soul-image', 'kling-animate', 'grok-image', 'kling-animate', 'seedance-text-video'], array_column($job->steps, 'model'));
        $this->assertSame([null, $sceneIds[0], null, $sceneIds[1], $sceneIds[2]], array_map(fn ($s) => $s['scene_id'] ?? null, $job->steps));
        $this->assertSame([0, 2], [$job->steps[1]['source']['from_step'], $job->steps[3]['source']['from_step']]);
        // 0.004 + 5 × 0.0525 + 0.07 + 5 × 0.0525 + 6 × 0.2647 = 0.004 + 0.2625 + 0.07 + 0.2625 + 1.5882 = 2.1872
        $this->assertSame(2.1872, (float) $job->estimated_usd);

        // Go — by a collaborator, on a job no chat owns: the three independent steps start in the SAME tick.
        $go = $this->api('POST', "/api/admin/creatives/agent/jobs/{$jobId}/approve")->assertOk()
            ->assertJsonPath('job.status', 'running')
            ->assertJsonPath('job.approved_by_name', 'Mara Marketing')
            ->assertJsonPath('job.video_id', $id)
            ->assertJsonPath('job.steps.0.status', 'running')
            ->assertJsonPath('job.steps.1.status', 'waiting')
            ->assertJsonPath('job.steps.2.status', 'running')
            ->assertJsonPath('job.steps.3.status', 'waiting')
            ->assertJsonPath('job.steps.4.status', 'running');
        $submits = $this->submitted();
        $this->assertCount(3, $submits);
        $this->assertStringContainsString('soul', $submits[0]->url());
        $this->assertStringContainsString('grok', $submits[1]->url());
        $this->assertStringEndsWith('bytedance/seedance-2.5/text-to-video', $submits[2]->url());
        // Every step in the video's shape; Seedance from text gets exactly its five fields.
        $this->assertSame('16:9', $submits[0]->data()['aspect_ratio']);
        $this->assertSame('16:9', $submits[1]->data()['aspect_ratio']);
        $this->assertSame(['prompt', 'duration', 'resolution', 'aspect_ratio', 'generate_audio'], array_keys($submits[2]->data()));
        $this->assertSame(['16:9', 6, '720p', true], [$submits[2]->data()['aspect_ratio'], $submits[2]->data()['duration'], $submits[2]->data()['resolution'], $submits[2]->data()['generate_audio']]);
        // Pixel only where asked: the Grok still carries the references and the character text.
        $this->assertArrayHasKey('image_urls', $submits[1]->data());
        $this->assertStringContainsString('Character "Pixel"', $submits[1]->data()['prompt']);
        $this->assertStringNotContainsString('Character "Pixel"', $submits[0]->data()['prompt']);
        $this->assertStringNotContainsString('Character "Pixel"', $submits[2]->data()['prompt']);
        $this->assertSame([$this->collaborator], CreativeGeneration::query()->pluck('created_by_uni_id')->unique()->values()->all());

        $read = fn () => $this->api('GET', "/api/admin/creatives/videos/{$id}")->assertOk();
        $read()->assertJsonPath('video.scenes.0.status', 'generating')
            ->assertJsonPath('video.scenes.1.status', 'generating')
            ->assertJsonPath('video.scenes.2.status', 'generating');

        // The stills complete: the video read advances the job and both animations start, from their own still.
        $this->complete('req-1', ['images' => [['url' => 'https://cdn.higgsfield.test/img/still-1.png']]]);
        $this->complete('req-2', ['images' => [['url' => 'https://cdn.higgsfield.test/img/still-2.png']]]);
        $this->travel(5)->seconds();
        $read()->assertJsonPath('video.jobs.0.steps.1.status', 'running')->assertJsonPath('video.jobs.0.steps.3.status', 'running');
        $submits = $this->submitted();
        $this->assertCount(5, $submits);
        $this->assertSame('https://cdn.higgsfield.test/img/still-1.png', $submits[3]->data()['image_url']);
        $this->assertSame('https://cdn.higgsfield.test/img/still-2.png', $submits[4]->data()['image_url']);
        $this->assertSame([5, 5], [$submits[3]->data()['duration'], $submits[4]->data()['duration']]);

        // The clips land in whatever order they finish — each on its own scene.
        $this->complete('req-3', ['video' => ['url' => 'https://cdn.higgsfield.test/vid/city.mp4']]);
        $this->travel(1)->minute();
        $this->artisan('creatives:poll')->assertSuccessful();
        $read()->assertJsonPath('video.scenes.2.status', 'ready')
            ->assertJsonPath('video.scenes.2.generation.outputs.0.url', 'https://cdn.higgsfield.test/vid/city.mp4')
            ->assertJsonPath('video.scenes.0.status', 'generating')
            ->assertJsonPath('video.jobs.0.status', 'running');
        $this->complete('req-5', ['video' => ['url' => 'https://cdn.higgsfield.test/vid/pixel.mp4']]);
        $this->complete('req-4', ['video' => ['url' => 'https://cdn.higgsfield.test/vid/screens.mp4']]);
        $this->travel(1)->minute();
        $this->artisan('creatives:poll')->assertSuccessful();

        $video = $read()->assertJsonPath('video.jobs.0.status', 'completed')->json('video');
        $byRequest = CreativeGeneration::query()->pluck('id', 'request_id');
        $this->assertSame(
            [[$sceneIds[0], $byRequest['req-4'], 'ready'], [$sceneIds[1], $byRequest['req-5'], 'ready'], [$sceneIds[2], $byRequest['req-3'], 'ready']],
            array_map(fn ($s) => [$s['id'], $s['generation']['id'], $s['status']], $video['scenes']),
        );
        $this->assertSame([0, 1, 2], array_column($video['scenes'], 'position'));
        $this->assertNotNull(CreativeAgentJob::find($jobId)->finished_at);
        $this->api('GET', '/api/admin/creatives/videos')->assertJsonPath('videos.0.scenes_ready', 3)->assertJsonPath('videos.0.scenes_generating', 0);
        $this->assertCount(5, $this->submitted());
    }

    public function test_one_failed_step_fails_only_its_scene(): void
    {
        $this->makeRef();
        $id = $this->makeVideo();
        $jobId = (int) $this->api('POST', "/api/admin/creatives/videos/{$id}/scenes/propose", ['scenes' => $this->threeScenes()])->assertOk()->json('job.id');
        $this->api('POST', "/api/admin/creatives/agent/jobs/{$jobId}/approve", [], $this->admin)->assertOk();

        // Pixel's still is blocked; the other still and the Seedance clip work.
        $this->statuses['req-2'] = ['status' => 'nsfw', 'request_id' => 'req-2'];
        $this->complete('req-1', ['images' => [['url' => 'https://cdn.higgsfield.test/img/still-1.png']]]);
        $this->complete('req-3', ['video' => ['url' => 'https://cdn.higgsfield.test/vid/city.mp4']]);
        $this->travel(1)->minute();
        $this->artisan('creatives:poll')->assertSuccessful();

        $job = CreativeAgentJob::findOrFail($jobId);
        $this->assertSame(['completed', 'running', 'failed', 'canceled', 'completed'], array_column($job->steps, 'status'));
        $this->assertSame('running', $job->status);
        $this->complete('req-4', ['video' => ['url' => 'https://cdn.higgsfield.test/vid/screens.mp4']]);
        $this->travel(1)->minute();
        $this->artisan('creatives:poll')->assertSuccessful();

        $video = $this->api('GET', "/api/admin/creatives/videos/{$id}")->assertOk()
            ->assertJsonPath('video.jobs.0.status', 'completed_with_failures')
            ->assertJsonPath('video.scenes.0.status', 'ready')
            ->assertJsonPath('video.scenes.1.status', 'failed')
            ->assertJsonPath('video.scenes.1.generation', null)
            ->assertJsonPath('video.scenes.1.prompt', 'Pixel waves at the camera from a desk')
            ->assertJsonPath('video.scenes.2.status', 'ready')
            ->json('video');
        $this->assertStringContainsString('moderation', (string) $video['scenes'][1]['error']);
        $this->assertSame('completed_with_failures', CreativeAgentJob::find($jobId)->status);
        $this->assertCount(4, $this->submitted(), 'The failed still\'s animation never started.');

        // When no clip at all is made, the job fails.
        $second = $this->makeVideo(['title' => 'Second']);
        $jobId = (int) $this->api('POST', "/api/admin/creatives/videos/{$second}/scenes/propose", ['scenes' => [$this->threeScenes()[2]]])->json('job.id');
        $this->api('POST', "/api/admin/creatives/agent/jobs/{$jobId}/approve")->assertOk();
        $this->statuses['req-5'] = ['status' => 'failed', 'request_id' => 'req-5', 'error' => 'Render failed'];
        $this->travel(1)->minute();
        $this->artisan('creatives:poll')->assertSuccessful();
        $this->assertSame('failed', CreativeAgentJob::find($jobId)->status);
        $this->api('GET', "/api/admin/creatives/videos/{$second}")->assertJsonPath('video.scenes.0.status', 'failed')
            ->assertJsonPath('video.scenes.0.error', 'Render failed');
    }

    public function test_at_most_four_steps_of_a_job_run_at_once(): void
    {
        $id = $this->makeVideo();
        $seedance = $this->threeScenes()[2];
        $seedance['seconds'] = 5;
        $jobId = (int) $this->api('POST', "/api/admin/creatives/videos/{$id}/scenes/propose", ['scenes' => array_fill(0, 6, $seedance)])->assertOk()->json('job.id');
        $this->api('POST', "/api/admin/creatives/agent/jobs/{$jobId}/approve")->assertOk();
        $this->assertCount(CreativeAgentJobs::MAX_IN_FLIGHT, $this->submitted());

        $this->complete('req-2', ['video' => ['url' => 'https://cdn.higgsfield.test/vid/2.mp4']]);
        $this->travel(1)->minute();
        $this->artisan('creatives:poll')->assertSuccessful();
        $this->assertCount(5, $this->submitted());
        $this->assertSame(['running', 'completed', 'running', 'running', 'running', 'waiting'], array_column(CreativeAgentJob::find($jobId)->steps, 'status'));
    }

    public function test_cancelling_or_letting_a_proposal_expire_releases_its_slots(): void
    {
        $id = $this->makeVideo();
        $jobId = (int) $this->api('POST', "/api/admin/creatives/videos/{$id}/scenes/propose", ['scenes' => [$this->threeScenes()[0]]])->json('job.id');
        $this->api('POST', "/api/admin/creatives/agent/jobs/{$jobId}/cancel", [], $this->admin)->assertOk()->assertJsonPath('job.status', 'canceled');
        $this->api('GET', "/api/admin/creatives/videos/{$id}")->assertJsonPath('video.scenes.0.status', 'failed');

        $jobId = (int) $this->api('POST', "/api/admin/creatives/videos/{$id}/scenes/propose", ['scenes' => [$this->threeScenes()[0]]])->json('job.id');
        $this->travel(61)->minutes();
        $this->api('GET', "/api/admin/creatives/videos/{$id}")
            ->assertJsonPath('video.jobs.1.status', 'expired')
            ->assertJsonPath('video.scenes.1.status', 'failed');
        $this->assertSame('expired', CreativeAgentJob::find($jobId)->status);
        $this->assertCount(0, $this->submitted());
    }

    public function test_a_video_job_outlives_its_pruned_chat_detached(): void
    {
        $id = $this->makeVideo();
        $chat = CreativeAgentSession::create(['uni_id' => $this->collaborator, 'user_name' => 'Mara Marketing']);
        $videoJob = CreativeAgentJob::create(['session_id' => $chat->id, 'video_id' => $id, 'owner_uni_id' => $this->collaborator, 'title' => 'Video: x', 'status' => 'completed', 'steps' => [], 'estimated_usd' => 0]);
        $chatJob = CreativeAgentJob::create(['session_id' => $chat->id, 'title' => 'A still', 'status' => 'completed', 'steps' => [], 'estimated_usd' => 0]);
        $this->travel(8)->days();

        $this->artisan('creatives:agent-prune')->assertSuccessful();

        $this->assertNull(CreativeAgentSession::find($chat->id));
        $this->assertNull(CreativeAgentJob::find($chatJob->id));
        $kept = CreativeAgentJob::find($videoJob->id);
        $this->assertNotNull($kept, 'The editor still lists the job that filled its scenes.');
        $this->assertNull($kept->session_id);
        $this->assertSame($this->collaborator, $kept->owner_uni_id);
        $this->api('GET', "/api/admin/creatives/videos/{$id}")->assertOk()->assertJsonPath('video.jobs.0.id', $videoJob->id);
    }

    // ---- export -------------------------------------------------------------------

    public function test_the_export_is_recorded_as_metadata(): void
    {
        $id = $this->makeVideo();
        $this->api('POST', "/api/admin/creatives/videos/{$id}/exported", ['seconds' => 21.456, 'bytes' => 4_812_330], $this->admin)->assertOk()
            ->assertJsonPath('video.status', 'exported')
            ->assertJsonPath('video.exported_by_name', 'Owner Admin')
            ->assertJsonPath('video.export_seconds', 21.46)
            ->assertJsonPath('video.export_bytes', 4812330)
            ->assertJsonPath('video.exported_at', '2026-11-15T12:00:00+00:00');
        $this->api('POST', "/api/admin/creatives/videos/{$id}/exported", ['seconds' => -1, 'bytes' => 10])->assertStatus(422);
        $this->api('POST', "/api/admin/creatives/videos/{$id}/exported", ['seconds' => 3])->assertStatus(422);
    }
}
