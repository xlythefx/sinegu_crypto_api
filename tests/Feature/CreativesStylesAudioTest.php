<?php

namespace Tests\Feature;

use App\Models\CreativeAudioAsset;
use App\Models\CreativeSetting;
use App\Models\CreativeStyle;
use App\Models\UserCredential;
use App\Services\Creatives\CreativeAudio;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/**
 * Admin → Creatives v2: the style library, the video playbook and the audio
 * library.
 *
 * Pinned here: the five seeded styles (and the seeded playbook) carry no money
 * sign and no figure; every staff role edits styles, the playbook and the
 * audio library (collaborators included), a plain user is refused; the
 * style's slug and enums are checked and a style a video uses cannot be
 * deleted; audio uploads are limited by size, type and count, stored on the
 * private disk, served only through a signed link, and kept while a video
 * plays them.
 */
class CreativesStylesAudioTest extends EngineTestCase
{
    private string $admin;

    private string $collaborator;

    private string $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = $this->makeUser(['type' => 'admin', 'name' => 'Owner Admin']);
        $this->collaborator = $this->makeUser(['type' => 'collaborator', 'name' => 'Mara Marketing']);
        $this->user = $this->makeUser(['name' => 'Plain Customer']);
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

    private function upload(array $body, ?string $as = null): TestResponse
    {
        return $this->post('/api/admin/creatives/audio', $body, $this->headers($as ?? $this->collaborator) + ['Accept' => 'application/json']);
    }

    private function style(array $over = []): array
    {
        return array_merge([
            'slug' => 'retro-wave', 'name' => 'Retro wave', 'summary' => 'Neon sunsets and synth pads.',
            'markdown' => "# Retro wave\n\nNeon grids, a sunset, slow drives.", 'default_platform' => 'tiktok', 'default_aspect' => '9:16',
            'pixel_default' => 'none', 'default_engine' => 'seedance', 'caption_style' => 'bold', 'template_family' => 'clean',
            'music_default' => 'arcade-chill', 'pixel_look' => false, 'enabled' => true, 'sort' => 60,
        ], $over);
    }

    // ---- who ------------------------------------------------------------------

    public function test_a_plain_user_is_refused_everywhere(): void
    {
        foreach ([
            ['GET', '/api/admin/creatives/styles'], ['POST', '/api/admin/creatives/styles'],
            ['PUT', '/api/admin/creatives/styles/1'], ['DELETE', '/api/admin/creatives/styles/1'],
            ['GET', '/api/admin/creatives/playbook'], ['PUT', '/api/admin/creatives/playbook'],
            ['GET', '/api/admin/creatives/audio'], ['POST', '/api/admin/creatives/audio'], ['DELETE', '/api/admin/creatives/audio/1'],
        ] as [$method, $uri]) {
            $this->api($method, $uri, [], $this->user)->assertForbidden()->assertJsonPath('error_code', 'FORBIDDEN');
        }
    }

    // ---- styles --------------------------------------------------------------------

    public function test_the_five_styles_are_seeded_without_money_or_names(): void
    {
        $styles = $this->api('GET', '/api/admin/creatives/styles')->assertOk()->assertJsonPath('success', true)->json('styles');
        $this->assertSame(['data-recap', 'pixel-arcade', 'pixel-storytime', 'cinematic-broll', 'fast-cut'], array_column($styles, 'slug'));
        $arcade = collect($styles)->firstWhere('slug', 'pixel-arcade');
        $this->assertSame([
            'tiktok', '9:16', 'cameo', 'seedance', 'pixel', 'pixel', 'arcade-upbeat', true, true,
        ], [
            $arcade['default_platform'], $arcade['default_aspect'], $arcade['pixel_default'], $arcade['default_engine'],
            $arcade['caption_style'], $arcade['template_family'], $arcade['music_default'], $arcade['pixel_look'], $arcade['enabled'],
        ]);
        $this->assertStringContainsString('8-bit chiptune music and arcade sound effects', $arcade['markdown']);
        $this->assertStringContainsString('HIGH SCORE', $arcade['markdown']);
        $this->assertSame(['x', '16:9'], [collect($styles)->firstWhere('slug', 'cinematic-broll')['default_platform'], collect($styles)->firstWhere('slug', 'cinematic-broll')['default_aspect']]);
        foreach ($styles as $style) {
            $this->assertLessThanOrEqual(2000, mb_strlen($style['markdown']), $style['slug']);
            $this->assertStringNotContainsString('$', $style['markdown'].$style['summary']);
            $this->assertDoesNotMatchRegularExpression('/\d\s?%/', $style['markdown'], 'A seeded style carries no figure.');
            $this->assertNull($style['updated_by_name']);
        }
    }

