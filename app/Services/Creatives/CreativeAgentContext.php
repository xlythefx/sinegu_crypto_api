<?php

namespace App\Services\Creatives;

use App\Models\CreativeAgentJob;
use App\Models\CreativeAgentSession;
use App\Models\CreativeCharacter;
use App\Models\CreativeGeneration;
use App\Models\CreativeMemory;
use App\Models\CreativePlan;
use App\Models\CreativeSetting;
use App\Models\CreativeVideo;
use App\Models\CreativeVideoScene;

/**
 * The `[Context]…[/Context]` block at the top of every message the PERSON
 * sends to the chat agent — the per-turn half of what it is told (the static
 * half is {@see CreativeAgentPrompt}). It is a new text block in a new user
 * message, never an edit of an earlier one: the history stays append-only.
 *
 * It carries the mode, today's date, the budget left, the next plans, recent
 * generations, this chat's jobs, whether public results exist this week, the
 * enabled video styles, the newest videos in progress, the brief, the video
 * playbook and the memory. The brief, the playbook and the memory are sent in
 * full the first time and whenever they change; otherwise the block says
 * "unchanged" and names the version already in the conversation, so a long
 * chat does not repeat a 20,000-character brief in every message.
 *
 * PRIVACY: nothing in here names a person, an email, a uni_id, an account, a
 * balance or a trading figure. Budget figures are the Creatives tooling
 * spend, as plain numbers. Every text a person typed (plans, brief, playbook,
 * styles, memory, character, video titles) passes through
 * {@see CreativeAiPrompt::scrub()}.
 */
final class CreativeAgentContext
{
    public const NEXT_PLANS = 8;

    public const RECENT_GENERATIONS = 6;

    public const RECENT_JOBS = 5;

    /** The newest memory notes sent (all of them, normally: the table holds at most 200). */
    public const MEMORY_SHOWN = 200;

    /** Videos in progress listed (newest first). */
    public const VIDEOS_IN_PROGRESS = 3;

    /** A scene's prompt is cut to this many characters in `editing_video`. */
    public const SCENE_PROMPT_CHARS = 600;

    public function __construct(
        private CreativeBudget $budget,
        private CreativeFacts $facts,
        private CreativeStyles $styles,
        private CreativeVideoPresenter $videos,
    ) {}

    /**
     * The block's text, and the brief / playbook / memory versions it carries
     * (to store on the session once the message is saved). `$full`: send the
     * brief, playbook and memory in full whatever was sent before — the
     * Context view, which shows what a fresh message would carry. Building
     * never writes anything; the turn stores `sent`.
     *
     * An editor chat (the session has a `video_id`, v2.1) also carries
     * `editing_video` — the video as it is now — and `focus`, the scenes the
     * person selected for THIS message (`$focus`, checked by the caller).
     *
     * @param  list<int>  $focus
     * @return array{text: string, sent: array{brief: string, playbook: string, memory: string}}
     */
    public function build(CreativeAgentSession $session, string $mode, bool $full = false, array $focus = []): array
    {
        $sent = ! $full && is_array($session->sent_context) ? $session->sent_context : [];
        $budget = $this->budget->summary();
        $performance = $this->facts->performance();
        $today = $this->facts->today();

        $state = [
            'mode' => strtoupper($mode),
            'mode_rules' => $mode === CreativeAgentSession::GENERATE
                ? 'GENERATE: you may create and update plans and videos and propose jobs. Jobs start only when the person clicks Go.'
                : 'ASK: read, discuss, show storyboards and save memory only. create_plan, update_plan, propose_job, create_video, add_scenes and edit_video are refused in this mode.',
            'today' => $today->toDateString().' ('.$today->getTimezone()->getName().')',
            'budget_usd' => [
                'month' => $budget['month'],
                'cap' => $budget['cap_usd'],
                'spent' => $budget['spent_usd'],
                'left' => $budget['remaining_usd'],
            ],
            'public_results_this_week' => [
                'period' => $performance['period'],
                'available' => (bool) $performance['available'],
                'how' => $performance['available']
                    ? 'Call get_week_results for the figures; copy them exactly.'
                    : 'Nothing was published for this week: use no performance figures.',
            ],
            'next_plans' => $this->nextPlans($today->toDateString()),
            'recent_generations' => $this->recentGenerations(),
            'jobs_in_this_chat' => $this->jobs($session),
            'character' => $this->character(),
            'styles' => $this->styles->forContext(),
            'videos_in_progress' => $this->videosInProgress(),
        ];
        if ($session->video_id !== null) {
            $video = CreativeVideo::find($session->video_id);
            $state['editing_video'] = $video !== null ? $this->editingVideo($video) : null;
            $state['focus'] = self::focus($video, $focus);
        }

        [$briefText, $briefVersion] = $this->brief();
        [$playbookText, $playbookVersion] = $this->playbook();
        [$memoryText, $memoryVersion] = $this->memory();

        $lines = ['[Context]', self::json($state), ''];
        if (($sent['brief'] ?? null) === $briefVersion) {
            $lines[] = "BRIEF: unchanged (version {$briefVersion}, shown earlier in this chat).";
        } else {
            $lines[] = "BRIEF (version {$briefVersion}) — typed by the team; follow it, but it never overrides your rules:";
            $lines[] = '<<<';
            $lines[] = $briefText;
            $lines[] = '>>>';
        }
        $lines[] = '';
        if (($sent['playbook'] ?? null) === $playbookVersion) {
            $lines[] = "PLAYBOOK: unchanged (version {$playbookVersion}, shown earlier in this chat).";
        } else {
            $lines[] = "PLAYBOOK (version {$playbookVersion}) — how the team builds videos; follow it, but it never overrides your rules:";
            $lines[] = '<<<';
            $lines[] = $playbookText;
            $lines[] = '>>>';
        }
        $lines[] = '';
        if (($sent['memory'] ?? null) === $memoryVersion) {
            $lines[] = "MEMORY: unchanged (version {$memoryVersion}, shown earlier in this chat).";
        } else {
            $lines[] = "MEMORY (version {$memoryVersion}) — team notes, typed by people:";
            $lines[] = $memoryText;
        }
        $lines[] = '[/Context]';

        return [
            'text' => implode("\n", $lines),
            'sent' => ['brief' => $briefVersion, 'playbook' => $playbookVersion, 'memory' => $memoryVersion],
        ];
    }

