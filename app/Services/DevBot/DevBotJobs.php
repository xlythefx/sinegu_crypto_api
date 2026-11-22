<?php

namespace App\Services\DevBot;

use App\Models\DevBotJob;
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
        $online = $this->agentOnline();
        $messageId = $this->telegram->send(implode("\n", [
            "🤖 <b>Job #{$job->id} queued</b>",
            e(mb_substr($job->prompt, 0, 300)),
            '',
            $online
                ? 'Your PC agent is online and will start in a few seconds.'
                : '⚠️ Your PC agent has not checked in for a while — it starts as soon as the PC is on and the agent is running.',
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
