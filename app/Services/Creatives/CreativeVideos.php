<?php

namespace App\Services\Creatives;

use App\Models\CreativeAgentJob;
use App\Models\CreativeAgentSession;
use App\Models\CreativeAudioAsset;
use App\Models\CreativeGeneration;
use App\Models\CreativeStyle;
use App\Models\CreativeVideo;
use App\Models\CreativeVideoScene;
use App\Models\UserCredential;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Video projects of the Creatives editor: create, edit, scenes in and out,
 * the export record — and proposing the clips a video still needs as ONE job
 * the person approves with Go ({@see proposeScenes()}).
 *
 * Rules that hold here whoever calls (the editor or the chat agent):
 *  - A video is at most `max_seconds` (30) long, its scenes' trimmed spans
 *    added up: anything longer is TOO_LONG (422), nothing saved.
 *  - Its platform and shape are fixed once a clip is on it or on its way
 *    (ASPECT_LOCKED, 409): every scene is generated in the video's shape.
 *  - The scene columns the job runner writes (generation_id, status, error…)
 *    are never taken from a request.
 *  - A clip added by hand must be a finished VIDEO generation whose file is
 *    still at Higgsfield; a template scene is drawn in the browser and free.
 */
final class CreativeVideos
{
    public const MAX_DRAFTS = 6;

    /** A TimelineDoc is at most this big, as JSON. */
    public const MAX_TIMELINE_BYTES = 65536;

    public const TEMPLATE_SECONDS = [2, 6];

    public const SEEDANCE_SECONDS = [4, 15];

    public const KLING_SECONDS = [5, 10];

    public const ENGINE_LABELS = [
        CreativeVideoScene::KLING => 'Kling (silent)',
        CreativeVideoScene::SEEDANCE => 'Seedance (with sound)',
    ];

    public function __construct(
        private CreativeAgentJobs $jobs,
        private CreativeModels $models,
    ) {}

    // ---- projects ---------------------------------------------------------------

    /**
     * @param  array{title: string, platform: string, aspect: string, style_id?: int|null, plan_id?: int|null}  $data
     * @param  array{caption_style?: string, music?: string|null, pixel_look?: bool}  $timelineOver  the chat agent's choices
     */
    public function create(array $data, UserCredential $user, array $timelineOver = []): CreativeVideo
    {
        $style = isset($data['style_id']) ? CreativeStyle::find((int) $data['style_id']) : null;

        return CreativeVideo::create([
            'plan_id' => isset($data['plan_id']) ? (int) $data['plan_id'] : null,
            'style_id' => $style?->id,
            'title' => trim((string) $data['title']),
            'platform' => $data['platform'],
            'aspect' => $data['aspect'],
            'max_seconds' => CreativeVideo::MAX_SECONDS,
            'fps' => 30,
            'timeline' => self::seedTimeline($style, $timelineOver),
            'status' => CreativeVideo::DRAFT,
            'created_by_uni_id' => $user->uni_id,
            'created_by_name' => mb_substr((string) ($user->name ?? 'Someone'), 0, 120),
            'updated_by_name' => $user->name !== null ? mb_substr($user->name, 0, 120) : null,
        ]);
    }

    /**
     * A new video's TimelineDoc, from its style: the caption style, the
     * chiptune preset (with a random seed — the same seed always plays the
     * same tune) and the pixel look. Overrides come from the chat agent's
     * create_video.
     *
     * @param  array{caption_style?: string, music?: string|null, pixel_look?: bool}  $over
     */
    public static function seedTimeline(?CreativeStyle $style, array $over = []): array
    {
        $pixelLook = $over['pixel_look'] ?? ($style?->pixel_look ?? false);
        $music = array_key_exists('music', $over) ? $over['music'] : $style?->music_default;
        $music = in_array($music, CreativeStyles::MUSIC_PRESETS, true) ? $music : null;

        return [
            'version' => 1,
            'caption_style' => $over['caption_style'] ?? ($style?->caption_style ?? 'bold'),
            'captions' => [],
            'look' => ['pixel_size' => $pixelLook ? 10 : 0, 'scanlines' => (bool) $pixelLook],
            'music' => $music !== null
                ? ['source' => 'chiptune', 'preset' => $music, 'seed' => random_int(1, 99999), 'volume' => 0.6, 'fade_out_ms' => 1500]
                : null,
            'voiceover' => null,
            'sfx' => [],
            'clip_audio_volume' => 1,
            'ducking' => ['enabled' => true, 'music_under_voice' => 0.25],
        ];
    }

