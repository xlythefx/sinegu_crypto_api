<?php

namespace Tests\Feature;

use App\Models\CreativeAgentJob;
use App\Models\CreativeAgentMessage;
use App\Models\CreativeAgentSession;
use App\Models\CreativeVideo;
use App\Models\CreativeVideoScene;
use App\Models\UserCredential;
use App\Services\Creatives\CreativeAgentModel;
use App\Services\Creatives\CreativeAgentPrompt;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Support\FakeCreativeAgentModel;

/**
 * Admin → Creatives v2.1: the chat agent shows how it thinks, shows a
 * storyboard before it builds, makes scenes of any length, and has a chat
 * inside the video editor that edits the video for free.
 *
 * Pinned here: thinking summaries reach the presented assistant message while
 * the stored blocks replay byte for byte; show_storyboard's card and its
 * LOCAL prices (hand-derived below; Higgsfield is never asked) and its 30 s
 * limit; a scene's length on the timeline mapped to the length the model
 * makes plus a trim; the editor chat bound to a video (the workspace chat
 * never picks it), the focus_scene_ids checks and the [Context] the model
 * receives; edit_video's every change, TOO_LONG, the privacy wall and Ask
 * mode; and that the two new tools are plain (STRICT_TOOLS unchanged) in a
 * tools array that stays byte-identical across calls.
 *
 * Claude is a FAKE bound over CreativeAgentModel; Higgsfield is one catch-all
 * Http::fake (an unexpected URL fails the test) with stray requests
 * prevented. No ConnectionException is ever faked (that crashes PHP natively
 * on the WAMP box).
 */
class CreativesEditorChatTest extends EngineTestCase
{
    private FakeCreativeAgentModel $claude;

    private string $admin;

    private string $collaborator;

    /** @var list<string> */
    private array $unexpected = [];

    private int $submits = 0;

    protected function setUp(): void
    {
        parent::setUp();
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

        $this->admin = $this->makeUser(['type' => 'admin', 'name' => 'Owner Admin']);
        $this->collaborator = $this->makeUser(['type' => 'collaborator', 'name' => 'Mara Marketing']);

        Http::preventStrayRequests();
        Http::fake(function (ClientRequest $request) {
            $url = $request->url();
            if (str_starts_with($url, 'https://api.higgsfield.ai/estimate/')) {
                // Kling and Seedance answer a description, as they do live: their per-second rates are reserved.
                if (str_contains($url, 'kling') || str_contains($url, 'seedance')) {
                    return Http::response(['type' => 'description', 'pricing_description' => 'per video second']);
                }

                return Http::response(['type' => 'estimate', 'usd' => str_contains($url, 'grok') ? '0.070' : '0.004']);
            }
            if (preg_match('#^https://api\.higgsfield\.ai/requests/([^/]+)/status$#', $url, $m) === 1) {
                return Http::response(['status' => 'queued', 'request_id' => $m[1]]);
            }
            if (str_starts_with($url, 'https://api.higgsfield.ai/') && ! str_contains($url, '/files/')) {
                $n = ++$this->submits;

                return Http::response(['request_id' => "req-{$n}", 'status' => 'queued']);
            }
            $this->unexpected[] = $url;

            return Http::response(['detail' => 'unexpected'], 418);
        });
    }

