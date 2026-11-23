<?php

namespace App\Console\Commands;

use App\Services\Admin\MasterComparison;
use App\Services\EngineCache;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * The weekly Customers vs master line for the admin Telegram group
 * (2026-11-23): last week's (Mon–Sun, UTC days) master return, how many
 * customers kept up, and who fell more than THRESHOLD percentage points
 * behind — each with the biggest reason (missed trades, the same trades
 * doing worse, their own extra trades, or joining mid-week). The figures are
 * MasterComparison's, the same as the page the message links to.
 *
 *   php artisan insights:vs-master-weekly            last full week, sent
 *   php artisan insights:vs-master-weekly --dry-run  printed, not sent
 *   php artisan insights:vs-master-weekly --from=2026-11-16 --to=2026-11-22
 */
class InsightsVsMasterWeekly extends Command
{
    /** "Behind" in the message means more than this many percentage points. */
    public const THRESHOLD = 1.0;

    private const MAX_NAMES = 10;

    protected $signature = 'insights:vs-master-weekly {--from=} {--to=} {--dry-run}';

    protected $description = 'Post last week’s Customers vs master summary to the admin Telegram group';

    public function handle(MasterComparison $comparison, EngineCache $engine): int
    {
        $lastMonday = Carbon::now('UTC')->startOfWeek(Carbon::MONDAY)->subWeek();
        $from = $this->option('from') ?: $lastMonday->toDateString();
        $to = $this->option('to') ?: $lastMonday->copy()->addDays(6)->toDateString();

        $data = $comparison->compare(null, $from, $to);
        $message = self::message($data, rtrim((string) config('services.dev_bot.site_url', 'https://pixel-alpha.com'), '/'));
        if ($message === null) {
            $this->info('Nothing to report (no master or no customers in the window).');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->line($message['title']);
            foreach ($message['lines'] as $line) {
                $this->line($line);
            }
            $this->line($message['link']);

            return self::SUCCESS;
        }

        $sent = $engine->teamReport($message['title'], $message['lines'], $message['link']);
        $this->info($sent ? 'Sent.' : 'The engine did not answer; nothing was sent.');

        return $sent ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $data  MasterComparison::compare's answer
     * @return array{title: string, lines: list<string>, link: string}|null
     */
    public static function message(array $data, string $site): ?array
    {
        $master = $data['master'];
        $rows = collect($data['customers'])->filter(fn ($r) => $r['gap'] !== null)->values();
        if ($master === null || $master['pct'] === null || $rows->isEmpty()) {
            return null;
        }

        $pct = fn (float $v) => ($v < 0 ? '−' : '+').number_format(abs($v), 2).'%';
        $pp = fn (float $v) => ($v < 0 ? '−' : '+').number_format(abs($v), 2).' pp';
        $behind = $rows->filter(fn ($r) => $r['gap'] < -self::THRESHOLD)->sortBy('gap')->values();
        $ahead = $rows->filter(fn ($r) => $r['gap'] >= 0)->count();

        $lines = [
            "Master: {$pct($master['pct'])} · {$master['trades']} trades",
            "Ahead of or level with the master: {$ahead} of {$rows->count()}",
        ];
        if ($behind->isEmpty()) {
            $lines[] = 'Nobody was more than '.self::THRESHOLD.' pp behind the master.';
        } else {
            $lines[] = 'More than '.self::THRESHOLD.' pp behind:';
            foreach ($behind->take(self::MAX_NAMES) as $r) {
                $lines[] = "• {$r['name']} {$pp($r['gap'])} — ".self::reason($r, $pp);
            }
            if ($behind->count() > self::MAX_NAMES) {
                $lines[] = '…and '.($behind->count() - self::MAX_NAMES).' more on the page.';
            }
        }

        $range = Carbon::parse($data['from'])->format('j M').' – '.Carbon::parse($data['to'])->format('j M Y');

        return [
            'title' => "Customers vs master — {$range}",
            'lines' => $lines,
            'link' => $site.'/admin/vs-master?'.http_build_query([
                'date' => 'custom', 'from' => $data['from'], 'to' => $data['to'], 'show' => 'behind',
            ]),
        ];
    }

    /** The biggest negative part of the gap, in words. */
    private static function reason(array $row, callable $pp): string
    {
        $parts = $row['gap_parts'] ?? null;
        if (! is_array($parts)) {
            return 'see the page';
        }
        asort($parts);
        $key = array_key_first($parts);
        $value = (float) $parts[$key];
        if ($value >= 0) {
            return 'see the page';
        }

        return match ($key) {
            'missed' => "missed {$row['missed']} of {$row['master_closes']} master trades ({$pp($value)})",
            'same' => "the same trades did worse ({$pp($value)})",
            'extra' => "their own extra closes ({$pp($value)})",
            default => "joined after part of the week ({$pp($value)})",
        };
    }
}
