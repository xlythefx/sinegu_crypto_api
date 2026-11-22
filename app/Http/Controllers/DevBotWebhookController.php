<?php

namespace App\Http\Controllers;

use App\Models\TradeLog;
use App\Services\DevBot\DevBotActions;
use App\Services\DevBot\DevBotJobs;
use App\Services\DevBot\DevBotTelegram;
use App\Services\DevBot\SystemStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * POST /api/devbot/telegram — Telegram's webhook for the developer error bot
 * (2026-11-22). Its buttons change REAL-MONEY settings and can start a
 * deploy, so two gates, both server-side:
 *
 *  1. The call must carry DEV_BOT_WEBHOOK_SECRET in Telegram's
 *     X-Telegram-Bot-Api-Secret-Token header (set with `devbot:webhook`), or it
 *     is not Telegram — 403. No secret configured = the endpoint is shut.
 *  2. The tap or message must come from DEV_BOT_CHAT_ID's Telegram user — the
 *     bot talks in one DM, whose chat id is that user's id. Anyone else who
 *     finds the bot is told it is private, and nothing runs.
 *
 * Buttons: da = add a coin, dj / dw / dx = investigate (a signal, a failing
 * service check, a server error), dd = deploy, jt = hand a status question to
 * the PC agent. Every accepted call answers 200, even for an update it
 * ignores: anything else makes Telegram re-deliver it for hours.
 */
class DevBotWebhookController extends Controller
{
    /** Typed text that is a question about whether things are running. */
    private const STATUS_WORDS = '/\b(status|up|down|running|alive|online|offline|working|healthy|health|listeners?|watcher|scheduler|cron)\b/i';

    public function __construct(
        private DevBotTelegram $telegram,
        private DevBotActions $actions,
        private DevBotJobs $jobs,
        private SystemStatus $status,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $secret = (string) config('services.dev_bot.webhook_secret', '');
        $given = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');
        if ($secret === '' || ! hash_equals($secret, $given)) {
            return response()->json(['ok' => false], 403);
        }

        if (is_array($cb = $request->input('callback_query'))) {
            $this->tap($cb);
        } elseif (is_array($msg = $request->input('message')) && isset($msg['chat']['id'])) {
            $this->message($msg);
        }

        return response()->json(['ok' => true]);
    }

    private function isOwner(string $id): bool
    {
        $owner = $this->telegram->chatId();

        return $owner !== '' && $id !== '' && hash_equals($owner, $id);
    }

    private function tap(array $cb): void
    {
        $id = (string) ($cb['id'] ?? '');
        $data = (string) ($cb['data'] ?? '');
        if (! $this->isOwner((string) ($cb['from']['id'] ?? ''))) {
            $this->telegram->answer($id, 'This bot is private.');

            return;
        }
        $who = trim((string) ($cb['from']['first_name'] ?? '')) ?: 'Owner';
        $messageId = (int) ($cb['message']['message_id'] ?? 0);

        // Add a coin (size buttons).
        if (preg_match('/^da:(\d+):(\d+|skip)$/', $data, $m)) {
            $result = $this->actions->resolve((int) $m[1], $m[2], $who);
            $action = $result['action'];
            if ($action !== null && $action->message_id) {
                $this->telegram->edit((int) $action->message_id,
                    trim((string) ($action->payload['message_html'] ?? '')."\n\n".e($result['text'])));
            }
            $this->telegram->answer($id, $result['toast']);

            return;
        }

        // Deploy after a merge: ask → confirm → a job for the PC.
        if (preg_match('/^dd:(\d+):(ask|go|no)$/', $data, $m)) {
            $r = $this->jobs->deployTap((int) $m[1], $m[2], $who);
            $target = $messageId > 0 ? $messageId : (int) ($r['message_id'] ?? 0);
            if ($r['html'] !== null && $target > 0) {
                $this->telegram->edit($target, $r['html'], $r['keyboard']);
            }
            $this->telegram->answer($id, $r['toast']);

            return;
        }

        // Investigate: a signal problem, a failing service check, a server error.
        if (preg_match('/^dj:(\d+):([a-z_]{1,40})$/', $data, $m)) {
            $log = TradeLog::find((int) $m[1]);
            $this->once($id, "signal:{$m[1]}:{$m[2]}", $log === null ? null
                : fn () => $this->jobs->queueForSignal($log, $m[2]), 'That signal is gone.');

            return;
        }
        if (preg_match('/^dw:([a-z_]{1,20})$/', $data, $m)) {
            $check = collect($this->status->checks())->firstWhere('key', $m[1]);
            $this->once($id, "watch:{$m[1]}", $check === null ? null : fn () => $this->jobs->queue(
                "The \"{$check['name']}\" service check is failing: {$check['detail']}. Find out why it stopped and what "
                .'brings it back. Fix code only if the code is wrong; otherwise say exactly what to do on the server.',
                'button', ['kind' => 'service', 'check' => $check]), 'That check no longer exists.');

            return;
        }
        if (preg_match('/^dx:([a-f0-9]{16})$/', $data, $m)) {
            $exc = Cache::get("devbot:exc:{$m[1]}");
            $this->once($id, "exc:{$m[1]}", ! is_array($exc) ? null : fn () => $this->jobs->queue(
                "A server error was reported: {$exc['class']}: {$exc['message']} at {$exc['at']} ({$exc['where']}). "
                .'Find the cause. If the code is wrong, fix it with a test.', 'button', ['kind' => 'exception'] + $exc),
                'That error is too old to investigate from here; type it to me instead.');

            return;
        }
        // A status question handed to the PC agent after all.
        if (preg_match('/^jt:([A-Za-z0-9]{16})$/', $data, $m)) {
            $text = Cache::pull("devbot:ask:{$m[1]}");
            $this->once($id, "ask:{$m[1]}", ! is_string($text) ? null : fn () => $this->jobs->queue($text, 'message'),
                'That question is too old; type it again.');

            return;
        }

        $this->telegram->answer($id, 'Unknown button.');
    }

