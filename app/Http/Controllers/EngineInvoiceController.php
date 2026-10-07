<?php

namespace App\Http\Controllers;

use App\Mail\PaymentReminder;
use App\Models\BinanceAccount;
use App\Models\Invoice;
use App\Services\Billing\InvoiceNotifier;
use App\Services\Billing\OverdueEnforcer;
use App\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * POST /api/engine/{exchange}/invoices/monthly — the automatic monthly run.
 *
 * The trading engine's scheduler (trading-flask `binance_abcd/monthly_invoices.py`)
 * calls this on the 1st at 23:00 Asia/Manila, right after refreshing every
 * balance, so the invoice's live equity is minutes old rather than a poll old.
 * The fee math is NOT here: every row goes through
 * InvoiceService::generateForAccount, the same call Admin → Invoice Testing
 * makes, so an automatic invoice and a hand-made one cannot differ.
 *
 * Who is billed: every connected, real-money (demo = 0, not a sandbox, not an
 * SBXINV- scratch) account whose owner is a CUSTOMER (`type = 'user'`). The
 * master is refused by notInvoiceableReason as always; staff and developer
 * accounts are not customers and are never billed by the robot.
 *
 * Three rules make it safe to call twice:
 * - An account that already has an invoice for that month is SKIPPED, never
 *   regenerated — regenerating would overwrite a manual fee an admin typed,
 *   and would restate figures a customer may already be looking at.
 * - Only a month that has ENDED (UTC, which is how invoice months bucket
 *   trades) may be billed; the running month is refused. And only the month
 *   that ended most recently is billed on the robot's word — an older month
 *   would be born overdue and priced from today's balance — unless the body
 *   carries `force: true` (a human billing an older month deliberately).
 * - One account failing never stops the rest; it is reported, not thrown.
 */
class EngineInvoiceController extends Controller
{
    public function __construct(
        private InvoiceService $invoices,
        private InvoiceNotifier $notifier,
        private OverdueEnforcer $enforcer,
    ) {}

    public function monthly(Request $request, string $exchange): JsonResponse
    {
        $data = $request->validate([
            'month_year' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            // A human's say-so for a month other than the one that just ended.
            'force' => ['sometimes', 'boolean'],
        ]);
        $month = $data['month_year'];

        if (! InvoiceService::canInvoice($exchange)) {
            return response()->json([
                'success' => false,
                'error_code' => 'EXCHANGE_UNSUPPORTED',
                'message' => "Invoicing for '{$exchange}' is not available yet.",
            ], 422);
        }

        if ($month >= now()->utc()->format('Y-m')) {
            return response()->json([
                'success' => false,
                'error_code' => 'MONTH_NOT_ENDED',
                'message' => "{$month} has not ended yet.",
            ], 422);
        }

        // Only the month that just ended is billed on the robot's word. An
        // older month's due date — the 4th of the month after it — is already
        // behind us, so a back-dated invoice is born overdue and the next
        // enforce step pauses the account the same day; and it is priced from
        // TODAY's balance, not the equity that month actually ended on. The
        // engine never sends anything else, so any other month is a bug or a
        // replayed request unless a human says `force: true` and owns it.
        $previous = now()->utc()->startOfMonth()->subMonth()->format('Y-m');
        if ($month !== $previous && ! ($data['force'] ?? false)) {
            return response()->json([
                'success' => false,
                'error_code' => 'MONTH_NOT_PREVIOUS',
                'message' => "Only {$previous}, the month that just ended, is invoiced automatically; send force: true to bill {$month}.",
                'expected_month_year' => $previous,
            ], 422);
        }

        $period = Carbon::createFromFormat('!Y-m', $month, 'UTC');
        $created = [];
        $skipped = [];
        $failed = [];

        $accounts = BinanceAccount::query()
            ->join('user_credentials as u', 'u.uni_id', '=', 'binance_accounts.uni_id')
            ->where('u.type', 'user')
            ->where('binance_accounts.demo', 0)
            ->where('binance_accounts.is_sandbox', 0)
            ->where('binance_accounts.api_key', 'not like', 'SBXINV-%')
            // An account connected after the month ended has nothing to bill for it.
            ->where('binance_accounts.created_at', '<=', $period->copy()->endOfMonth())
            ->orderBy('binance_accounts.id')
            ->get([
                'binance_accounts.*',
                'u.name as owner_name',
                'u.realized_percentage',
                'u.unrealized_percentage',
            ]);

        foreach ($accounts as $account) {
            $who = ['account_id' => $account->id, 'owner_name' => $account->owner_name];

            if (Invoice::where(['exchange' => $exchange, 'account_id' => $account->id, 'month_year' => $month])->exists()) {
                $skipped[] = $who + ['reason' => 'already invoiced'];

                continue;
            }
            if ($reason = InvoiceService::notInvoiceableReason($account)) {
                $skipped[] = $who + ['reason' => $reason];

                continue;
            }

            try {
                $invoice = $this->invoices->generateForAccount($account, $month, [
                    'realized' => (float) ($account->realized_percentage ?? 20),
                    'unrealized' => (float) ($account->unrealized_percentage ?? 6),
                ], $exchange);
                // Day 0 of the schedule: "your invoice is ready". Only a real
                // fee — a $0 month is stored paid and owes nothing.
                $emailed = $invoice->status === 'pending' && (float) $invoice->total_fee > 0
                    && $this->notifier->issued($invoice);
                $created[] = $who + [
                    'emailed' => $emailed,
                    'invoice_id' => $invoice->id,
                    'total_fee' => round((float) $invoice->total_fee, 2),
                    'status' => $invoice->status,
                    'due_date' => $invoice->due_date instanceof Carbon
                        ? $invoice->due_date->toDateString()
                        : (string) $invoice->due_date,
                ];
            } catch (\Throwable $e) {
                Log::error('Monthly invoice failed.', ['account_id' => $account->id, 'month' => $month, 'exception' => $e->getMessage()]);
                $failed[] = $who + ['error' => $e->getMessage()];
            }
        }

        // Disconnected during the month but traded in it: the robot does not
        // bill a row whose balance is no longer polled, so it names them for a
        // human instead of silently forgiving the fee.
        $disconnected = DB::table('binance_accounts as a')
            ->join('user_credentials as u', 'u.uni_id', '=', 'a.uni_id')
            ->whereNotNull('a.deleted_at')
            ->where('u.type', 'user')
            ->where('a.demo', 0)
            ->where('a.is_sandbox', 0)
            ->whereExists(fn ($q) => $q->from('binance_pastpositions as t')
                ->whereColumn('t.api_key', 'a.api_key')
                ->whereBetween('t.closed_at', [$period->copy()->startOfMonth(), $period->copy()->endOfMonth()]))
            ->whereNotExists(fn ($q) => $q->from('invoices as i')
                ->where('i.exchange', $exchange)
                ->whereColumn('i.account_id', 'a.id')
                ->where('i.month_year', $month))
            ->get(['a.id as account_id', 'u.name as owner_name'])
            ->map(fn ($r) => ['account_id' => $r->account_id, 'owner_name' => $r->owner_name, 'reason' => 'disconnected — bill by hand if owed'])
            ->all();

        $billed = array_filter($created, fn ($c) => $c['total_fee'] > 0);

        return response()->json([
            'success' => true,
            'exchange' => $exchange,
            'month_year' => $month,
            'created' => $created,
            'skipped' => array_merge($skipped, $disconnected),
            'failed' => $failed,
            'totals' => [
                'created' => count($created),
                'billed' => count($billed),
                'zero_fee' => count($created) - count($billed),
                'skipped' => count($skipped) + count($disconnected),
                'failed' => count($failed),
                'amount' => round(array_sum(array_column($billed, 'total_fee')), 2),
                'emailed' => count(array_filter($created, fn ($c) => $c['emailed'])),
            ],
        ]);
    }

