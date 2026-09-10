<?php

namespace App\Services\Payments;

use App\Models\Invoice;
use App\Models\PaymentIntent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Issues and claims the amount reservations that make a shared receiving
 * address workable. See the payment_intents migration for why the amount is the
 * correlation key at all.
 */
class TronIntentService
{
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

        if ($existing) {
            $existing->reused = true;

            return $existing;
        }

        return $this->mint($invoice, $t);
    }

    /**
     * Insert the reservation, letting the UNIQUE index arbitrate rather than
     * checking first — a check-then-insert loses to a concurrent request, and
     * this index is the only thing standing between two invoices and the same
     * expected amount.
     */
    private function mint(Invoice $invoice, array $t): PaymentIntent
    {
        $cents = $invoice->feeCents();
        $decimals = (int) $t['decimals'];
        $base = TronUnits::centsToUnits($cents, $decimals);

        $fingerprintMax = (int) config('payments.tron.fingerprint_units', 0);
        $attempts = max(1, (int) config('payments.tron.fingerprint_attempts', 8));
        $ttl = (int) config('payments.tron.intent_ttl', 3600);

        // With the fingerprint off (the current posture) there is exactly one
        // candidate figure, so a collision is final rather than retryable —
        // which is the honest answer. Handing out a near-identical amount
        // instead would only turn a clean refusal now into a payment that
        // arrives later and cannot be attributed to either invoice, because the
        // tolerance bands overlap.
        $tries = $fingerprintMax > 0 ? $attempts : 1;

        for ($attempt = 0; $attempt < $tries; $attempt++) {
            $units = $fingerprintMax > 0
                ? bcadd($base, (string) random_int(1, $fingerprintMax), 0)
                : $base;

            $shortfall = $this->shortfallUnits($units, $decimals);
            $overpay = TronUnits::pctUnits($units, (float) config('payments.tron.overpay_pct', 5.0));

            try {
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
                    'shortfall_units' => $shortfall,
                    'overpay_units' => $overpay,
                    'open_units' => $units,
                    'status' => PaymentIntent::STATUS_OPEN,
                    'expires_at' => now()->addSeconds($ttl),
                ]);
            } catch (QueryException $e) {
                if (! $this->isUniqueViolation($e)) {
                    throw $e;
                }

                continue;
            }

            $intent->reused = false;

            return $intent;
        }

        // Someone else holds this figure. It frees itself when their intent
        // expires; the controller turns this into a 409 that says so.
        throw new RuntimeException('TRON_AMOUNT_UNAVAILABLE');
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

    /** MySQL 1062 / SQLite 19 — the same duplicate-key answer in two dialects. */
    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        $driverCode = (string) ($e->errorInfo[1] ?? '');

        return $sqlState === '23000'
            || $sqlState === '23505'
            || $driverCode === '1062'
            || $driverCode === '19';
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
     * Take ownership of an intent for a specific transfer.
     *
     * A conditional UPDATE, not a read-then-write: the scheduled watcher and a
     * hand-run `php artisan payments:watch-tron` can be inside the same match at
     * the same moment, and withoutOverlapping() does not cover that pair. Only
     * the writer whose UPDATE actually changed a row proceeds — the same "first
     * writer owns provenance" rule InvoiceService::settle() applies one level
     * down.
     */
    public function claim(PaymentIntent $intent, string $txHash, string $receivedUnits): bool
    {
        $affected = PaymentIntent::whereKey($intent->getKey())
            ->where('status', PaymentIntent::STATUS_OPEN)
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
