<?php

namespace App\Services\Payments;

use App\Models\Invoice;
use App\Models\PaymentIntent;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Issues and claims the amount reservations that make a shared receiving
 * address workable. See the payment_intents migration for why the amount is the
 * correlation key at all.
 */
class TronIntentService
{
    /**
     * An open intent closed because the invoice it quoted for changed its fee
     * underneath it. Listed in the payment_intents migration's status comment
     * from day one; the model constant has not been added yet because
     * PaymentIntent is outside this change's scope — move it there when that
     * file is next touched.
     */
    public const STATUS_SUPERSEDED = 'superseded';

    public function __construct(private PaymentEnvironment $env) {}

    /**
     * The open intent for this invoice on this network, minting one if there
     * isn't one already.
     *
     * IDEMPOTENT ON PURPOSE. A trader refreshing the pay sheet, or opening it in
     * two tabs, must not mint two reservations — each one consumes a figure out
     * of a space that is deliberately narrow, and two open intents for one
     * invoice would make its own payment ambiguous against itself.
     * `$intent->reused` tells the caller which happened.
     *
     * Reused ONLY WHILE ITS FIGURE IS STILL THE INVOICE'S FIGURE. An admin
     * editing `total_fee`, or regenerating the invoice, while a reservation is
     * open would otherwise leave the old amount matchable: the watcher settles
     * whatever the intent reserved, and the invoice would be marked paid at the
     * new fee for the old money. So a stale reservation is superseded — its
     * `open_units` released, its `expected_units` kept for the admin screen's
     * late-payment hints — and a fresh one minted at the current fee.
     *
     * @throws RuntimeException  message is the error code the controller returns
     */
    public function openFor(Invoice $invoice, string $network): PaymentIntent
    {
        $t = $this->env->tron($network);

        if (! $t['configured']) {
            throw new RuntimeException(
                $t['address'] !== '' && ! $t['address_valid']
                    ? 'TRON_BAD_ADDRESS'
                    : 'TRON_NOT_CONFIGURED'
            );
        }

        // Release anything that has run out of time first, so its figure is
        // available again before we look for a free one.
        $this->expireStale($network);

        $existing = PaymentIntent::forNetwork($network)
            ->open()
            ->where('invoice_id', $invoice->id)
            ->where('address', $t['address'])
            ->where('expires_at', '>', now())
            ->orderByDesc('id')
            ->first();

        if ($existing && $this->usdToCents($existing->expected_usd) === $invoice->feeCents()) {
            $existing->reused = true;

            return $existing;
        }

        if ($existing) {
            $this->supersede($existing);
        }

        return $this->mint($invoice, $t);
    }

    /**
     * Close a reservation whose figure the invoice no longer owes. Conditional
     * on it still being open, like claim(): the watcher may have consumed it
     * between our read and this write, and a settled intent must keep its
     * settled status and tx_hash.
     */
    private function supersede(PaymentIntent $intent): void
    {
        PaymentIntent::whereKey($intent->getKey())
            ->where('status', PaymentIntent::STATUS_OPEN)
            ->update([
                'status' => self::STATUS_SUPERSEDED,
                'open_units' => null,
                'updated_at' => now(),
            ]);
    }

    /**
     * `expected_usd` back to the cents it was issued from, so it can be compared
     * with Invoice::feeCents() exactly. Same bcmath half-up as feeCents(): the
     * column is decimal(20,8), which MySQL hands back as a string and SQLite as
     * a float, and `(int) round($usd * 100)` would disagree with feeCents() on
     * a value like 12.345.
     */
    private function usdToCents(string|float|int|null $usd): int
    {
        $usd = (string) ($usd ?? '0');
        if ($usd === '' || ! is_numeric($usd)) {
            return 0;
        }

        $scaled = bcmul($usd, '100', 8);
        $rounded = bccomp($scaled, '0', 8) >= 0
            ? bcadd($scaled, '0.5', 0)
            : bcsub($scaled, '0.5', 0);

        return (int) $rounded;
    }

    /**
     * The accepted band around an expected amount — THE ONE DEFINITION of the
     * tolerance. mint() stores it on the row, and the watcher reads it back when
     * an admin attributes by hand, so "inside tolerance" means the same thing on
     * the automatic path and the manual one. A second derivation anywhere else
     * is how the two would drift.
     *
     * Floor and ceiling are clamped the way floorUnits()/ceilingUnits() behave
     * on a stored intent: the shortfall never exceeds the amount itself.
     *
     * @return array{expected: string, shortfall: string, overpay: string, floor: string, ceiling: string}
     */
    public function bandFor(string $expectedUnits, int $decimals): array
    {
        $shortfall = $this->shortfallUnits($expectedUnits, $decimals);
        $overpay = TronUnits::pctUnits($expectedUnits, (float) config('payments.tron.overpay_pct', 5.0));

        return [
            'expected' => $expectedUnits,
            'shortfall' => $shortfall,
            'overpay' => $overpay,
            'floor' => bcsub($expectedUnits, $shortfall, 0),
            'ceiling' => bcadd($expectedUnits, $overpay, 0),
        ];
    }