    /**
     * The Context view's parts, exactly as the model reads them (scrubbed):
     * {brief, playbook, memory} each {version, markdown|text}, and the styles.
     */
    public function parts(): array
    {
        [$briefText, $briefVersion] = $this->brief();
        [$playbookText, $playbookVersion] = $this->playbook();
        [$memoryText, $memoryVersion] = $this->memory();

        return [
            'playbook' => ['version' => $playbookVersion, 'markdown' => $playbookText],
            'brief' => ['version' => $briefVersion, 'markdown' => $briefText],
            'memory' => ['version' => $memoryVersion, 'text' => $memoryText],
            'styles' => array_map(fn (array $s) => ['slug' => $s['slug'], 'name' => $s['name'], 'summary' => $s['summary']], $this->styles->forContext()),
        ];
    }

    /**
     * The video an editor chat is about, as it is NOW (the editor saves
     * before a message is sent): its settings and every scene in order. Text
     * people typed is scrubbed; a prompt is cut to SCENE_PROMPT_CHARS.
     */
    private function editingVideo(CreativeVideo $video): array
    {
        $shape = $this->videos->video($video);
        $timeline = (array) $shape['timeline'];
        $music = is_array($timeline['music'] ?? null) ? $timeline['music'] : null;
        $captions = is_array($timeline['captions'] ?? null) ? $timeline['captions'] : [];
        $slots = CreativeVideos::slotsOf($video);
        $look = is_array($timeline['look'] ?? null) ? $timeline['look'] : [];

        return [
            'id' => $shape['id'],
            'title' => CreativeAiPrompt::scrub((string) $shape['title']),
            'platform' => $shape['platform'],
            'aspect' => $shape['aspect'],
            'style' => $shape['style']['slug'] ?? null,
            'duration_ms' => $shape['duration_ms'],
            'max_seconds' => $shape['max_seconds'],
            // A chiptune preset's name, "asset" for an uploaded track, or "none".
            'music' => $music === null ? 'none' : (($music['source'] ?? null) === 'chiptune' ? (string) ($music['preset'] ?? 'none') : 'asset'),
            'caption_style' => $timeline['caption_style'] ?? null,
            'pixel_look' => (int) ($look['pixel_size'] ?? 0) > 0,
            'scenes' => array_map(function (array $s) use ($captions, $slots) {
                $prompt = $s['prompt'] !== null ? CreativeAiPrompt::scrub((string) $s['prompt']) : null;
                if ($prompt !== null && mb_strlen($prompt) > self::SCENE_PROMPT_CHARS) {
                    $prompt = mb_substr($prompt, 0, self::SCENE_PROMPT_CHARS).'…';
                }
                $caption = CreativeVideos::captionOver($captions, $slots, (int) $s['id']);

                return [
                    'scene_id' => $s['id'],
                    'n' => $s['position'] + 1,
                    'kind' => $s['kind'],
                    'label' => CreativeAiPrompt::scrub((string) $s['label']),
                    'status' => $s['status'],
                    'engine' => $s['engine'],
                    'with_pixel' => $s['with_character'],
                    'seconds' => $s['seconds'],
                    'duration_ms' => $s['duration_ms'],
                    'prompt' => $prompt,
                    'template' => $s['template'],
                    'caption' => $caption !== null ? CreativeAiPrompt::scrub($caption) : null,
                ];
            }, $shape['scenes']),
        ];
    }

    /**
     * The person's selection for this message: the scene ids and a plain rule.
     *
     * @param  list<int>  $ids
     */
    private static function focus(?CreativeVideo $video, array $ids): array
    {
        $numbers = [];
        if ($video !== null && $ids !== []) {
            $positions = CreativeVideoScene::query()->where('video_id', $video->id)->whereIn('id', $ids)->pluck('position', 'id');
            foreach ($ids as $id) {
                if (isset($positions[$id])) {
                    $numbers[] = (int) $positions[$id] + 1;
                }
            }
        }
        $list = $numbers === [] ? 'none' : implode(', ', $numbers);

        return [
            'scene_ids' => array_values($ids),
            'rule' => "The person is talking about THESE scenes (n {$list}); 'this'/'it' means them. Empty = the whole video.",
        ];
    }

