<?php

namespace Tests\Feature;

use App\Mail\PaymentDisputedNotice;
use App\Models\Invoice;
use App\Models\PaymentIntent;
use App\Models\TronPaymentClaim;
use App\Models\TronTransfer;
use Illuminate\Support\Facades\Mail;

/**
 * Two customers waiting for the same amount, and late payments
 * (2026-10-08): the watcher HOLDS a payment more than one invoice could own,
 * each possible owner is asked for the transaction ID, and a second customer
 * claiming a payment already placed opens a dispute the team is told about.
 *
 * Person A and person B each owe 12.34 on separate accounts — the case
 * Christian raised, at the fixture's fee.
 */
class TronPaymentClaimsTest extends PaymentTestCase
{
    private string $uniA;

    private string $uniB;

    private Invoice $invoiceA;

    private Invoice $invoiceB;

    protected function setUp(): void
    {
        parent::setUp();

        config(['payments.tron.public' => true, 'mail.admin_address' => 'team@pixel.test']);

        $this->uniA = $this->makeUser(['name' => 'Person A']);
        $this->uniB = $this->makeUser(['name' => 'Person B']);
        $this->invoiceA = $this->makeInvoice($this->makeAccount($this->uniA, ['enabled' => 0]), $this->uniA);
        $this->invoiceB = $this->makeInvoice($this->makeAccount($this->uniB, ['enabled' => 0]), $this->uniB);
    }

    private function scan(): void
    {
        $this->artisan('payments:watch-tron', ['--network' => 'nile'])->assertSuccessful();
    }

    private function hash(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function sheet(string $uniId, Invoice $invoice): \Illuminate\Testing\TestResponse
    {
        return $this->getJson("/api/payments/tron/intent/{$invoice->id}", $this->userHeaders($uniId));
    }

    private function claim(string $uniId, Invoice $invoice, string $txHash): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(
            "/api/payments/tron/intent/{$invoice->id}/claim",
            ['tx_hash' => $txHash],
            $this->userHeaders($uniId),
        );
    }

    /** B pays 12.34 while both A and B are waiting for 12.34. */
    private function holdBsPayment(): string
    {
        $this->makeIntent($this->invoiceA);
        $this->makeIntent($this->invoiceB);
        $txB = $this->hash();
        $this->fakeTron([$this->tronPage([$this->tronItem(['transaction_id' => $txB])])]);
        $this->scan();

        return $txB;
    }

    // ---- the agreed flow ------------------------------------------------

    public function test_two_customers_can_both_open_the_pay_sheet_for_the_same_amount(): void
    {
        $this->postJson('/api/payments/tron/intent', ['invoice_id' => $this->invoiceA->id], $this->userHeaders($this->uniA))->assertOk();
        $this->postJson('/api/payments/tron/intent', ['invoice_id' => $this->invoiceB->id], $this->userHeaders($this->uniB))->assertOk();

        $this->assertSame(2, PaymentIntent::open()->count());
    }

    public function test_a_payment_two_invoices_could_own_is_held_and_both_are_asked(): void
    {
        $this->holdBsPayment();

        $this->assertSame('pending', $this->invoiceA->fresh()->status);
        $this->assertSame('pending', $this->invoiceB->fresh()->status);

        $transfer = TronTransfer::firstOrFail();
        $this->assertSame(TronTransfer::STATUS_UNMATCHED, $transfer->status);
        $this->assertEqualsCanonicalizing([$this->invoiceA->id, $this->invoiceB->id], $transfer->candidate_invoice_ids);

        foreach ([[$this->uniA, $this->invoiceA], [$this->uniB, $this->invoiceB]] as [$uni, $invoice]) {
            $response = $this->sheet($uni, $invoice)
                ->assertOk()
                ->assertJsonPath('claim.state', 'needed')
                ->assertJsonPath('claim.amount', '12.340000');

            // The question must never carry its own answer.
            $this->assertStringNotContainsString($transfer->tx_hash, $response->getContent());
        }
    }

