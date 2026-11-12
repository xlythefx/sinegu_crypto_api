<?php

namespace App\Services\Creatives;

use App\Models\CreativeAgentJob;
use App\Models\CreativeAgentMessage;
use App\Models\CreativeAgentSession;
use App\Models\CreativeAiCall;
use App\Models\CreativeVideo;
use App\Models\CreativeVideoScene;
use App\Models\UserCredential;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Admin → Creatives' chat agent — the CLIENT-STEPPED loop. Cloudflare cuts a
 * request at 100 s, so ONE HTTP request makes AT MOST ONE model call:
 *
 *   turn(text)  appends the person's message (with its [Context] block) and
 *               calls Claude once. Tool calls in the answer are executed at
 *               once (fast DB work and free price checks) and their results
 *               appended → `continue`, and the client asks again (no text).
 *   …           until `done` (an answer with no tools, or buttons were shown
 *               with ask_choices — the pick is the person's next message),
 *               `awaiting_approval` (a job was proposed — Go is the person's),
 *               or `error`. At most
 *               {@see MAX_CALLS} model calls per person message: the next one
 *               answers `done` with a short "I stopped here" note.
 *
 * Rules that keep the conversation valid for Claude (preserved thinking):
 *  - The stored history is APPEND-ONLY. Assistant content blocks are stored as
 *    the exact JSON Claude returned (thinking included) and replayed
 *    unchanged; nothing earlier is ever edited, re-ordered or dropped. Rows of
 *    the same role in a row (a person writing after a Stop) are sent as one
 *    message, their blocks in order — the earlier bytes stay a prefix.
 *  - Every tool_result for one assistant message goes back in ONE user
 *    message, `is_error` on failures.
 *  - The stop reason is read BEFORE the content: a refusal or an answer cut
 *    off at max_tokens is never stored (a cut tool_use cannot be replayed)
 *    and its tools never run.
 *  - The tools array is identical on every call; modes are enforced by the
 *    tool executor's error results. A chat started on an older tools array
 *    ({@see CreativeAgentPrompt::TOOLS_VERSION}) is over: a fresh one opens.
 *  - A short cache lock per session (SESSION_BUSY) so two tabs cannot
 *    interleave their appends.
 *
 * Money: every model call reserves CreativeAi::MAX_CALL_USD under the
 * settings-row lock and records its real cost as a `creative_ai_calls` row of
 * kind `agent` — the same cap as everything else in Creatives.
 */
final class CreativeAgent
{
    /** Model calls allowed per person message. */
    public const MAX_CALLS = 8;

    /** How long one turn may hold the session (a turn is one model call, ≤ ~95 s). */
    private const LOCK_SECONDS = 150;

    /**
     * A rough ceiling on the conversation sent (characters ≈ 4 per token):
     * past it a single call could cost more than its reservation, so the
     * chat asks for a fresh start instead.
     */
    public const MAX_PROMPT_CHARS = 240_000;

    public function __construct(
        private CreativeAgentModel $model,
        private CreativeAi $ai,
        private CreativeBudget $budget,
        private CreativeAgentContext $context,
        private CreativeAgentTools $tools,
        private CreativeAgentJobs $jobs,
        private CreativeAgentPresenter $presenter,
    ) {}

    public function configured(): bool
    {
        return $this->model->configured();
    }

    public static function lockName(string $sessionId): string
    {
        return 'creatives-agent-turn:'.$sessionId;
    }

    // ---- sessions -------------------------------------------------------------

    /**
     * The caller's chat: the one named if it is theirs and still live, else
     * their newest live one, else a new one. Another person's id → NOT_FOUND.
     *
     * "Live" includes the tool list: a chat started before the agent's tools
     * changed cannot continue (its replayed history would meet a different
     * tools array), so a fresh chat opens and is flagged
     * (`replacedForNewTools`) for the snapshot's one-time `new_tools` notice.
     *
     * `$videoId` (v2.1): the video EDITOR's chat — the caller's newest live
     * chat bound to that video, else a new one bound to it (NOT_FOUND when
     * the video does not exist). Without it, the workspace chat: a chat bound
     * to a video is never picked as "the newest" there.
     */
    public function resolveSession(UserCredential $user, ?string $id, ?int $videoId = null): CreativeAgentSession
    {
        if ($videoId !== null && ! CreativeVideo::whereKey($videoId)->exists()) {
            throw CreativeException::notFound('Video not found.');
        }

        $outdated = false;
        if ($id !== null && $id !== '') {
            $named = CreativeAgentSession::find($id);
            if ($named !== null && $named->uni_id !== $user->uni_id) {
                throw CreativeException::notFound('Chat not found.');
            }
            // An editor chat answers for its own video only.
            $sameScope = $named !== null && ($videoId === null || $named->video_id === $videoId);
            if ($named !== null && $sameScope && $named->isLive()) {
                if ($named->hasCurrentTools()) {
                    return $named;
                }
                $outdated = true;
            }
        }

        $live = CreativeAgentSession::query()
            ->where('uni_id', $user->uni_id)
            ->where('updated_at', '>', now()->subDays(CreativeAgentSession::MAX_AGE_DAYS)->toDateTimeString());
        $videoId === null ? $live->whereNull('video_id') : $live->where('video_id', $videoId);
        $newest = (clone $live)
            ->where('tools_version', '>=', CreativeAgentPrompt::TOOLS_VERSION)
            ->orderByDesc('updated_at')->orderByDesc('created_at')
            ->first();
        if ($newest !== null) {
            return $newest;
        }

        // Only chats from before the new tools are left (or none at all).
        $fresh = $this->newSession($user, $videoId);
        $fresh->replacedForNewTools = $outdated || (clone $live)->exists();

        return $fresh;
    }

    /** A new chat; with `$videoId`, that video's editor chat (NOT_FOUND when it does not exist). */
    public function newSession(UserCredential $user, ?int $videoId = null): CreativeAgentSession
    {
        if ($videoId !== null && ! CreativeVideo::whereKey($videoId)->exists()) {
            throw CreativeException::notFound('Video not found.');
        }

        return CreativeAgentSession::create([
            'uni_id' => $user->uni_id,
            'user_name' => $user->name !== null ? mb_substr($user->name, 0, 120) : null,
            'video_id' => $videoId,
            'last_mode' => CreativeAgentSession::ASK,
            'tools_version' => CreativeAgentPrompt::TOOLS_VERSION,
        ]);
    }

    /** The `session` object of every chat payload: {id, mode, video_id}. */
    public static function sessionShape(CreativeAgentSession $session): array
    {
        return [
            'id' => $session->id,
            'mode' => $session->last_mode,
            'video_id' => $session->video_id !== null ? (int) $session->video_id : null,
        ];
    }

    /**
     * The scene ids a person's message is about (the editor's selection):
     * each must be a scene of the chat's video, else 422 — never stored, only
     * put in that message's [Context].
     *
     * @param  list<mixed>  $ids
     * @return list<int>
     */
    public function focusSceneIds(CreativeAgentSession $session, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        if ($session->video_id === null) {
            throw ValidationException::withMessages(['focus_scene_ids' => 'Only the video editor\'s chat can focus scenes.']);
        }
        $known = CreativeVideoScene::query()->where('video_id', $session->video_id)->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $foreign = array_values(array_diff($ids, $known));
        if ($foreign !== []) {
            throw ValidationException::withMessages(['focus_scene_ids' => 'Not a scene of this video: '.implode(', ', $foreign).'.']);
        }

        return $ids;
    }

    /** The caller's OWN live chat, for a turn. */
    public function ownedSession(UserCredential $user, string $id): CreativeAgentSession
    {
        $session = CreativeAgentSession::find($id);
        if ($session === null || $session->uni_id !== $user->uni_id) {
            throw CreativeException::notFound('Chat not found.');
        }
        if (! $session->isLive()) {
            throw CreativeException::notFound('This chat is more than '.CreativeAgentSession::MAX_AGE_DAYS.' days old. Start a new chat.');
        }
        if (! $session->hasCurrentTools()) {
            throw CreativeException::notFound('The assistant learned new tools, so this chat was closed. Start a new chat.');
        }

        return $session;
    }

    /**
     * The session read's payload: `{session, messages, jobs, budget}` (jobs
     * advanced first), plus `notice: "new_tools"` once — when this chat was
     * just opened because the previous one predates the agent's new tools.
     */
    public function snapshot(CreativeAgentSession $session): array
    {
        $this->jobs->refreshSession($session);

        $snapshot = [
            'session' => self::sessionShape($session),
            'messages' => $this->presenter->messages($session->messages()->get()),
            'jobs' => $this->presenter->jobs($session->jobs()->get()),
            'budget' => $this->budget->summary(),
        ];
        if ($session->replacedForNewTools) {
            $snapshot['notice'] = 'new_tools';
        }

        return $snapshot;
    }

    // ---- the turn ---------------------------------------------------------------

    /**
     * One step of the loop. Answers `{status, messages, jobs, cost_usd}` (the
     * controller adds the budget); refusals before or around the model call are
     * CreativeExceptions in the house shape.
     *
     * `$focus`: the editor's selected scene ids (checked by
     * {@see focusSceneIds()}), told to the model in this message's [Context].
     *
     * @param  list<int>  $focus
     * @return array{status: string, messages: list<array<string, mixed>>, jobs: list<array<string, mixed>>, cost_usd: float}
     */
    public function turn(CreativeAgentSession $session, UserCredential $user, string $mode, ?string $text, array $focus = []): array
    {
        if (! $this->model->configured()) {
            throw new CreativeException('AI_NOT_CONFIGURED', 'Claude isn\'t connected on this server yet.', 503);
        }

        $lock = Cache::lock(self::lockName($session->id), self::LOCK_SECONDS);
        if (! $lock->get()) {
            throw new CreativeException('SESSION_BUSY', 'This chat is already answering in another window. Wait for it to finish.', 409);
        }

        try {
            return $this->step($session, $user, $mode, $text, $focus);
        } finally {
            $lock->release();
        }
    }

    private function step(CreativeAgentSession $session, UserCredential $user, string $mode, ?string $text, array $focus): array
    {
        $rows = $session->messages()->get();
        $last = $rows->last(fn (CreativeAgentMessage $m) => $m->role !== CreativeAgentMessage::NOTICE);
        $text = $text !== null && trim($text) !== '' ? $text : null;

        // Is a model call owed without new words? After a person's message or
        // tool results, or a paused turn.
        $pending = $last !== null && (
            $last->role === CreativeAgentMessage::USER
            || ($last->role === CreativeAgentMessage::ASSISTANT && $last->stop_reason === 'pause_turn')
        );
        if ($text === null && ! $pending) {
            throw ValidationException::withMessages(['text' => 'Type a message.']);
        }

        // The mode that governs this step: Generate-only tools need Generate
        // on BOTH the request and the person's latest message (the mode the
        // model was told) — switching to Ask stops spending at once, switching
        // to Generate takes effect with the next message.
        $lastPerson = $rows->last(fn (CreativeAgentMessage $m) => $m->isPerson());
        $effectiveMode = $text !== null
            ? $mode
            : ($mode === CreativeAgentSession::GENERATE && $lastPerson?->mode === CreativeAgentSession::GENERATE
                ? CreativeAgentSession::GENERATE
                : CreativeAgentSession::ASK);

        if ($text === null && $this->callsSinceLastPerson($rows) >= self::MAX_CALLS) {
            $notice = $this->notice($session, 'I stopped here after '.self::MAX_CALLS.' steps so this does not run up the bill. Say "carry on" if you want me to continue.');

            return $this->answer('done', [$notice], [], 0.0);
        }

        // The person's message: the [Context] block, then their words (team
        // text is scrubbed of emails, ids and money amounts before it leaves).
        $personContent = null;
        $context = null;
        if ($text !== null) {
            $context = $this->context->build($session, $mode, focus: $focus);
            $personContent = [
                ['type' => 'text', 'text' => $context['text']],
                ['type' => 'text', 'text' => CreativeAiPrompt::scrub($text)],
            ];
        }

        $messages = $this->payload($rows, $personContent);
        $tools = CreativeAgentPrompt::tools();
        $size = strlen((string) json_encode($messages)) + strlen(CreativeAgentPrompt::SYSTEM) + strlen((string) json_encode($tools));
        if ($size > self::MAX_PROMPT_CHARS) {
            $notice = $this->notice($session, 'This chat has grown too long for me to keep answering it well. Start a new chat — memory, the brief and plans all stay.');

            return $this->answer('error', [$notice], [], 0.0);
        }

        // Reserve before anything is stored: no room = nothing happened.
        $call = $this->ai->reserveCall(
            CreativeAiCall::AGENT,
            $this->model->model(),
            $user->uni_id,
            $user->name,
            what: 'the assistant: one step',
        );

        $new = [];
        if ($personContent !== null) {
            $new[] = $this->store($session, CreativeAgentMessage::USER, $personContent, [['kind' => 'user', 'text' => $text]], $mode);
            $session->sent_context = $context['sent'];
        }
        $session->last_mode = $mode;
        $session->updated_at = now();
        $session->save();

        try {
            $reply = $this->model->step(CreativeAgentPrompt::SYSTEM, $tools, $messages, CreativeAgentPrompt::MAX_TOKENS);
        } catch (Throwable $e) {
            // The model's contract is to never throw; if one does, the request
            // may have run — keep the reservation.
            Log::warning('Creatives agent model threw.', ['class' => $e::class]);
            $reply = AgentReply::unanswered($this->model->model(), 'error', maybeBilled: true);
        }

        if (! $reply->answered) {
            $this->ai->abandonCall($call, $reply->maybeBilled);

            throw new CreativeException('AI_UNAVAILABLE', CreativeAi::unavailableMessage($reply->failure), 503, [
                'status' => 'error',
                'messages' => $this->presenter->messages($new),
                'jobs' => [],
                'cost_usd' => 0.0,
                'budget' => $this->budget->summary(),
            ]);
        }

        // The stop reason FIRST, then the content.
        $cost = $this->ai->settleCall($call, $reply->model, $reply->stopReason, $reply->usage);

        if ($reply->stopReason === 'refusal') {
            $new[] = $this->notice($session, 'Claude declined to answer that. Try rewording it, or start a new chat.');

            throw new CreativeException('AI_REFUSED', 'Claude declined to answer that. Try rewording it.', 422, [
                'status' => 'error',
                'messages' => $this->presenter->messages($new),
                'jobs' => [],
                'cost_usd' => $cost,
                'budget' => $this->budget->summary(),
            ]);
        }
        if (! in_array($reply->stopReason, ['tool_use', 'end_turn', 'stop_sequence', 'pause_turn'], true)) {
            // max_tokens, a full context window or anything new: the answer is
            // incomplete (a tool call may be cut mid-input) — never stored.
            $new[] = $this->notice($session, $reply->stopReason === 'max_tokens'
                ? 'I ran out of room before finishing that step. Ask again, a bit more narrowly.'
                : 'I could not finish that answer. Ask again, or start a new chat.');

            return $this->answer('error', $new, [], $cost);
        }

        $blocks = $reply->blocks();
        $toolUses = array_values(array_filter($blocks, fn ($b) => is_array($b) && ($b['type'] ?? null) === 'tool_use'));
        $assistantText = trim(implode("\n\n", array_map(
            fn ($b) => (string) ($b['text'] ?? ''),
            array_filter($blocks, fn ($b) => is_array($b) && ($b['type'] ?? null) === 'text'),
        )));
        $assistantDisplay = $assistantText !== '' ? [['kind' => 'assistant', 'text' => $assistantText]] : [];

        if ($toolUses === []) {
            $new[] = $this->storeRaw($session, CreativeAgentMessage::ASSISTANT, (string) $reply->content, $assistantDisplay, $mode, $reply->stopReason);

            return $this->answer($reply->stopReason === 'pause_turn' ? 'continue' : 'done', $new, [], $cost);
        }

        // Tool calls: the answer, every result in ONE user message — together.
        $jobs = [];
        $awaiting = false;
        $asked = false;
        DB::transaction(function () use ($session, $reply, $assistantDisplay, $mode, $effectiveMode, $toolUses, $user, &$new, &$jobs, &$awaiting, &$asked) {
            $new[] = $this->storeRaw($session, CreativeAgentMessage::ASSISTANT, (string) $reply->content, $assistantDisplay, $mode, $reply->stopReason);

            $results = [];
            $display = [];
            foreach ($toolUses as $use) {
                $input = is_array($use['input'] ?? null) ? $use['input'] : [];
                $outcome = $this->tools->run((string) ($use['name'] ?? ''), $input, $session, $effectiveMode, $user->name);
                $result = ['type' => 'tool_result', 'tool_use_id' => (string) ($use['id'] ?? ''), 'content' => $outcome['content']];
                if ($outcome['is_error']) {
                    $result['is_error'] = true;
                }
                $results[] = $result;
                array_push($display, ...$outcome['display']);
                if ($outcome['job'] instanceof CreativeAgentJob) {
                    $jobs[] = $outcome['job'];
                    $awaiting = true;
                }
                if (! $outcome['is_error'] && collect($outcome['display'])->contains(fn ($item) => ($item['kind'] ?? null) === 'choices')) {
                    $asked = true;
                }
            }
            $new[] = $this->store($session, CreativeAgentMessage::USER, $results, $display, $effectiveMode);
        });

        // Buttons were shown: the person answers next (a job card wins — Go is theirs too).
        return $this->answer($awaiting ? 'awaiting_approval' : ($asked ? 'done' : 'continue'), $new, $jobs, $cost);
    }

    // ---- history ----------------------------------------------------------------

    /**
     * The conversation as Claude receives it: every stored row except notices,
     * content decoded to objects (so it goes to the wire exactly as stored),
     * consecutive rows of one role sent as one message.
     *
     * @param  Collection<int, CreativeAgentMessage>  $rows
     * @param  list<array<string, mixed>>|null  $append  a new person message, not yet stored
     * @return list<array{role: string, content: list<mixed>}>
     */
    private function payload(Collection $rows, ?array $append): array
    {
        $messages = [];
        $add = function (string $role, array $blocks) use (&$messages) {
            $lastIndex = count($messages) - 1;
            if ($lastIndex >= 0 && $messages[$lastIndex]['role'] === $role) {
                array_push($messages[$lastIndex]['content'], ...$blocks);
            } else {
                $messages[] = ['role' => $role, 'content' => $blocks];
            }
        };

        foreach ($rows as $row) {
            if ($row->role === CreativeAgentMessage::NOTICE || $row->content === null) {
                continue;
            }
            $blocks = json_decode($row->content, false);
            if (is_array($blocks) && $blocks !== []) {
                $add($row->role, $blocks);
            }
        }
        if ($append !== null) {
            $add(CreativeAgentMessage::USER, json_decode(self::encode($append), false));
        }

        return $messages;
    }

    /** Model calls since the person last wrote. */
    private function callsSinceLastPerson(Collection $rows): int
    {
        $calls = 0;
        foreach ($rows->reverse() as $row) {
            if ($row->isPerson()) {
                break;
            }
            if ($row->role === CreativeAgentMessage::ASSISTANT) {
                $calls++;
            }
        }

        return $calls;
    }

    /** @param  list<array<string, mixed>>  $display */
    private function store(CreativeAgentSession $session, string $role, array $content, array $display, ?string $mode): CreativeAgentMessage
    {
        return $this->storeRaw($session, $role, self::encode($content), $display, $mode, null);
    }

    /** @param  list<array<string, mixed>>  $display */
    private function storeRaw(CreativeAgentSession $session, string $role, string $content, array $display, ?string $mode, ?string $stopReason): CreativeAgentMessage
    {
        return CreativeAgentMessage::create([
            'session_id' => $session->id,
            'role' => $role,
            'content' => $content,
            'display' => $display,
            'mode' => $mode,
            'stop_reason' => $stopReason !== null ? mb_substr($stopReason, 0, 24) : null,
        ]);
    }

    /** A display-only line (never sent to Claude). */
    private function notice(CreativeAgentSession $session, string $text): CreativeAgentMessage
    {
        return CreativeAgentMessage::create([
            'session_id' => $session->id,
            'role' => CreativeAgentMessage::NOTICE,
            'content' => null,
            'display' => [['kind' => 'notice', 'text' => $text]],
            'mode' => null,
        ]);
    }

    /**
     * @param  list<CreativeAgentMessage>  $rows
     * @param  list<CreativeAgentJob>  $jobs
     */
    private function answer(string $status, array $rows, array $jobs, float $cost): array
    {
        return [
            'status' => $status,
            'messages' => $this->presenter->messages($rows),
            'jobs' => $this->presenter->jobs(collect($jobs)),
            'cost_usd' => $cost,
        ];
    }

    private static function encode(array $content): string
    {
        return (string) json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