    /**
     * Insert the reservation.
     *
     * NEVER REFUSES ANY MORE (2026-10-08). Two invoices may now wait for the
     * same figure at once: a payment either of them could own is held by the
     * watcher and both customers are asked for their transaction ID
     * (TronPaymentClaims). Refusing the second one locked a customer out of
     * paying for up to an hour because a stranger owed the same sum.
     *
     * With a fingerprint configured it still TRIES for a figure nobody holds,
     * so the common case stays automatic — best effort, since a collision is
     * no longer unsafe, only slower.
     */
    private function mint(Invoice $invoice, array $t): PaymentIntent
    {
        $cents = $invoice->feeCents();
        $decimals = (int) $t['decimals'];
        $base = TronUnits::centsToUnits($cents, $decimals);

        $fingerprintMax = (int) config('payments.tron.fingerprint_units', 0);
        $attempts = max(1, (int) config('payments.tron.fingerprint_attempts', 8));
        $ttl = (int) config('payments.tron.intent_ttl', 3600);

        $units = $base;
        if ($fingerprintMax > 0) {
            for ($attempt = 0; $attempt < $attempts; $attempt++) {
                $units = bcadd($base, (string) random_int(1, $fingerprintMax), 0);
                if (! $this->figureIsHeld($t, $units)) {
                    break;
                }
            }
        }

        $band = $this->bandFor($units, $decimals);

        $intent = PaymentIntent::create([
            'provider' => 'tron',
            'network' => $t['network'],
            'invoice_id' => $invoice->id,
            'user_id' => $invoice->user_id,
            'account_id' => $invoice->account_id,
            'address' => $t['address'],
            'contract_address' => $t['contract'],
            'asset' => $t['asset'],
            'decimals' => $decimals,
            'expected_units' => $units,
            'expected_usd' => $cents / 100,
            'shortfall_units' => $band['shortfall'],
            'overpay_units' => $band['overpay'],
            'open_units' => $units,
            'status' => PaymentIntent::STATUS_OPEN,
            'expires_at' => now()->addSeconds($ttl),
        ]);

        $intent->reused = false;

        return $intent;
    }

    private function figureIsHeld(array $t, string $units): bool
    {
        return PaymentIntent::forNetwork($t['network'])
            ->open()
            ->where('address', $t['address'])
            ->where('open_units', $units)
            ->exists();
    }

    /**
     * How far short a payment may land and still settle.
     *
     * Floored at an absolute figure because every major exchange DEDUCTS ITS
     * WITHDRAWAL FEE FROM THE AMOUNT THE CUSTOMER TYPES — a trader who enters
     * exactly what we display arrives short by that fee, so a purely
     * proportional tolerance refuses every small invoice paid from an exchange.
     *
     * Clamped to the expected amount so the accepted floor can never go
     * negative on an invoice smaller than the flat minimum.
     */
    private function shortfallUnits(string $units, int $decimals): string
    {
        $pct = TronUnits::pctUnits($units, (float) config('payments.tron.shortfall_pct', 1.0));
        $minUsd = (float) config('payments.tron.shortfall_min_usd', 1.0);
        $flat = TronUnits::centsToUnits((int) round($minUsd * 100), $decimals);

        $shortfall = bccomp($pct, $flat, 0) >= 0 ? $pct : $flat;

        return bccomp($shortfall, $units, 0) > 0 ? $units : $shortfall;
    }

    /**
     * Close intents past their deadline, releasing the figures they reserved.
     *
     * `open_units` goes NULL (that is the reservation), but `expected_units`
     * stays — which is what lets a payment arriving an hour late still be traced
     * back to the invoice it was meant for on the admin screen.
     *
     * @return int  how many were released
     */
    public function expireStale(?string $network = null): int
    {
        $query = PaymentIntent::open()->where('expires_at', '<=', now());

        if ($network !== null) {
            $query->forNetwork($network);
        }

        return $query->update([
            'status' => PaymentIntent::STATUS_EXPIRED,
            'open_units' => null,
            'updated_at' => now(),
        ]);
    }

