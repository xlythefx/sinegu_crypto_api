<?php

namespace App\Services\Payments;

use App\Models\Invoice;
use App\Models\PaymentIntent;
use App\Models\TronTransfer;
use App\Services\InvoiceService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Reads incoming TRC-20 transfers and settles the invoices they pay for.
 *
 * The trust rules, all of which must hold before a transfer is treated as
 * money, and none of which may be relaxed:
 *
 *   - the token is identified by its CONTRACT ADDRESS ONLY. `symbol` is
 *     attacker-controlled — anyone can deploy a token called "USDT" — and so is
 *     `decimals`, where a contract reporting 0 would make one base unit look
 *     like a whole dollar. Both are stored as reported and never consulted.
 *   - the recipient is the address we configured, compared as exact base58.
 *   - the transfer is confirmed (TronGrid's `only_confirmed`, which is TRON's
 *     own finality rule — solidified blocks cannot be rolled back by a fork).
 *   - the amount resolves to EXACTLY ONE open intent. Zero or several and
 *     nothing settles; a human decides on the admin screen. Guessing here would
 *     mean paying off the wrong customer's invoice.
 */
class TronWatcher
{
    public function __construct(
        private TronGateway $tron,
        private TronIntentService $intents,
        private InvoiceService $invoices,
        private PaymentEventRecorder $events,
        private PaymentEnvironment $env,
    ) {}

    /**
     * Scan one network and settle whatever it can.
     *
     * @return array{network: string, configured: bool, fetched: int, stored: int,
     *   settled: int, unmatched: int, rejected: int, ambiguous: int,
     *   cursor_from: int, truncated: bool, error: ?string}
     */
    public function scan(string $network, bool $dryRun = false, ?int $sinceMs = null): array
    {
        $t = $this->env->tron($network);

        $summary = [
            'network' => $network,
            'configured' => $t['configured'],
            'fetched' => 0,
            'stored' => 0,
            'settled' => 0,
            'unmatched' => 0,
            'rejected' => 0,
            'ambiguous' => 0,
            'cursor_from' => 0,
            'truncated' => false,
            'error' => null,
        ];

        if (! $t['configured']) {
            $summary['error'] = $t['address'] !== '' && ! $t['address_valid']
                ? 'address failed its checksum'
                : 'not configured';

            return $summary;
        }

        $this->intents->expireStale($network);

        $cursor = $sinceMs ?? $this->cursorFor($network, $t['address']);
        $summary['cursor_from'] = $cursor;

        $page = $this->tron->incomingTransfers($network, $cursor);
        $summary['fetched'] = count($page['items']);
        $summary['truncated'] = $page['truncated'];

        if (! $page['ok']) {
            $summary['error'] = $page['status'] === 0
                ? 'TronGrid unreachable'
                : "TronGrid answered {$page['status']}";
        }

        foreach ($page['items'] as $item) {
            $outcome = $this->ingest($network, $t, $item, $dryRun);

            if ($outcome === null) {
                continue;   // already seen
            }

            $summary['stored']++;
            $summary[$outcome] = ($summary[$outcome] ?? 0) + 1;
        }

        // Only a scan that actually completed counts as liveness — otherwise a
        // network that has been unreachable for an hour would still look fresh.
        if ($page['ok']) {
            TronGateway::markScanned($network);
        }

        return $summary;
    }

    /**
     * Where to resume from, in milliseconds.
     *
     * DERIVED from what we have stored rather than kept as a separate counter,
     * which is what makes a failed or half-finished scan harmless: we ask for
     * everything at or after the newest transfer we hold, minus an overlap. A
     * crash mid-page loses nothing (the unique index makes re-reading free), and
     * there is no cursor to get out of step with reality.
     *
     * Clamped to a maximum lookback so a table that has been empty for a year
     * does not ask TronGrid for a year of history on its first run.
     */
    private function cursorFor(string $network, string $address): int
    {
        $overlapMs = (int) config('payments.tron.scan_overlap_minutes', 30) * 60 * 1000;
        $lookbackMs = (int) config('payments.tron.scan_max_lookback_hours', 72) * 3600 * 1000;

        $newest = (int) TronTransfer::forNetwork($network)
            ->where('to_address', $address)
            ->max('block_timestamp');

        $floor = (int) (now()->getTimestampMs() - $lookbackMs);

        if ($newest <= 0) {
            return $floor;
        }

        return max($floor, $newest - $overlapMs);
    }

