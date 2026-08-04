<?php

namespace Tests\Feature;

use App\Models\PaymentEvent;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/payments/stripe/webhook.
 *
 * The recurring assertion here is that unprocessable deliveries answer 200.
 * Stripe retries any non-2xx for three days and then disables the endpoint, so
 * reporting a permanent condition as an error is a self-inflicted outage.
 */
class StripeWebhookTest extends PaymentTestCase
{
    private const URL = '/api/payments/stripe/webhook';

    private function sendEvent(array $event, ?string $signature = null)
    {
        $payload = json_encode($event);

        return $this->call(
            'POST', self::URL, [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => $signature ?? $this->stripeSignature($payload),
                'CONTENT_TYPE' => 'application/json'],
            $payload
        );
    }

    public function test_missing_signature_is_rejected(): void
    {
        $this->sendEvent($this->stripeEvent(), '')
            ->assertStatus(401)
            ->assertJson(['success' => false, 'error_code' => 'STRIPE_SIGNATURE_INVALID']);
    }

    public function test_signature_from_the_wrong_secret_is_rejected(): void
    {
        $event = $this->stripeEvent();
        $payload = json_encode($event);

        $this->sendEvent($event, $this->stripeSignature($payload, 'whsec_someone_else'))
            ->assertStatus(401)
            ->assertJson(['error_code' => 'STRIPE_SIGNATURE_INVALID']);
    }

    public function test_unconfigured_secret_fails_closed(): void
    {
        $this->usePayments('sandbox', ['payments.stripe.test.webhook_secret' => '']);

        $this->sendEvent($this->stripeEvent())
            ->assertStatus(503)
            ->assertJson(['error_code' => 'STRIPE_WEBHOOK_NOT_CONFIGURED']);
    }

    public function test_other_event_types_are_acknowledged_and_ignored(): void
    {
        $this->sendEvent($this->stripeEvent([], 'payment_intent.succeeded'))
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame('ignored', PaymentEvent::first()->outcome);
    }

    /** `stripe trigger` sends a session with no metadata at all. */
    public function test_session_without_an_invoice_reference_is_a_no_op(): void
    {
        $this->sendEvent($this->stripeEvent(['metadata' => [], 'client_reference_id' => null]))
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame('tracking_unknown', PaymentEvent::first()->outcome);
    }

    public function test_unpaid_session_does_not_settle(): void
    {
        $user = $this->makeUser();
        $invoice = $this->makeInvoice($this->makeAccount($user), $user);

        $this->sendEvent($this->stripeEvent([
            'payment_status' => 'unpaid',
            'metadata' => ['invoice_id' => (string) $invoice->id],
        ]))->assertOk();

        $this->assertSame('pending', $invoice->fresh()->status);
        $this->assertSame('not_processed', PaymentEvent::first()->outcome);
    }

    public function test_unknown_invoice_is_acknowledged(): void
    {
        $this->sendEvent($this->stripeEvent(['metadata' => ['invoice_id' => '99999']]))
            ->assertOk();

        $this->assertSame('invoice_not_found', PaymentEvent::first()->outcome);
    }

    public function test_amount_mismatch_answers_200_and_does_not_settle(): void
    {
        $user = $this->makeUser();
        $invoice = $this->makeInvoice($this->makeAccount($user), $user);

        $this->sendEvent($this->stripeEvent([
            'amount_total' => 1200,
            'metadata' => ['invoice_id' => (string) $invoice->id],
        ]))->assertOk();

        $this->assertSame('pending', $invoice->fresh()->status);
        $this->assertSame('amount_mismatch', PaymentEvent::first()->outcome);
    }

    public function test_wrong_currency_does_not_settle(): void
    {
        $user = $this->makeUser();
        $invoice = $this->makeInvoice($this->makeAccount($user), $user);

        $this->sendEvent($this->stripeEvent([
            'currency' => 'eur',
            'metadata' => ['invoice_id' => (string) $invoice->id],
        ]))->assertOk();

        $this->assertSame('pending', $invoice->fresh()->status);
        $this->assertSame('amount_mismatch', PaymentEvent::first()->outcome);
    }

    public function test_paid_session_settles_the_invoice_and_reenables_the_account(): void
    {
        $user = $this->makeUser();
        $accountId = $this->makeAccount($user, ['enabled' => 0]);
        $invoice = $this->makeInvoice($accountId, $user);

        $this->sendEvent($this->stripeEvent([
            'id' => 'cs_test_settle',
            'metadata' => ['invoice_id' => (string) $invoice->id],
        ]))->assertOk()->assertJson(['status' => 'paid', 'provider' => 'stripe']);

        $fresh = $invoice->fresh();
        $this->assertSame('paid', $fresh->status);
        $this->assertNotNull($fresh->paid_at);
        $this->assertSame('stripe', $fresh->payment_provider);
        $this->assertSame('cs_test_settle', $fresh->payment_reference);
        $this->assertSame(12.34, (float) $fresh->paid_amount);
        $this->assertSame(1, (int) DB::table('binance_accounts')->find($accountId)->enabled);
        $this->assertSame('paid', PaymentEvent::first()->outcome);
    }

    /** client_reference_id carries the invoice when metadata is stripped. */
    public function test_client_reference_id_is_used_when_metadata_is_absent(): void
    {
        $user = $this->makeUser();
        $invoice = $this->makeInvoice($this->makeAccount($user), $user);

        $this->sendEvent($this->stripeEvent([
            'metadata' => [],
            'client_reference_id' => (string) $invoice->id,
        ]))->assertOk();

        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_replaying_the_same_event_is_idempotent(): void
    {
        $user = $this->makeUser();
        $invoice = $this->makeInvoice($this->makeAccount($user), $user);
        $event = $this->stripeEvent(['metadata' => ['invoice_id' => (string) $invoice->id]]);

        $this->sendEvent($event)->assertOk();
        $paidAt = $invoice->fresh()->paid_at;

        $this->sendEvent($event)->assertOk()->assertJson(['duplicate' => true]);

        $this->assertSame(1, PaymentEvent::where('outcome', 'paid')->count());
        $this->assertEquals($paidAt, $invoice->fresh()->paid_at);
    }

    /** A different event for an already-paid invoice must not rewrite it. */
    public function test_second_event_for_a_paid_invoice_does_not_overwrite_provenance(): void
    {
        $user = $this->makeUser();
        $invoice = $this->makeInvoice($this->makeAccount($user), $user);

        $this->sendEvent($this->stripeEvent([
            'id' => 'cs_first',
            'metadata' => ['invoice_id' => (string) $invoice->id],
        ]))->assertOk();

        $this->sendEvent($this->stripeEvent([
            'id' => 'cs_second',
            'metadata' => ['invoice_id' => (string) $invoice->id],
        ]))->assertOk()->assertJson(['already_paid' => true]);

        $this->assertSame('cs_first', $invoice->fresh()->payment_reference);
    }
}
