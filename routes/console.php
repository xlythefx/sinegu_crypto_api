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

// Frees the "one Binance account per user" slot when a key the exchange keeps
// refusing has run past its grace period, so the user can connect a new one.
Schedule::command('exchange:disconnect-blocked-keys')->dailyAt('00:20');

// Safety net for the fee ledger: the engine's fee ingest rebases each closed
// trade from its estimated to its actual fee the moment the receipts land, so
// this normally finds nothing. It catches a rebase that threw during ingest
// and receipts that arrived after their close was attributed. 00:30 is after
// the sweeps above and half an hour past the 00:00 UTC funding charge, so the
// poller has ingested it by the time this runs.
Schedule::command('fees:reconcile')->dailyAt('00:30')->withoutOverlapping(30);

// Direct USDT-TRC20 payments. TRON pushes nothing, so this poll is the only way
// such a payment is ever noticed — which makes THE CRON ENTRY LOAD-BEARING FOR
// MONEY, not just for the daily sweeps above: a dead scheduler now silently
// stops invoices settling while customers' funds sit in the wallet. Admin →
// Crypto Transfers shows last_scan_at per network and flags it when this stops
// running, and the trader's pay sheet says so rather than spinning forever.
//
// Every minute because TRON solidification is roughly a minute, so a tighter
// interval buys nothing. withoutOverlapping takes an expiry so a killed process
// cannot hold the lock indefinitely.
Schedule::command('payments:watch-tron')->everyMinute()->withoutOverlapping(5);

// Failed-trade summary for the hour that just ended (TradeAlerts): nothing is
// sent for a clean hour. A stuck close is emailed at once by the engine's call.
Schedule::command('trade-alerts:digest')->hourlyAt(2)->withoutOverlapping(10);

// High-risk alert when the bot engine stops answering (EngineWatch: two misses
// in a row, emailed; closes itself when it answers). Odd minutes, beside the
// even-minute jobs.
Schedule::command('engine:watch')->cron('1-59/2 * * * *')->withoutOverlapping(5);

// The scheduler's own heartbeat (2026-11-22): the error bot's /status reads
// it, and the PC agent's polls (php-fpm, not cron) notice when it goes quiet —
// the one failure a scheduled check could never report about itself.
Schedule::call(fn () => \Illuminate\Support\Facades\Cache::put(
    \App\Services\DevBot\SystemStatus::HEARTBEAT_KEY, now()->timestamp, now()->addDay(),
))->everyMinute()->name('scheduler-heartbeat');

// The error bot's watchdog: a DM when a service check goes down or recovers
// (crypto listener, background jobs, disk, engine pollers, invoicing switch).
// :04, :09, … — beside the :03 / :08 classify-closes run.
Schedule::command('devbot:watch')->cron('4-59/5 * * * *')->withoutOverlapping(5);

// Discord server roles (Member / Trader) follow the account: every approve,
// suspend, connect and disconnect syncs the user on the spot, so this nightly
// pass only catches the write that happened while Discord was down and the
// role someone removed by hand in the server.
Schedule::command('discord:sync-roles')->dailyAt('00:40')->withoutOverlapping(30);

// Tokens expire after config('sanctum.expiration') minutes (30 days since
// 2026-10-07; before that, never). An expired token is refused on sight, so
// this prune is housekeeping — it keeps personal_access_tokens from holding
// every session ever opened — not the enforcement.
Schedule::command('sanctum:prune-expired --hours=24')->dailyAt('00:50');

// Saved daily P&L percentages (the Date Range card adds them up). The
// analytics endpoint saves its own scope on every read, so this only keeps the
// rows of users who have not opened the page since their last close current.
//
// SPREAD (2026-11-12): every task that runs often has its own minute, so the
// scheduler boots as few PHP processes at once as it can on this 2-vCPU box
// (the trading engine shares it, and the ops reporter read the old pile-up at
// :00 as "CPU 95%"). Only payments:watch-tron runs every minute; the rest
// alternate even / odd minutes, and the 5- and 15-minute ones sit off :00.
// Keep a new frequent task off the minutes already taken.
Schedule::command('pnl:daily-returns')->cron('7-59/15 * * * *')->withoutOverlapping(15);

// A size change saved while the main account held the coin waits for that
// position to close (App\Services\Assets\PendingSizing). The engine's close and
// position syncs put it live the moment they land; this catches the rest, so
// even minutes are plenty (SPREAD above).
Schedule::command('assets:apply-pending')->everyTwoMinutes()->withoutOverlapping(5);

// "Closed by the bot" vs "closed outside the bot" on every closed trade
// (App\Services\Positions\CloseSource). An outside close is shown on the admin
// screens and left out of streak sizing.
Schedule::command('positions:classify-closes')->cron('3-59/5 * * * *')->withoutOverlapping(10);

// Admin → Creatives: Higgsfield pushes nothing, so this poll is how a running
// image / video generation becomes "done" (and its reservation against the
// monthly cap is settled), and how a result whose 7 days are over is marked
// expired. Sends no request at all while nothing is running. ODD minutes
// (SPREAD above): an open chat or editor advances its own jobs every few
// seconds, so this only matters while nobody is watching — and for that,
// one process keeps checking every 30 s for up to 100 s while something is
// still running (2026-11-17: a 2-minute gap between a scene's still and its
// animation made unwatched videos slow). In the background, so its sleeps
// never hold up the other commands due in the same minute.
Schedule::command('creatives:poll --for=100 --every=30')->cron('1-59/2 * * * *')->withoutOverlapping(5)->runInBackground();

// Admin → Creatives' AI writer drafts the weekly recap post (an `idea` plan for
// the coming Monday) from the published track record — Saturdays 00:15 MANILA,
// just after the Friday 23:55 Telegram weekly recap of the same 7 days. One per
// week (a re-run skips), within the monthly cap, and only while a full admin
// leaves the "weekly recap" switch on.
Schedule::command('creatives:weekly-recap')->saturdays()->at('00:15')->timezone('Asia/Manila')->withoutOverlapping(30);

// Admin → Creatives' chat agent keeps only the current conversation: a chat
// idle for 7 days is deleted with its messages and jobs. Nothing is lost —
// decisions live in Memory, the Brief and Plans, and generations (the month's
// spend) are never deleted.
Schedule::command('creatives:agent-prune')->dailyAt('01:20');

// Customers vs master, weekly (2026-11-23): Mondays 09:13 Manila, the admin
// Telegram group gets last week's (Mon–Sun UTC) master return and who fell
// more than 1 pp behind it, with the reason (missed trades or the same trades
// doing worse). Odd minute, off the :00 / :15 / :30 / :45 signal slots.
Schedule::command('insights:vs-master-weekly')->mondays()->at('09:13')->timezone('Asia/Manila')->withoutOverlapping(30);

// The visitor counter keeps full IP addresses for 90 days (owner, 2026-10-28;
// the Privacy Policy says so). After that the IP and User-Agent are removed
// from the row and the counts stay.
Schedule::command('visits:purge-ips')->dailyAt('01:10');
