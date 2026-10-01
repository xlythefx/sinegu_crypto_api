<?php

namespace App\Http\Controllers;

use App\Models\BinanceAccount;
use App\Models\Invoice;
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
 *   trades) may be billed; the running month is refused.
 * - One account failing never stops the rest; it is reported, not thrown.
 */
class EngineInvoiceController extends Controller
{
    public function __construct(private InvoiceService $invoices) {}

    public function monthly(Request $request, string $exchange): JsonResponse
    {
        $data = $request->validate([
            'month_year' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
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
                $created[] = $who + [
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
            ],
        ]);
    }
}
