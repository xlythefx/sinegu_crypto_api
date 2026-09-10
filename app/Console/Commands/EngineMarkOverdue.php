<?php

namespace App\Console\Commands;

use App\Models\BinanceAccount;
use App\Models\Invoice;
use App\Services\EngineCache;
use Illuminate\Console\Command;

/**
 * The billing gate's "off switch" — the other half of InvoiceService::settle(),
 * which flips enabled back to 1 on payment.
 *
 * Marks past-due pending invoices as overdue and disables the owning exchange
 * account so it drops out of GET /api/engine/{exchange}/accounts and stops
 * trading (the engine caches accounts ~90s, so the cutoff lands within
 * minutes). Also disables accounts owned by suspended users, belt and braces
 * on top of the accounts endpoint's own suspended filter.
 *
 * Scheduled daily in routes/console.php; safe to run by hand any time —
 * selecting only status='pending' makes the disable exactly-once.
 */
class EngineMarkOverdue extends Command
{
    protected $signature = 'engine:mark-overdue';

    protected $description = 'Mark past-due invoices overdue and disable their exchange accounts';

    public function handle(EngineCache $engineCache): int
    {
        // 1) Past-due pending invoices → overdue + account disabled.
        $due = Invoice::where('status', 'pending')
            ->where('total_fee', '>', 0)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', today())
            ->get();

        $disabled = 0;
        foreach ($due as $invoice) {
            $invoice->status = 'overdue';
            $invoice->save();

            if ($invoice->account_id && $invoice->exchange === 'binance') {
                $disabled += BinanceAccount::whereKey($invoice->account_id)
                    ->update(['enabled' => 0]);
            }
            // bybit/mexc: disable path lands with their accounts tables.
        }

        // 2) Suspended users must not keep trading even with paid invoices.
        $suspendedDisabled = BinanceAccount::where('enabled', 1)
            ->whereIn('uni_id', function ($query) {
                $query->select('uni_id')
                    ->from('user_credentials')
                    ->where('status', 'suspended');
            })
            ->update(['enabled' => 0]);

        // Only when something actually changed — the daily run is usually a
        // no-op, and a no-op should not make the engine re-read anything.
        if ($disabled > 0 || $suspendedDisabled > 0) {
            $engineCache->refreshAccounts();
        }

        $this->info(sprintf(
            'Invoices marked overdue: %d. Accounts disabled: %d (overdue) + %d (suspended owners).',
            $due->count(),
            $disabled,
            $suspendedDisabled,
        ));

        return self::SUCCESS;
    }
}