    public function test_the_payer_confirming_their_txid_settles_their_invoice_only(): void
    {
        $txB = $this->holdBsPayment();

        $this->claim($this->uniB, $this->invoiceB, $txB)
            ->assertOk()
            ->assertJsonPath('code', 'CLAIM_ACCEPTED')
            ->assertJsonPath('invoice_status', 'paid');

        $this->assertSame('paid', $this->invoiceB->fresh()->status);
        $this->assertSame($txB, $this->invoiceB->fresh()->payment_reference);
        $this->assertSame('pending', $this->invoiceA->fresh()->status);

        $transfer = TronTransfer::firstOrFail();
        $this->assertSame(TronTransfer::STATUS_SETTLED, $transfer->status);
        $this->assertSame($this->invoiceB->id, (int) $transfer->invoice_id);
        $this->assertSame('claim:'.$this->uniB, $transfer->settled_by);
        $this->assertSame(TronPaymentClaim::OUTCOME_ACCEPTED, TronPaymentClaim::firstOrFail()->outcome);

        // A is still asked, softly — if it was really theirs this is the only
        // way they would find out.
        $this->sheet($this->uniA, $this->invoiceA)->assertJsonPath('claim.state', 'claimed_by_other');
    }

    /** Once B's payment is placed, A's own one fits only A and settles itself. */
    public function test_the_other_customers_payment_then_settles_automatically(): void
    {
        $this->makeIntent($this->invoiceA);
        $this->makeIntent($this->invoiceB);
        $txB = $this->hash();
        $this->fakeTron([
            $this->tronPage([$this->tronItem(['transaction_id' => $txB])]),
            $this->tronPage([$this->tronItem(['transaction_id' => $this->hash()])]),
        ]);

        $this->scan();
        $this->claim($this->uniB, $this->invoiceB, $txB)->assertOk();
        $this->scan();

        $this->assertSame('paid', $this->invoiceA->fresh()->status);
        $this->assertSame('watcher', TronTransfer::orderByDesc('id')->firstOrFail()->settled_by);
    }

    // ---- disputes ----------------------------------------------------------

    public function test_a_second_customer_claiming_a_placed_payment_opens_a_dispute(): void
    {
        Mail::fake();
        $txB = $this->holdBsPayment();
        $this->claim($this->uniB, $this->invoiceB, $txB)->assertOk();

        // A pastes the same ID (it is public on the explorer).
        $this->claim($this->uniA, $this->invoiceA, $txB)
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'CLAIM_DISPUTED')
            ->assertJsonPath('claim.state', 'disputed');

        // Nothing moved: B stays paid, A stays unpaid, until a human decides.
        $this->assertSame('paid', $this->invoiceB->fresh()->status);
        $this->assertSame('pending', $this->invoiceA->fresh()->status);

        $dispute = TronPaymentClaim::where('outcome', TronPaymentClaim::OUTCOME_DISPUTED)->firstOrFail();
        $this->assertSame($this->invoiceA->id, (int) $dispute->invoice_id);
        $this->assertSame($this->invoiceB->id, (int) $dispute->against_invoice_id);

        Mail::assertSent(PaymentDisputedNotice::class, function (PaymentDisputedNotice $mail) use ($txB) {
            return $mail->hasTo('team@pixel.test')
                && $mail->txHash === $txB
                && $mail->holderName === 'Person B'
                && $mail->claimantName === 'Person A'
                && $mail->firstVia === 'that customer\'s transaction ID';
        });

