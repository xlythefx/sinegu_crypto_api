<?php

namespace App\Console\Commands;

use App\Services\DevBot\DevBotWatch;
use Illuminate\Console\Command;

/** The error bot's watchdog: DMs when a service check changes (App\Services\DevBot\DevBotWatch). */
class DevBotWatchCommand extends Command
{
    protected $signature = 'devbot:watch';

    protected $description = 'Tell the developer error bot when a service check goes down or recovers';

    public function handle(DevBotWatch $watch): int
    {
        $this->line('changes told: '.$watch->run());

        return self::SUCCESS;
    }
}
