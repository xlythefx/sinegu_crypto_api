<?php

namespace App\Services\Payments;

use App\Http\Controllers\AdminInsightsController;
use App\Mail\PaymentDisputedNotice;
use App\Models\Invoice;
use App\Models\PaymentIntent;
use App\Models\TronPaymentClaim;
use App\Models\TronTransfer;
use App\Models\UserCredential;
use App\Services\Notifications\AccountMail;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * "That payment is mine": a customer confirms a HELD payment by pasting its
 * transaction ID (2026-10-08).
 *
 * The receiving address is shared and matching is by amount, so when two
 * customers wait for the same figure — or one's reservation ran out before
 * their withdrawal arrived — the watcher cannot tell whose a payment is and
 * holds it (TronWatcher::match, `candidate_invoice_ids`). Each possible owner's
 * pay sheet then asks for the transaction ID, and this class settles the
 * invoice of whoever names it.
 *
 * A TXID IS NOT PROOF OF OWNERSHIP. Every payment to a public address is
 * readable on any block explorer, so a customer owing the same amount could
 * paste someone else's. The ID only POINTS at a payment we already recorded
 * from the chain ourselves — the claimant can never invent one — and the
 * defence is that a theft cannot stay hidden: the real payer always has the ID
 * in their exchange's withdrawal history, and when they paste it the payment
 * is already on another invoice. That is a DISPUTE: recorded, emailed to the
 * team once, and counted on the Admin Overview until an admin resolves it.
 *
 * What it will settle: a credible, not-ignored transfer on the caller's own
 * network that is still unplaced, whose amount fits this invoice's band, and
 * which arrived after the invoice existed. It does not require the invoice to
 * be among the held payment's candidates — a payment sent without ever opening
 * the pay sheet is the same money — but only candidates are ASKED.
 */
class TronPaymentClaims
{
    public const STATE_NEEDED = 'needed';

    public const STATE_TAKEN = 'claimed_by_other';

    public const STATE_DISPUTED = 'disputed';

    public function __construct(
        private PaymentEnvironment $env,
        private TronIntentService $intents,
        private TronWatcher $watcher,
    ) {}

    /**
     * Pull a transaction ID out of whatever was pasted: the bare 64-hex id, or
     * a Tronscan link containing it. Null when there is none.
     */
    public static function normaliseHash(string $raw): ?string
    {
        if (preg_match('/(?<![0-9a-f])([0-9a-f]{64})(?![0-9a-f])/i', trim($raw), $m) !== 1) {
            return null;
        }

        return strtolower($m[1]);
    }

    /**
     * What this invoice's pay sheet should ask, or null for nothing.
     *
     * NEVER CARRIES THE TRANSACTION ID — handing it to the page would hand
     * every candidate the answer to the question. Amount and arrival time only.
     *
     * @return array{state: string, amount: string, asset: string, seen_at: ?string, message: string}|null
     */
    public function promptFor(Invoice $invoice, string $network): ?array
    {
        if ($invoice->isPaid()) {
            return null;
        }

        $t = $this->env->tron($network);
        $decimals = (int) $t['decimals'];

        $dispute = TronPaymentClaim::openDisputes()
            ->where('invoice_id', $invoice->id)
            ->where('network', $network)
            ->latest('id')
            ->first();

        if ($dispute) {
            $transfer = TronTransfer::find($dispute->tron_transfer_id);

            return $this->prompt(self::STATE_DISPUTED, $transfer, $t, $decimals,
                'This payment is already matched to another invoice. Our team has been alerted and will contact you.');
        }

        $windowDays = max(1, (int) config('payments.tron.claim_window_days', 14));

        $held = TronTransfer::forNetwork($network)
            ->whereNotNull('candidate_invoice_ids')
            ->whereIn('status', [TronTransfer::STATUS_UNMATCHED, TronTransfer::STATUS_SETTLED])
            ->where('created_at', '>=', now()->subDays($windowDays))
            ->orderByDesc('id')
            ->get()
            ->filter(fn (TronTransfer $tr) => $tr->hasCandidate($invoice->id));

        $open = $held->first(fn (TronTransfer $tr) => $tr->status === TronTransfer::STATUS_UNMATCHED);
        if ($open) {
            return $this->prompt(self::STATE_NEEDED, $open, $t, $decimals,
                'We received a payment of this amount, but another customer is paying the same amount, or it arrived after your timer ran out. Paste the transaction ID (TXID) from your exchange or wallet to confirm it is yours.');
        }

        // Placed on someone else's invoice — by its owner's TXID, or by an
        // admin. Still asked, more softly: if it was really this customer's,
        // this is the only way they would ever find out.
        $taken = $held->first(fn (TronTransfer $tr) => (int) $tr->invoice_id !== $invoice->id);
        if ($taken) {
            return $this->prompt(self::STATE_TAKEN, $taken, $t, $decimals,
                'A payment of this amount was confirmed by another customer. If you sent it, paste your transaction ID (TXID) and our team will review it.');
        }

        return null;
    }

