<?php

namespace Tests\Feature;

use App\Models\PaymentEvent;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/** POST /api/payments/coinsbuy/webhook. */
class CoinsbuyWebhookTest extends PaymentTestCase
{
    private const URL = '/api/payments/coinsbuy/webhook';

    private function sendCallback(array $payload)
    {
        return $this->postJson(self::URL, $payload);
    }

    /** An invoice whose id matches the default tracking_id (inv_1_…). */
    private function seedInvoice(array $overrides = [], array $accountOverrides = []): array
    {
        $user = $this->makeUser();
        $accountId = $this->makeAccount($user, $accountOverrides);
        $invoice = $this->makeInvoice($accountId, $user, $overrides);

        return [$invoice, $accountId];
    }

    private function tracking(int $invoiceId): string
    {
        return "inv_{$invoiceId}_1785312000";
    }

    public function test_bad_signature_is_rejected(): void
    {
        $payload = $this->coinsbuyPayload(['secret' => 'not-the-secret']);

        $this->sendCallback($payload)
            ->assertStatus(401)
            ->assertJson(['success' => false, 'error_code' => 'COINSBUY_SIGNATURE_INVALID']);
    }

    public function test_unconfigured_secret_fails_closed(): void
    {
        $this->usePayments('sandbox', ['payments.coinsbuy.sandbox.webhook_secret' => '']);

        $this->sendCallback($this->coinsbuyPayload())
            ->assertStatus(503)
            ->assertJson(['error_code' => 'COINSBUY_WEBHOOK_NOT_CONFIGURED']);
    }

    public function test_unknown_tracking_id_is_acknowledged(): void
    {
        $this->sendCallback($this->coinsbuyPayload(['tracking_id' => 'someone_elses_ref_1']))
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame('tracking_unknown', PaymentEvent::first()->outcome);
    }

    public function test_deleted_invoice_is_acknowledged(): void
    {
        $this->sendCallback($this->coinsbuyPayload(['tracking_id' => 'inv_99999_1785312000']))
            ->assertOk();

        $this->assertSame('invoice_not_found', PaymentEvent::first()->outcome);
    }

    public function test_paid_deposit_settles_and_reenables_the_account(): void
    {
        [$invoice, $accountId] = $this->seedInvoice([], ['enabled' => 0]);

        $this->sendCallback($this->coinsbuyPayload([
            'tracking_id' => $this->tracking($invoice->id),
            'deposit_status' => 3,
            'transfer_status' => 1,
        ]))->assertOk()->assertJson(['status' => 'paid', 'provider' => 'coinsbuy']);

        $fresh = $invoice->fresh();
        $this->assertSame('paid', $fresh->status);
        $this->assertSame('coinsbuy', $fresh->payment_provider);
        $this->assertSame('9001', $fresh->payment_reference);
        $this->assertNotNull($fresh->paid_at);
        $this->assertSame(1, (int) DB::table('binance_accounts')->find($accountId)->enabled);
        $this->assertSame('paid', PaymentEvent::first()->outcome);
    }

