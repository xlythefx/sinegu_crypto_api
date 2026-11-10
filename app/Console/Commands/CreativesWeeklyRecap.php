<?php

namespace App\Console\Commands;

use App\Services\Creatives\CreativeAi;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Admin → Creatives, Saturdays 00:15 Asia/Manila (just after the Friday
 * 23:55 Telegram weekly recap): the AI writer drafts a TikTok recap of the
 * week that just ended and saves it as an `idea` plan for the coming Monday.
 * A DRAFT — nothing is posted and no video is generated.
 *
 * Skips, and says why, when: the recap is switched off, Claude is not
 * connected, nothing was published that week, the week's plan already
 * exists (one per week, whatever re-runs it), or the month's cap has no
 * room for an AI call. Never throws into the scheduler.
 */
class CreativesWeeklyRecap extends Command
{
    protected $signature = 'creatives:weekly-recap';

    protected $description = 'Draft the weekly recap post (Admin → Creatives AI writer), saved as an idea plan';

    public function handle(CreativeAi $ai): int
    {
        try {
            $result = $ai->weeklyRecap();
        } catch (\Throwable $e) {
            Log::warning('Creatives weekly recap failed.', ['class' => $e::class, 'message' => mb_substr($e->getMessage(), 0, 300)]);
            $this->error('The weekly recap failed: '.$e::class);

            return self::FAILURE;
        }

        $line = "[{$result['outcome']}] {$result['message']}";
        if ($result['outcome'] === 'failed') {
            Log::warning('Creatives weekly recap was not drafted.', ['message' => $result['message']]);
            $this->error($line);

            return self::FAILURE;
        }
        if ($result['outcome'] === 'over_budget') {
            Log::warning('Creatives weekly recap skipped: the monthly cap has no room for an AI call.');
        }

        $this->info($line);

        return self::SUCCESS;
    }
}