    /**
     * PUT: the client-owned fields only. `scenes` lists {id, position?,
     * label?, trim_in_ms?, trim_out_ms?, template?}; any other key on a scene
     * (generation_id, status…) is ignored. Checked as a whole and saved in one
     * transaction: a refusal saves nothing.
     *
     * @param  array<string, mixed>  $data  validated shape (CreativeVideoUpdateRequest)
     */
    public function update(CreativeVideo $video, array $data, UserCredential $user): CreativeVideo
    {
        return DB::transaction(function () use ($video, $data, $user) {
            $video = CreativeVideo::query()->lockForUpdate()->findOrFail($video->id);

            foreach (['platform', 'aspect'] as $field) {
                if (array_key_exists($field, $data) && $data[$field] !== $video->{$field} && $this->shapeLocked($video)) {
                    throw new CreativeException(
                        'ASPECT_LOCKED',
                        'The platform and shape are fixed once a clip is on this video (every clip was made in this shape). Start a new video for another shape.',
                        409,
                    );
                }
            }

            if (array_key_exists('title', $data)) {
                $video->title = trim((string) $data['title']);
            }
            if (array_key_exists('style_id', $data)) {
                $video->style_id = $data['style_id'] !== null ? (int) $data['style_id'] : null;
            }
            foreach (['platform', 'aspect'] as $field) {
                if (array_key_exists($field, $data)) {
                    $video->{$field} = $data[$field];
                }
            }
            if (array_key_exists('timeline', $data)) {
                $video->timeline = self::validateTimeline($data['timeline']);
            }

            if (isset($data['scenes']) && is_array($data['scenes'])) {
                $scenes = $video->scenes()->get()->keyBy('id');
                foreach ($data['scenes'] as $i => $patch) {
                    $scene = $scenes->get((int) ($patch['id'] ?? 0));
                    if ($scene === null) {
                        throw ValidationException::withMessages(["scenes.{$i}.id" => 'That scene is not on this video.']);
                    }
                    $this->patchScene($scene, $patch, $i);
                }
                foreach ($scenes as $scene) {
                    if ($scene->isDirty()) {
                        $scene->save();
                    }
                }
                $this->repack($video);
            }

            $this->assertFits($video);
            $video->updated_by_name = $user->name !== null ? mb_substr($user->name, 0, 120) : $video->updated_by_name;
            $video->save();

            return $video;
        });
    }

    /** POST …/exported: the export record (the MP4 itself is never sent here). */
    public function exported(CreativeVideo $video, float $seconds, int $bytes, UserCredential $user): CreativeVideo
    {
        $video->forceFill([
            'status' => CreativeVideo::EXPORTED,
            'exported_at' => now(),
            'exported_by_name' => $user->name !== null ? mb_substr($user->name, 0, 120) : null,
            'export_seconds' => round($seconds, 2),
            'export_bytes' => $bytes,
        ])->save();

        return $video;
    }

    /** The video and its scenes; its generations (the month's spend) stay. */
    public function delete(CreativeVideo $video): void
    {
        DB::transaction(function () use ($video) {
            CreativeVideoScene::query()->where('video_id', $video->id)->delete();
            $video->delete();
        });
    }

    // ---- scenes -------------------------------------------------------------------

    /** Add a finished clip from the gallery (free): a completed, live VIDEO generation. */
    public function addClip(CreativeVideo $video, int $generationId, int $outputIndex, ?int $position): CreativeVideo
    {
        $generation = CreativeGeneration::find($generationId);
        $output = $generation?->kind === 'video' ? $generation->liveOutput($outputIndex) : null;
        if ($output === null || $output['type'] !== 'video') {
            throw new CreativeException(
                'CLIP_UNAVAILABLE',
                'That clip cannot be added: it is not a finished video, or its 7 days at Higgsfield are over.',
                422,
            );
        }

        $seconds = (int) (($generation->params ?? [])['duration'] ?? 5);
        $label = trim((string) $generation->prompt);

        return $this->insert($video, [
            'kind' => CreativeVideoScene::CLIP,
            'label' => mb_substr($label !== '' ? $label : $this->models->label($generation->model), 0, 80),
            'prompt' => $generation->prompt,
            'engine' => self::engineOf($generation->model),
            'with_character' => (bool) $generation->used_character,
            'seconds' => max(1, min(255, $seconds)),
            'generation_id' => (int) $generation->id,
            'output_index' => $outputIndex,
            'status' => CreativeVideoScene::READY,
        ], $position);
    }

