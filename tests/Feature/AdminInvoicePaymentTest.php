<?php

namespace Tests\Feature;

use App\Models\TronTransfer;
use App\Services\InvoiceService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * The admin invoice page (/admin/invoices/{id}, 2026-11-11) says HOW an invoice
 * was paid — card (Stripe) or crypto (USDT) — with the reference and, for USDT,
 * the TronScan link and what actually arrived on chain. One definition
 * (Billing\PaymentMethod) feeds this page and the payment notices.
 */
class AdminInvoicePaymentTest extends PaymentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Http::fake();
    }

    private function invoice(): array
    {
        $uniId = $this->makeUser(['name' => 'Sample Payer']);
        $invoice = $this->makeInvoice($this->makeAccount($uniId), $uniId, ['total_fee' => 24.04]);

        return [$invoice, $this->userHeaders($this->makeUser(['type' => 'admin']))];
    }

    private function payment(int $id, array $admin): ?array
    {
        return $this->getJson("/api/admin/invoices/{$id}", $admin)->assertOk()->json('invoice.payment');
    }

    public function test_a_card_payment_names_stripe_and_its_session(): void
    {
        [$invoice, $admin] = $this->invoice();
        app(InvoiceService::class)->settle($invoice, 'stripe', 'cs_live_abc123', notify: false);

        $res = $this->getJson("/api/admin/invoices/{$invoice->id}", $admin)->assertOk();

        $this->assertSame('Sample Payer', $res->json('invoice.user_name'));
        $payment = $res->json('invoice.payment');
        $this->assertSame('stripe', $payment['provider']);
        $this->assertSame('card', $payment['kind']);
        $this->assertSame('Card (Stripe)', $payment['label']);
        $this->assertFalse($payment['test']);
        $this->assertSame('cs_live_abc123', $payment['reference']);
        $this->assertEquals(24.04, $payment['paid_amount']);
        $this->assertNull($payment['explorer_url']);
    }

    public function test_a_usdt_payment_links_the_transaction_and_says_what_arrived(): void
    {
        [$invoice, $admin] = $this->invoice();
        // Arrived 0.02 short — an exchange took its withdrawal fee from the amount.
        TronTransfer::create([
            'network' => 'mainnet', 'event_key' => hash('sha256', 'm1'), 'tx_hash' => 'tx-main-1',
            'contract_address' => $this->tronNileContract, 'from_address' => $this->tronPayer,
            'to_address' => $this->tronMainnetAddress, 'value_raw' => '24020000', 'value_units' => '24020000',
            'block_timestamp' => now()->getTimestampMs(), 'status' => TronTransfer::STATUS_SETTLED,
            'invoice_id' => $invoice->id,
        ]);
        app(InvoiceService::class)->settle($invoice, 'tron', 'tx-main-1', 24.04, notify: false);

        $payment = $this->payment($invoice->id, $admin);

        $this->assertSame('crypto', $payment['kind']);
        $this->assertSame('USDT (TRC-20)', $payment['label']);
        $this->assertFalse($payment['test']);
        $this->assertSame('mainnet', $payment['network']);
        $this->assertSame('https://tronscan.org/#/transaction/tx-main-1', $payment['explorer_url']);
        $this->assertSame('USDT', $payment['received']['asset']);
        $this->assertEquals(24.02, (float) $payment['received']['amount']);
    }

    public function test_marked_paid_by_hand_says_so(): void
    {
        [$invoice, $admin] = $this->invoice();
        $this->putJson("/api/admin/invoices/{$invoice->id}",
            ['status' => 'paid', 'method' => 'cash', 'note' => 'Paid at the office'], $admin)->assertOk();

        $payment = $this->payment($invoice->id, $admin);

        $this->assertSame('manual', $payment['kind']);
        $this->assertSame('Cash (marked paid by an admin)', $payment['label']);
        $this->assertSame('cash', $payment['manual_method']);
        $this->assertSame('Paid at the office', $payment['note']);
        $this->assertNotNull($payment['marked_by']);
    }

    public function test_an_unpaid_invoice_has_no_payment(): void
    {
        [$invoice, $admin] = $this->invoice();

        $this->assertNull($this->payment($invoice->id, $admin));
    }

    public function test_a_customer_cannot_read_the_admin_invoice(): void
    {
        [$invoice] = $this->invoice();
        $customer = $this->userHeaders($invoice->user_id);

        $this->getJson("/api/admin/invoices/{$invoice->id}", $customer)->assertForbidden();
    }
}