    public function test_styles_are_created_edited_validated_and_deleted(): void
    {
        $created = $this->api('POST', '/api/admin/creatives/styles', $this->style())->assertCreated()
            ->assertJsonPath('style.slug', 'retro-wave')
            ->assertJsonPath('style.music_default', 'arcade-chill')
            ->assertJsonPath('style.updated_by_name', 'Mara Marketing');
        $id = (int) $created->json('style.id');

        foreach ([
            'slug' => ['slug' => 'Bad Slug!'], 'short' => ['slug' => 'ab'], 'taken' => ['slug' => 'data-recap'],
            'platform' => ['default_platform' => 'youtube'], 'aspect' => ['default_aspect' => '4:3'], 'pixel' => ['pixel_default' => 'always'],
            'engine' => ['default_engine' => 'sora'], 'caption' => ['caption_style' => 'comic'], 'family' => ['template_family' => 'retro'],
            'music' => ['music_default' => 'rock'], 'markdown' => ['markdown' => str_repeat('x', 20001)], 'name' => ['name' => str_repeat('n', 81)],
            'summary' => ['summary' => str_repeat('s', 201)],
        ] as $case => $over) {
            $this->api('POST', '/api/admin/creatives/styles', $this->style($over + ['slug' => 'case-'.$case]))->assertStatus(422);
        }
        $this->api('POST', '/api/admin/creatives/styles', array_diff_key($this->style(['slug' => 'no-markdown']), ['markdown' => 1]))->assertStatus(422)
            ->assertJsonValidationErrors('markdown');

        // PUT is partial; its own slug is not "taken".
        $this->api('PUT', "/api/admin/creatives/styles/{$id}", ['summary' => 'Synths and sunsets.', 'slug' => 'retro-wave', 'enabled' => false], $this->admin)->assertOk()
            ->assertJsonPath('style.summary', 'Synths and sunsets.')
            ->assertJsonPath('style.name', 'Retro wave')
            ->assertJsonPath('style.enabled', false)
            ->assertJsonPath('style.updated_by_name', 'Owner Admin');
        $this->api('PUT', "/api/admin/creatives/styles/{$id}", ['slug' => 'fast-cut'])->assertStatus(422);
        $this->api('PUT', '/api/admin/creatives/styles/9999', ['name' => 'x'])->assertNotFound();
        // The disabled one is still listed.
        $this->assertContains('retro-wave', array_column($this->api('GET', '/api/admin/creatives/styles')->json('styles'), 'slug'));

        // In use by a video: refused; once the video is gone: deleted.
        $video = (int) $this->api('POST', '/api/admin/creatives/videos', ['title' => 'Retro', 'platform' => 'tiktok', 'aspect' => '9:16', 'style_id' => $id])->assertCreated()->json('video.id');
        $this->api('DELETE', "/api/admin/creatives/styles/{$id}")->assertStatus(409)->assertJsonPath('error_code', 'STYLE_IN_USE');
        $this->api('DELETE', "/api/admin/creatives/videos/{$video}")->assertOk();
        $this->api('DELETE', "/api/admin/creatives/styles/{$id}")->assertOk()->assertJsonPath('success', true);
        $this->assertNull(CreativeStyle::find($id));
        $this->api('DELETE', "/api/admin/creatives/styles/{$id}")->assertNotFound();
    }