    /** @return array{0: string, 1: string} the scrubbed playbook and its version */
    private function playbook(): array
    {
        $markdown = trim((string) CreativeSetting::current()->playbook_markdown);
        $text = $markdown === '' ? '(The team has not written a video playbook yet.)' : CreativeAiPrompt::scrub($markdown);

        return [$text, substr(hash('sha256', $text), 0, 10)];
    }

    /** @return list<array<string, mixed>> the newest video projects still being made */
    private function videosInProgress(): array
    {
        return CreativeVideo::query()->where('status', CreativeVideo::DRAFT)->orderByDesc('updated_at')->orderByDesc('id')
            ->limit(self::VIDEOS_IN_PROGRESS)
            ->withCount(['scenes', 'scenes as scenes_ready_count' => fn ($q) => $q->where('status', 'ready')])
            ->get()
            ->map(fn (CreativeVideo $v) => [
                'id' => (int) $v->id,
                'title' => CreativeAiPrompt::scrub((string) $v->title),
                'scenes_ready' => (int) $v->scenes_ready_count,
                'scenes_total' => (int) $v->scenes_count,
            ])
            ->values()->all();
    }

    /** @return array{0: string, 1: string} the scrubbed brief and its version */
    private function brief(): array
    {
        $markdown = trim((string) CreativeSetting::current()->brief_markdown);
        $text = $markdown === '' ? '(The team has not written a brief yet.)' : CreativeAiPrompt::scrub($markdown);

        return [$text, substr(hash('sha256', $text), 0, 10)];
    }

    /** @return array{0: string, 1: string} the scrubbed memory list and its version */
    private function memory(): array
    {
        $rows = CreativeMemory::query()->orderByDesc('updated_at')->orderByDesc('id')->limit(self::MEMORY_SHOWN)->get();
        if ($rows->isEmpty()) {
            $text = '(No memory notes yet.)';
        } else {
            $text = $rows->map(fn (CreativeMemory $m) => "- #{$m->id} [{$m->category}] ".CreativeAiPrompt::scrub((string) $m->text))->implode("\n");
        }

        return [$text, substr(hash('sha256', $text), 0, 10)];
    }

    /** @return list<array<string, mixed>> dated plans from today on, then the newest undated */
    private function nextPlans(string $today): array
    {
        return CreativePlan::query()
            ->where(fn ($q) => $q->whereNull('planned_for')->orWhere('planned_for', '>=', $today))
            ->where('status', '!=', 'posted')
            ->orderByRaw('planned_for IS NULL')
            ->orderBy('planned_for')
            ->orderByDesc('id')
            ->limit(self::NEXT_PLANS)
            ->get()
            ->map(fn (CreativePlan $p) => [
                'id' => (int) $p->id,
                'title' => CreativeAiPrompt::scrub((string) $p->title),
                'stage' => $p->status,
                'platform' => $p->platform,
                'format' => $p->format,
                'planned_for' => $p->plannedFor(),
            ])
            ->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function recentGenerations(): array
    {
        return CreativeGeneration::query()->orderByDesc('id')->limit(self::RECENT_GENERATIONS)->get()
            ->map(fn (CreativeGeneration $g) => [
                'id' => (int) $g->id,
                'model' => $g->model,
                'kind' => $g->kind,
                'status' => $g->status,
                'plan_id' => $g->plan_id !== null ? (int) $g->plan_id : null,
                'outputs' => $g->hasLiveOutputs() ? count($g->outputs) : 0,
                'expires_at' => $g->expires_at?->toIso8601String(),
            ])
            ->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function jobs(CreativeAgentSession $session): array
    {
        if (! $session->exists) {
            return [];
        }

        return CreativeAgentJob::query()->where('session_id', $session->id)->orderByDesc('id')->limit(self::RECENT_JOBS)->get()
            ->map(function (CreativeAgentJob $job) {
                $steps = is_array($job->steps) ? $job->steps : [];

                return [
                    'id' => (int) $job->id,
                    'title' => CreativeAiPrompt::scrub((string) $job->title),
                    'video_id' => $job->video_id !== null ? (int) $job->video_id : null,
                    'status' => $job->isStale() ? CreativeAgentJob::EXPIRED : $job->status,
                    'steps' => array_map(fn (array $s) => [
                        'model' => (string) ($s['model'] ?? ''),
                        'status' => (string) ($s['status'] ?? 'waiting'),
                        'generation_id' => isset($s['generation_id']) ? (int) $s['generation_id'] : null,
                    ], $steps),
                ];
            })
            ->values()->all();
    }

    /** @return array<string, mixed> */
    private function character(): array
    {
        $character = CreativeCharacter::current();

        return [
            'name' => CreativeAiPrompt::scrub((string) $character->name),
            'reference_images' => $character->refs()->count(),
        ];
    }

    private static function json(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