    /**
     * Store one chain item and, if it is credible, try to settle it.
     *
     * @return string|null  the summary bucket it landed in, or null if we had
     *                      already recorded this transfer
     */
    private function ingest(string $network, array $t, array $item, bool $dryRun): ?string
    {
        $txHash = (string) ($item['transaction_id'] ?? '');
        $from = (string) ($item['from'] ?? '');
        $to = (string) ($item['to'] ?? '');
        $value = (string) ($item['value'] ?? '');
        $blockTs = (int) ($item['block_timestamp'] ?? 0);
        $tokenInfo = (array) ($item['token_info'] ?? []);
        $contract = (string) ($tokenInfo['address'] ?? '');

        $eventKey = TronTransfer::eventKey($txHash, $from, $to, $contract, $value, $blockTs);

        if (TronTransfer::forNetwork($network)->where('event_key', $eventKey)->exists()) {
            return null;
        }

        $reject = $this->rejectReasonFor($t, $item, $to, $from, $contract, $value);

        $attributes = [
            'network' => $network,
            'event_key' => $eventKey,
            'tx_hash' => $txHash,
            'contract_address' => $contract,
            // Recorded as reported, never consulted. See the class docblock.
            'token_symbol' => isset($tokenInfo['symbol']) ? (string) $tokenInfo['symbol'] : null,
            'token_decimals' => isset($tokenInfo['decimals']) ? (int) $tokenInfo['decimals'] : null,
            'from_address' => $from,
            'to_address' => $to,
            'value_raw' => $value,
            'value_units' => TronUnits::isSafeInteger($value) ? $value : null,
            'block_timestamp' => $blockTs,
            'confirmed' => true,
            'status' => $reject !== null ? TronTransfer::STATUS_REJECTED : TronTransfer::STATUS_UNMATCHED,
            'reject_reason' => $reject,
            'payload' => mb_substr(json_encode($item) ?: '', 0, 4000),
        ];

        if ($dryRun) {
            return $reject !== null ? 'rejected' : 'unmatched';
        }

        try {
            $transfer = TronTransfer::create($attributes);
        } catch (QueryException $e) {
            // A concurrent scan stored it between our check and our insert. The
            // unique index is the authority; treat it as already seen.
            return null;
        }

        if ($reject !== null) {
            return 'rejected';
        }

        return $this->match($transfer, $t);
    }

    /**
     * Why this transfer is not money, or null if it might be.
     *
     * Order matters only for which reason gets reported; every one of these is
     * disqualifying on its own.
     */
    private function rejectReasonFor(
        array $t,
        array $item,
        string $to,
        string $from,
        string $contract,
        string $value
    ): ?string {
        // TronGrid labels approvals and transfers alike on this endpoint.
        if (isset($item['type']) && (string) $item['type'] !== 'Transfer') {
            return 'not_transfer';
        }

        if (! TronAddress::equals($to, $t['address'])) {
            return 'wrong_recipient';
        }

        // The ONLY trusted identity of the token.
        if (! TronAddress::equals($contract, $t['contract'])) {
            return 'wrong_contract';
        }

        if (TronAddress::equals($from, $t['address'])) {
            return 'self_transfer';
        }

        if (! TronUnits::isSafeInteger($value)) {
            return 'value_out_of_range';
        }

        $min = (string) (int) config('payments.tron.min_value_units', 0);
        if (bccomp($value, $min, 0) < 0) {
            return 'dust';
        }

        return null;
    }