    /**
     * Settle $invoice from the transfer named by $rawHash, or say why not.
     *
     * @return array{ok: bool, status: int, code: string, message: string, settled?: bool}
     */
    public function claim(Invoice $invoice, string $network, string $rawHash, string $claimantUniId): array
    {
        $hash = self::normaliseHash($rawHash);
        if ($hash === null) {
            return $this->refusal(422, 'INVALID_TX_HASH',
                'That does not look like a transaction ID. It is 64 letters and numbers, shown in your exchange\'s withdrawal history.');
        }

        if ($invoice->isPaid()) {
            return $this->refusal(409, 'INVOICE_ALREADY_PAID', 'This invoice has already been paid.');
        }

        $t = $this->env->tron($network);
        $decimals = (int) $t['decimals'];
        $expected = TronUnits::centsToUnits($invoice->feeCents(), $decimals);
        $band = $this->intents->bandFor($expected, $decimals);

        // One transaction may carry several transfers; take the one that could
        // be this invoice's. TronGrid reports ids in lower-case hex, which is
        // what normaliseHash() produces.
        $rows = TronTransfer::forNetwork($network)
            ->where('tx_hash', $hash)
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            return $this->refusal(404, 'TX_NOT_FOUND',
                'We have not seen this transaction arrive yet. Exchange withdrawals can take a few minutes; try again shortly.');
        }

        $fits = fn (TronTransfer $tr) => $tr->value_units !== null
            && bccomp((string) $tr->value_units, $band['floor'], 0) >= 0
            && bccomp((string) $tr->value_units, $band['ceiling'], 0) <= 0;

        $credible = $rows->filter(fn (TronTransfer $tr) => $tr->isCredible());
        if ($credible->isEmpty()) {
            return $this->refusal(422, 'NOT_A_PAYMENT',
                'This transaction is not a USDT payment to our address, so it cannot pay an invoice.');
        }

        $transfer = $credible->first($fits);
        if ($transfer === null) {
            $got = TronUnits::format((string) ($credible->first()->value_units ?? '0'), $decimals);

            return $this->refusal(422, 'AMOUNT_MISMATCH', sprintf(
                'This transaction is for %s %s, which does not match this invoice. Please contact support so we can place it by hand.',
                $got, $t['asset'],
            ));
        }

        if ($transfer->status === TronTransfer::STATUS_IGNORED) {
            return $this->refusal(422, 'TX_NOT_CLAIMABLE',
                'Our team has already reviewed this transaction. Please contact support.');
        }

        $createdMs = $invoice->created_at ? $invoice->created_at->getTimestampMs() : 0;
        if ((int) $transfer->block_timestamp < $createdMs) {
            return $this->refusal(422, 'TX_BEFORE_INVOICE',
                'This transaction was sent before this invoice was issued, so it cannot be its payment.');
        }

        // Take it ATOMICALLY: two candidates pasting at the same instant must
        // not both settle from one payment. The loser is told it is taken.
        $by = 'claim:'.$claimantUniId;
        $taken = TronTransfer::whereKey($transfer->id)
            ->where('status', TronTransfer::STATUS_UNMATCHED)
            ->update([
                'status' => TronTransfer::STATUS_SETTLED,
                'invoice_id' => $invoice->id,
                'settled_by' => $by,
                'settled_at' => now(),
                'updated_at' => now(),
            ]);

        if ($taken !== 1) {
            $transfer->refresh();

            if ((int) $transfer->invoice_id === $invoice->id) {
                return ['ok' => true, 'status' => 200, 'code' => 'ALREADY_YOURS', 'settled' => false,
                    'message' => 'This payment is already on this invoice.'];
            }

            return $this->dispute($transfer, $invoice, $network, $claimantUniId);
        }

        $intent = PaymentIntent::forNetwork($network)
            ->where('invoice_id', $invoice->id)
            ->whereIn('status', [PaymentIntent::STATUS_OPEN, PaymentIntent::STATUS_EXPIRED])
            ->orderByDesc('id')
            ->first();

        if ($intent !== null) {
            $this->intents->claim($intent, (string) $transfer->tx_hash, (string) $transfer->value_units, true);
        }

        $settled = $this->watcher->attribute($transfer->refresh(), $invoice, $intent, $by);

        $this->record($transfer, $invoice, $network, $claimantUniId, TronPaymentClaim::OUTCOME_ACCEPTED, null);