    // ---- playbook ------------------------------------------------------------------------

    public function test_the_playbook_is_seeded_and_editable(): void
    {
        $playbook = $this->api('GET', '/api/admin/creatives/playbook')->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('playbook.updated_by_name', null)
            ->assertJsonPath('playbook.updated_at', null)
            ->json('playbook.markdown');
        foreach (['## Purpose', '## Anatomy of a video', '## Pixel is a guest, not the host', '## Writing scene prompts', '## Engines: Kling or Seedance', '## Captions', '## Sound', '## Recipes', 'Weekly recap', 'Coin spotlight', 'How it works', 'Pixel arcade recap', '## The asking routine', 'Build it?'] as $section) {
            $this->assertStringContainsString($section, $playbook);
        }
        $this->assertGreaterThanOrEqual(3000, mb_strlen($playbook));
        $this->assertLessThanOrEqual(5500, mb_strlen($playbook));
        $this->assertStringNotContainsString('$', $playbook);
        $this->assertDoesNotMatchRegularExpression('/\d\s?%/', $playbook, 'The seeded playbook carries no figures.');

        $this->api('PUT', '/api/admin/creatives/playbook', ['markdown' => str_repeat('x', 20001)])->assertStatus(422);
        $this->api('PUT', '/api/admin/creatives/playbook', [])->assertStatus(422);
        $this->api('PUT', '/api/admin/creatives/playbook', ['markdown' => "# Playbook\n\nShort videos only."])->assertOk()
            ->assertJsonPath('playbook.markdown', "# Playbook\n\nShort videos only.")
            ->assertJsonPath('playbook.updated_by_name', 'Mara Marketing');
        $this->assertNotNull(CreativeSetting::current()->playbook_updated_at);
        // The brief and the cap keep their own "last edited by".
        $this->api('GET', '/api/admin/creatives/brief')->assertJsonPath('brief.updated_by_name', null);
        $this->api('GET', '/api/admin/creatives/budget')->assertJsonPath('budget.updated_by_name', null);
    }

    // ---- audio -----------------------------------------------------------------------