    /**
     * Find the one intent this transfer pays, and settle it.
     *
     * @return string  the summary bucket
     */
    private function match(TronTransfer $transfer, array $t): string
    {
        $units = (string) $transfer->value_units;
        $candidates = $this->intents->candidatesFor($transfer->network, $t['address'], $units);

        if ($candidates->count() > 1) {
            $this->recordUnmatched($transfer, $t, 'tracking_unknown', sprintf(
                'Ambiguous: %d open intents accept %s %s.',
                $candidates->count(),
                TronUnits::format($units, (int) $t['decimals']),
                $t['asset'],
            ));

            return 'ambiguous';
        }

        if ($candidates->isEmpty()) {
            $this->recordUnmatched($transfer, $t, ...$this->missReason($transfer, $t, $units));

            return 'unmatched';
        }

        /** @var PaymentIntent $intent */
        $intent = $candidates->first();

        $invoice = Invoice::find($intent->invoice_id);
        if (! $invoice) {
            $this->recordUnmatched($transfer, $t, 'invoice_not_found', "Intent {$intent->id} points at a missing invoice.");

            return 'unmatched';
        }

        // Conditional claim: the scheduled run and a hand-run command can be
        // here at the same instant, and only one of them may consume the intent.
        if (! $this->intents->claim($intent, $transfer->tx_hash, $units)) {
            return 'unmatched';
        }

        // Counted as settled even when the invoice had already been paid another
        // way: the transfer was consumed and attributed either way, and calling
        // that "unmatched" would send someone hunting for a payment sitting
        // safely on the invoice it belongs to.
        $this->attribute($transfer, $invoice, $intent, 'watcher');

        return 'settled';
    }

    /**
     * Whether a miss was a near-miss on a real reservation or an unrelated
     * payment — the difference between "they underpaid" and "we have no idea
     * who this is", which is what the admin screen needs to say.
     *
     * @return array{0: string, 1: string}  [outcome, message]
     */
    private function missReason(TronTransfer $transfer, array $t, string $units): array
    {
        $nearby = $this->intents->candidatesFor($transfer->network, $t['address'], $units, true);
        $decimals = (int) $t['decimals'];
        $amount = TronUnits::format($units, $decimals);

        if ($nearby->isNotEmpty()) {
            return ['tracking_unknown', "Matches only closed or expired intents; {$amount} {$t['asset']} received."];
        }

        $open = PaymentIntent::forNetwork($transfer->network)
            ->open()
            ->where('address', $t['address'])
            ->get();

        if ($open->isEmpty()) {
            return ['tracking_unknown', "No reservation matches {$amount} {$t['asset']}."];
        }

        $nearest = $open->sortBy(fn (PaymentIntent $i) => (float) bcsub(
            bccomp($units, (string) $i->expected_units, 0) >= 0 ? $units : (string) $i->expected_units,
            bccomp($units, (string) $i->expected_units, 0) >= 0 ? (string) $i->expected_units : $units,
            0
        ))->first();

        $expected = TronUnits::format((string) $nearest->expected_units, $decimals);
        $short = bccomp($units, $nearest->floorUnits(), 0) < 0;

        return ['amount_mismatch', sprintf(
            'Received %s %s; invoice %d expects %s (%s beyond tolerance).',
            $amount, $t['asset'], $nearest->invoice_id, $expected, $short ? 'short' : 'over',
        )];
    }

    /**
     * Settle an invoice from a transfer. THE ONE DEFINITION OF SETTLEMENT for
     * this rail — the watcher and the admin's manual attribution both come
     * through here, so the two can never drift apart.
     *
     * `$by` is 'watcher' or "admin:{uni_id}".
     */
    public function attribute(
        TronTransfer $transfer,
        Invoice $invoice,
        ?PaymentIntent $intent,
        string $by
    ): bool {
        $t = $this->env->tron($transfer->network);
        $decimals = (int) ($intent?->decimals ?? $t['decimals']);
        $units = (string) $transfer->value_units;
        $expectedCents = $invoice->feeCents();

        // Deliberately the invoice's own USD figure, not the USDT amount
        // reinterpreted: if USDT ever depegs we under-record by the depeg rather
        // than billing a number the customer never agreed to.
        $settled = $this->invoices->settle($invoice, 'tron', $transfer->tx_hash, $expectedCents / 100);

        $transfer->forceFill([
            'status' => TronTransfer::STATUS_SETTLED,
            'intent_id' => $intent?->id,
            'invoice_id' => $invoice->id,
            'settled_by' => $by,
            'settled_at' => now(),
        ])->save();

        $paidUnits = $intent !== null && bccomp($units, (string) $intent->expected_units, 0) > 0;

        $this->events->record([
            'provider' => 'tron',
            'event_id' => $transfer->event_key,
            'external_id' => $transfer->tx_hash,
            'tx_hash' => $transfer->tx_hash,
            'invoice_id' => $invoice->id,
            'user_id' => $invoice->user_id,
            'account_id' => $invoice->account_id,
            'outcome' => ! $settled ? 'already_paid' : ($paidUnits ? 'overpaid' : 'paid'),
            'provider_status' => $transfer->network,
            'amount' => $expectedCents / 100,
            'amount_currency' => 'USD',
            'expected_amount' => $expectedCents / 100,
            'crypto_currency' => $t['asset'],
            'crypto_amount' => TronUnits::format($units, $decimals),
            'message' => sprintf('%s via %s on %s.', $settled ? 'Settled' : 'Already paid', $by, $transfer->network),
        ]);

        if ($settled) {
            Log::info('Invoice settled from a TRON transfer.', [
                'invoice_id' => $invoice->id,
                'network' => $transfer->network,
                'tx_hash' => $transfer->tx_hash,
                'by' => $by,
            ]);
        }

        return $settled;
    }

