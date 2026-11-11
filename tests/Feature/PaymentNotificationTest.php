<?php

namespace Tests\Feature;

use App\Mail\InvoicePaidNotice;
use App\Mail\PaymentReceived;
use App\Models\Invoice;
use App\Models\TronTransfer;
use App\Services\InvoiceService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * "An invoice was paid" is TOLD (2026-11-11). Alexandre Guyomard paid his
 * October invoice by card on 10 Nov; it settled correctly and nobody heard
 * about it — no email, and the only payments page (Crypto Transfers) cannot
 * show a card. InvoiceService::settle now announces every settlement once:
 * the customer's thank-you, the team's "invoice paid", the admin Telegram
 * line (through the engine), and the Overview's "Payments received" card.
 */
class PaymentNotificationTest extends PaymentTestCase
{
    private const ENGINE = 'http://engine.test';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'mail.admin_address' => 'desk@pixel-alpha.test, dima@pixel-alpha.test',
            'services.engine.targets.local' => self::ENGINE,
            'services.engine.admin_secret' => 'engine-admin-token',
        ]);
        Mail::fake();
        Http::fake([self::ENGINE.'/*' => Http::response(['success' => true], 200)]);
    }

    private function invoiceFor(array $user = [], array $invoice = [], array $account = []): Invoice
    {
        $uniId = $this->makeUser(array_merge(['name' => 'Alexandre Guyomard', 'email' => 'alex@example.test'], $user));

        return $this->makeInvoice($this->makeAccount($uniId, $account), $uniId, array_merge(['month_year' => '2026-10'], $invoice));
    }

    private function settle(Invoice $invoice, string $method = 'stripe', ?string $reference = 'cs_live_abc', ?float $paid = 12.34, bool $notify = true): bool
    {
        return app(InvoiceService::class)->settle($invoice, $method, $reference, $paid, $notify);
    }

    /** @return list<array<string, mixed>> */
    private function telegramPings(): array
    {
        return Http::recorded()
            ->filter(fn ($pair) => $pair[0]->url() === self::ENGINE.'/admin/payment-received')
            ->map(fn ($pair) => $pair[0]->data())
            ->values()
            ->all();
    }

    private function assertNothingAnnounced(): void
    {
        Mail::assertNothingSent();
        $this->assertSame([], $this->telegramPings());
    }

    public function test_a_card_payment_is_told_to_the_customer_the_team_and_the_admin_chat(): void
    {
        $invoice = $this->invoiceFor();

        $this->assertTrue($this->settle($invoice));

        Mail::assertSent(PaymentReceived::class, fn (PaymentReceived $m) => $m->hasTo('alex@example.test')
            && $m->name === 'Alexandre'
            && $m->amount === 12.34
            && $m->period === 'October 2026'
            && $m->method === 'Card (Stripe)'
            && $m->reference === 'cs_live_abc'
            && $m->wasPaused === false);
        Mail::assertSent(InvoicePaidNotice::class, fn (InvoicePaidNotice $m) => $m->hasTo('desk@pixel-alpha.test')
            && $m->hasTo('dima@pixel-alpha.test')
            && ! $m->hasTo('alex@example.test')
            && $m->name === 'Alexandre Guyomard'
            && $m->invoiceId === (int) $invoice->id);

        $pings = $this->telegramPings();
        $this->assertCount(1, $pings);
        $this->assertSame('Alexandre Guyomard', $pings[0]['name']);
        $this->assertSame(12.34, $pings[0]['amount']);
        $this->assertSame('October 2026', $pings[0]['period']);
        $this->assertSame('Card (Stripe)', $pings[0]['method']);
        $this->assertFalse($pings[0]['test']);
        Http::assertSent(fn ($r) => $r->url() === self::ENGINE.'/admin/payment-received'
            && $r->header('X-Admin-Secret')[0] === 'engine-admin-token');
    }

    /** A replayed webhook, or a second "Mark paid", must not thank anyone twice. */
    public function test_settling_twice_announces_once(): void
    {
        $invoice = $this->invoiceFor();

        $this->assertTrue($this->settle($invoice));
        $this->assertFalse($this->settle($invoice->fresh()));

        Mail::assertSent(PaymentReceived::class, 1);
        Mail::assertSent(InvoicePaidNotice::class, 1);
        $this->assertCount(1, $this->telegramPings());
    }

    /** A $0 month is stored paid but collected nothing — nobody paid anything. */
    public function test_a_zero_fee_invoice_is_never_announced(): void
    {
        $invoice = $this->invoiceFor(invoice: ['total_fee' => 0]);

        $this->assertTrue($this->settle($invoice, 'manual', null, null));

        $this->assertNothingAnnounced();
    }

    /** Scenario scratch rows hang off a REAL user's uni_id. */
    public function test_scenario_rows_are_never_announced(): void
    {
        $invoice = $this->invoiceFor(invoice: ['api_key' => 'SBXINV-scratch-1']);

        $this->assertTrue($this->settle($invoice, 'manual', null, null));

        $this->assertNothingAnnounced();
    }

    public function test_the_rehearsal_opt_out_is_silent(): void
    {
        $invoice = $this->invoiceFor();

        $this->assertTrue($this->settle($invoice, notify: false));

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertNothingAnnounced();
    }

    public function test_an_admin_mark_paid_is_announced_but_a_sandbox_one_is_not(): void
    {
        $admin = $this->userHeaders($this->makeUser(['type' => 'admin']));
        $real = $this->invoiceFor();
        $rehearsal = $this->invoiceFor(['email' => 'other@example.test']);

        $this->putJson("/api/admin/invoices/{$rehearsal->id}", ['status' => 'paid', 'sandbox' => true], $admin)->assertOk();
        $this->assertSame('paid', $rehearsal->fresh()->status);
        $this->assertNothingAnnounced();

        $this->putJson("/api/admin/invoices/{$real->id}", ['status' => 'paid'], $admin)->assertOk();
        Mail::assertSent(PaymentReceived::class, fn (PaymentReceived $m) => $m->hasTo('alex@example.test')
            && $m->method === 'Marked paid by an admin'
            && $m->reference === '—');
        $this->assertCount(1, $this->telegramPings());
    }

    public function test_paying_an_overdue_invoice_says_trading_is_back_on(): void
    {
        $invoice = $this->invoiceFor(invoice: ['status' => 'overdue'], account: ['enabled' => 0]);

        $this->assertTrue($this->settle($invoice, 'tron', 'tx-abc', 12.34));

        Mail::assertSent(PaymentReceived::class, fn (PaymentReceived $m) => $m->wasPaused === true
            && $m->method === 'USDT (TRC-20)');
        $this->assertTrue($this->telegramPings()[0]['was_paused']);
        $this->assertSame(1, (int) DB::table('binance_accounts')->where('id', $invoice->account_id)->value('enabled'));
    }

    /** A pending invoice on an enabled account was never paused, so nothing is "back on". */
    public function test_an_ordinary_payment_does_not_claim_a_pause(): void
    {
        $invoice = $this->invoiceFor();

        $this->settle($invoice);

        Mail::assertSent(PaymentReceived::class, fn (PaymentReceived $m) => $m->wasPaused === false);
        $this->assertFalse($this->telegramPings()[0]['was_paused']);
    }

    public function test_play_money_is_labelled_a_test(): void
    {
        $card = $this->invoiceFor();
        $this->settle($card, 'stripe', 'cs_test_abc');

        $nile = $this->invoiceFor(['email' => 'nile@example.test']);
        TronTransfer::create([
            'network' => 'nile', 'event_key' => hash('sha256', 'n1'), 'tx_hash' => 'tx-nile-1',
            'contract_address' => $this->tronNileContract, 'from_address' => $this->tronPayer,
            'to_address' => $this->tronNileAddress, 'value_raw' => '12340000', 'value_units' => '12340000',
            'block_timestamp' => now()->getTimestampMs(), 'status' => TronTransfer::STATUS_SETTLED,
            'invoice_id' => $nile->id,
        ]);
        $this->settle($nile, 'tron', 'tx-nile-1');

        $pings = $this->telegramPings();
        $this->assertSame(['Card (Stripe test mode)', 'USDT (TRC-20, Nile testnet)'], array_column($pings, 'method'));
        $this->assertSame([true, true], array_column($pings, 'test'));
    }

    /** The paid row is the fact; a dead engine or mail server is not a reason to undo it. */
    public function test_a_failed_announcement_never_undoes_the_payment(): void
    {
        Http::fake([self::ENGINE.'/*' => Http::response('down', 503)]);
        $invoice = $this->invoiceFor();

        $this->assertTrue($this->settle($invoice));

        $this->assertSame('paid', $invoice->fresh()->status);
        Mail::assertSent(PaymentReceived::class, 1);
    }

    public function test_the_overview_lists_recent_customer_payments(): void
    {
        $admin = $this->userHeaders($this->makeUser(['type' => 'admin']));
        $paid = $this->invoiceFor();
        $this->settle($paid, 'stripe', 'cs_live_abc', 24.04);
        // Not revenue: a $0 month, a developer's own invoice, a scenario row.
        $this->settle($this->invoiceFor(['email' => 'z@example.test'], ['total_fee' => 0]), 'manual', null, null);
        $this->settle($this->invoiceFor(['email' => 'dev@example.test', 'type' => 'developer']), 'stripe', 'cs_test_x');
        $this->settle($this->invoiceFor(['email' => 's@example.test'], ['api_key' => 'SBXINV-x']), 'manual', null, null);

        $recent = $this->getJson('/api/admin/insights/overview', $admin)->assertOk()->json('attention.recent_payments');

        $this->assertSame(1, $recent['count']);
        $this->assertSame(24.04, $recent['amount']);
        $this->assertSame(7, $recent['days']);
        $this->assertSame((int) $paid->id, $recent['latest'][0]['invoice_id']);
        $this->assertSame('Alexandre Guyomard', $recent['latest'][0]['name']);
        $this->assertSame('stripe', $recent['latest'][0]['provider']);
    }

    /** The Overview is cached for a minute; a payment must not wait for it. */
    public function test_a_payment_shows_on_the_overview_at_once(): void
    {
        $admin = $this->userHeaders($this->makeUser(['type' => 'admin']));
        $this->assertSame(0, $this->getJson('/api/admin/insights/overview', $admin)->json('attention.recent_payments.count'));

        $this->settle($this->invoiceFor());

        $this->assertSame(1, $this->getJson('/api/admin/insights/overview', $admin)->json('attention.recent_payments.count'));
    }

    public function test_a_collaborator_never_receives_the_payments(): void
    {
        $this->settle($this->invoiceFor());
        Cache::flush();

        $attention = $this->getJson('/api/admin/insights/overview', $this->userHeaders($this->makeUser(['type' => 'collaborator'])))
            ->assertOk()->json('attention');

        $this->assertArrayNotHasKey('recent_payments', $attention);
    }
}