        // Pasting again neither duplicates the row nor mails the team twice.
        $this->claim($this->uniA, $this->invoiceA, $txB)->assertStatus(409);
        Mail::assertSentCount(1);
        $this->assertSame(1, TronPaymentClaim::openDisputes()->count());
    }

    public function test_the_overview_counts_open_disputes_until_an_admin_resolves_them(): void
    {
        Mail::fake();
        $admin = $this->makeUser(['type' => 'admin']);
        $txB = $this->holdBsPayment();
        $this->claim($this->uniB, $this->invoiceB, $txB)->assertOk();

        $this->getJson('/api/admin/insights/overview', $this->userHeaders($admin))
            ->assertJsonPath('attention.disputed_payments', 0);

        $this->claim($this->uniA, $this->invoiceA, $txB)->assertStatus(409);

        // The claim busts the overview's minute-long cache.
        $this->getJson('/api/admin/insights/overview', $this->userHeaders($admin))
            ->assertJsonPath('attention.disputed_payments', 1);

        $this->getJson('/api/admin/tron-transfers?status=disputed', $this->userHeaders($admin))
            ->assertOk()
            ->assertJsonPath('counts.disputed', 1)
            ->assertJsonCount(1, 'transfers')
            ->assertJsonPath('transfers.0.disputed', true)
            ->assertJsonPath('transfers.0.claims.1.owner.name', 'Person A');

        $claimId = TronPaymentClaim::openDisputes()->value('id');
        $this->postJson("/api/admin/tron-transfers/claims/{$claimId}/resolve", ['note' => 'B showed the withdrawal record.'], $this->userHeaders($admin))
            ->assertOk()
            ->assertJsonPath('transfer.disputed', false);

        $this->getJson('/api/admin/insights/overview', $this->userHeaders($admin))
            ->assertJsonPath('attention.disputed_payments', 0);

        $resolved = TronPaymentClaim::findOrFail($claimId);
        $this->assertSame('admin:'.$admin, $resolved->resolved_by);
        $this->assertSame('B showed the withdrawal record.', $resolved->resolution_note);
    }

    public function test_a_read_only_collaborator_never_sees_the_dispute_count(): void
    {
        $collaborator = $this->makeUser(['type' => 'collaborator']);

        $response = $this->getJson('/api/admin/insights/overview', $this->userHeaders($collaborator))->assertOk();

        $this->assertArrayNotHasKey('disputed_payments', $response->json('attention'));
    }

    // ---- late payments (option 3) -------------------------------------------

    /**
     * B's timer ran out, A opened the sheet, then B's withdrawal cleared. It
     * used to settle A's invoice with B's money.
     */
    public function test_a_late_payment_is_held_rather_than_handed_to_whoever_is_waiting(): void
    {
        $this->makeIntent($this->invoiceB, [
            'status' => PaymentIntent::STATUS_EXPIRED, 'open_units' => null, 'expires_at' => now()->subMinutes(5),
        ]);
        $this->makeIntent($this->invoiceA);
        $txB = $this->hash();
        $this->fakeTron([$this->tronPage([$this->tronItem(['transaction_id' => $txB])])]);

        $this->scan();

        $this->assertSame('pending', $this->invoiceA->fresh()->status);
        $this->assertEqualsCanonicalizing(
            [$this->invoiceA->id, $this->invoiceB->id],
            TronTransfer::firstOrFail()->candidate_invoice_ids,
        );

        $this->claim($this->uniB, $this->invoiceB, $txB)->assertOk();
        $this->assertSame('paid', $this->invoiceB->fresh()->status);
        $this->assertSame(PaymentIntent::STATUS_SETTLED, PaymentIntent::where('invoice_id', $this->invoiceB->id)->value('status'));
    }

    /** Late with nobody else waiting: still the payer's to confirm, not lost. */
    public function test_a_late_payment_alone_asks_its_own_customer(): void
    {
        $this->makeIntent($this->invoiceB, [
            'status' => PaymentIntent::STATUS_EXPIRED, 'open_units' => null, 'expires_at' => now()->subMinutes(5),
        ]);
        $this->fakeTron([$this->tronPage([$this->tronItem()])]);

        $this->scan();

        $this->assertSame('pending', $this->invoiceB->fresh()->status);
        $this->sheet($this->uniB, $this->invoiceB)->assertJsonPath('claim.state', 'needed');
        $this->sheet($this->uniA, $this->invoiceA)->assertJsonPath('claim', null);
    }

    public function test_a_reservation_that_expired_long_ago_does_not_hold_a_payment(): void
    {
        $this->makeIntent($this->invoiceB, [
            'status' => PaymentIntent::STATUS_EXPIRED, 'open_units' => null, 'expires_at' => now()->subHours(25),
        ]);
        $this->makeIntent($this->invoiceA);
        $this->fakeTron([$this->tronPage([$this->tronItem()])]);

        $this->scan();

        $this->assertSame('paid', $this->invoiceA->fresh()->status);
    }

    public function test_an_expired_reservation_on_a_paid_invoice_is_nobody_waiting(): void
    {
        $this->invoiceB->forceFill(['status' => 'paid', 'paid_at' => now()])->save();
        $this->makeIntent($this->invoiceB, [
            'status' => PaymentIntent::STATUS_EXPIRED, 'open_units' => null, 'expires_at' => now()->subMinutes(5),
        ]);
        $this->makeIntent($this->invoiceA);
        $this->fakeTron([$this->tronPage([$this->tronItem()])]);

        $this->scan();

        $this->assertSame('paid', $this->invoiceA->fresh()->status);
    }

    /** Two tabs, or a renew, must never make an invoice ambiguous against itself. */
    public function test_one_invoices_own_reservations_are_never_ambiguous(): void
    {
        $this->makeIntent($this->invoiceA);
        $this->makeIntent($this->invoiceA);
        $this->fakeTron([$this->tronPage([$this->tronItem()])]);

        $this->scan();

        $this->assertSame('paid', $this->invoiceA->fresh()->status);
        // The leftover reservation is closed so it cannot hold a stranger's payment.
        $this->assertSame(0, PaymentIntent::open()->count());
    }

    // ---- what a claim refuses ----------------------------------------------

    public function test_a_tronscan_link_is_accepted_as_the_id(): void
    {
        $txB = $this->holdBsPayment();

        $this->claim($this->uniB, $this->invoiceB, "https://nile.tronscan.org/#/transaction/{$txB}")
            ->assertOk()
            ->assertJsonPath('code', 'CLAIM_ACCEPTED');
    }

    public function test_claims_are_refused_with_a_reason(): void
    {
        $this->holdBsPayment();

        $this->claim($this->uniB, $this->invoiceB, 'not-a-hash')
            ->assertStatus(422)->assertJsonPath('error_code', 'INVALID_TX_HASH');

        $this->claim($this->uniB, $this->invoiceB, $this->hash())
            ->assertStatus(404)->assertJsonPath('error_code', 'TX_NOT_FOUND');

        // Someone else's invoice is not found, whatever the ID.
        $this->claim($this->uniB, $this->invoiceA, TronTransfer::firstOrFail()->tx_hash)
            ->assertStatus(404)->assertJsonPath('error_code', 'INVOICE_NOT_FOUND');
    }

    public function test_a_payment_that_does_not_fit_the_invoice_is_refused(): void
    {
        $tx = $this->hash();
        $this->makeIntent($this->invoiceB);
        $this->fakeTron([$this->tronPage([$this->tronItem(['transaction_id' => $tx, 'value' => '5000000'])])]);
        $this->scan();

        $this->claim($this->uniB, $this->invoiceB, $tx)
            ->assertStatus(422)->assertJsonPath('error_code', 'AMOUNT_MISMATCH');
        $this->assertSame('pending', $this->invoiceB->fresh()->status);
    }

    public function test_a_payment_sent_before_the_invoice_existed_is_refused(): void
    {
        $tx = $this->hash();
        $this->makeIntent($this->invoiceB);
        $this->makeIntent($this->invoiceA);
        $this->fakeTron([$this->tronPage([$this->tronItem([
            'transaction_id' => $tx,
            'block_timestamp' => now()->subDays(2)->getTimestampMs(),
        ])])]);
        $this->scan();

        $this->claim($this->uniB, $this->invoiceB, $tx)
            ->assertStatus(422)->assertJsonPath('error_code', 'TX_BEFORE_INVOICE');
    }
}
