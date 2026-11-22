<?php

namespace App\Services\DevBot;

use App\Models\DevBotAction;
use App\Models\DevBotJob;
use App\Models\DevBotSetting;
use App\Models\TradeLog;
use App\Services\Exchanges\ExchangeSchema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The developer agent's queue (2026-11-22). Jobs come from the error bot —
 * a tap on "Investigate" under a problem, or any text Christian types to it —
 * and are worked by `dev-agent/` on his PC, which polls `claim()` over HTTPS
 * (it dials out; no tunnel into the PC). Every step is told in the DM.
 *
 * The agent investigates with the read-only AI connector and may open a pull
 * request; it never merges, deploys or writes to this server. Merging is
 * Christian's, on GitHub.
 */
class DevBotJobs
{
    /** The agent counts as online if it polled this recently. */
    public const ONLINE_SECONDS = 120;

    private const SEEN_KEY = 'devbot:agent:last-poll';

    public function __construct(private DevBotTelegram $telegram) {}

    /** Queue a job and say so in the DM. */
    public function queue(string $prompt, string $source, array $context = []): DevBotJob
    {
        $job = DevBotJob::create([
            'status' => DevBotJob::QUEUED,
            'source' => $source,
            'prompt' => mb_substr(trim($prompt), 0, 4000),
            'context' => $context ?: null,
        ]);
        $messageId = $this->telegram->send(implode("\n", [
            "🤖 <b>Job #{$job->id} queued</b>",
            e(mb_substr($job->prompt, 0, 300)),
            '',
            $this->waitingLine(),
        ]));
        $job->forceFill(['message_id' => $messageId])->save();

        return $job;
    }

    /** A tap on "Investigate" under a signal problem. */
    public function queueForSignal(TradeLog $log, string $reasonKey): DevBotJob
    {
        $venue = ExchangeSchema::isSupported((string) $log->exchange)
            ? ExchangeSchema::for((string) $log->exchange)->brokerLabel : ucfirst((string) $log->exchange);
        $label = \App\Services\Admin\SignalHealth::reason($reasonKey)['label'];

        return $this->queue(
            "Investigate the \"{$label}\" problem on {$log->action} {$log->ticker} ({$venue}, {$log->feed} feed), "
            ."Signal Log row #{$log->id}. Find the root cause; fix it in code only if the code is wrong.",
            'button',
            ['trade_log_id' => $log->id, 'reason' => $reasonKey, 'exchange' => $log->exchange,
                'ticker' => $log->ticker, 'action' => $log->action, 'feed' => $log->feed],
        );
    }

    /** The oldest queued job, now WORKING — or null. Also records that the agent is alive. */
    public function claim(): ?DevBotJob
    {
        Cache::put(self::SEEN_KEY, now()->timestamp, now()->addDay());
        // This call comes through php-fpm, not cron: the one place that can
        // notice the scheduler itself has stopped. Once a minute at most.
        if (Cache::add('devbot:scheduler-check', 1, 60)) {
            app(DevBotWatch::class)->run(['scheduler']);
        }
        // Paused on Admin → Error Bot: the agent still checks in (so it shows
        // online) but gets no work; queued jobs wait for the resume.
        if (DevBotSetting::agentPaused()) {
            return null;
        }

        $job = DB::transaction(function () {
            $job = DevBotJob::where('status', DevBotJob::QUEUED)->orderBy('id')->lockForUpdate()->first();
            if ($job) {
                $job->forceFill(['status' => DevBotJob::WORKING, 'claimed_at' => now()])->save();
            }

            return $job;
        });
        if ($job) {
            $this->telegram->send("🛠 <b>Job #{$job->id}</b>: your PC agent is working on it.");
        }

        return $job;
    }

    /** A short progress line from the agent, relayed as-is. */
    public function progress(DevBotJob $job, string $text): void
    {
        $this->telegram->send("⏳ <b>Job #{$job->id}</b>: ".e(mb_substr($text, 0, 500)));
    }

    /**
     * The agent's verdict. `summary` is private (it may name accounts — this
     * DM is the only place it goes); `links` are pull requests or branches.
     *
     * @param  list<array{label: string, url: string}>  $links
     */
    public function finish(DevBotJob $job, bool $ok, string $summary, array $links = []): void
    {
        $job->forceFill([
            'status' => $ok ? DevBotJob::DONE : DevBotJob::FAILED,
            'finished_at' => now(),
            'summary' => mb_substr($summary, 0, 8000),
            'links' => $links ?: null,
        ])->save();

        $lines = [($ok ? '✅' : '❌')." <b>Job #{$job->id} ".($ok ? 'done' : 'failed').'</b>', '', e(mb_substr($summary, 0, 3000))];
        foreach ($links as $l) {
            $url = (string) ($l['url'] ?? '');
            if (preg_match('#^https://github\.com/#', $url)) {
                $lines[] = '🔗 <a href="'.e($url).'">'.e((string) ($l['label'] ?? 'Open')).'</a>';
            }
        }
        if ($links !== []) {
            $lines[] = "\nNothing is merged or deployed until you approve the pull request on GitHub.";
        }
        $this->telegram->send(implode("\n", $lines));
    }

