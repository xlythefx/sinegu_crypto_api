<?php

namespace App\Console\Commands;

use App\Services\DevBot\DevBotTelegram;
use Illuminate\Console\Command;

/**
 * Point the developer error bot's button taps at this server (2026-11-22).
 *
 *   php artisan devbot:webhook                 register https://pixel-alpha.com/api/devbot/telegram
 *   php artisan devbot:webhook --url=https://…  register another address
 *   php artisan devbot:webhook --info          what Telegram has now (no change)
 *   php artisan devbot:webhook --delete        stop the webhook
 *
 * Telegram sends DEV_BOT_WEBHOOK_SECRET back on every call; the controller
 * refuses anything without it. Nothing secret is printed.
 */
class DevBotWebhook extends Command
{
    protected $signature = 'devbot:webhook {--url=} {--info} {--delete}';

    protected $description = 'Register (or inspect / remove) the developer error bot\'s Telegram webhook';

    public function handle(DevBotTelegram $telegram): int
    {
        if ($this->option('info')) {
            $info = $telegram->webhookInfo();
            if ($info === null) {
                $this->error('Telegram did not answer (is DEV_BOT_TOKEN set?).');

                return self::FAILURE;
            }
            $this->line('url: '.($info['url'] ?? '(none)'));
            $this->line('pending updates: '.($info['pending_update_count'] ?? 0));
            if (! empty($info['last_error_message'])) {
                $this->line('last error: '.$info['last_error_message']);
            }

            return self::SUCCESS;
        }

        if ($this->option('delete')) {
            $answer = $telegram->deleteWebhook();
            $this->line($answer['ok'] ? 'Webhook removed.' : 'Telegram refused: '.($answer['description'] ?? '?'));

            return $answer['ok'] ? self::SUCCESS : self::FAILURE;
        }

        $secret = (string) config('services.dev_bot.webhook_secret', '');
        if (! preg_match('/^[A-Za-z0-9_-]{16,256}$/', $secret)) {
            $this->error('DEV_BOT_WEBHOOK_SECRET must be 16–256 characters of A–Z, a–z, 0–9, _ or -.');

            return self::FAILURE;
        }
        $url = (string) ($this->option('url') ?: rtrim((string) config('services.dev_bot.site_url', 'https://pixel-alpha.com'), '/').'/api/devbot/telegram');
        $answer = $telegram->setWebhook($url, $secret);
        $this->line($answer['ok'] ? "Webhook set: {$url}" : 'Telegram refused: '.($answer['description'] ?? '?'));
        $menu = $telegram->setCommands();
        $this->line($menu['ok'] ? 'Command menu set: /status, /help' : 'Command menu refused: '.($menu['description'] ?? '?'));

        return $answer['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
