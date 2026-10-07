<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\PaymentIntent;
use App\Models\TronTransfer;
use App\Models\UserCredential;
use App\Services\Payments\PaymentEnvironment;
use App\Services\Payments\TronGateway;
use App\Services\Payments\TronUnits;
use App\Services\Payments\TronWatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin → Crypto Transfers. Every USDT-TRC20 arrival we have seen, and the one
 * action the automatic matcher cannot take: deciding, as a human, which invoice
 * an unplaceable payment belongs to.
 *
 * This screen is not a stopgap. Wrong amounts, payments an hour after the quote
 * expired, someone paying twice, someone paying from a wallet that rounded —
 * these happen with real customers no matter how good the matcher is, and every
 * one of them is money already sitting in our wallet that has to reach the right
 * invoice.
 *
 * Two rules carried over from AdminApiKeyController:
 *   - the eligibility verdict (`attributable` / `attribution_blocked_reason`) is
 *     computed SERVER-SIDE and shipped with each row, so the UI's disabled
 *     buttons and the server's refusal share one definition;
 *   - and it is RE-EVALUATED when the action arrives, never trusted from the row
 *     the admin was looking at — an invoice can be paid another way between the
 *     page loading and the button being pressed.
 */
class AdminTronTransferController extends Controller
{
    private const PER_PAGE_MAX = 500;

    public function __construct(
        private TronWatcher $watcher,
        private PaymentEnvironment $env,
    ) {}

    /**
     * GET /api/admin/tron-transfers?network=&status=
     */
    public function index(Request $request): JsonResponse
    {
        $rows = TronTransfer::query()
            ->when($request->query('network'), fn ($q, $n) => $q->where('network', $n))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('block_timestamp')
            ->limit(self::PER_PAGE_MAX)
            ->get();

        $built = $rows->map(fn (TronTransfer $t) => $this->row($t))->all();

        // Owners come from the SUGGESTIONS, not from the transfers' invoice_id:
        // an unmatched transfer has no invoice yet, and naming the customer a
        // suggestion points at is the entire value of the hint.
        $userIds = collect($built)
            ->flatMap(fn (array $row) => array_column($row['suggestions'], 'user_id'))
            ->filter()
            ->unique()
            ->all();

        $owners = UserCredential::whereIn('uni_id', $userIds)
            ->get(['uni_id', 'name', 'email'])
            ->keyBy('uni_id');

        $built = array_map(function (array $row) use ($owners) {
            $row['suggestions'] = array_map(function (array $s) use ($owners) {
                $owner = $owners->get($s['user_id'] ?? '');
                $s['owner'] = $owner === null ? null : [
                    'uni_id' => $owner->uni_id,
                    'name' => $owner->name,
                    'email' => $owner->email,
                ];

                return $s;
            }, $row['suggestions']);

            return $row;
        }, $built);

        $counts = TronTransfer::selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return response()->json([
            'success' => true,
            'networks' => $this->networkHealth(),
            'counts' => [
                'all' => (int) $counts->sum(),
                'unmatched' => (int) ($counts[TronTransfer::STATUS_UNMATCHED] ?? 0),
                'settled' => (int) ($counts[TronTransfer::STATUS_SETTLED] ?? 0),
                'ignored' => (int) ($counts[TronTransfer::STATUS_IGNORED] ?? 0),
                'rejected' => (int) ($counts[TronTransfer::STATUS_REJECTED] ?? 0),
            ],
            'transfers' => $built,
        ]);
    }