    /** What a merged change sends live, by repo + changed paths. */
    public const TARGET_LABELS = [
        'website' => 'the website', 'api' => 'the API', 'engine' => 'the trading engine (restarts it)',
        'telegram' => 'the Telegram ops reporter',
    ];

    /**
     * Which deploy steps a merged pull request needs. Tests and docs alone
     * need none; dev-agent/ lives on the PC and is never deployed.
     *
     * @param  list<string>  $files
     * @return list<string>  keys of TARGET_LABELS
     */
    public static function deployTargets(string $repo, array $files): array
    {
        if ($repo === 'both') {
            return ['website', 'api']; // a typed "deploy": the current main of both
        }
        $t = [];
        foreach ($files as $f) {
            $f = ltrim(str_replace('\\', '/', (string) $f), '/');
            if (preg_match('#(^|/)(tests?)/|\.md$#i', $f)) {
                continue;
            }
            if ($repo === 'api') {
                $t['api'] = true;
            } elseif (str_starts_with($f, 'trading-flask/')) {
                $t['engine'] = true;
            } elseif (str_starts_with($f, 'pixel-telegram/')) {
                $t['telegram'] = true;
            } elseif (preg_match('#^(src/|public/|index\.html$|package(-lock)?\.json$|vite\.config\.|tsconfig)#', $f)) {
                $t['website'] = true;
            }
        }

        return array_values(array_intersect(array_keys(self::TARGET_LABELS), array_keys($t)));
    }

    /**
     * The PC agent saw one of its pull requests merged on GitHub: offer the
     * deploy. Two taps (Deploy → Yes) because it changes the live site.
     *
     * @param  array{repo: string, number: int, url: string, title: string, branch?: string, files: list<string>, job_id?: ?int}  $pr
     */
    public function pullRequestMerged(array $pr): DevBotAction
    {
        $targets = self::deployTargets($pr['repo'], $pr['files']);
        $action = DevBotAction::create([
            'kind' => DevBotAction::DEPLOY,
            'status' => $targets === [] ? DevBotAction::SKIPPED : DevBotAction::OPEN,
            'exchange' => 'github',
            'ticker' => mb_substr('PR #'.$pr['number'], 0, 20),
            'payload' => $pr + ['targets' => $targets],
            'result_text' => $targets === [] ? 'Nothing to deploy (tests or docs only).' : null,
        ]);
        $head = $pr['repo'] === 'both'
            ? '🚀 <b>Deploy the current main</b> (asked by typing “deploy”)'
            : '🔀 <b>Merged</b>: '.e($pr['title'])."\n".'<a href="'.e($pr['url']).'">'.e(($pr['repo'] === 'api' ? 'API' : 'website repo').' PR #'.$pr['number']).'</a>';
        $html = $targets === []
            ? $head."\n\nNothing to deploy: it only changes tests or docs."
            : $head."\n\nGoing live would update ".e(self::targetList($targets)).'. Deploy now?';
        $action->payload = $action->payload + ['message_html' => $html];
        $action->save();

        $messageId = $this->telegram->send($html, $targets === [] ? null : [[
            ['text' => '🚀 Deploy', 'callback_data' => "dd:{$action->id}:ask"],
            ['text' => 'Not now', 'callback_data' => "dd:{$action->id}:no"],
        ]]);
        $action->forceFill(['message_id' => $messageId])->save();

        return $action;
    }

