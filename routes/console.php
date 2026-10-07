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
Schedule::command('pnl:daily-returns')->everyFifteenMinutes()->withoutOverlapping(15);
