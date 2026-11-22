<?php

namespace App\Http\Controllers;

use App\Models\TradeLog;
use App\Services\DevBot\DevBotActions;
use App\Services\DevBot\DevBotJobs;
use App\Services\DevBot\DevBotTelegram;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * POST /api/devbot/telegram — Telegram's webhook for the developer error bot
 * (2026-11-22). Its button taps change REAL-MONEY settings (a new coin trades
 * on the next signal), so two gates, both server-side:
 *
 *  1. The call must carry DEV_BOT_WEBHOOK_SECRET in Telegram's
 *     X-Telegram-Bot-Api-Secret-Token header (set with `devbot:webhook`), or it
 *     is not Telegram — 403. No secret configured = the endpoint is shut.
 *  2. The tap must come from DEV_BOT_CHAT_ID's Telegram user — the bot talks
 *     in one DM, whose chat id is that user's id. Anyone else who finds the
 *     bot and presses something is told no, and nothing runs.
 *
 * Every accepted call answers 200, even for an update it ignores: anything
 * else makes Telegram re-deliver it for hours.
 */
class DevBotWebhookController extends Controller
{
    public function handle(Request $request, DevBotTelegram $telegram, DevBotActions $actions, DevBotJobs $jobs): JsonResponse
    {
        $secret = (string) config('services.dev_bot.webhook_secret', '');
        $given = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');
        if ($secret === '' || ! hash_equals($secret, $given)) {
            return response()->json(['ok' => false], 403);
        }

        $owner = $telegram->chatId();

        if (is_array($cb = $request->input('callback_query'))) {
            $from = (string) ($cb['from']['id'] ?? '');
            $id = (string) ($cb['id'] ?? '');
            if ($owner === '' || ! hash_equals($owner, $from)) {
                $telegram->answer($id, 'This bot is private.');

                return response()->json(['ok' => true]);
            }
            // "Investigate on my PC" under a problem message.
            if (preg_match('/^dj:(\d+):([a-z_]{1,40})$/', (string) ($cb['data'] ?? ''), $j)) {
                $log = TradeLog::find((int) $j[1]);
                if ($log === null) {
                    $telegram->answer($id, 'That signal is gone.');
                } elseif (! Cache::add("devbot:job-for:{$j[1]}:{$j[2]}", 1, now()->addHours(6))) {
                    $telegram->answer($id, 'Already sent to your PC agent.');
                } else {
                    $job = $jobs->queueForSignal($log, $j[2]);
                    $telegram->answer($id, "Queued as job #{$job->id}");
                }

                return response()->json(['ok' => true]);
            }
            if (! preg_match('/^da:(\d+):(\d+|skip)$/', (string) ($cb['data'] ?? ''), $m)) {
                $telegram->answer($id, 'Unknown button.');

                return response()->json(['ok' => true]);
            }

            $who = trim((string) ($cb['from']['first_name'] ?? '')) ?: 'Owner';
            $result = $actions->resolve((int) $m[1], $m[2], $who);
            $action = $result['action'];
            if ($action !== null && $action->message_id) {
                $original = (string) ($action->payload['message_html'] ?? '');
                $telegram->edit((int) $action->message_id, trim($original."\n\n".e($result['text'])));
            }
            $telegram->answer($id, $result['toast']);

            return response()->json(['ok' => true]);
        }

        // A typed message. Anyone but the owner learns nothing beyond the bot
        // being private. The owner's /start is answered; anything else he
        // types is a job for his PC agent ("why did LTC skip at 14:15?").
        if (is_array($msg = $request->input('message')) && isset($msg['chat']['id'])) {
            $chat = (string) $msg['chat']['id'];
            $isOwner = $owner !== '' && hash_equals($owner, $chat) && hash_equals($owner, (string) ($msg['from']['id'] ?? $chat));
            $text = trim((string) ($msg['text'] ?? ''));
            if (! $isOwner) {
                $telegram->send('This bot is private.', null, $chat);
            } elseif ($text === '' || str_starts_with($text, '/start')) {
                $telegram->send('✅ Connected. Pixel Alpha trading problems will arrive here. '
                    .'Type anything to hand it to your PC agent, for example “why did the LTC buy at 14:15 skip?”.');
            } elseif (str_starts_with($text, '/')) {
                $telegram->send('Commands are not used here. Type the question or the fix you want, in plain words.');
            } else {
                $jobs->queue($text, 'message');
            }
        }

        return response()->json(['ok' => true]);
    }
}