    protected function tearDown(): void
    {
        $this->assertSame([], $this->unexpected, 'A request went to a URL the test did not expect.');
        parent::tearDown();
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

    private function sessionId(?int $videoId = null): string
    {
        $uri = '/api/admin/creatives/agent/session'.($videoId !== null ? '?video_id='.$videoId : '');

        return (string) $this->api('GET', $uri)->assertOk()->json('session.id');
    }

    private function turn(string $session, string $mode, ?string $text = null, ?array $focus = null): TestResponse
    {
        $body = ['session_id' => $session, 'mode' => $mode];
        if ($text !== null) {
            $body['text'] = $text;
        }
        if ($focus !== null) {
            $body['focus_scene_ids'] = $focus;
        }

        return $this->api('POST', '/api/admin/creatives/agent/turn', $body);
    }

    /** The stored tool_result blocks of the newest tool-results message. */
    private function lastResults(string $session): array
    {
        $row = CreativeAgentMessage::query()->where('session_id', $session)->where('role', 'user')->orderByDesc('id')->get()
            ->first(fn (CreativeAgentMessage $m) => ! $m->isPerson());

        return array_values(array_filter($row?->blocks() ?? [], fn ($b) => ($b['type'] ?? null) === 'tool_result'));
    }

    /** The [Context] state JSON of a recorded call's newest person message. */
    private function contextState(array $call): array
    {
        $person = null;
        foreach ($call['decoded'] as $message) {
            foreach ($message['content'] as $block) {
                if (($block['type'] ?? null) === 'text' && str_starts_with((string) $block['text'], '[Context]')) {
                    $person = $block['text'];
                }
            }
        }
        $this->assertNotNull($person, 'No [Context] block was sent.');

        return json_decode(explode("\n", $person)[1], true);
    }

    private function makeVideo(string $title = 'Weekly recap'): int
    {
        return (int) $this->api('POST', '/api/admin/creatives/videos', ['title' => $title, 'platform' => 'tiktok', 'aspect' => '9:16'])
            ->assertCreated()->json('video.id');
    }

    private function template(string $key = 'clean-stat', array $over = []): array
    {
        return array_merge(['key' => $key, 'title' => 'This week', 'value' => '+6.03%', 'subtitle' => 'Published results'], $over);
    }

    /** A video of an 8 s Kling clip (made 10 s), a 3 s stat card and a 2 s disclaimer card, two of them captioned. */
    private function threeSceneVideo(): array
    {
        $id = $this->makeVideo();
        $res = $this->api('POST', "/api/admin/creatives/videos/{$id}/scenes/propose", ['scenes' => [
            ['kind' => 'clip', 'label' => 'Screens', 'prompt' => 'Slow push-in on glowing trading screens', 'seconds' => 8, 'engine' => 'kling', 'with_character' => false, 'caption' => 'Calm and steady', 'template' => null],
            ['kind' => 'template', 'label' => 'Stat', 'prompt' => null, 'seconds' => 3, 'engine' => null, 'with_character' => false, 'caption' => 'Up this week', 'template' => $this->template()],
            ['kind' => 'template', 'label' => 'Disclaimer', 'prompt' => null, 'seconds' => 2, 'engine' => null, 'with_character' => false, 'caption' => null, 'template' => $this->template('disclaimer', ['title' => '', 'value' => '', 'subtitle' => 'Past performance does not guarantee future results.'])],
        ]])->assertOk();

        return [$id, array_map(fn ($s) => (int) $s['id'], $res->json('video.scenes')), (int) $res->json('job.id')];
    }

    /** An edit_video input with every property, "keep" values by default. */
    private static function edit(int $videoId, array $changes, array $over = []): array
    {
        return array_merge(['video_id' => $videoId, 'scene_changes' => $changes, 'music' => 'keep', 'caption_style' => 'keep', 'pixel_look' => 'keep'], $over);
    }

    /** One scene_changes entry with every property, "keep" values by default. */
    private static function change(int $sceneId, array $over = []): array
    {
        return array_merge([
            'scene_id' => $sceneId, 'label' => '', 'seconds' => 0, 'position' => -1,
            'template_title' => '', 'template_value' => '', 'template_subtitle' => '', 'caption' => '-', 'remove' => false,
        ], $over);
    }

    /** One storyboard scene with every property. */
    private static function board(string $kind, int $seconds, string $engine, bool $pixel = false, string $label = ''): array
    {
        return ['kind' => $kind, 'label' => $label, 'seconds' => $seconds, 'engine' => $engine, 'with_pixel' => $pixel,
            'shows' => 'Glowing screens at night', 'why' => 'A silent 3 s hook; the music carries it'];
    }

    // ---- 1. thinking summaries ---------------------------------------------------------

    public function test_thinking_summaries_reach_the_assistant_message_and_the_blocks_replay_unchanged(): void
    {
        $session = $this->sessionId();
        $first = FakeCreativeAgentModel::tools([['get_budget', []]], '', thinking: 'The person wants a video; check the budget first.');
        $two = FakeCreativeAgentModel::answer([
            FakeCreativeAgentModel::thinking('Plenty is left.'),
            FakeCreativeAgentModel::thinking(''),
            FakeCreativeAgentModel::thinking('Suggest three scenes.'),
            ['type' => 'text', 'text' => 'You have plenty left.'],
        ], 'end_turn');
        $this->claude->replies = [$first, $two, FakeCreativeAgentModel::text('Hi.')];

        // A tools-only step still shows its reasoning: an assistant item with no text.
        $res = $this->turn($session, 'ask', 'Can we afford a video?')->assertOk()->assertJsonPath('status', 'continue');
        $this->assertSame(['user', 'assistant', 'activity'], array_column($res->json('messages'), 'kind'));
        $this->assertSame('', $res->json('messages.1.text'));
        $this->assertSame('The person wants a video; check the budget first.', $res->json('messages.1.thinking'));
        $this->assertArrayNotHasKey('thinking', $res->json('messages.0'));
        $this->assertArrayNotHasKey('thinking', $res->json('messages.2'));

        // Several thinking blocks: the non-empty ones, joined by a blank line.
        $done = $this->turn($session, 'ask')->assertOk()->assertJsonPath('status', 'done');
        $this->assertSame(['assistant'], array_column($done->json('messages'), 'kind'));
        $this->assertSame('You have plenty left.', $done->json('messages.0.text'));
        $this->assertSame("Plenty is left.\n\nSuggest three scenes.", $done->json('messages.0.thinking'));

        // No readable thinking: the key is there, null.
        $plain = $this->turn($session, 'ask', 'Thanks')->assertOk()->json('messages.1');
        $this->assertArrayHasKey('thinking', $plain);
        $this->assertNull($plain['thinking']);

        // The blocks are stored and replayed exactly, summaries and signatures included.
        $stored = CreativeAgentMessage::query()->where('session_id', $session)->where('role', 'assistant')->orderBy('id')->get();
        $this->assertSame($first->content, $stored[0]->content);
        $this->assertSame($two->content, $stored[1]->content);
        [, $c1, $c2] = $this->claude->calls;
        $this->assertSame($first->content, json_encode($c1['objects'][1]->content, FakeCreativeAgentModel::FLAGS));
        $this->assertSame($two->content, json_encode($c2['objects'][3]->content, FakeCreativeAgentModel::FLAGS));
        $this->assertSame('The person wants a video; check the budget first.', $c2['decoded'][1]['content'][0]['thinking']);

        // The session read presents the same reasoning.
        $all = $this->api('GET', '/api/admin/creatives/agent/session?id='.$session)->assertOk()->json('messages');
        $assistants = array_values(array_filter($all, fn ($m) => $m['kind'] === 'assistant'));
        $this->assertSame(['The person wants a video; check the budget first.', "Plenty is left.\n\nSuggest three scenes.", null], array_column($assistants, 'thinking'));
        $ids = array_column($all, 'id');
        $this->assertSame(count($ids), count(array_unique($ids)));
    }

    // ---- 2. the storyboard ----------------------------------------------------------------

    public function test_show_storyboard_shows_a_locally_priced_card_and_spends_nothing(): void
    {
        $session = $this->sessionId();
        $input = [
            'title' => 'Arcade recap', 'video_id' => 0, 'platform' => 'tiktok', 'aspect' => '9:16', 'style' => '',
            'scenes' => [
                self::board('clip', 3, 'kling', label: 'Hook'),
                self::board('clip', 6, 'seedance'),
                self::board('template', 3, 'none', label: 'Score'),
                self::board('clip', 8, 'kling', true, 'Pixel cameo'),
                self::board('clip', 6, 'seedance', true, 'Pixel talks'),
            ],
            'notes' => 'Five scenes under 10 s keep TikTok retention; the stat card is free.',
        ];
        $tooLong = ['title' => 'Long', 'video_id' => 0, 'platform' => 'tiktok', 'aspect' => '9:16', 'style' => '', 'notes' => '',
            'scenes' => array_fill(0, 6, self::board('clip', 6, 'seedance'))];
        $this->claude->replies = [FakeCreativeAgentModel::tools([
            ['show_storyboard', $input],
            ['show_storyboard', $tooLong],
            ['show_storyboard', ['video_id' => 999] + $input],
            ['show_storyboard', ['scenes' => [self::board('clip', 12, 'kling')]] + $input],
            ['show_storyboard', ['style' => 'pixel-arcade', 'scenes' => [self::board('clip', 5, 'none')]] + $input],
        ]), FakeCreativeAgentModel::text('Build it?')];

        // Both modes: Ask shows it too.
        $res = $this->turn($session, 'ask', 'Plan an arcade recap')->assertOk()->assertJsonPath('status', 'continue');
        $results = $this->lastResults($session);
        $this->assertArrayNotHasKey('is_error', $results[0], $results[0]['content']);

        $card = collect($res->json('messages'))->firstWhere('kind', 'storyboard');
        $board = $card['storyboard'];
        $this->assertSame(['title', 'video_id', 'platform', 'aspect', 'style', 'total_seconds', 'est_total_usd', 'notes', 'scenes'], array_keys($board));
        $this->assertSame(['Arcade recap', null, 'tiktok', '9:16', null, 26], [$board['title'], $board['video_id'], $board['platform'], $board['aspect'], $board['style'], $board['total_seconds']]);
        $this->assertSame('Five scenes under 10 s keep TikTok retention; the stat card is free.', $board['notes']);
        $this->assertSame(['n', 'kind', 'label', 'seconds', 'generated_seconds', 'engine', 'engine_label', 'with_pixel', 'shows', 'why', 'est_usd'], array_keys($board['scenes'][0]));
        $rows = array_map(fn ($s) => [$s['n'], $s['kind'], $s['label'], $s['seconds'], $s['generated_seconds'], $s['engine'], $s['with_pixel'], $s['est_usd']], $board['scenes']);
        // Hand-derived, never asked of Higgsfield:
        //   1 kling 3 s → made 5 s: Soul still 0.004 + 5 × 0.0525 = 0.2665
        //   2 seedance 6 s → made 6 s at 720p: 6 × 0.2647 = 1.5882
        //   3 template → free
        //   4 kling 8 s with Pixel → made 10 s: Grok still 0.07 + 10 × 0.0525 = 0.595
        //   5 seedance 6 s with Pixel (reference-to-video) → 6 × 0.2647 = 1.5882
        $this->assertEquals([
            [1, 'clip', 'Hook', 3, 5, 'kling', false, 0.2665],
            [2, 'clip', 'Scene 2', 6, 6, 'seedance', false, 1.5882],
            [3, 'template', 'Score', 3, 3, 'none', false, 0],
            [4, 'clip', 'Pixel cameo', 8, 10, 'kling', true, 0.595],
            [5, 'clip', 'Pixel talks', 6, 6, 'seedance', true, 1.5882],
        ], $rows);
        $this->assertSame(['Kling (silent)', 'Seedance (with sound)', null], [$board['scenes'][0]['engine_label'], $board['scenes'][1]['engine_label'], $board['scenes'][2]['engine_label']]);
        // 0.2665 + 1.5882 + 0 + 0.595 + 1.5882 = 4.0379
        $this->assertSame(4.0379, $board['est_total_usd']);
        $this->assertSame('Glowing screens at night', $board['scenes'][0]['shows']);
        $this->assertSame('A silent 3 s hook; the music carries it', $board['scenes'][0]['why']);

        // The model gets the same data and the next step.
        $sent = json_decode($results[0]['content'], true);
        $this->assertSame($board, $sent['storyboard']);
        $this->assertStringContainsString('"Build it?" with ask_choices', $sent['instruction']);
        $this->assertStringContainsString('estimates', $sent['instruction']);

        // Over 30 s, an unknown video, a 12 s Kling scene: errors the model can fix, no card.
        $this->assertTrue($results[1]['is_error']);
        $this->assertStringContainsString('36 seconds; a video is at most 30 seconds', $results[1]['content']);
        $this->assertTrue($results[2]['is_error']);
        $this->assertStringContainsString('no video #999', $results[2]['content']);
        $this->assertTrue($results[3]['is_error']);
        $this->assertStringContainsString('Kling scene is 2 to 10 seconds', $results[3]['content']);
        $this->assertCount(2, array_filter($res->json('messages'), fn ($m) => $m['kind'] === 'storyboard'));
        // "none" takes the style's default engine (pixel-arcade: seedance), made at least 4 s.
        $styled = json_decode($results[4]['content'], true)['storyboard'];
        $this->assertSame(['pixel-arcade', 'seedance', 5, 1.3235], [$styled['style'], $styled['scenes'][0]['engine'], $styled['scenes'][0]['generated_seconds'], $styled['scenes'][0]['est_usd']]);

        // Nothing made, saved, priced at Higgsfield or spent.
        $this->assertSame(0, CreativeVideo::count());
        $this->assertSame(0, CreativeAgentJob::count());
        $this->assertCount(0, Http::recorded());
    }

    // ---- 3. any scene length ----------------------------------------------------------------

    public function test_a_scenes_length_is_made_at_a_length_the_model_makes_and_trimmed(): void
    {
        $scene = fn (string $engine, int $seconds, string $label) => [
            'kind' => 'clip', 'label' => $label, 'prompt' => 'Slow push-in on glowing trading screens', 'seconds' => $seconds, 'engine' => $engine,
            'with_pixel' => false, 'caption' => $label, 'template_key' => 'none', 'template_title' => '', 'template_value' => '', 'template_subtitle' => '',
        ];
        $this->claude->replies = [FakeCreativeAgentModel::tools([['create_video', [
            'title' => 'Lengths', 'plan_id' => 0, 'style' => '', 'platform' => 'tiktok', 'aspect' => '9:16',
            'caption_style' => 'bold', 'music' => 'none', 'pixel_look' => false,
            'scenes' => [$scene('kling', 3, 'K3'), $scene('kling', 8, 'K8'), $scene('seedance', 6, 'S6'), $scene('seedance', 2, 'S2')],
        ]]])];
        $this->turn($this->sessionId(), 'generate', 'Build it')->assertOk()->assertJsonPath('status', 'awaiting_approval');

        $video = CreativeVideo::sole();
        $rows = CreativeVideoScene::query()->where('video_id', $video->id)->orderBy('position')->get();
        // kling 3 → made 5, trimmed to 3 s; kling 8 → made 10, trimmed to 8; seedance 6 → 6, no trim; seedance 2 → made 4, trimmed to 2.
        $this->assertSame([[5, null, 3000], [10, null, 8000], [6, null, null], [4, null, 2000]], $rows->map(fn ($s) => [$s->seconds, $s->trim_in_ms, $s->trim_out_ms])->all());
        $this->assertSame([3000, 8000, 6000, 2000], $rows->map(fn ($s) => $s->durationMs())->all());

        // The job makes those lengths.
        $job = CreativeAgentJob::sole();
        $clipSteps = array_values(array_filter($job->steps, fn ($s) => isset($s['scene_id'])));
        $this->assertSame([['kling-animate', 5], ['kling-animate', 10], ['seedance-text-video', 6], ['seedance-text-video', 4]], array_map(fn ($s) => [$s['model'], $s['params']['duration']], $clipSteps));
        // Kling 5 s + 10 s at 0.0525/s with two Soul stills, Seedance 6 s + 4 s at 0.2647/s:
        // 0.004 + 0.2625 + 0.004 + 0.525 + 1.5882 + 1.0588 = 3.4425
        $this->assertSame(3.4425, round((float) $job->estimated_usd, 4));

        // Captions span the TRIMMED slots: 0–3, 3–11, 11–17, 17–19 s.
        $this->assertSame([[0, 3000], [3000, 11000], [11000, 17000], [17000, 19000]], array_map(fn ($c) => [$c['start_ms'], $c['end_ms']], $video->timeline['captions']));

        // The editor's propose route maps the same way, and a replaced slot takes its new trim.
        $propose = fn (array $body) => $this->api('POST', "/api/admin/creatives/videos/{$video->id}/scenes/propose", $body);
        $res = $propose(['replace_scene_id' => $rows[2]->id, 'scenes' => [
            ['kind' => 'clip', 'label' => 'K4', 'prompt' => 'Coins spinning', 'seconds' => 4, 'engine' => 'kling', 'with_character' => false, 'caption' => null, 'template' => null],
        ]])->assertOk()
            ->assertJsonPath('video.scenes.2.seconds', 5)
            ->assertJsonPath('video.scenes.2.trim_in_ms', null)
            ->assertJsonPath('video.scenes.2.trim_out_ms', 4000)
            ->assertJsonPath('video.scenes.2.duration_ms', 4000)
            ->assertJsonPath('job.steps.1.model', 'kling-animate');
        $this->assertSame(0.2665, $res->json('job.estimated_usd'));
        foreach ([1, 11, 16] as $bad) {
            $propose(['scenes' => [['kind' => 'clip', 'label' => 'x', 'prompt' => 'A rocket', 'seconds' => $bad, 'engine' => $bad === 16 ? 'seedance' : 'kling', 'with_character' => false, 'caption' => null, 'template' => null]]])
                ->assertStatus(422);
        }
    }

    // ---- 4. the editor's chat ------------------------------------------------------------------

    public function test_the_editor_chat_is_bound_to_its_video_and_carries_the_focus(): void
    {
        [$videoId, $sceneIds] = $this->threeSceneVideo();
        $other = $this->makeVideo('Other');
        $otherScene = (int) $this->api('POST', "/api/admin/creatives/videos/{$other}/scenes", ['kind' => 'template', 'template' => $this->template(), 'seconds' => 3])
            ->assertOk()->json('video.scenes.0.id');

        // The editor chat first: the workspace chat never picks it as "the newest".
        $editor = $this->api('GET', '/api/admin/creatives/agent/session?video_id='.$videoId)->assertOk()
            ->assertJsonPath('session.video_id', $videoId)->assertJsonPath('messages', [])->json('session.id');
        $this->assertSame($videoId, CreativeAgentSession::find($editor)->video_id);
        $this->travel(1)->minute();
        $main = $this->api('GET', '/api/admin/creatives/agent/session')->assertOk()->assertJsonPath('session.video_id', null)->json('session.id');
        $this->assertNotSame($editor, $main);
        $this->assertSame($editor, $this->sessionId($videoId), 'The video\'s chat is offered again.');
        // Even when the editor chat is the most recently used one.
        $this->travel(1)->minute();
        $this->claude->replies = [FakeCreativeAgentModel::text('Hi.')];
        $this->turn($editor, 'ask', 'Hello')->assertOk();
        $this->assertSame($main, $this->sessionId());
        // Naming the workspace chat on a video read still answers the video's chat.
        $this->api('GET', "/api/admin/creatives/agent/session?video_id={$videoId}&id={$main}")->assertOk()->assertJsonPath('session.id', $editor);
        $this->assertNotSame($editor, $this->sessionId($other));

        $this->api('GET', '/api/admin/creatives/agent/session?video_id=99999')->assertNotFound()->assertJsonPath('error_code', 'NOT_FOUND');
        $this->api('POST', '/api/admin/creatives/agent/sessions', ['video_id' => 99999])->assertNotFound();
        $fresh = $this->api('POST', '/api/admin/creatives/agent/sessions', ['video_id' => $videoId])->assertOk()
            ->assertJsonPath('session.video_id', $videoId)->json('session.id');
        $this->assertNotSame($editor, $fresh);
        $this->api('POST', '/api/admin/creatives/agent/sessions')->assertOk()->assertJsonPath('session.video_id', null);
        $editor = $fresh;

        // focus_scene_ids: only this video's scenes, at most 12, never on the workspace chat — 422 before any model call.
        $calls = count($this->claude->calls);
        $this->turn($editor, 'ask', 'Improve this', [$otherScene])->assertStatus(422)->assertJsonValidationErrors('focus_scene_ids');
        $this->turn($editor, 'ask', 'Improve this', range(1, 13))->assertStatus(422)->assertJsonValidationErrors('focus_scene_ids');
        $this->turn($main, 'ask', 'Improve this', [$sceneIds[0]])->assertStatus(422)->assertJsonValidationErrors('focus_scene_ids');
        $this->assertCount($calls, $this->claude->calls);

        // The model receives the video as it is and the selection, in THIS message's [Context].
        $this->claude->replies = [FakeCreativeAgentModel::text('On it.')];
        $this->turn($editor, 'generate', 'Improve the visuals of these', [$sceneIds[2], $sceneIds[0]])->assertOk();
        $state = $this->contextState($this->claude->calls[count($this->claude->calls) - 1]);
        $editing = $state['editing_video'];
        $this->assertSame(['id', 'title', 'platform', 'aspect', 'style', 'duration_ms', 'max_seconds', 'music', 'caption_style', 'pixel_look', 'scenes'], array_keys($editing));
        $this->assertSame([$videoId, 'Weekly recap', 'tiktok', '9:16', null, 13000, 30, 'none', 'bold', false], array_slice(array_values($editing), 0, 10));
        $this->assertSame(['scene_id', 'n', 'kind', 'label', 'status', 'engine', 'with_pixel', 'seconds', 'duration_ms', 'prompt', 'template', 'caption'], array_keys($editing['scenes'][0]));
        $this->assertSame(
            [[$sceneIds[0], 1, 'clip', 'Screens', 'planned', 'kling', false, 10, 8000, 'Slow push-in on glowing trading screens', null, 'Calm and steady'],
                [$sceneIds[1], 2, 'template', 'Stat', 'ready', null, false, 3, 3000, null, $this->template(), 'Up this week'],
                [$sceneIds[2], 3, 'template', 'Disclaimer', 'ready', null, false, 2, 2000, null, $this->template('disclaimer', ['title' => '', 'value' => '', 'subtitle' => 'Past performance does not guarantee future results.']), null]],
            array_map('array_values', $editing['scenes']),
        );
        $this->assertSame([$sceneIds[2], $sceneIds[0]], $state['focus']['scene_ids']);
        $this->assertSame("The person is talking about THESE scenes (n 3, 1); 'this'/'it' means them. Empty = the whole video.", $state['focus']['rule']);

        // The focus is never stored: the next message without one is about the whole video.
        $this->claude->replies = [FakeCreativeAgentModel::text('Sure.')];
        $this->turn($editor, 'ask', 'And the whole thing?')->assertOk();
        $state = $this->contextState($this->claude->calls[count($this->claude->calls) - 1]);
        $this->assertSame([], $state['focus']['scene_ids']);
        $this->assertStringContainsString('(n none)', $state['focus']['rule']);
        $this->assertArrayNotHasKey('focus_scene_ids', CreativeAgentSession::find($editor)->getAttributes());

        // The workspace chat carries neither.
        $this->claude->replies = [FakeCreativeAgentModel::text('Hi.')];
        $this->turn($main, 'ask', 'Hi')->assertOk();
        $state = $this->contextState($this->claude->calls[count($this->claude->calls) - 1]);
        $this->assertArrayNotHasKey('editing_video', $state);
        $this->assertArrayNotHasKey('focus', $state);
    }

    // ---- 5. edit_video ---------------------------------------------------------------------------

    public function test_edit_video_changes_the_video_for_free(): void
    {
        $this->makeUser(['name' => 'Juan Dela Cruz']);
        [$videoId, [$clip, $stat, $disclaimer]] = $this->threeSceneVideo();
        $session = $this->sessionId($videoId);
        $estimates = count(Http::recorded());

        // Label + a shorter clip; the stat card first with a new figure and caption; the music, captions and look.
        $this->claude->replies = [FakeCreativeAgentModel::tools([['edit_video', self::edit($videoId, [
            self::change($clip, ['label' => 'Screens at dusk', 'seconds' => 6]),
            self::change($stat, ['template_value' => '+7.10%', 'caption' => 'Best week yet', 'position' => 0]),
        ], ['music' => 'arcade-chill', 'caption_style' => 'pixel', 'pixel_look' => 'on'])]]), FakeCreativeAgentModel::text('Done.')];
        $res = $this->turn($session, 'generate', 'Stat first, shorter clip, chill music', [$stat])->assertOk()->assertJsonPath('status', 'continue');
        $results = $this->lastResults($session);
        $this->assertArrayNotHasKey('is_error', $results[0], $results[0]['content']);
        $activity = collect($res->json('messages'))->firstWhere('kind', 'activity');
        $this->assertSame(['tool' => 'edit_video', 'label' => 'Edited the video', 'ok' => true], $activity['activity']);
        $this->assertSame($videoId, $activity['video_id']);

        $scenes = CreativeVideoScene::query()->where('video_id', $videoId)->orderBy('position')->get();
        $this->assertSame([$stat, $clip, $disclaimer], $scenes->pluck('id')->all());
        $this->assertSame(['Stat', 'Screens at dusk', 'Disclaimer'], $scenes->pluck('label')->all());
        $this->assertSame([10, null, 6000], [$scenes[1]->seconds, $scenes[1]->trim_in_ms, $scenes[1]->trim_out_ms]);
        $this->assertSame('+7.10%', $scenes[0]->template['value']);
        $this->assertSame('This week', $scenes[0]->template['title'], 'An empty template field keeps the text.');
        $video = CreativeVideo::findOrFail($videoId);
        // Captions follow their scene: the stat's now 0–3 s (renamed), the clip's 3–9 s (kept inside its shorter slot).
        $this->assertSame([['Calm and steady', 3000, 9000], ['Best week yet', 0, 3000]], array_map(fn ($c) => [$c['text'], $c['start_ms'], $c['end_ms']], $video->timeline['captions']));
        $this->assertSame(['source' => 'chiptune', 'preset' => 'arcade-chill', 'volume' => 0.6, 'fade_out_ms' => 1500], array_diff_key($video->timeline['music'], ['seed' => 1]));
        $this->assertSame(['pixel', ['pixel_size' => 10, 'scanlines' => true]], [$video->timeline['caption_style'], $video->timeline['look']]);
        $this->assertSame('Mara Marketing', $video->updated_by_name);
        // The answer is get_video's shape.
        $got = json_decode($results[0]['content'], true)['video'];
        $this->assertEquals([$videoId, 11], [$got['id'], $got['seconds_used']]);
        $this->assertSame(['Stat', 'Screens at dusk', 'Disclaimer'], array_column($got['scenes'], 'label'));
        $this->assertSame(0, $this->submits, 'An edit never reaches Higgsfield.');
        $this->assertCount($estimates, Http::recorded());

        // Remove a scene, a caption, the music; the look off. Then refusals, each saving nothing.
        $this->travel(1)->minute();
        $this->api('POST', "/api/admin/creatives/videos/{$videoId}/scenes", ['kind' => 'template', 'template' => $this->template(), 'seconds' => 6])->assertOk();
        $this->api('POST', "/api/admin/creatives/videos/{$videoId}/scenes", ['kind' => 'template', 'template' => $this->template(), 'seconds' => 6])->assertOk();
        $this->api('POST', "/api/admin/creatives/videos/{$videoId}/scenes", ['kind' => 'template', 'template' => $this->template(), 'seconds' => 6])->assertOk();
        // 3 + 6 + 2 + 18 = 29 s now.
        $calls = [
            ['edit_video', self::edit($videoId, [self::change($disclaimer, ['remove' => true]), self::change($clip, ['caption' => ''])], ['music' => 'none', 'pixel_look' => 'off'])],
            ['edit_video', self::edit($videoId, [self::change($clip, ['seconds' => 10, 'label' => 'Too long'])])],       // 3 + 10 + 18 = 31 s
            ['edit_video', self::edit($videoId, [self::change($clip, ['seconds' => 11])])],                               // made 10 s
            ['edit_video', self::edit($videoId, [self::change($stat, ['seconds' => 2.5])])],
            ['edit_video', self::edit($videoId, [self::change($stat, ['seconds' => 7])])],
            ['edit_video', self::edit($videoId, [self::change($clip, ['template_title' => 'Nope'])])],
            ['edit_video', self::edit($videoId, [self::change(999999, ['label' => 'Ghost'])])],
            ['edit_video', self::edit($videoId, [self::change($stat, ['template_value' => '$40'])])],
            ['edit_video', self::edit($videoId, [self::change($stat, ['caption' => 'Thanks Juan Dela Cruz'])])],
            ['edit_video', self::edit($videoId, [self::change($clip, ['label' => 'juan dela cruz at his desk'])])],
            ['edit_video', self::edit($videoId, [self::change($stat)])],
            ['edit_video', self::edit(999999, [])],
        ];
        $this->claude->replies = [FakeCreativeAgentModel::tools($calls), FakeCreativeAgentModel::text('Done.')];
        $this->turn($session, 'generate', 'More changes')->assertOk();
        $results = $this->lastResults($session);
        $this->assertArrayNotHasKey('is_error', $results[0], $results[0]['content']);
        $scenes = CreativeVideoScene::query()->where('video_id', $videoId)->orderBy('position')->get();
        $this->assertNotContains($disclaimer, $scenes->pluck('id')->all());
        $this->assertSame(range(0, 4), $scenes->pluck('position')->all(), 'Positions are re-packed.');
        $video->refresh();
        $this->assertSame(['Best week yet'], array_column($video->timeline['captions'], 'text'));
        $this->assertNull($video->timeline['music']);
        $this->assertSame(['pixel_size' => 0, 'scanlines' => false], $video->timeline['look']);

        $expect = [
            1 => 'at most 30 seconds',
            2 => 'made 10 seconds long, so it can be at most 10 seconds',
            3 => 'whole number of seconds',
            4 => 'template scene is 2 to 6 seconds',
            5 => 'only a template scene has card text',
            6 => 'is not on this video',
            7 => 'PRIVATE_IN_POST: ',
            8 => 'PRIVATE_IN_POST: ',
            9 => 'PRIVATE_IN_POST: ',
            10 => 'Name at least one change',
            11 => 'There is no video #999999',
        ];
        foreach ($expect as $i => $needle) {
            $this->assertTrue($results[$i]['is_error'] ?? false, "Call {$i} was accepted: {$results[$i]['content']}");
            $this->assertStringContainsString($needle, $results[$i]['content'], "Call {$i}");
        }
        $this->assertStringContainsString('$40', $results[7]['content']);
        $this->assertStringContainsString('names a customer', $results[8]['content']);
        $clipRow = CreativeVideoScene::findOrFail($clip);
        $this->assertSame(['Screens at dusk', 6000], [$clipRow->label, $clipRow->trim_out_ms], 'A refused edit saves nothing.');
        $this->assertSame('+7.10%', CreativeVideoScene::findOrFail($stat)->template['value']);
        $this->assertSame(3, CreativeVideoScene::findOrFail($stat)->seconds);

        // Ask mode refuses it.
        $this->claude->replies = [FakeCreativeAgentModel::tools([['edit_video', self::edit($videoId, [self::change($clip, ['label' => 'Asked'])])]]), FakeCreativeAgentModel::text('Switch.')];
        $this->turn($session, 'ask', 'Rename it')->assertOk();
        $this->assertSame('Ask mode: switch to Generate to do this.', $this->lastResults($session)[0]['content']);
        $this->assertSame('Screens at dusk', CreativeVideoScene::findOrFail($clip)->label);

        // A scene whose clip is being made cannot be removed; the rest of the call still applies.
        $job = CreativeAgentJob::query()->where('video_id', $videoId)->sole();
        $this->api('POST', "/api/admin/creatives/agent/jobs/{$job->id}/approve")->assertOk()->assertJsonPath('job.status', 'running');
        $this->claude->replies = [FakeCreativeAgentModel::tools([
            ['edit_video', self::edit($videoId, [self::change($clip, ['remove' => true])], ['caption_style' => 'clean'])],
            ['edit_video', self::edit($videoId, [self::change($clip, ['remove' => true])])],
        ]), FakeCreativeAgentModel::text('OK.')];
        $this->turn($session, 'generate', 'Drop the clip')->assertOk();
        $results = $this->lastResults($session);
        $this->assertArrayNotHasKey('is_error', $results[0]);
        $answer = json_decode($results[0]['content'], true);
        $this->assertSame($clip, $answer['refused'][0]['scene_id']);
        $this->assertStringContainsString('being made', $answer['refused'][0]['reason']);
        $this->assertSame('clean', CreativeVideo::findOrFail($videoId)->timeline['caption_style']);
        $this->assertTrue($results[1]['is_error']);
        $this->assertStringContainsString('Nothing was changed', $results[1]['content']);
        $this->assertNotNull(CreativeVideoScene::find($clip));
    }

    // ---- 6. the tools array --------------------------------------------------------------------

    public function test_the_new_tools_are_plain_and_the_tools_array_never_changes(): void
    {
        $this->assertSame(['ask_choices', 'propose_job', 'create_video', 'add_scenes'], CreativeAgentPrompt::STRICT_TOOLS);
        $this->assertSame(3, CreativeAgentPrompt::TOOLS_VERSION);
        $this->assertContains('edit_video', CreativeAgentPrompt::GENERATE_ONLY);
        $this->assertNotContains('show_storyboard', CreativeAgentPrompt::GENERATE_ONLY);
        $this->assertSame(json_encode(CreativeAgentPrompt::tools()), json_encode(CreativeAgentPrompt::tools()));

        $tools = collect(CreativeAgentPrompt::tools())->keyBy('name');
        foreach (['show_storyboard', 'edit_video'] as $name) {
            $this->assertArrayNotHasKey('strict', $tools[$name], "{$name} must stay non-strict (the compiled-grammar limit).");
            $schema = $tools[$name]['inputSchema'];
            $this->assertSame(array_keys($schema['properties']), $schema['required']);
        }
        $this->assertSame(['title', 'video_id', 'platform', 'aspect', 'style', 'scenes', 'notes'], $tools['show_storyboard']['inputSchema']['required']);
        $this->assertSame(['kind', 'label', 'seconds', 'engine', 'with_pixel', 'shows', 'why'], $tools['show_storyboard']['inputSchema']['properties']['scenes']['items']['required']);
        $this->assertSame(['video_id', 'scene_changes', 'music', 'caption_style', 'pixel_look'], $tools['edit_video']['inputSchema']['required']);
        $this->assertSame(['scene_id', 'label', 'seconds', 'position', 'template_title', 'template_value', 'template_subtitle', 'caption', 'remove'], $tools['edit_video']['inputSchema']['properties']['scene_changes']['items']['required']);

        // The same bytes on every call, in a workspace chat and an editor chat, in both modes.
        [$videoId] = $this->threeSceneVideo();
        $this->claude->replies = [FakeCreativeAgentModel::text('Hi.')];
        $this->turn($this->sessionId(), 'ask', 'Hi')->assertOk();
        $this->turn($this->sessionId($videoId), 'generate', 'Hi')->assertOk();
        $this->assertCount(1, array_unique(array_column($this->claude->calls, 'tools')));
        $this->assertSame(CreativeAgentPrompt::TOOLS_VERSION, CreativeAgentSession::query()->where('video_id', $videoId)->sole()->tools_version);
    }
}
