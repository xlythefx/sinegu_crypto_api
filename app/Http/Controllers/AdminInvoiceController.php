<?php

namespace App\Http\Controllers;

use App\Models\BinanceAccount;
use App\Models\Invoice;
use App\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin invoice management (auth:sanctum + admin middleware): list/inspect,
 * manually generate from an account's P&L, edit, manually settle, and delete.
 * This is the "charge manually first" surface — the sandbox drives generate +
 * mark-paid; live Stripe/Coinsbuy providers arrive in a later phase.
 */
class AdminInvoiceController extends Controller
{
    public function __construct(private InvoiceService $invoices) {}

    /** GET /api/admin/invoices — every invoice + filters + summary. */
    public function index(Request $request): JsonResponse
    {
        $query = Invoice::with('account');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($exchange = $request->query('exchange')) {
            $query->where('exchange', $exchange);
        }

        $invoices = $query->orderByDesc('month_year')->orderByDesc('id')->get();

        $rows = $this->invoices->mapList($invoices);

        // Attach user name/email for the admin table.
        $users = DB::table('user_credentials')
            ->whereIn('uni_id', $invoices->pluck('user_id')->unique()->all())
            ->get(['uni_id', 'name', 'email'])
            ->keyBy('uni_id');
        $rows = array_map(function ($row) use ($users) {
            $u = $users->get($row['user_id']);
            $row['user_name'] = $u->name ?? null;
            $row['user_email'] = $u->email ?? null;

            return $row;
        }, $rows);

        // Optional free-text search over account/user/formatted id.
        if ($search = trim((string) $request->query('search'))) {
            $needle = mb_strtolower($search);
            $rows = array_values(array_filter($rows, function ($r) use ($needle) {
                return str_contains(mb_strtolower($r['account_name'].' '.$r['formatted_id'].' '
                    .($r['user_name'] ?? '').' '.($r['user_email'] ?? '')), $needle);
            }));
        }

        $paid = $invoices->where('status', 'paid');

        return response()->json([
            'success' => true,
            'invoices' => $rows,
            'summary' => [
                'total_collected' => round((float) $paid->sum('total_fee'), 2),
                'count_total' => $invoices->count(),
                'count_paid' => $paid->count(),
                'count_pending' => $invoices->where('status', '!=', 'paid')->count(),
                'outstanding_amount' => round(
                    (float) $invoices->where('status', '!=', 'paid')->sum('total_fee'),
                    2
                ),
            ],
        ]);
    }

    /** GET /api/admin/invoices/{id}. */
    public function show(int $id): JsonResponse
    {
        $invoice = Invoice::with('account')->find($id);

        if (! $invoice) {
            return $this->notFound();
        }

        return response()->json([
            'success' => true,
            'invoice' => $invoice->toApiArray($invoice->account?->name, $this->invoices->isFirstInvoice($invoice)),
        ]);
    }