    /** Add a template card (drawn in the browser, free, ready at once). */
    public function addTemplate(CreativeVideo $video, array $template, int $seconds, ?int $position): CreativeVideo
    {
        $template = self::validateTemplate($template, 'template');
        self::assertSeconds('template', $seconds, 'seconds');

        return $this->insert($video, [
            'kind' => CreativeVideoScene::TEMPLATE,
            'label' => mb_substr(self::templateLabel($template), 0, 80),
            'seconds' => $seconds,
            'template' => $template,
            'status' => CreativeVideoScene::READY,
        ], $position);
    }

    /** Remove one scene; the others close up. */
    public function removeScene(CreativeVideo $video, int $sceneId): CreativeVideo
    {
        DB::transaction(function () use ($video, $sceneId) {
            $scene = CreativeVideoScene::query()->where('video_id', $video->id)->find($sceneId)
                ?? throw CreativeException::notFound('Scene not found.');
            $scene->delete();
            $this->repack($video);
        });

        return $video;
    }

    /**
     * Propose the clips for 1–6 SceneDrafts as ONE job (nothing spent; Go is
     * the person's). Template drafts become ready slots with no step; clip
     * drafts become `planned` slots and steps ({@see scenesToSteps()}).
     * `$replaceSceneId` re-plans that slot instead (one draft; its trims
     * reset). A draft's caption is added to the timeline over its slot.
     * Priced before anything is kept: a refusal leaves the video as it was.
     *
     * @param  list<array<string, mixed>>  $drafts
     * @return array{job: ?CreativeAgentJob, video: CreativeVideo, scene_ids: list<int>}
     */
    public function proposeScenes(CreativeVideo $video, array $drafts, ?int $replaceSceneId, UserCredential $user, ?CreativeAgentSession $session = null): array
    {
        if (! array_is_list($drafts) || $drafts === [] || count($drafts) > self::MAX_DRAFTS) {
            throw ValidationException::withMessages(['scenes' => 'Give 1 to '.self::MAX_DRAFTS.' scenes.']);
        }
        if ($replaceSceneId !== null && count($drafts) !== 1) {
            throw ValidationException::withMessages(['scenes' => 'Replacing a scene takes exactly one new scene.']);
        }
        $style = $video->style_id !== null ? CreativeStyle::find($video->style_id) : null;
        $drafts = array_map(fn ($d, $i) => self::validateDraft(is_array($d) ? $d : [], (int) $i, $style), $drafts, array_keys($drafts));

        return DB::transaction(function () use ($video, $drafts, $replaceSceneId, $user, $session) {
            $video = CreativeVideo::query()->lockForUpdate()->findOrFail($video->id);

            $placed = [];
            if ($replaceSceneId !== null) {
                $scene = CreativeVideoScene::query()->where('video_id', $video->id)->find($replaceSceneId);
                if ($scene === null) {
                    throw ValidationException::withMessages(['replace_scene_id' => 'That scene is not on this video.']);
                }
                $scene->forceFill(self::sceneColumns($drafts[0]) + [
                    'generation_id' => null, 'output_index' => 0, 'agent_job_id' => null, 'step_index' => null,
                    'trim_in_ms' => null, 'trim_out_ms' => null, 'error' => null,
                ])->save();
                $placed[] = [$scene, $drafts[0]];
            } else {
                $next = (int) CreativeVideoScene::query()->where('video_id', $video->id)->max('position') + 1;
                if (! CreativeVideoScene::query()->where('video_id', $video->id)->exists()) {
                    $next = 0;
                }
                foreach ($drafts as $draft) {
                    $scene = CreativeVideoScene::create(['video_id' => $video->id, 'position' => $next++] + self::sceneColumns($draft));
                    $placed[] = [$scene, $draft];
                }
            }
            $this->assertFits($video);

            $clipScenes = array_values(array_filter(array_map(fn ($p) => $p[0], $placed), fn (CreativeVideoScene $s) => $s->kind === CreativeVideoScene::CLIP));
            $job = null;
            if ($clipScenes !== []) {
                $steps = $this->scenesToSteps($video, $clipScenes);
                $job = $this->jobs->propose($session, [
                    'title' => mb_substr('Video: '.$video->title, 0, 160),
                    'plan_id' => $video->plan_id,
                    'steps' => $steps,
                ], ['video_id' => (int) $video->id, 'owner_uni_id' => $user->uni_id, 'scenes' => true]);
                foreach ($job->steps as $i => $step) {
                    if (isset($step['scene_id'])) {
                        CreativeVideoScene::query()->whereKey($step['scene_id'])->update(['agent_job_id' => $job->id, 'step_index' => $i]);
                    }
                }
            }

            $this->addCaptions($video, $placed);
            $video->updated_by_name = $user->name !== null ? mb_substr($user->name, 0, 120) : $video->updated_by_name;
            $video->save();

            return ['job' => $job, 'video' => $video, 'scene_ids' => array_map(fn ($p) => (int) $p[0]->id, $placed)];
        });
    }

