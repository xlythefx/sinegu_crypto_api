<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\PaymentIntent;
use App\Services\Payments\TronIntentService;
use App\Services\Payments\TronUnits;
use RuntimeException;

/**
 * Amount reservations — the mechanism that makes one shared receiving address
 * workable. The UNIQUE index is the guarantee under concurrency; these tests
 * check that nothing routes around it.
 */
class TronIntentTest extends PaymentTestCase
{
    private string $uniId;

    private int $accountId;

    private Invoice $invoice;

    private TronIntentService $intents;

    protected function setUp(): void
    {
        parent::setUp();

        $this->uniId = $this->makeUser();
        $this->accountId = $this->makeAccount($this->uniId);
        $this->invoice = $this->makeInvoice($this->accountId, $this->uniId);
        $this->intents = app(TronIntentService::class);
    }

    public function test_it_reserves_the_exact_invoice_amount(): void
    {
        $intent = $this->intents->openFor($this->invoice, 'nile');

        $this->assertFalse($intent->reused);
        $this->assertSame('nile', $intent->network);
        $this->assertSame($this->tronNileAddress, $intent->address);
        $this->assertSame($this->tronNileContract, $intent->contract_address);
        $this->assertSame(6, (int) $intent->decimals);
        $this->assertSame('12340000', (string) $intent->expected_units);
        // Open reservations hold their figure; the unique index does the rest.
        $this->assertSame('12340000', (string) $intent->open_units);
        $this->assertEquals(12.34, (float) $intent->expected_usd);

        // $1.00 flat floor beats 1% of $12.34, because an exchange withdrawal
        // fee is an absolute amount and would otherwise refuse the payment.
        $this->assertSame('1000000', (string) $intent->shortfall_units);
        $this->assertSame('617000', (string) $intent->overpay_units);
    }