    /** Queue at most one job per button; answer the tap either way. */
    private function once(string $callbackId, string $key, ?\Closure $queue, string $gone): void
    {
        if ($queue === null) {
            $this->telegram->answer($callbackId, $gone);
        } elseif (! Cache::add("devbot:job-for:{$key}", 1, now()->addHours(6))) {
            $this->telegram->answer($callbackId, 'Already sent to your PC agent.');
        } else {
            $job = $queue();
            $this->telegram->answer($callbackId, "Queued as job #{$job->id}");
        }
    }

    private function message(array $msg): void
    {
        $chat = (string) $msg['chat']['id'];
        if (! $this->isOwner($chat) || ! $this->isOwner((string) ($msg['from']['id'] ?? $chat))) {
            $this->telegram->send('This bot is private.', null, $chat);

            return;
        }
        $text = trim((string) ($msg['text'] ?? ''));
        $command = strtolower((string) Str::before(ltrim($text, '/'), '@'));

        if ($text === '' || $command === 'start' || $command === 'help') {
            $this->telegram->send(implode("\n", [
                '✅ <b>Pixel Alpha error bot</b>',
                'I message you when something breaks: a refused trade, a server error, a stopped service, a serious alert.',
                '',
                '• /status — is everything running? (engine, crypto payment listener, invoicing, scheduled jobs, disk)',
                '• Type any question or fix, e.g. “why did the LTC buy at 14:15 skip?” — your PC agent looks into it.',
                '• Type “deploy” to put the current main live (with a confirm step).',
                '• Buttons under a problem: add a missing coin, investigate, deploy after a merge.',
            ]));

            return;
        }
        if ($command === 'status' || preg_match('/^status\b/i', $text)) {
            $this->telegram->send($this->status->report());

            return;
        }
        if (strtolower($text) === 'deploy' || $command === 'deploy') {
            $this->jobs->pullRequestMerged([
                'repo' => 'both', 'number' => 0, 'url' => 'https://github.com/xlythefx/sinegu_crypto',
                'title' => 'Manual deploy of the current main', 'files' => [],
            ]);

            return;
        }
        if (str_starts_with($text, '/')) {
            $this->telegram->send('I know /status and /help. Anything else: type it in plain words.');

            return;
        }
        // A short question about whether things run: answer now, from the
        // server; the PC agent stays one tap away for the "why".
        if (mb_strlen($text) <= 160 && preg_match(self::STATUS_WORDS, $text)) {
            $key = Str::random(16);
            Cache::put("devbot:ask:{$key}", $text, now()->addHours(6));
            $this->telegram->send($this->status->report(), [[
                ['text' => '🤖 Ask my PC agent instead', 'callback_data' => "jt:{$key}"],
            ]]);

            return;
        }
        $this->jobs->queue($text, 'message');
    }
}