    /**
     * The job steps that make these CLIP scenes, every one in the video's
     * shape. The step whose output IS the clip carries `scene_id`:
     *
     *   kling,    no Pixel → soul-image still (no character) → kling-animate
     *   kling,    Pixel    → grok-image still (the character) → kling-animate
     *   seedance, no Pixel → seedance-text-video (with sound)
     *   seedance, Pixel    → seedance-reference (the character, with sound)
     *
     * Kling's text-to-video takes no aspect ratio (it cannot make 9:16), so a
     * Kling scene always starts from a still in the right shape; the
     * animation keeps the still's shape.
     *
     * @param  iterable<CreativeVideoScene>  $scenes
     * @return list<array<string, mixed>>
     */
    public function scenesToSteps(CreativeVideo $video, iterable $scenes): array
    {
        $aspect = $video->aspect;
        $composition = match ($aspect) {
            '16:9' => 'wide',
            '1:1' => 'square',
            default => 'vertical',
        };

        $steps = [];
        foreach ($scenes as $scene) {
            $prompt = trim((string) $scene->prompt);
            $label = (string) $scene->label;
            $pixel = (bool) $scene->with_character;

            if ($scene->engine === CreativeVideoScene::SEEDANCE) {
                $steps[] = [
                    'model' => $pixel ? CreativeModels::SEEDANCE : CreativeModels::SEEDANCE_TEXT,
                    'label' => $label,
                    'prompt' => $prompt,
                    'params' => ['duration' => (int) $scene->seconds, 'resolution' => '720p', 'aspect_ratio' => $aspect, 'generate_audio' => true],
                    'use_character' => $pixel,
                    'source' => null,
                    'scene_id' => (int) $scene->id,
                ];

                continue;
            }

            $still = count($steps);
            $steps[] = [
                'model' => $pixel ? CreativeModels::GROK : CreativeModels::SOUL,
                'label' => mb_substr($label.' (still)', 0, 80),
                'prompt' => mb_substr($prompt.", {$composition} composition, first frame of the shot", 0, 2000),
                'params' => $pixel
                    ? ['aspect_ratio' => $aspect, 'resolution' => '1k', 'quality' => 'medium']
                    : ['aspect_ratio' => $aspect, 'resolution' => '720p', 'batch_size' => 1],
                'use_character' => $pixel,
                'source' => null,
            ];
            $steps[] = [
                'model' => CreativeModels::KLING,
                'label' => $label,
                'prompt' => $prompt,
                'params' => ['duration' => (int) $scene->seconds],
                'use_character' => $pixel,
                'source' => ['from_step' => $still],
                'scene_id' => (int) $scene->id,
            ];
        }

        return $steps;
    }

    // ---- checks -----------------------------------------------------------------