    /**
     * Audit a credible transfer we could not place.
     *
     * Only transfers that got this far are written to `payment_events`: the
     * receiving address is public and anyone can spray dust at it for nothing,
     * so junk stays in `tron_transfers` where it cannot inflate the audit trail.
     */
    private function recordUnmatched(TronTransfer $transfer, array $t, string $outcome, string $message): void
    {
        $this->events->record([
            'provider' => 'tron',
            'event_id' => $transfer->event_key,
            'external_id' => $transfer->tx_hash,
            'tx_hash' => $transfer->tx_hash,
            'outcome' => $outcome,
            'provider_status' => $transfer->network,
            'crypto_currency' => $t['asset'],
            'crypto_amount' => TronUnits::format((string) $transfer->value_units, (int) $t['decimals']),
            'message' => $message,
        ]);
    }

    /**
     * Why this transfer may NOT be attributed by hand, or null.
     *
     * Shipped with every admin row so the UI's disabled buttons and the server's
     * refusal share one definition — and re-evaluated when the action actually
     * arrives, because an invoice can be paid another way between the page
     * loading and the button being pressed.
     */
    public function attributionBlockedReason(TronTransfer $transfer): ?string
    {
        return match (true) {
            $transfer->status === TronTransfer::STATUS_SETTLED => 'Already attributed.',
            $transfer->status === TronTransfer::STATUS_IGNORED => 'Marked as ignored.',
            $transfer->reject_reason !== null => 'Not a valid payment: '.$transfer->reject_reason.'.',
            ! $transfer->confirmed => 'Not confirmed on chain yet.',
            $transfer->value_units === null => 'Amount is outside the representable range.',
            default => null,
        };
    }

    /**
     * Invoices this unmatched transfer plausibly belongs to — including ones
     * whose intent has since EXPIRED, which is the common case: someone paid an
     * hour late. A hint for a human, never an auto-settle.
     *
     * @return list<array<string, mixed>>
     */
    public function suggestionsFor(TronTransfer $transfer): array
    {
        if ($transfer->value_units === null || $transfer->reject_reason !== null) {
            return [];
        }

        $t = $this->env->tron($transfer->network);
        $units = (string) $transfer->value_units;
        $decimals = (int) $t['decimals'];

        $candidates = $this->intents
            ->candidatesFor($transfer->network, $transfer->to_address, $units, true)
            ->take(5);

        $invoices = Invoice::whereIn('id', $candidates->pluck('invoice_id')->all())->get()->keyBy('id');

        return $candidates->map(function (PaymentIntent $intent) use ($invoices, $units, $decimals) {
            $invoice = $invoices->get($intent->invoice_id);
            $delta = (float) TronUnits::format($units, $decimals) - (float) $intent->expected_usd;

            return [
                'invoice_id' => $intent->invoice_id,
                'intent_id' => $intent->id,
                'intent_status' => $intent->status,
                'expected' => TronUnits::format((string) $intent->expected_units, $decimals),
                'expected_usd' => (float) $intent->expected_usd,
                'delta_usd' => round($delta, 2),
                'invoice_status' => $invoice?->status,
                'user_id' => $intent->user_id,
                'why' => $intent->isOpen()
                    ? 'amount is within the tolerance of an open reservation'
                    : sprintf('amount matches a reservation that %s', $intent->status),
            ];
        })->values()->all();
    }
}