    /**
     * POST /api/engine/{exchange}/invoices/remind  {month_year, stage}
     *
     * stage `gentle` (the 2nd) / `firm` (the 3rd) emails every customer whose
     * invoice for that month is still pending and not yet due; `issued`
     * re-sends the day-0 "invoice ready" email to the same set (for a month
     * invoiced before that email was live). Never the master, never a
     * sandbox scratch row, never a paid or already-overdue invoice.
     */
    public function remind(Request $request, string $exchange): JsonResponse
    {
        $data = $request->validate([
            'month_year' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'stage' => ['required', 'in:issued,gentle,firm'],
        ]);

        $masters = DB::table('user_credentials')->where('type', 'master')->pluck('uni_id')->all();
        $open = Invoice::forExchange($exchange)
            ->where('month_year', $data['month_year'])
            ->where('status', 'pending')
            ->where('total_fee', '>', 0)
            ->whereDate('due_date', '>=', today()->toDateString())
            ->where('api_key', 'not like', 'SBXINV-%')
            ->whereNotIn('user_id', $masters)
            ->orderBy('id')
            ->get();

        $rows = [];
        foreach ($open as $invoice) {
            $sent = $data['stage'] === 'issued'
                ? $this->notifier->issued($invoice)
                : $this->notifier->reminder($invoice, $data['stage'] === 'gentle'
                    ? PaymentReminder::STAGE_GENTLE
                    : PaymentReminder::STAGE_FIRM);
            $rows[] = $this->row($invoice) + ['emailed' => $sent];
        }

        return response()->json([
            'success' => true,
            'month_year' => $data['month_year'],
            'stage' => $data['stage'],
            'unpaid' => $rows,
            'totals' => [
                'unpaid' => count($rows),
                'emailed' => count(array_filter($rows, fn ($r) => $r['emailed'])),
                'amount' => round(array_sum(array_column($rows, 'total_fee')), 2),
            ],
        ]);
    }

    /**
     * POST /api/engine/{exchange}/invoices/enforce
     *
     * The 4th at the billing hour: every pending invoice due today or earlier
     * goes overdue, its account stops trading and the customer is emailed
     * "trading paused". Paying switches it back on (InvoiceService::settle).
     * The nightly engine:mark-overdue is the safety net behind this.
     */
    public function enforce(string $exchange): JsonResponse
    {
        $result = $this->enforcer->run(today());
        $rows = $result['overdue']->map(fn (Invoice $i) => $this->row($i))->values()->all();

        return response()->json([
            'success' => true,
            'paused' => $rows,
            'totals' => [
                'overdue' => count($rows),
                'disabled' => $result['disabled'],
                'emailed' => $result['emailed'],
                'amount' => round(array_sum(array_column($rows, 'total_fee')), 2),
            ],
        ]);
    }

    /** @return array{invoice_id: int, owner_name: ?string, total_fee: float, due_date: ?string} */
    private function row(Invoice $invoice): array
    {
        return [
            'invoice_id' => (int) $invoice->id,
            'owner_name' => DB::table('user_credentials')->where('uni_id', $invoice->user_id)->value('name'),
            'total_fee' => round((float) $invoice->total_fee, 2),
            'due_date' => $invoice->due_date ? Carbon::parse($invoice->due_date)->toDateString() : null,
        ];
    }
}