    /**
     * One SceneDraft, checked: {kind, label, prompt, seconds, engine,
     * with_character, caption, template}. A clip with no engine takes the
     * style's default engine (else Kling).
     *
     * @return array<string, mixed>
     */
    public static function validateDraft(array $draft, int $i, ?CreativeStyle $style): array
    {
        $n = $i + 1;
        $kind = $draft['kind'] ?? CreativeVideoScene::CLIP;
        if (! in_array($kind, CreativeVideoScene::KINDS, true)) {
            throw ValidationException::withMessages(["scenes.{$i}.kind" => "Scene {$n}: kind is clip or template."]);
        }
        $caption = is_string($draft['caption'] ?? null) ? trim($draft['caption']) : '';
        if (mb_strlen($caption) > 200) {
            throw ValidationException::withMessages(["scenes.{$i}.caption" => "Scene {$n}: a caption is at most 200 characters."]);
        }
        $seconds = filter_var($draft['seconds'] ?? null, FILTER_VALIDATE_INT);
        $label = is_string($draft['label'] ?? null) ? trim($draft['label']) : '';
        if (mb_strlen($label) > 80) {
            throw ValidationException::withMessages(["scenes.{$i}.label" => "Scene {$n}: the label is at most 80 characters."]);
        }

        if ($kind === CreativeVideoScene::TEMPLATE) {
            $template = self::validateTemplate(is_array($draft['template'] ?? null) ? $draft['template'] : [], "scenes.{$i}.template", $n);
            self::assertSeconds('template', $seconds === false ? 0 : $seconds, "scenes.{$i}.seconds", $n);

            return [
                'kind' => $kind,
                'label' => $label !== '' ? $label : self::templateLabel($template),
                'prompt' => null,
                'engine' => null,
                'with_character' => false,
                'seconds' => (int) $seconds,
                'template' => $template,
                'caption' => $caption !== '' ? $caption : null,
            ];
        }

        $prompt = is_string($draft['prompt'] ?? null) ? trim($draft['prompt']) : '';
        if (mb_strlen($prompt) < 3 || mb_strlen($prompt) > 1800) {
            throw ValidationException::withMessages(["scenes.{$i}.prompt" => "Scene {$n}: the prompt must be 3 to 1800 characters."]);
        }
        $engine = $draft['engine'] ?? null;
        if ($engine === null || $engine === '' || $engine === 'none') {
            $engine = $style?->default_engine ?? CreativeVideoScene::KLING;
        }
        if (! in_array($engine, CreativeVideoScene::ENGINES, true)) {
            throw ValidationException::withMessages(["scenes.{$i}.engine" => "Scene {$n}: the engine is kling or seedance."]);
        }
        self::assertSeconds($engine, $seconds === false ? 0 : $seconds, "scenes.{$i}.seconds", $n);

        return [
            'kind' => $kind,
            'label' => $label !== '' ? $label : 'Scene '.$n,
            'prompt' => $prompt,
            'engine' => $engine,
            'with_character' => (bool) ($draft['with_character'] ?? false),
            'seconds' => (int) $seconds,
            'template' => null,
            'caption' => $caption !== '' ? $caption : null,
        ];
    }

    /**
     * A template's {key, title, value, subtitle}: a known key, short texts.
     *
     * @return array{key: string, title: string, value: string, subtitle: string}
     */
    public static function validateTemplate(array $template, string $field, ?int $n = null): array
    {
        $prefix = $n !== null ? "Scene {$n}: " : '';
        $key = $template['key'] ?? null;
        if (! is_string($key) || ! in_array($key, CreativeVideoScene::TEMPLATE_KEYS, true)) {
            throw ValidationException::withMessages(["{$field}.key" => $prefix.'the template is one of: '.implode(', ', CreativeVideoScene::TEMPLATE_KEYS).'.']);
        }
        $out = ['key' => $key];
        foreach (['title' => 80, 'value' => 40, 'subtitle' => 160] as $name => $max) {
            $value = $template[$name] ?? '';
            if ($value !== null && ! is_scalar($value)) {
                throw ValidationException::withMessages(["{$field}.{$name}" => "{$prefix}the template's {$name} is text."]);
            }
            $value = trim((string) $value);
            if (mb_strlen($value) > $max) {
                throw ValidationException::withMessages(["{$field}.{$name}" => "{$prefix}the template's {$name} is at most {$max} characters."]);
            }
            $out[$name] = $value;
        }

        return $out;
    }