    public function test_audio_uploads_are_checked_stored_privately_and_served_signed(): void
    {
        $this->upload(['kind' => 'music', 'label' => 'Too big', 'file' => UploadedFile::fake()->create('big.mp3', CreativeAudio::MAX_KB + 1, 'audio/mpeg')])->assertStatus(422);
        $this->upload(['kind' => 'music', 'label' => 'Not audio', 'file' => UploadedFile::fake()->create('notes.txt', 2, 'text/plain')])->assertStatus(422)
            ->assertJsonValidationErrors('file');
        $this->upload(['kind' => 'sfx', 'label' => 'Bad kind', 'file' => UploadedFile::fake()->create('a.mp3', 10, 'audio/mpeg')])->assertStatus(422);
        $this->upload(['kind' => 'music', 'file' => UploadedFile::fake()->create('a.mp3', 10, 'audio/mpeg')])->assertStatus(422);

        $res = $this->upload(['kind' => 'music', 'label' => 'Upbeat loop', 'duration_ms' => 31500, 'file' => UploadedFile::fake()->create('loop.mp3', 120, 'audio/mpeg')])
            ->assertCreated()
            ->assertJsonPath('asset.kind', 'music')
            ->assertJsonPath('asset.label', 'Upbeat loop')
            ->assertJsonPath('asset.mime', 'audio/mpeg')
            ->assertJsonPath('asset.bytes', 120 * 1024)
            ->assertJsonPath('asset.duration_ms', 31500)
            ->assertJsonPath('asset.created_by_name', 'Mara Marketing');
        $asset = CreativeAudioAsset::findOrFail($res->json('asset.id'));
        $this->assertStringStartsWith('creatives/audio/', $asset->path);
        $this->assertStringEndsWith('.mp3', $asset->path);
        Storage::disk('local')->assertExists($asset->path);

        // A browser's recorded voiceover sniffs as video/webm: kept as audio.
        $voice = $this->upload(['kind' => 'voiceover', 'label' => 'Take 1', 'file' => UploadedFile::fake()->create('take.webm', 40, 'video/webm')])
            ->assertCreated()->assertJsonPath('asset.mime', 'audio/webm');

        // The signed link is relative; tampering or no signature is refused, no token needed.
        $url = (string) $res->json('asset.url');
        $this->assertStringStartsWith('/api/creatives/audio/'.$asset->id.'/file?', $url);
        $this->app['auth']->forgetGuards();
        $file = $this->get($url)->assertOk();
        $this->assertSame('audio/mpeg', $file->headers->get('Content-Type'));
        $this->assertStringContainsString('private', (string) $file->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=3600', (string) $file->headers->get('Cache-Control'));
        $this->assertStringContainsString('inline', (string) $file->headers->get('Content-Disposition'));
        $this->get('/api/creatives/audio/'.$asset->id.'/file')->assertForbidden();
        $this->get(str_replace('/audio/'.$asset->id.'/', '/audio/'.$voice->json('asset.id').'/', $url))->assertForbidden();

        $this->api('GET', '/api/admin/creatives/audio', [], $this->admin)->assertOk()
            ->assertJsonPath('audio.0.id', $voice->json('asset.id'))
            ->assertJsonPath('audio.1.id', $asset->id);

        // Used by a video's music: kept until the video lets go of it.
        $video = (int) $this->api('POST', '/api/admin/creatives/videos', ['title' => 'With music', 'platform' => 'tiktok', 'aspect' => '9:16'])->assertCreated()->json('video.id');
        $timeline = $this->api('GET', "/api/admin/creatives/videos/{$video}")->json('video.timeline');
        $timeline['music'] = ['source' => 'asset', 'asset_id' => $asset->id, 'volume' => 0.7, 'start_ms' => 0, 'fade_out_ms' => 1000];
        $this->api('PUT', "/api/admin/creatives/videos/{$video}", ['timeline' => $timeline])->assertOk()->assertJsonPath('video.timeline.music.asset_id', $asset->id);
        $this->api('DELETE', "/api/admin/creatives/audio/{$asset->id}")->assertStatus(409)->assertJsonPath('error_code', 'AUDIO_IN_USE');
        Storage::disk('local')->assertExists($asset->path);

        $timeline['music'] = null;
        $timeline['voiceover'] = ['asset_id' => (int) $voice->json('asset.id'), 'volume' => 1, 'start_ms' => 500];
        $this->api('PUT', "/api/admin/creatives/videos/{$video}", ['timeline' => $timeline])->assertOk();
        $this->api('DELETE', '/api/admin/creatives/audio/'.$voice->json('asset.id'))->assertStatus(409)->assertJsonPath('error_code', 'AUDIO_IN_USE');
        $this->api('DELETE', "/api/admin/creatives/audio/{$asset->id}")->assertOk()->assertJsonPath('success', true);
        Storage::disk('local')->assertMissing($asset->path);
        $this->api('DELETE', "/api/admin/creatives/audio/{$asset->id}")->assertNotFound();
        $this->get($url)->assertNotFound();
    }

    public function test_the_audio_library_holds_at_most_forty_files(): void
    {
        for ($i = 0; $i < CreativeAudio::MAX; $i++) {
            CreativeAudioAsset::create([
                'kind' => 'music', 'label' => "Track {$i}", 'mime' => 'audio/mpeg', 'bytes' => 10,
                'path' => "creatives/audio/seed-{$i}.mp3", 'created_by_name' => 'Owner Admin',
            ]);
        }
        $this->upload(['kind' => 'music', 'label' => 'One more', 'file' => UploadedFile::fake()->create('more.wav', 10, 'audio/wav')])
            ->assertStatus(422)->assertJsonPath('error_code', 'AUDIO_LIBRARY_FULL');
        $this->assertSame(CreativeAudio::MAX, CreativeAudioAsset::count());
        $this->assertSame(0, count(Storage::disk('local')->allFiles('creatives/audio')));
    }
}