        return ['ok' => true, 'status' => 200, 'code' => 'CLAIM_ACCEPTED', 'settled' => $settled,
            'message' => 'Thank you, your payment is confirmed and this invoice is now paid.'];
    }

    /**
     * Admin: mark an open dispute dealt with. Correcting the invoices themselves
     * stays with the existing tools (invoice history); this only closes the
     * alarm, with a note saying what was decided.
     */
    public function resolve(TronPaymentClaim $claim, string $adminUniId, ?string $note): void
    {
        $claim->forceFill([
            'resolved_at' => now(),
            'resolved_by' => 'admin:'.$adminUniId,
            'resolution_note' => $note,
        ])->save();

        self::forgetOverview();
    }

    /** The Overview is cached for a minute; a dispute must not wait for it. */
    public static function forgetOverview(): void
    {
        Cache::forget(AdminInsightsController::CACHE_PREFIX.'overview');
    }

    // ---- helpers ---------------------------------------------------------

    /** @return array{ok: false, status: int, code: string, message: string} */
    private function dispute(TronTransfer $transfer, Invoice $invoice, string $network, string $claimantUniId): array
    {
        $created = $this->record(
            $transfer, $invoice, $network, $claimantUniId,
            TronPaymentClaim::OUTCOME_DISPUTED, $transfer->invoice_id !== null ? (int) $transfer->invoice_id : null,
        );

        // Once per (payment, invoice): pasting again must not mail the team again.
        if ($created) {
            self::forgetOverview();
            $this->notifyTeam($transfer, $invoice, $claimantUniId);

            Log::warning('TRON payment claimed by a second customer.', [
                'transfer_id' => $transfer->id,
                'tx_hash' => $transfer->tx_hash,
                'holder_invoice' => $transfer->invoice_id,
                'claimant_invoice' => $invoice->id,
            ]);
        }

        return $this->refusal(409, 'CLAIM_DISPUTED',
            'This payment is already matched to another invoice. Our team has been alerted, will review it and contact you.');
    }

    /** @return bool whether a new row was written */
    private function record(
        TronTransfer $transfer,
        Invoice $invoice,
        string $network,
        string $uniId,
        string $outcome,
        ?int $againstInvoiceId,
    ): bool {
        try {
            TronPaymentClaim::create([
                'tron_transfer_id' => $transfer->id,
                'invoice_id' => $invoice->id,
                'user_id' => $uniId,
                'network' => $network,
                'tx_hash' => (string) $transfer->tx_hash,
                'outcome' => $outcome,
                'against_invoice_id' => $againstInvoiceId,
            ]);

            return true;
        } catch (QueryException) {
            // UNIQUE(transfer, invoice): this customer already claimed it.
            return false;
        }
    }

    /** Best-effort, like every team notice: the dispute row is the fact. */
    private function notifyTeam(TronTransfer $transfer, Invoice $claimantInvoice, string $claimantUniId): void
    {
        $to = AccountMail::teamRecipients();
        if ($to === []) {
            Log::warning('TronPaymentClaims: no mail.admin_address configured — dispute notice skipped.', [
                'transfer_id' => $transfer->id,
            ]);

            return;
        }

        try {
            $t = $this->env->tron($transfer->network);
            $holderInvoice = $transfer->invoice_id !== null ? Invoice::find($transfer->invoice_id) : null;
            $holder = $holderInvoice ? UserCredential::where('uni_id', $holderInvoice->user_id)->first() : null;
            $claimant = UserCredential::where('uni_id', $claimantUniId)->first();

            Mail::to($to)->send(new PaymentDisputedNotice(
                amount: TronUnits::format((string) $transfer->value_units, (int) $t['decimals']),
                asset: (string) $t['asset'],
                network: (string) $t['label'],
                txHash: (string) $transfer->tx_hash,
                receivedAt: ($transfer->created_at ?? now())->format('j M Y, H:i').' UTC',
                holderName: (string) ($holder->name ?? 'Unknown customer'),
                holderEmail: (string) ($holder->email ?? '—'),
                holderInvoiceId: (int) ($holderInvoice->id ?? 0),
                holderPeriod: $holderInvoice ? $this->period($holderInvoice) : '—',
                firstVia: $this->via((string) $transfer->settled_by),
                claimantName: (string) ($claimant->name ?? 'Unknown customer'),
                claimantEmail: (string) ($claimant->email ?? '—'),
                claimantInvoiceId: $claimantInvoice->id,
                claimantPeriod: $this->period($claimantInvoice),
                claimedAt: now()->format('j M Y, H:i').' UTC',
            ));
        } catch (Throwable $e) {
            Log::warning('TronPaymentClaims: dispute notice failed to send.', [
                'transfer_id' => $transfer->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function via(string $settledBy): string
    {
        return match (true) {
            $settledBy === 'watcher' => 'the automatic matcher',
            str_starts_with($settledBy, 'claim:') => 'that customer\'s transaction ID',
            str_starts_with($settledBy, 'admin:') => 'an admin',
            default => $settledBy,
        };
    }

    private function period(Invoice $invoice): string
    {
        return Carbon::createFromFormat('!Y-m', (string) $invoice->month_year)->format('F Y');
    }

    /** @return array{state: string, amount: string, asset: string, seen_at: ?string, message: string} */
    private function prompt(string $state, ?TronTransfer $transfer, array $t, int $decimals, string $message): array
    {
        return [
            'state' => $state,
            'amount' => $transfer?->value_units !== null
                ? TronUnits::format((string) $transfer->value_units, $decimals)
                : '',
            'asset' => (string) $t['asset'],
            'seen_at' => $transfer?->created_at?->toIso8601String(),
            'message' => $message,
        ];
    }

    /** @return array{ok: false, status: int, code: string, message: string} */
    private function refusal(int $status, string $code, string $message): array
    {
        return ['ok' => false, 'status' => $status, 'code' => $code, 'message' => $message];
    }
}