    /**
     * A TimelineDoc, checked shallowly: an object, `version` 1, ≤ 64 KB as
     * JSON, and every audio file it names exists. The rest is the client's.
     */
    public static function validateTimeline(mixed $timeline): array
    {
        if (! is_array($timeline) || ($timeline !== [] && array_is_list($timeline))) {
            throw ValidationException::withMessages(['timeline' => 'The timeline is an object.']);
        }
        if (($timeline['version'] ?? null) !== 1) {
            throw ValidationException::withMessages(['timeline.version' => 'The timeline version must be 1.']);
        }
        if (strlen((string) json_encode($timeline)) > self::MAX_TIMELINE_BYTES) {
            throw ValidationException::withMessages(['timeline' => 'The timeline is larger than 64 KB.']);
        }
        $ids = CreativeAudio::assetIds($timeline);
        if ($ids !== [] && CreativeAudioAsset::query()->whereIn('id', $ids)->count() !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['timeline' => 'The timeline names an audio file that is not in the library.']);
        }

        return $timeline;
    }

    /** The engine a generation's model belongs to, for a clip added by hand. */
    public static function engineOf(string $model): ?string
    {
        return match ($model) {
            CreativeModels::KLING => CreativeVideoScene::KLING,
            CreativeModels::SEEDANCE, CreativeModels::SEEDANCE_TEXT => CreativeVideoScene::SEEDANCE,
            default => null,
        };
    }

    /** The video plus its scenes would run longer than allowed. */
    private function assertFits(CreativeVideo $video): void
    {
        $total = CreativeVideoScene::query()->where('video_id', $video->id)->get()
            ->sum(fn (CreativeVideoScene $s) => $s->durationMs());
        $max = (int) $video->max_seconds * 1000;
        if ($total > $max) {
            throw new CreativeException(
                'TOO_LONG',
                sprintf('That makes the video %s seconds long; it may be at most %d seconds. Trim or remove a scene first.', rtrim(rtrim(number_format($total / 1000, 1), '0'), '.'), (int) $video->max_seconds),
                422,
                ['duration_ms' => (int) $total, 'max_ms' => $max],
            );
        }
    }

    /** Once a clip is on the video (or a job is making one), its shape is fixed. */
    private function shapeLocked(CreativeVideo $video): bool
    {
        return CreativeVideoScene::query()
            ->where('video_id', $video->id)
            ->where('kind', CreativeVideoScene::CLIP)
            ->where(fn ($q) => $q->whereNotNull('generation_id')->orWhereNotNull('agent_job_id'))
            ->exists();
    }

    /** The client-owned fields of one scene, from a PUT. */
    private function patchScene(CreativeVideoScene $scene, array $patch, int $i): void
    {
        if (array_key_exists('position', $patch)) {
            $scene->position = max(0, (int) $patch['position']);
        }
        if (array_key_exists('label', $patch)) {
            $label = trim((string) $patch['label']);
            if ($label === '' || mb_strlen($label) > 80) {
                throw ValidationException::withMessages(["scenes.{$i}.label" => 'A scene label is 1 to 80 characters.']);
            }
            $scene->label = $label;
        }
        foreach (['trim_in_ms', 'trim_out_ms'] as $field) {
            if (array_key_exists($field, $patch)) {
                $scene->{$field} = $patch[$field] !== null ? (int) $patch[$field] : null;
            }
        }
        $in = $scene->trim_in_ms ?? 0;
        $out = $scene->trim_out_ms ?? $scene->seconds * 1000;
        if ($in < 0 || $out > $scene->seconds * 1000 || $in >= $out) {
            throw ValidationException::withMessages(["scenes.{$i}.trim_in_ms" => 'A trim must keep part of the scene: 0 ≤ in < out ≤ the scene\'s length.']);
        }
        if (array_key_exists('template', $patch)) {
            if ($scene->kind !== CreativeVideoScene::TEMPLATE) {
                throw ValidationException::withMessages(["scenes.{$i}.template" => 'Only a template scene has template text.']);
            }
            $scene->template = self::validateTemplate(is_array($patch['template']) ? $patch['template'] : [], "scenes.{$i}.template");
        }
    }

    /** Insert a scene at $position (null = the end), the others shifted, then checked. */
    private function insert(CreativeVideo $video, array $columns, ?int $position): CreativeVideo
    {
        DB::transaction(function () use ($video, $columns, $position) {
            CreativeVideo::query()->lockForUpdate()->findOrFail($video->id);
            $count = CreativeVideoScene::query()->where('video_id', $video->id)->count();
            $position = $position === null ? $count : max(0, min($count, $position));
            CreativeVideoScene::query()->where('video_id', $video->id)->where('position', '>=', $position)->increment('position');
            CreativeVideoScene::create(['video_id' => $video->id, 'position' => $position] + $columns);
            $this->repack($video);
            $this->assertFits($video);
            $video->touch();
        });

        return $video;
    }

    /** Positions 0..n-1 in the current order (position, then id). */
    private function repack(CreativeVideo $video): void
    {
        $scenes = CreativeVideoScene::query()->where('video_id', $video->id)->orderBy('position')->orderBy('id')->get();
        foreach ($scenes->values() as $i => $scene) {
            if ($scene->position !== $i) {
                CreativeVideoScene::query()->whereKey($scene->id)->update(['position' => $i]);
            }
        }
    }

    /**
     * One caption per placed scene that has one, over that scene's slot:
     * the first scene's is the hook (top), the others body (bottom).
     *
     * @param  list<array{0: CreativeVideoScene, 1: array<string, mixed>}>  $placed
     */
    private function addCaptions(CreativeVideo $video, array $placed): void
    {
        $withCaption = array_filter($placed, fn ($p) => ($p[1]['caption'] ?? null) !== null);
        if ($withCaption === []) {
            return;
        }
        $starts = [];
        $at = 0;
        foreach (CreativeVideoScene::query()->where('video_id', $video->id)->orderBy('position')->orderBy('id')->get() as $scene) {
            $starts[$scene->id] = [$at, $at + $scene->durationMs(), (int) $scene->position];
            $at += $scene->durationMs();
        }

        $timeline = is_array($video->timeline) ? $video->timeline : self::seedTimeline(null);
        $captions = is_array($timeline['captions'] ?? null) ? $timeline['captions'] : [];
        foreach ($withCaption as [$scene, $draft]) {
            [$start, $end, $position] = $starts[$scene->id] ?? [0, $scene->durationMs(), 0];
            $hook = $position === 0;
            $captions[] = [
                'id' => 'c'.Str::lower(Str::random(10)),
                'text' => $draft['caption'],
                'start_ms' => $start,
                'end_ms' => $end,
                'style' => $draft['kind'] === CreativeVideoScene::TEMPLATE && ($draft['template']['key'] ?? null) === 'disclaimer' ? 'disclaimer' : ($hook ? 'hook' : 'body'),
                'position' => $hook ? 'top' : 'bottom',
            ];
        }
        $timeline['captions'] = $captions;
        $video->timeline = $timeline;
    }

    /** The columns a draft sets on its slot. @return array<string, mixed> */
    private static function sceneColumns(array $draft): array
    {
        return [
            'kind' => $draft['kind'],
            'label' => mb_substr((string) $draft['label'], 0, 80),
            'prompt' => $draft['prompt'],
            'engine' => $draft['engine'],
            'with_character' => (bool) $draft['with_character'],
            'seconds' => (int) $draft['seconds'],
            'template' => $draft['template'],
            'status' => $draft['kind'] === CreativeVideoScene::TEMPLATE ? CreativeVideoScene::READY : CreativeVideoScene::PLANNED,
        ];
    }

    /** Seconds a scene may last: kling 5|10, seedance 4–15, template 2–6. */
    private static function assertSeconds(string $kind, int $seconds, string $field, ?int $n = null): void
    {
        $prefix = $n !== null ? "Scene {$n}: " : '';
        $ok = match ($kind) {
            CreativeVideoScene::KLING => in_array($seconds, self::KLING_SECONDS, true),
            CreativeVideoScene::SEEDANCE => $seconds >= self::SEEDANCE_SECONDS[0] && $seconds <= self::SEEDANCE_SECONDS[1],
            default => $seconds >= self::TEMPLATE_SECONDS[0] && $seconds <= self::TEMPLATE_SECONDS[1],
        };
        if (! $ok) {
            throw ValidationException::withMessages([$field => $prefix.match ($kind) {
                CreativeVideoScene::KLING => 'a Kling scene is 5 or 10 seconds.',
                CreativeVideoScene::SEEDANCE => 'a Seedance scene is 4 to 15 seconds.',
                default => 'a template scene is 2 to 6 seconds.',
            }]);
        }
    }

    private static function templateLabel(array $template): string
    {
        $title = trim((string) ($template['title'] ?? ''));

        return $title !== '' ? $title : ucfirst(str_replace('-', ' ', (string) $template['key']));
    }
}
