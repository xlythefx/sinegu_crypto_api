<?php

namespace App\Console\Commands;

use App\Models\BinanceAccount;
use App\Services\Billing\OverdueEnforcer;
use App\Services\EngineCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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

    public function handle(EngineCache $engineCache, OverdueEnforcer $enforcer): int
    {
        // 1) Past-due pending invoices → overdue + account disabled. Due
        // BEFORE today: the engine's schedule pauses the due day itself at the
        // billing hour; this nightly run is the safety net behind it.
        $result = $enforcer->run(today()->subDay());
        $due = $result['overdue'];
        $disabled = $result['disabled'];

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