    /**
     * POST /api/admin/tron-transfers/{id}/attribute
     * Body: { invoice_id: int, accept_amount?: bool }
     *
     * Three refusals, all 422, all re-evaluated here rather than trusted from
     * the row the admin was looking at:
     *   ATTRIBUTION_REFUSED — the transfer or the invoice is no longer eligible;
     *   NETWORK_MISMATCH    — the transfer is on a network the invoice owner's
     *                         role does not pay on (a testnet transfer must never
     *                         settle a customer's real invoice); final;
     *   AMOUNT_MISMATCH     — the amount is outside the watcher's own tolerance.
     *                         Carries expected_usd / received_usdt / difference
     *                         so the page can show them, and is overridden by
     *                         re-posting with `accept_amount: true` — wrong
     *                         amounts are what this screen is for.
     */
    public function attribute(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'invoice_id' => ['required', 'integer'],
            'accept_amount' => ['sometimes', 'boolean'],
        ]);

        $transfer = TronTransfer::find($id);
        if (! $transfer) {
            return $this->notFound('TRANSFER_NOT_FOUND', 'Transfer not found.');
        }

        // Re-evaluated here, not trusted from the listing.
        $blocked = $this->watcher->attributionBlockedReason($transfer);
        if ($blocked !== null) {
            return $this->refused($blocked);
        }

        $invoice = Invoice::find((int) $data['invoice_id']);
        if (! $invoice) {
            return $this->notFound('INVOICE_NOT_FOUND', 'Invoice not found.');
        }
        if ($invoice->isPaid()) {
            return $this->refused('That invoice has already been paid.');
        }

        // Before the intent is touched, so a refusal consumes nothing.
        $refusal = $this->watcher->manualAttributionRefusal(
            $transfer,
            $invoice,
            (bool) ($data['accept_amount'] ?? false),
        );
        if ($refusal !== null) {
            return response()->json(
                ['success' => false, 'error_code' => $refusal['code']] + array_diff_key($refusal, ['code' => true]),
                422,
            );
        }

        // If a reservation for this invoice exists, close it alongside the
        // settlement so its figure is released and it cannot also be matched
        // automatically a minute later.
        $intent = PaymentIntent::forNetwork($transfer->network)
            ->where('invoice_id', $invoice->id)
            ->orderByDesc('id')
            ->first();

        if ($intent !== null && $intent->isOpen()) {
            $this->watcherClaim($intent, $transfer);
        }

        $settled = $this->watcher->attribute(
            $transfer,
            $invoice,
            $intent,
            'admin:'.$request->user()->uni_id,
        );

        return response()->json([
            'success' => true,
            'settled' => $settled,
            'invoice_id' => $invoice->id,
            'message' => $settled
                ? sprintf('Invoice #%d settled from %s.', $invoice->id, $this->shortHash($transfer->tx_hash))
                : sprintf('Invoice #%d was already paid; the transfer is now recorded against it.', $invoice->id),
            'transfer' => $this->row($transfer->fresh()),
        ]);
    }

    /**
     * POST /api/admin/tron-transfers/{id}/ignore
     * Body: { note?: string }
     *
     * For arrivals that are simply not ours to place — someone else's mistake,
     * a test send, a refunded payment. Keeps them out of the queue without
     * pretending they never happened.
     */
    public function ignore(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $transfer = TronTransfer::find($id);
        if (! $transfer) {
            return $this->notFound('TRANSFER_NOT_FOUND', 'Transfer not found.');
        }
        if ($transfer->isSettled()) {
            return $this->refused('That transfer has already been attributed to an invoice.');
        }

        $transfer->forceFill([
            'status' => TronTransfer::STATUS_IGNORED,
            'note' => $data['note'] ?? null,
            'settled_by' => 'admin:'.$request->user()->uni_id,
        ])->save();

        return response()->json([
            'success' => true,
            'message' => 'Transfer marked as ignored.',
            'transfer' => $this->row($transfer->fresh()),
        ]);
    }

    // ---- helpers ---------------------------------------------------------

    private function watcherClaim(PaymentIntent $intent, TronTransfer $transfer): void
    {
        app(\App\Services\Payments\TronIntentService::class)
            ->claim($intent, $transfer->tx_hash, (string) $transfer->value_units);
    }

    /** @return list<array<string, mixed>> */
    private function networkHealth(): array
    {
        $names = array_keys((array) config('payments.tron.networks', []));

        return array_map(function (string $network) {
            $t = $this->env->tron($network);

            return [
                'name' => $network,
                'configured' => $t['configured'],
                'address' => $t['address'] !== '' ? $t['address'] : null,
                'address_valid' => $t['address_valid'],
                'contract_valid' => $t['contract_valid'],
                'label' => $t['label'],
                'last_scan_at' => TronGateway::lastScanAt($network)?->toIso8601String(),
                // The scheduler is load-bearing for money now. A stale scan means
                // payments are arriving and nothing is noticing.
                'scan_stale' => $t['configured'] && TronGateway::scanIsStale($network),
                // The stall a fresh scan can hide: the page budget ran out inside
                // the overlap window, so the cursor is not moving and anything
                // newer is unreachable. Null once a scan makes progress again.
                'budget_exhausted_at' => TronGateway::budgetExhaustedAt($network)?->toIso8601String(),
                'last_transfer_at' => TronTransfer::forNetwork($network)->max('created_at'),
            ];
        }, $names);
    }

    /**
     * One listing row. Owner details are grafted onto the suggestions afterwards
     * by index(), in one query for the whole page rather than one per row.
     *
     * @return array<string, mixed>
     */
    private function row(TronTransfer $t): array
    {
        $tron = $this->env->tron($t->network);
        $decimals = (int) $tron['decimals'];
        $units = (string) ($t->value_units ?? '0');
        $blocked = $this->watcher->attributionBlockedReason($t);

        // Only a transfer that could still be placed needs suggestions.
        $suggestions = $blocked === null ? $this->watcher->suggestionsFor($t) : [];

        return [
            'id' => $t->id,
            'network' => $t->network,
            'tx_hash' => $t->tx_hash,
            'explorer_url' => $tron['explorer_tx'].$t->tx_hash,
            'from_address' => $t->from_address,
            'to_address' => $t->to_address,
            'contract_address' => $t->contract_address,
            // Computed here so the UI never re-derives what counts as our token.
            'contract_trusted' => \App\Services\Payments\TronAddress::equals($t->contract_address, $tron['contract']),
            'token_symbol_reported' => $t->token_symbol,
            'amount' => $t->value_units === null ? null : TronUnits::format($units, $decimals),
            'amount_usd' => $t->value_units === null ? null : round(TronUnits::toUsd($units, $decimals), 2),
            'value_raw' => $t->value_raw,
            'block_timestamp' => $t->block_timestamp,
            'seen_at' => $t->created_at?->toIso8601String(),
            'confirmed' => (bool) $t->confirmed,
            'status' => $t->status,
            'reject_reason' => $t->reject_reason,
            'intent_id' => $t->intent_id,
            'invoice_id' => $t->invoice_id,
            'settled_by' => $t->settled_by,
            'settled_at' => $t->settled_at?->toIso8601String(),
            'note' => $t->note,
            'attributable' => $blocked === null,
            'attribution_blocked_reason' => $blocked,
            'suggestions' => $suggestions,
        ];
    }

    private function shortHash(?string $hash): string
    {
        $hash = (string) $hash;

        return strlen($hash) > 14 ? substr($hash, 0, 8).'…'.substr($hash, -4) : $hash;
    }

    private function notFound(string $code, string $message): JsonResponse
    {
        return response()->json(['success' => false, 'error_code' => $code, 'message' => $message], 404);
    }

    private function refused(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error_code' => 'ATTRIBUTION_REFUSED',
            'message' => $message,
        ], 422);
    }
}