    /**
     * POST /api/admin/invoices/generate
     * Generate invoice(s) for a month from an account's closed P&L. Target a
     * single account (`account_id`) or every account of a user (`uni_id`).
     */
    public function generate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'uni_id' => ['nullable', 'string', 'exists:user_credentials,uni_id'],
            'account_id' => ['nullable', 'integer', 'exists:binance_accounts,id'],
            'exchange' => ['nullable', 'in:binance,bybit,mexc'],
            'month_year' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'invoice_demo' => ['nullable', 'boolean'],
        ]);

        $exchange = $validated['exchange'] ?? 'binance';
        if ($exchange !== 'binance') {
            return response()->json([
                'success' => false,
                'error_code' => 'EXCHANGE_UNSUPPORTED',
                'message' => "Invoice generation for '{$exchange}' is not available yet.",
            ], 422);
        }

        if (empty($validated['account_id']) && empty($validated['uni_id'])) {
            return response()->json([
                'success' => false,
                'error_code' => 'TARGET_REQUIRED',
                'message' => 'Provide account_id or uni_id.',
            ], 422);
        }

        // Resolve target accounts.
        if (! empty($validated['account_id'])) {
            $accounts = BinanceAccount::where('id', $validated['account_id'])->get();
        } else {
            $accounts = BinanceAccount::where('uni_id', $validated['uni_id'])
                ->when(! ($validated['invoice_demo'] ?? true), fn ($q) => $q->where('demo', 0))
                ->get();
        }

        if ($accounts->isEmpty()) {
            return response()->json([
                'success' => false,
                'error_code' => 'NO_ACCOUNTS',
                'message' => 'No matching exchange accounts to invoice.',
            ], 422);
        }

        // The master is never billed (InvoiceService::notInvoiceableReason).
        // Said out loud rather than silently skipped, so an admin who picked
        // the master sees why nothing was made.
        $accounts = $accounts->reject(fn ($a) => InvoiceService::notInvoiceableReason($a) !== null)->values();
        if ($accounts->isEmpty()) {
            return response()->json([
                'success' => false,
                'error_code' => 'NOT_INVOICEABLE',
                'message' => 'The master account is never invoiced.',
            ], 422);
        }

        $created = [];
        foreach ($accounts as $account) {
            $cred = DB::table('user_credentials')->where('uni_id', $account->uni_id)->first();
            $rates = [
                'realized' => (float) ($cred->realized_percentage ?? 20),
                'unrealized' => (float) ($cred->unrealized_percentage ?? 6),
            ];
            $created[] = $this->invoices->generateForAccount($account, $validated['month_year'], $rates, $exchange);
        }

        $collection = Invoice::with('account')
            ->whereIn('id', array_map(fn ($i) => $i->id, $created))
            ->get();

        return response()->json([
            'success' => true,
            'invoices' => $this->invoices->mapList($collection),
        ], 201);
    }

    /**
     * POST /api/admin/invoices/manual
     * One invoice for ONE account + month at a fee the admin typed
     * (InvoiceService::generateManual). Replaces that month's unpaid invoice
     * if there is one; refuses a paid one. Binance only, like generate —
     * settle() re-enables by binance_accounts id, so an invoice on another
     * venue's account would re-enable the wrong row.
     */
    public function manual(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'account_id' => ['required', 'integer', 'exists:binance_accounts,id'],
            'month_year' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
        ]);

        $account = BinanceAccount::find($validated['account_id']);
        if (! $account) {
            // exists: passes a soft-deleted row; a disconnected account is not billed by hand.
            return response()->json([
                'success' => false,
                'error_code' => 'ACCOUNT_DISCONNECTED',
                'message' => 'That account is disconnected.',
            ], 422);
        }

        try {
            $invoice = $this->invoices->generateManual($account, $validated['month_year'], (float) $validated['amount']);
        } catch (\DomainException $e) {
            // The service refuses for exactly two reasons; the master is the other one.
            $alreadyPaid = InvoiceService::notInvoiceableReason($account) === null;

            return response()->json([
                'success' => false,
                'error_code' => $alreadyPaid ? 'INVOICE_ALREADY_PAID' : 'NOT_INVOICEABLE',
                'message' => $e->getMessage(),
            ], $alreadyPaid ? 409 : 422);
        }

        $collection = Invoice::with('account')->whereKey($invoice->id)->get();

        return response()->json([
            'success' => true,
            'invoices' => $this->invoices->mapList($collection),
        ], 201);
    }

    /**
     * PUT /api/admin/invoices/{id}
     * Edit total_fee and/or status. Setting status → paid runs the single
     * settlement path (idempotent mark-paid + re-enable account).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'total_fee' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', 'in:pending,paid,failed,overdue'],
        ]);

        $invoice = Invoice::find($id);
        if (! $invoice) {
            return $this->notFound();
        }

        if (array_key_exists('total_fee', $validated) && $validated['total_fee'] !== null) {
            $invoice->total_fee = $validated['total_fee'];
            $invoice->save();
        }

        if (! empty($validated['status'])) {
            if ($validated['status'] === 'paid') {
                $this->invoices->settle($invoice, 'manual');
            } else {
                $invoice->status = $validated['status'];
                $invoice->save();
            }
        }

        $invoice->refresh()->load('account');

        return response()->json([
            'success' => true,
            'invoice' => $invoice->toApiArray($invoice->account?->name, $this->invoices->isFirstInvoice($invoice)),
        ]);
    }

    /** DELETE /api/admin/invoices/{id}. */
    public function destroy(int $id): JsonResponse
    {
        $invoice = Invoice::find($id);
        if (! $invoice) {
            return $this->notFound();
        }

        $invoice->delete();

        return response()->json(['success' => true, 'deleted' => $id]);
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error_code' => 'INVOICE_NOT_FOUND',
            'message' => 'Invoice not found.',
        ], 404);
    }
}