    /**
     * A trader refreshing the pay sheet, or opening two tabs, must not mint two
     * reservations — that would make their own payment ambiguous against itself.
     */
    public function test_it_is_idempotent_per_invoice_and_network(): void
    {
        $first = $this->intents->openFor($this->invoice, 'nile');
        $second = $this->intents->openFor($this->invoice, 'nile');

        $this->assertTrue($second->reused);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, PaymentIntent::count());
    }

    /**
     * Two invoices may wait for the same figure at once (2026-10-08). The
     * second used to be refused for up to an hour; a payment either could own
     * is now held by the watcher and both customers are asked for the TXID.
     */
    public function test_a_second_invoice_may_wait_for_the_same_figure(): void
    {
        $other = $this->makeInvoice($this->accountId, $this->uniId, ['month_year' => '2026-05']);

        $first = $this->intents->openFor($this->invoice, 'nile');
        $second = $this->intents->openFor($other, 'nile');

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame((string) $first->expected_units, (string) $second->expected_units);
        $this->assertSame(2, PaymentIntent::open()->count());
    }

    /** Once the first reservation lapses its figure is available again. */
    public function test_an_expired_reservation_frees_its_figure(): void
    {
        $other = $this->makeInvoice($this->accountId, $this->uniId, ['month_year' => '2026-05']);

        $first = $this->intents->openFor($this->invoice, 'nile');
        $first->forceFill(['expires_at' => now()->subMinute()])->save();

        $second = $this->intents->openFor($other, 'nile');

        $this->assertSame((string) $first->expected_units, (string) $second->expected_units);
        $this->assertSame(PaymentIntent::STATUS_EXPIRED, $first->fresh()->status);
        $this->assertNull($first->fresh()->open_units);
    }

    /** With a fingerprint configured, clashes resolve instead of refusing. */
    public function test_a_configured_fingerprint_disambiguates_identical_amounts(): void
    {
        config(['payments.tron.fingerprint_units' => 9999]);
        $other = $this->makeInvoice($this->accountId, $this->uniId, ['month_year' => '2026-05']);

        $first = $this->intents->openFor($this->invoice, 'nile');
        $second = $this->intents->openFor($other, 'nile');

        $this->assertNotSame((string) $first->expected_units, (string) $second->expected_units);
        $this->assertSame(2, PaymentIntent::open()->count());
    }

    public function test_expiring_releases_the_reservation_but_keeps_the_expectation(): void
    {
        $intent = $this->makeIntent($this->invoice, ['expires_at' => now()->subHour()]);

        $released = $this->intents->expireStale('nile');

        $this->assertSame(1, $released);
        $fresh = $intent->fresh();
        $this->assertSame(PaymentIntent::STATUS_EXPIRED, $fresh->status);
        $this->assertNull($fresh->open_units);
        // Survives on purpose: this is what lets a late payment still be traced
        // back to the invoice it was meant for.
        $this->assertSame('12340000', (string) $fresh->expected_units);
    }

    public function test_candidates_are_bounded_by_the_tolerance_band(): void
    {
        $this->makeIntent($this->invoice);   // 11.34 .. 12.957

        $inBand = fn (string $units) => $this->intents
            ->candidatesFor('nile', $this->tronNileAddress, $units)
            ->count();

        $this->assertSame(1, $inBand('12340000'));   // exact
        $this->assertSame(1, $inBand('11340000'));   // the floor
        $this->assertSame(1, $inBand('12957000'));   // the ceiling
        $this->assertSame(0, $inBand('11339999'));   // a unit short
        $this->assertSame(0, $inBand('12957001'));   // a unit over
    }

    public function test_a_closed_intent_is_only_a_candidate_when_asked_for(): void
    {
        $intent = $this->makeIntent($this->invoice);
        $intent->forceFill(['status' => PaymentIntent::STATUS_EXPIRED, 'open_units' => null])->save();

        $this->assertSame(0, $this->intents->candidatesFor('nile', $this->tronNileAddress, '12340000')->count());
        $this->assertSame(1, $this->intents->candidatesFor('nile', $this->tronNileAddress, '12340000', true)->count());
    }

    /**
     * The scheduled watcher and a hand-run `payments:watch-tron` can be inside
     * the same match at once, and withoutOverlapping() does not cover that pair.
     * Only the writer whose UPDATE changed a row may proceed.
     */
    public function test_only_one_claimant_can_take_an_intent(): void
    {
        $intent = $this->makeIntent($this->invoice);

        $this->assertTrue($this->intents->claim($intent, 'tx-one', '12340000'));
        $this->assertFalse($this->intents->claim($intent, 'tx-two', '12340000'));

        $fresh = $intent->fresh();
        $this->assertSame('tx-one', $fresh->tx_hash);
        $this->assertNull($fresh->open_units);
    }

    public function test_an_unconfigured_network_refuses_by_name(): void
    {
        config(['payments.tron.networks.nile.address' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TRON_NOT_CONFIGURED');
        $this->intents->openFor($this->invoice, 'nile');
    }

    /** A checksum failure is a different problem from "not set up yet". */
    public function test_a_mistyped_address_refuses_by_a_different_name(): void
    {
        config(['payments.tron.networks.nile.address' => 'T9yD14Nj9j7xAB4dbGeiX9h8unkKT76qbX']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TRON_BAD_ADDRESS');
        $this->intents->openFor($this->invoice, 'nile');
    }

    /**
     * The flat $1.00 shortfall is larger than a small invoice. Clamped so the
     * accepted floor can never go below zero and let dust settle anything.
     */
    public function test_the_shortfall_never_exceeds_the_amount_owed(): void
    {
        $small = $this->makeInvoice($this->accountId, $this->uniId, [
            'month_year' => '2026-04', 'total_fee' => 0.40,
        ]);

        $intent = $this->intents->openFor($small, 'nile');

        $this->assertSame(TronUnits::centsToUnits(40, 6), (string) $intent->expected_units);
        $this->assertSame((string) $intent->expected_units, (string) $intent->shortfall_units);
        $this->assertSame('0', $intent->floorUnits());
    }

    // ---- a fee that changes under an open reservation ---------------------

    /**
     * An admin edits `total_fee` (or regenerates the invoice) while a quote is
     * open. The old figure must stop being matchable: the watcher settles
     * whatever the intent reserved, and the invoice would otherwise be marked
     * paid at the new fee for the old money.
     */
    public function test_a_changed_fee_supersedes_the_open_reservation(): void
    {
        $first = $this->intents->openFor($this->invoice, 'nile');

        $this->invoice->forceFill(['total_fee' => 15.00])->save();
        $second = $this->intents->openFor($this->invoice, 'nile');

        $this->assertNotSame($first->id, $second->id);
        $this->assertFalse($second->reused);
        $this->assertSame('15000000', (string) $second->expected_units);
        $this->assertSame('15000000', (string) $second->open_units);
        $this->assertEquals(15.00, (float) $second->expected_usd);

        $stale = $first->fresh();
        $this->assertSame(TronIntentService::STATUS_SUPERSEDED, $stale->status);
        // The reservation is released; the expectation survives for the admin
        // screen's late-payment hint, exactly as an expired intent's does.
        $this->assertNull($stale->open_units);
        $this->assertSame('12340000', (string) $stale->expected_units);

        $this->assertSame(1, PaymentIntent::open()->count());
    }

    /** Superseding must actually free the figure, or the UNIQUE index still holds it. */
    public function test_a_superseded_figure_is_free_for_another_invoice(): void
    {
        $other = $this->makeInvoice($this->accountId, $this->uniId, ['month_year' => '2026-05']);

        $this->intents->openFor($this->invoice, 'nile');
        $this->invoice->forceFill(['total_fee' => 15.00])->save();
        $this->intents->openFor($this->invoice, 'nile');

        $reissued = $this->intents->openFor($other, 'nile');

        $this->assertSame('12340000', (string) $reissued->expected_units);
        $this->assertSame(2, PaymentIntent::open()->count());
    }

    /** A re-save that does not change the cents is not a change. */
    public function test_an_unchanged_fee_reuses_the_same_reservation(): void
    {
        $first = $this->intents->openFor($this->invoice, 'nile');

        $this->invoice->forceFill(['total_fee' => '12.340'])->save();
        $second = $this->intents->openFor($this->invoice, 'nile');

        $this->assertTrue($second->reused);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(PaymentIntent::STATUS_OPEN, $first->fresh()->status);
        $this->assertSame(1, PaymentIntent::count());
    }

    /** Two networks are two independent spaces; the same figure is free in both. */
    public function test_the_same_figure_may_be_reserved_on_two_networks(): void
    {
        config([
            'payments.tron.networks.mainnet.contract' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
        ]);

        $nile = $this->intents->openFor($this->invoice, 'nile');
        $mainnet = $this->intents->openFor($this->invoice, 'mainnet');

        $this->assertSame((string) $nile->expected_units, (string) $mainnet->expected_units);
        $this->assertNotSame($nile->id, $mainnet->id);
        $this->assertSame(2, PaymentIntent::open()->count());
    }
}
