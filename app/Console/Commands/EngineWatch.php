<?php

namespace App\Console\Commands;

use App\Models\RiskAlert;
use App\Services\Alerts\RiskAlerts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Is the bot engine answering? (2026-11-18, high-risk alerts.) Asks its
 * `/health` directly — never the 60 s cached copy EngineCache keeps, which
 * would hide an outage — every 2 minutes. Two misses in a row (~4 minutes)
 * raise a high-risk alert by EMAIL only: the engine owns the Telegram bot, so
 * it cannot post its own outage (pixel-telegram's watch reports a dead unit
 * there). The first answer after that closes the alert by itself.
 *
 * Runs only where an engine is expected: `services.engine.health_watch`
 * (ENGINE_HEALTH_WATCH, default on in production only), so a developer's
 * laptop without the engine never raises one.
 */
class EngineWatch extends Command
{
    protected $signature = 'engine:watch {--force : Run even where the watch is off}';

    protected $description = 'Raise a high-risk alert when the bot engine stops answering';

    public const MISSES_KEY = 'engine-watch:misses';

    public const MISSES_TO_ALERT = 2;

    public function handle(RiskAlerts $alerts): int
    {
        if (! $this->option('force') && ! config('services.engine.health_watch')) {
            $this->line('Engine watch is off on this box (ENGINE_HEALTH_WATCH).');

            return self::SUCCESS;
        }

        $base = rtrim((string) config('services.engine.targets.local', 'http://127.0.0.1:5010'), '/');
        $error = null;
        try {
            $response = Http::timeout(8)->get("{$base}/health");
            if (! $response->successful() || ! is_array($response->json())) {
                $error = 'answered HTTP '.$response->status();
            }
        } catch (Throwable $e) {
            $error = 'did not answer ('.mb_substr($e->getMessage(), 0, 160).')';
        }

        if ($error === null) {
            Cache::forget(self::MISSES_KEY);
            if ($alerts->recover(RiskAlert::ENGINE_DOWN, 'engine', 'The engine is answering again.') > 0) {
                $this->info('Engine answering again: alert closed.');
            } else {
                $this->line('Engine answering.');
            }

            return self::SUCCESS;
        }

        $misses = (int) Cache::get(self::MISSES_KEY, 0) + 1;
        Cache::put(self::MISSES_KEY, $misses, now()->addMinutes(30));
        $this->warn("Engine {$error} (miss {$misses}).");
        if ($misses < self::MISSES_TO_ALERT) {
            return self::SUCCESS;
        }

        $alerts->raise(
            RiskAlert::ENGINE_DOWN,
            'engine',
            'The trading bot engine is not answering',
            [
                "The bot engine {$error} on {$misses} checks in a row (every 2 minutes).",
                'While it is down NO signal is traded and no open position is closed by the bot — on any account.',
                'Restart it from Admin → Bot Engine, or on the server: systemctl restart sinegualerts-engine. This alert closes itself when it answers again.',
            ],
        );

        return self::SUCCESS;
    }
}
