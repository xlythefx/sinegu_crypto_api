<?php

namespace Tests\Feature;

use Cron\CronExpression;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * The scheduler boots one PHP process per due task, one after another, at
 * second :00 of the minute, on a 2-vCPU box the trading engine shares. Until
 * 2026-11-12 three tasks ran every minute (plus the 5- and 15-minute ones on
 * :00), and the ops reporter read that pile-up as "CPU 95%". This pins the
 * spread described above the schedule in routes/console.php.
 */
class ScheduleSpreadTest extends TestCase
{
    public function test_frequent_tasks_take_turns_instead_of_piling_up(): void
    {
        $events = collect($this->app->make(Schedule::class)->events())
            ->map(fn ($e) => ['command' => (string) $e->command, 'cron' => new CronExpression($e->expression)]);

        $everyMinute = $events->filter(fn ($e) => $e['cron']->getExpression() === '* * * * *')
            ->map(fn ($e) => $e['command'])->values();
        $this->assertCount(1, $everyMinute, 'Only the TRON payment watcher may run every minute: '.$everyMinute->implode(', '));
        $this->assertStringContainsString('payments:watch-tron', $everyMinute->first());

        // Over one hour, no minute runs more than two tasks beside the payment watcher.
        $hour = new DateTimeImmutable('2026-11-12 10:00:00', new DateTimeZone('UTC'));
        for ($m = 0; $m < 60; $m++) {
            $at = $hour->modify("+{$m} minutes");
            $due = $events->filter(fn ($e) => $e['cron']->getExpression() !== '* * * * *' && $e['cron']->isDue($at))
                ->map(fn ($e) => $e['command']);
            $this->assertLessThanOrEqual(2, $due->count(), sprintf('Minute :%02d runs %s', $m, $due->implode(', ')));
        }
    }

    public function test_creatives_poll_and_pending_sizes_alternate_minutes(): void
    {
        $expr = fn (string $cmd) => collect($this->app->make(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, $cmd))?->expression;

        $this->assertSame('*/2 * * * *', $expr('assets:apply-pending'));
        $this->assertSame('1-59/2 * * * *', $expr('creatives:poll'));
        $this->assertSame('3-59/5 * * * *', $expr('positions:classify-closes'));
        $this->assertSame('7-59/15 * * * *', $expr('pnl:daily-returns'));
    }
}