    /**
     * An Unresolved deposit with a Confirmed transfer is what an overpayment
     * looks like — a real payment. Carried over from the mother verbatim.
     */
    public function test_unresolved_deposit_with_confirmed_transfer_settles(): void
    {
        [$invoice] = $this->seedInvoice();

        $this->sendCallback($this->coinsbuyPayload([
            'tracking_id' => $this->tracking($invoice->id),
            'deposit_status' => 5,
            'transfer_status' => 2,
        ]))->assertOk();

        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_intermediate_created_state_does_not_settle(): void
    {
        [$invoice] = $this->seedInvoice();

        $this->sendCallback($this->coinsbuyPayload([
            'tracking_id' => $this->tracking($invoice->id),
            'deposit_status' => 2,
            'transfer_status' => 0,
        ]))->assertOk();

        $this->assertSame('pending', $invoice->fresh()->status);
        $this->assertSame('not_processed', PaymentEvent::first()->outcome);
    }

    public static function failureStates(): array
    {
        return [
            'canceled deposit' => [4, 1, 'canceled'],
            'failed transfer' => [2, -1, 'failed'],
            'blocked transfer' => [2, -2, 'blocked'],
            'canceled transfer' => [2, -3, 'canceled'],
        ];
    }

    #[DataProvider('failureStates')]
    public function test_failure_states_are_recorded_without_settling(
        int $depositStatus,
        int $transferStatus,
        string $expected
    ): void {
        [$invoice] = $this->seedInvoice();

        $this->sendCallback($this->coinsbuyPayload([
            'tracking_id' => $this->tracking($invoice->id),
            'deposit_status' => $depositStatus,
            'transfer_status' => $transferStatus,
        ]))->assertOk();

        $this->assertSame('pending', $invoice->fresh()->status);
        $this->assertSame($expected, PaymentEvent::first()->outcome);
    }

    /**
     * THE tolerance fix. The mother asked Coinsbuy for a 1% `inaccuracy` and
     * then rejected anything more than $0.01 off — so on any invoice above $1 a
     * payment Coinsbuy considered complete was refused: money taken, invoice
     * left unpaid. This test fails against a faithful port of that code.
     */
    public function test_shortfall_inside_the_requested_inaccuracy_still_settles(): void
    {
        [$invoice] = $this->seedInvoice();

        $this->sendCallback($this->coinsbuyPayload([
            'tracking_id' => $this->tracking($invoice->id),
            'target_paid' => '12.25',   // 0.7% under a 12.34 fee, inside the 1% we asked for
        ]))->assertOk();

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('paid', PaymentEvent::first()->outcome);
    }

    public function test_underpayment_beyond_tolerance_does_not_settle(): void
    {
        [$invoice] = $this->seedInvoice();

        $this->sendCallback($this->coinsbuyPayload([
            'tracking_id' => $this->tracking($invoice->id),
            'target_paid' => '6.00',
        ]))->assertOk();

        $this->assertSame('pending', $invoice->fresh()->status);
        $this->assertSame('amount_mismatch', PaymentEvent::first()->outcome);
    }

    public function test_overpayment_settles_and_is_flagged(): void
    {
        [$invoice] = $this->seedInvoice();

        $this->sendCallback($this->coinsbuyPayload([
            'tracking_id' => $this->tracking($invoice->id),
            'target_paid' => '12.90',
        ]))->assertOk();

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('overpaid', PaymentEvent::first()->outcome);
    }

    /** Enterprise wallets report only a crypto amount — nothing to compare. */
    public function test_crypto_only_callback_settles_and_records_it_as_unverified(): void
    {
        [$invoice] = $this->seedInvoice();

        $this->sendCallback($this->coinsbuyPayload([
            'tracking_id' => $this->tracking($invoice->id),
            'target_paid' => null,
        ]))->assertOk();

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertStringContainsString('amount_unverified', PaymentEvent::first()->message);
    }

    /** The second correlation path: the row written when the deposit was created. */
    public function test_deposit_id_resolves_the_invoice_when_tracking_id_is_foreign(): void
    {
        [$invoice] = $this->seedInvoice();

        PaymentEvent::create([
            'provider' => 'coinsbuy',
            'event_id' => 'created:9001',
            'external_id' => '9001',
            'invoice_id' => $invoice->id,
            'outcome' => 'created',
            'created_at' => now(),
        ]);

        $this->sendCallback($this->coinsbuyPayload([
            'deposit_id' => '9001',
            'tracking_id' => 'mangled_reference',
        ]))->assertOk();

        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_replaying_the_same_callback_is_idempotent(): void
    {
        [$invoice] = $this->seedInvoice();
        $payload = $this->coinsbuyPayload(['tracking_id' => $this->tracking($invoice->id)]);

        $this->sendCallback($payload)->assertOk();
        $paidAt = $invoice->fresh()->paid_at;

        $this->sendCallback($payload)->assertOk()->assertJson(['duplicate' => true]);

        $this->assertSame(1, PaymentEvent::where('outcome', 'paid')->count());
        $this->assertEquals($paidAt, $invoice->fresh()->paid_at);
    }

    /* ============ developer test payments ============ */

    /**
     * A live box where the two key sets have DIFFERENT webhook secrets, which is
     * the only configuration where the developer fallback can be exercised.
     */
    private function useLiveProductionWithDistinctSecrets(): void
    {
        $this->usePayments('production', [
            'payments.environments.production.api_url' => 'https://api.sinegualerts.test/api',
            'payments.environments.production.frontend_url' => 'https://sinegualerts.test',
            'payments.environments.production.public_api_url' => null,
            'payments.coinsbuy.production.webhook_secret' => 'live-callback-secret',
            'payments.coinsbuy.sandbox.webhook_secret' => $this->coinsbuyWebhookSecret,
        ]);
    }

    /**
     * A developer's invoice was paid with the sandbox key set, so Coinsbuy signs
     * its callback with the sandbox secret while the machine runs on live keys.
     * That callback must still settle, or developer test payments hang forever.
     */
    public function test_sandbox_signed_callback_settles_a_developer_invoice(): void
    {
        $this->useLiveProductionWithDistinctSecrets();

        $developer = $this->makeUser(['type' => 'developer']);
        $invoice = $this->makeInvoice($this->makeAccount($developer), $developer);

        $this->sendCallback($this->coinsbuyPayload([
            'tracking_id' => $this->tracking($invoice->id),
            'secret' => $this->coinsbuyWebhookSecret,   // the SANDBOX secret
        ]))->assertOk()->assertJson(['status' => 'paid']);

        $this->assertSame('paid', $invoice->fresh()->status);
    }

    /**
     * The other half of the rule: the sandbox secret settles nothing that is not
     * a developer's. Otherwise a leaked test secret would clear real invoices.
     */
    public function test_sandbox_signed_callback_is_rejected_for_a_real_invoice(): void
    {
        $this->useLiveProductionWithDistinctSecrets();

        foreach (['user', 'admin', 'master'] as $role) {
            $owner = $this->makeUser(['type' => $role]);
            $invoice = $this->makeInvoice($this->makeAccount($owner), $owner);

            $this->sendCallback($this->coinsbuyPayload([
                'tracking_id' => $this->tracking($invoice->id),
                'secret' => $this->coinsbuyWebhookSecret,
            ]))->assertStatus(401)->assertJson(['error_code' => 'COINSBUY_SIGNATURE_INVALID']);

            $this->assertSame('pending', $invoice->fresh()->status);
        }
    }
}