    /**
     * A tap on the deploy buttons: ask → confirm, go → a deploy job for the
     * PC (it holds the deploy script and its credentials), no → closed.
     *
     * @return array{toast: string, html: ?string, keyboard: ?array, message_id?: ?int}
     */
    public function deployTap(int $actionId, string $step, string $who): array
    {
        $messageId = DevBotAction::whereKey($actionId)->value('message_id');

        return ['message_id' => $messageId] + DB::transaction(function () use ($actionId, $step, $who) {
            $a = DevBotAction::whereKey($actionId)->where('kind', DevBotAction::DEPLOY)->lockForUpdate()->first();
            if ($a === null || $a->status !== DevBotAction::OPEN) {
                return ['toast' => 'Already handled'.($a?->result_text ? ': '.$a->result_text : '.'), 'html' => null, 'keyboard' => null];
            }
            $base = (string) ($a->payload['message_html'] ?? '');
            $targets = (array) ($a->payload['targets'] ?? []);
            if ($step === 'ask') {
                return ['toast' => 'Confirm to deploy', 'keyboard' => [[
                    ['text' => '✅ Yes, deploy now', 'callback_data' => "dd:{$a->id}:go"],
                    ['text' => 'Cancel', 'callback_data' => "dd:{$a->id}:no"],
                ]], 'html' => $base."\n\n⚠️ <b>This updates the live site at pixel-alpha.com</b> (".e(self::targetList($targets))
                    .'). Your PC takes a backup first, then deploys and checks the result.'];
            }
            if ($step === 'no') {
                $a->forceFill(['status' => DevBotAction::SKIPPED, 'result_text' => "Not deployed ({$who}).",
                    'resolved_by' => $who, 'resolved_at' => now()])->save();

                return ['toast' => 'Not deployed', 'html' => $base."\n\n⏸ Not deployed. Merge something else later, or type “deploy” to me.", 'keyboard' => null];
            }
            $job = DevBotJob::create([
                'status' => DevBotJob::QUEUED, 'source' => 'deploy',
                'prompt' => 'Deploy '.self::targetList($targets).' after '.$a->ticker.' was merged.',
                'context' => ['kind' => 'deploy', 'targets' => $targets, 'repo' => $a->payload['repo'] ?? null,
                    'pr' => ['number' => $a->payload['number'] ?? null, 'url' => $a->payload['url'] ?? null, 'title' => $a->payload['title'] ?? null]],
            ]);
            $a->forceFill(['status' => DevBotAction::APPLIED, 'chosen' => ['job_id' => $job->id],
                'result_text' => "Deploy queued as job #{$job->id} by {$who}.", 'resolved_by' => $who, 'resolved_at' => now()])->save();

            return ['toast' => "Deploy queued (job #{$job->id})", 'keyboard' => null,
                'html' => $base."\n\n🚀 Deploy queued as job #{$job->id} by ".e($who).".\n".e($this->waitingLine())];
        });
    }

    public static function targetList(array $targets): string
    {
        $labels = array_map(fn ($t) => self::TARGET_LABELS[$t] ?? $t, $targets);

        return count($labels) > 1 ? implode(', ', array_slice($labels, 0, -1)).' and '.end($labels) : (string) ($labels[0] ?? 'nothing');
    }

    /** When a queued job will start, in one line. */
    public function waitingLine(): string
    {
        return match (true) {
            DevBotSetting::agentPaused() => '⏸ The PC agent is paused on Admin → Error Bot: it starts when you resume it.',
            $this->agentOnline() => 'Your PC agent is online and will start in a few seconds.',
            default => '⚠️ Your PC agent has not checked in for a while — it starts as soon as the PC is on and the agent is running.',
        };
    }

    /** The agent's last check-in, or null. */
    public function lastSeen(): ?\Illuminate\Support\Carbon
    {
        $seen = (int) Cache::get(self::SEEN_KEY, 0);

        return $seen > 0 ? \Illuminate\Support\Carbon::createFromTimestamp($seen) : null;
    }

    public function agentOnline(): bool
    {
        $seen = (int) Cache::get(self::SEEN_KEY, 0);

        return $seen > 0 && now()->timestamp - $seen <= self::ONLINE_SECONDS;
    }

    /**
     * Strings that must never reach the PUBLIC GitHub repos: every person's
     * name and email, and customers' own account names. The agent refuses to
     * push a diff or a pull request containing any of them. Short or generic
     * words ("master", "Demo") are left out — they are everywhere in code.
     *
     * @return list<string>
     */
    public function privateStrings(): array
    {
        $out = [];
        foreach (DB::table('user_credentials')->get(['name', 'email', 'type']) as $u) {
            foreach ([(string) $u->name, (string) $u->email] as $s) {
                $out[] = trim($s);
            }
        }
        $customers = DB::table('user_credentials')->where('type', 'user')->pluck('uni_id')->all();
        foreach (ExchangeSchema::supported() as $exchange) {
            $table = ExchangeSchema::for($exchange)->accountsTable;
            foreach (DB::table($table)->whereIn('uni_id', $customers)->pluck('name') as $n) {
                $out[] = trim((string) $n);
            }
        }
        $generic = ['master', 'demo', 'test', 'admin', 'binance', 'mexc', 'bybit', 'user', 'account', 'main', 'tester', 'developer'];

        return array_values(array_unique(array_filter($out, fn (string $s) => mb_strlen($s) >= 5
            && ! in_array(mb_strtolower($s), $generic, true))));
    }
}
