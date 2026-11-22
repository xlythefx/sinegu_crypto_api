<?php

namespace App\Services\DevBot;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The error bot's watchdog (2026-11-22): the /status checks, compared with
 * the last run, and a DM only when one CHANGES — down once, recovered once,
 * never every few minutes while it stays down.
 *
 * Runs from the scheduler (`devbot:watch`, every 5 min). The one check the
 * scheduler cannot make about itself — that it has stopped — is made from the
 * PC agent's polls instead (DevBotJobs::claim → `scheduler` only), which come
 * through php-fpm, not cron.
 *
 * Left out on purpose: high-risk alerts (each already DMs when raised), the PC
 * agent and card payments (no liveness signal), and an engine that does not
 * answer at all (`engine:watch` raises `engine_down`, which already DMs).
 */
class DevBotWatch
{
    private const STATE = 'devbot:watch:state:';

    private const SKIP = ['alerts', 'agent', 'cards'];

    public function __construct(private SystemStatus $status, private DevBotTelegram $telegram) {}

    /** @param  list<string>|null  $only  check keys to look at (null = all watched) */
    public function run(?array $only = null): int
    {
        if (! $this->telegram->configured()) {
            return 0;
        }
        $told = 0;
        foreach ($this->status->checks() as $c) {
            if (in_array($c['key'], self::SKIP, true) || ($only !== null && ! in_array($c['key'], $only, true))) {
                continue;
            }
            if ($c['ok'] === null || ($c['key'] === 'engine' && $c['detail'] === 'not answering')) {
                continue;
            }
            $was = Cache::get(self::STATE.$c['key']);
            Cache::forever(self::STATE.$c['key'], $c['ok'] ? 'ok' : 'bad');
            // First sight: report a problem that is already there, stay quiet about health.
            if ($was === null ? $c['ok'] : ($was === ($c['ok'] ? 'ok' : 'bad'))) {
                continue;
            }
            try {
                $this->telegram->send($c['ok']
                    ? '🟢 <b>'.e($c['name']).' recovered</b>'."\n".e($c['detail'])
                    : '🔴 <b>'.e($c['name']).' has a problem</b>'."\n".e($c['detail'])."\n\nSend /status for everything.",
                    $c['ok'] ? null : [[['text' => '🤖 Investigate on my PC', 'callback_data' => 'dw:'.$c['key']]]]);
                $told++;
            } catch (Throwable) {
                // DevBotTelegram never throws; belt and braces for a watchdog.
            }
        }

        return $told;
    }
}
