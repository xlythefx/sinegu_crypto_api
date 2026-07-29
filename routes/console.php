<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Billing gate: overdue invoices disable their exchange account daily.
// (Requires the scheduler to run: `php artisan schedule:work` locally /
// a cron entry for `schedule:run` on the server.)
Schedule::command('engine:mark-overdue')->dailyAt('00:10');
