<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Trader-facing invoices for the authenticated user (auth:sanctum).
 * Read-only — generation and settlement are admin/service concerns.
 */
class InvoiceController extends Controller
{
    public function __construct(private InvoiceService $invoices) {}

    /** GET /api/invoices — the current user's invoices, newest first. */
    public function index(Request $request): JsonResponse
    {
        $invoices = Invoice::with('account')
            ->forUser($request->user()->uni_id)
            ->orderByDesc('month_year')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'success' => true,
            'invoices' => $this->invoices->mapList($invoices),
        ]);
    }

    /** GET /api/invoices/{id} — one invoice the user owns. */
    public function show(Request $request, int $id): JsonResponse
    {
        $invoice = Invoice::with('account')
            ->forUser($request->user()->uni_id)
            ->find($id);

        if (! $invoice) {
            return response()->json([
                'success' => false,
                'error_code' => 'INVOICE_NOT_FOUND',
                'message' => 'Invoice not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'invoice' => $invoice->toApiArray($invoice->account?->name, $this->invoices->isFirstInvoice($invoice)),
        ]);
    }
}
