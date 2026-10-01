<?php

namespace App\Services\Billing;

use App\Mail\PaymentReminder;
use App\Models\BinanceAccount;
use App\Models\Invoice;
use App\Services\EngineCache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The billing gate's off switch, shared by its two callers:
 *
 * - the engine's schedule on the 4th at the billing hour (EngineInvoiceController::enforce),
 *   which pauses invoices due THAT DAY — the moment the owner chose;
 * - `engine:mark-overdue` nightly at 00:10 UTC, the safety net, which pauses
 *   anything due BEFORE today (an engine that was down on the 4th).
 *
 * Pending invoices with a fee whose due date is on/before the cutoff become
 * 'overdue' and their Binance account is disabled (InvoiceService::settle
 * turns it back on when paid). Selecting status = 'pending' only makes it
 * exactly-once. The MASTER's account is never disabled (a sandbox rehearsal
 * left unpaid must not stop the house trading); its invoice still goes overdue.
 */
class OverdueEnforcer
{
    public function __construct(
        private EngineCache $engineCache,
        private InvoiceNotifier $notifier,
    ) {}

    /**
     * @return array{overdue: Collection<int, Invoice>, disabled: int, emailed: int}
     */
    public function run(Carbon $dueOnOrBefore, ?string $monthYear = null): array
    {
        $due = Invoice::where('status', 'pending')
            ->where('total_fee', '>', 0)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<=', $dueOnOrBefore->toDateString())
            ->when($monthYear, fn ($q) => $q->where('month_year', $monthYear))
            ->get();

        $masterUniIds = DB::table('user_credentials')->where('type', 'master')->pluck('uni_id')->all();

        $disabled = 0;
        $emailed = 0;
        foreach ($due as $invoice) {
            $invoice->status = 'overdue';
            $invoice->save();

            if (in_array($invoice->user_id, $masterUniIds, true)) {
                continue;
            }
            if ($invoice->account_id && $invoice->exchange === 'binance') {
                $disabled += BinanceAccount::whereKey($invoice->account_id)->update(['enabled' => 0]);
            }
            // bybit/mexc: disable path lands with their invoicing.

            // "Trading is paused — paying switches it back on." Sandbox
            // scratch rows are nobody's real invoice.
            if (! str_starts_with((string) $invoice->api_key, 'SBXINV-')
                && $this->notifier->reminder($invoice, PaymentReminder::STAGE_PAUSED)) {
                $emailed++;
            }
        }

        if ($disabled > 0) {
            $this->engineCache->refreshAccounts();
        }

        return ['overdue' => $due, 'disabled' => $disabled, 'emailed' => $emailed];
    }
}
