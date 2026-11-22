<?php

namespace App\Services\DevBot;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The developer error bot's Telegram calls (2026-11-22, @errorpixel_bot).
 *
 * Best-effort like every notice here: a Telegram outage is logged and never
 * fails the request that found the problem. The token is in every URL, so it
 * is scrubbed from anything logged — a Guzzle error quotes the request URL.
 */
class DevBotTelegram
{
    /** Telegram's limit is 4096 characters; leave room for the result line. */
    public const MAX_TEXT = 3800;

    public function configured(): bool
    {
        return $this->token() !== '' && $this->chatId() !== '';
    }

    public function chatId(): string
    {
        return trim((string) config('services.dev_bot.chat_id', ''));
    }

    /**
     * Send HTML text to the configured chat. Returns the message id, or null.
     *
     * @param  list<list<array{text: string, callback_data?: string, url?: string}>>|null  $keyboard
     */
    public function send(string $html, ?array $keyboard = null, ?string $chatId = null): ?int
    {
        $chat = $chatId ?? $this->chatId();
        if ($this->token() === '' || $chat === '') {
            return null;
        }
        $params = [
            'chat_id' => $chat,
            'text' => self::clip($html),
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];
        if ($keyboard !== null) {
            $params['reply_markup'] = ['inline_keyboard' => $keyboard];
        }
        $result = $this->call('sendMessage', $params);

        return isset($result['message_id']) ? (int) $result['message_id'] : null;
    }

    /** Replace a message's text; the buttons go unless new ones are given. */
    public function edit(int $messageId, string $html, ?array $keyboard = null): bool
    {
        return $this->call('editMessageText', [
            'chat_id' => $this->chatId(),
            'message_id' => $messageId,
            'text' => self::clip($html),
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
            'reply_markup' => ['inline_keyboard' => $keyboard ?? []],
        ]) !== null;
    }

    /** The small toast Telegram shows on the tapped button. */
    public function answer(string $callbackId, string $text): void
    {
        $this->call('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => mb_substr($text, 0, 190)]);
    }

    /** @return array{ok: bool, description: ?string} */
    public function setWebhook(string $url, string $secret): array
    {
        return $this->raw('setWebhook', [
            'url' => $url,
            'secret_token' => $secret,
            'allowed_updates' => ['message', 'callback_query'],
            'drop_pending_updates' => true,
        ]);
    }

    /** The "/" menu in the chat. @return array{ok: bool, description: ?string} */
    public function setCommands(): array
    {
        return $this->raw('setMyCommands', ['commands' => [
            ['command' => 'status', 'description' => 'Are all services up?'],
            ['command' => 'help', 'description' => 'What this bot does'],
        ]]);
    }

    /** @return array{ok: bool, description: ?string} */
    public function deleteWebhook(): array
    {
        return $this->raw('deleteWebhook', []);
    }

    /** @return array<string, mixed>|null */
    public function webhookInfo(): ?array
    {
        return $this->call('getWebhookInfo', []);
    }

    /** Telegram's `result`, or null on any failure. */
    private function call(string $method, array $params): ?array
    {
        $answer = $this->raw($method, $params);

        return $answer['ok'] ? ($answer['result'] ?? []) : null;
    }

    /** @return array{ok: bool, description: ?string, result?: mixed} */
    private function raw(string $method, array $params): array
    {
        if ($this->token() === '') {
            return ['ok' => false, 'description' => 'The developer bot is not configured on this server.'];
        }
        try {
            $request = Http::timeout(10)->connectTimeout(5)->acceptJson();
            $ca = (string) config('services.dev_bot.ca_bundle', '');
            if ($ca !== '') {
                $request = $request->withOptions(['verify' => $ca]);
            }
            $response = $request->post($this->base()."/bot{$this->token()}/{$method}", $params);
        } catch (Throwable $e) {
            Log::warning('DevBot: Telegram call failed.', ['method' => $method, 'error' => $this->scrub($e->getMessage())]);

            return ['ok' => false, 'description' => 'Telegram did not answer.'];
        }
        $ok = $response->successful() && $response->json('ok') === true;
        if (! $ok) {
            Log::warning('DevBot: Telegram refused a call.', [
                'method' => $method, 'status' => $response->status(),
                'description' => $this->scrub((string) $response->json('description')),
            ]);
        }

        return [
            'ok' => $ok,
            'description' => $response->json('description'),
            'result' => is_array($response->json('result')) ? $response->json('result') : ($ok ? [] : null),
        ];
    }

    private function token(): string
    {
        return trim((string) config('services.dev_bot.token', ''));
    }

    private function base(): string
    {
        return rtrim((string) config('services.dev_bot.base_url', 'https://api.telegram.org'), '/');
    }

    private function scrub(string $text): string
    {
        $token = $this->token();

        return $token === '' ? $text : str_replace($token, '[token]', $text);
    }

    private static function clip(string $html): string
    {
        return mb_strlen($html) > self::MAX_TEXT ? mb_substr($html, 0, self::MAX_TEXT).'…' : $html;
    }
}