    /**
     * Intents whose accepted band contains $units.
     *
     * The band arithmetic is done in PHP rather than SQL deliberately: these are
     * UNSIGNED BIGINT columns, and `expected_units - shortfall_units` in MySQL
     * either wraps or errors the moment the shortfall exceeds the expectation.
     * Open intents are few (they live an hour), so loading and filtering them is
     * cheaper than the class of bug that avoids.
     *
     * @return Collection<int, PaymentIntent>
     */
    public function candidatesFor(
        string $network,
        string $address,
        string $units,
        bool $includeClosed = false
    ): Collection {
        $query = PaymentIntent::forNetwork($network)->where('address', $address);

        if (! $includeClosed) {
            $query->open()->where('expires_at', '>', now());
        }

        return $query->orderByDesc('id')->get()->filter(
            fn (PaymentIntent $intent) => bccomp($units, $intent->floorUnits(), 0) >= 0
                && bccomp($units, $intent->ceilingUnits(), 0) <= 0
        )->values();
    }

    /**
     * Who could own a payment of $units, ONE INTENT PER INVOICE — what the
     * watcher decides on.
     *
     * Open reservations, as before, PLUS reservations that expired within
     * `late_match_hours` on an invoice that is still unpaid. That second half
     * is the whole fix for late payments: until 2026-10-08 a timer running out
     * made the system forget its customer was paying, so their withdrawal,
     * clearing after it, settled whoever else was waiting for a similar
     * amount. Now it makes the payment ambiguous instead, and the watcher holds
     * it for the transaction-ID question.
     *
     * One entry per invoice, the open intent preferred: two tabs, or a renew
     * after expiry, give one invoice several intents, and an invoice must never
     * read as ambiguous against itself.
     *
     * @return Collection<int, PaymentIntent>
     */
    public function matchCandidates(string $network, string $address, string $units): Collection
    {
        $lateHours = max(0, (int) config('payments.tron.late_match_hours', 24));

        $inBand = fn (PaymentIntent $intent) => bccomp($units, $intent->floorUnits(), 0) >= 0
            && bccomp($units, $intent->ceilingUnits(), 0) <= 0;

        $open = $this->candidatesFor($network, $address, $units);

        $late = $lateHours === 0 ? collect() : PaymentIntent::forNetwork($network)
            ->where('address', $address)
            ->where('status', PaymentIntent::STATUS_EXPIRED)
            ->where('expires_at', '>=', now()->subHours($lateHours))
            ->orderByDesc('id')
            ->get()
            ->filter($inBand);

        if ($late->isNotEmpty()) {
            // A reservation that expired on an invoice since paid another way
            // is nobody waiting.
            $paid = Invoice::whereIn('id', $late->pluck('invoice_id')->unique()->all())
                ->get()
                ->filter(fn (Invoice $invoice) => $invoice->isPaid())
                ->pluck('id')
                ->all();
            $late = $late->reject(fn (PaymentIntent $i) => in_array($i->invoice_id, $paid));
        }

        return $open->concat($late)
            ->unique('invoice_id')   // open intents come first, so they win
            ->values();
    }

    /**
     * Close every other open reservation of an invoice that has just been
     * paid, so a leftover one (a second tab, a renew) cannot make a stranger's
     * payment ambiguous later.
     */
    public function releaseSiblings(int $invoiceId, ?int $keepId = null): void
    {
        PaymentIntent::open()
            ->where('invoice_id', $invoiceId)
            ->when($keepId !== null, fn ($q) => $q->whereKeyNot($keepId))
            ->update([
                'status' => PaymentIntent::STATUS_CANCELLED,
                'open_units' => null,
                'updated_at' => now(),
            ]);
    }

    /**
     * Take ownership of an intent for a specific transfer.
     *
     * A conditional UPDATE, not a read-then-write: the scheduled watcher and a
     * hand-run `php artisan payments:watch-tron` can be inside the same match at
     * the same moment, and withoutOverlapping() does not cover that pair. Only
     * the writer whose UPDATE actually changed a row proceeds — the same "first
     * writer owns provenance" rule InvoiceService::settle() applies one level
     * down.
     *
     * `$allowExpired` is for a customer confirming a LATE payment with its
     * transaction ID: their reservation ran out before the money arrived, and
     * it is still theirs.
     */
    public function claim(PaymentIntent $intent, string $txHash, string $receivedUnits, bool $allowExpired = false): bool
    {
        $statuses = $allowExpired
            ? [PaymentIntent::STATUS_OPEN, PaymentIntent::STATUS_EXPIRED]
            : [PaymentIntent::STATUS_OPEN];

        $affected = PaymentIntent::whereKey($intent->getKey())
            ->whereIn('status', $statuses)
            ->update([
                'status' => PaymentIntent::STATUS_SETTLED,
                'open_units' => null,
                'tx_hash' => $txHash,
                'received_units' => $receivedUnits,
                'settled_at' => now(),
                'updated_at' => now(),
            ]);

        if ($affected === 1) {
            $intent->refresh();

            return true;
        }

        return false;
    }
}
