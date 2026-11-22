<?php

namespace App\Http\Controllers;

use App\Models\DevBotAction;
use App\Models\DevBotJob;
use App\Models\DevBotSetting;
use App\Services\DevBot\DevBotJobs;
use App\Services\DevBot\DevBotTelegram;
use App\Services\DevBot\SystemStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin → Error Bot (2026-11-23, Christian): the developer error bot from the
 * admin side — the same service checks as /status, what the bot may send
 * first (per kind, or muted for a while), the PC agent's pause, a test
 * message, and the agent's jobs with cancel / retry. Full admins only.
 *
 * Never returns a secret: the token, webhook secret and agent key appear as
 * "set" booleans; the webhook address is public by nature.
 */
class AdminDevBotController extends Controller
{
    public function __construct(
        private DevBotTelegram $telegram,
        private DevBotJobs $jobs,
        private SystemStatus $status,
    ) {}

    /** GET /api/admin/devbot */
    public function index(): JsonResponse
    {
        $s = DevBotSetting::current();
        $info = $this->telegram->configured() ? $this->telegram->webhookInfo() : null;
        $lastSeen = $this->jobs->lastSeen();

        return response()->json([
            'success' => true,
            'bot' => [
                'configured' => $this->telegram->configured(),
                'token_set' => trim((string) config('services.dev_bot.token', '')) !== '',
                'chat_set' => $this->telegram->chatId() !== '',
                'webhook_secret_set' => trim((string) config('services.dev_bot.webhook_secret', '')) !== '',
                'agent_key_set' => strlen((string) config('services.dev_bot.agent_key', '')) >= 24,
                'webhook_url' => $info['url'] ?? null,
                'webhook_pending' => isset($info['pending_update_count']) ? (int) $info['pending_update_count'] : null,
                'webhook_error' => $info['last_error_message'] ?? null,
            ],
            'settings' => $this->presentSettings($s),
            'kinds' => collect(DevBotSetting::KINDS)->map(fn ($v, $k) => ['key' => $k, 'label' => $v[0], 'description' => $v[1]])->values(),
            'agent' => [
                'online' => $this->jobs->agentOnline(),
                'last_seen_at' => $lastSeen?->toIso8601String(),
                'paused' => (bool) $s->agent_paused,
            ],
            'checks' => $this->status->checks(),
            'jobs' => DevBotJob::query()->orderByDesc('id')->limit(30)->get()->map(fn (DevBotJob $j) => [
                'id' => $j->id,
                'status' => $j->status,
                'source' => $j->source,
                'kind' => $j->context['kind'] ?? null,
                'prompt' => mb_substr($j->prompt, 0, 400),
                'summary' => $j->summary !== null ? mb_substr($j->summary, 0, 1500) : null,
                'links' => $j->links ?? [],
                'created_at' => $j->created_at?->toIso8601String(),
                'claimed_at' => $j->claimed_at?->toIso8601String(),
                'finished_at' => $j->finished_at?->toIso8601String(),
            ]),
            'actions' => DevBotAction::query()->orderByDesc('id')->limit(30)->get()->map(fn (DevBotAction $a) => [
                'id' => $a->id,
                'kind' => $a->kind,
                'status' => $a->status,
                'subject' => $a->kind === DevBotAction::DEPLOY
                    ? (string) ($a->payload['title'] ?? $a->ticker)
                    : $a->ticker.($a->side ? " {$a->side}" : ''),
                'result' => $a->result_text,
                'resolved_by' => $a->resolved_by,
                'occurrences' => $a->occurrences,
                'created_at' => $a->created_at?->toIso8601String(),
                'resolved_at' => $a->resolved_at?->toIso8601String(),
            ]),
        ]);
    }

    /** PUT /api/admin/devbot/settings {disabled_kinds?, mute_minutes?, agent_paused?} — absent = unchanged. */
    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'disabled_kinds' => ['sometimes', 'array'],
            'disabled_kinds.*' => ['string', Rule::in(array_keys(DevBotSetting::KINDS))],
            // 0 = unmute now; otherwise mute for this many minutes from now.
            'mute_minutes' => ['sometimes', 'integer', Rule::in([0, 60, 480, 1440])],
            'agent_paused' => ['sometimes', 'boolean'],
        ]);
        $s = DevBotSetting::current();
        if (array_key_exists('disabled_kinds', $data)) {
            $s->disabled_kinds = array_values(array_unique($data['disabled_kinds']));
        }
        if (array_key_exists('mute_minutes', $data)) {
            $s->muted_until = $data['mute_minutes'] === 0 ? null : now()->addMinutes($data['mute_minutes']);
        }
        if (array_key_exists('agent_paused', $data)) {
            $s->agent_paused = (bool) $data['agent_paused'];
        }
        $user = $request->user();
        $s->updated_by_name = mb_substr((string) ($user?->name ?: $user?->email ?: 'Admin'), 0, 100);
        $s->save();

        return response()->json(['success' => true, 'settings' => $this->presentSettings($s)]);
    }

    /** POST /api/admin/devbot/test — one message to the DM, so the owner sees it arrive. */
    public function test(Request $request): JsonResponse
    {
        if (! $this->telegram->configured()) {
            return response()->json(['error' => 'NOT_CONFIGURED', 'message' => 'The error bot is not set up on this server.'], 409);
        }
        $name = (string) ($request->user()?->name ?: 'an admin');
        $id = $this->telegram->send('🧪 <b>Test message</b> from Admin → Error Bot, sent by '.e($name).'. The bot can reach you.');

        return $id === null
            ? response()->json(['error' => 'NOT_DELIVERED', 'message' => 'Telegram did not accept the message. Check the bot settings.'], 503)
            : response()->json(['success' => true]);
    }

    /** POST /api/admin/devbot/jobs/{id}/cancel — only a job the agent has not started. */
    public function cancelJob(int $id): JsonResponse
    {
        $job = DevBotJob::findOrFail($id);
        if ($job->status !== DevBotJob::QUEUED) {
            return response()->json(['error' => 'NOT_QUEUED', 'message' => 'Only a job that has not started can be cancelled.'], 409);
        }
        $job->forceFill(['status' => DevBotJob::CANCELLED, 'finished_at' => now(), 'summary' => 'Cancelled on Admin → Error Bot.'])->save();

        return response()->json(['success' => true]);
    }

    /** POST /api/admin/devbot/jobs/{id}/retry — the same request again, as a new job. */
    public function retryJob(int $id): JsonResponse
    {
        $job = DevBotJob::findOrFail($id);
        if (! in_array($job->status, [DevBotJob::DONE, DevBotJob::FAILED, DevBotJob::CANCELLED], true)) {
            return response()->json(['error' => 'STILL_RUNNING', 'message' => 'That job has not finished yet.'], 409);
        }
        $new = $this->jobs->queue($job->prompt, $job->source, $job->context ?? []);

        return response()->json(['success' => true, 'job_id' => $new->id]);
    }

    private function presentSettings(DevBotSetting $s): array
    {
        return [
            'disabled_kinds' => array_values((array) ($s->disabled_kinds ?? [])),
            'muted_until' => $s->isMuted() ? $s->muted_until->toIso8601String() : null,
            'agent_paused' => (bool) $s->agent_paused,
            'updated_by' => $s->updated_by_name,
            'updated_at' => $s->updated_at?->toIso8601String(),
        ];
    }
}
