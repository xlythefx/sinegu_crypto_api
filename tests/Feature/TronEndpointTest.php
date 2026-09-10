<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\PaymentIntent;
use App\Models\TronTransfer;
use App\Services\Payments\PaymentEnvironment;
use Illuminate\Support\Facades\DB;

/**
 * The trader-facing TRON endpoints and the admin attribution screen.
 */
class TronEndpointTest extends PaymentTestCase
{
    private string $uniId;

    private string $devId;

    private int $accountId;

    /** The developer's own invoice — lookups are scoped to the caller. */
    private Invoice $invoice;

    private Invoice $traderInvoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->devId = $this->makeUser(['type' => 'developer']);
        $this->accountId = $this->makeAccount($this->devId, ['enabled' => 0]);
        $this->invoice = $this->makeInvoice($this->accountId, $this->devId);

        $this->uniId = $this->makeUser();
        $traderAccount = $this->makeAccount($this->uniId);
        // A different fee from the developer's invoice on purpose: two open
        // intents cannot reserve the same figure, and that collision is the
        // subject of its own test rather than an incidental trip-hazard here.
        $this->traderInvoice = $this->makeInvoice($traderAccount, $this->uniId, ['total_fee' => 20.00]);
    }

    private function intentFor(string $uniId, array $body = []): \Illuminate\Testing\TestResponse
    {
        $invoice = $uniId === $this->devId ? $this->invoice : $this->traderInvoice;

        return $this->postJson(
            '/api/payments/tron/intent',
            array_merge(['invoice_id' => $invoice->id], $body),
            $this->userHeaders($uniId),
        );
    }

    // ---- visibility ------------------------------------------------------

    /**
     * 404 rather than 403 while the rail is hidden: indistinguishable from "no
     * such endpoint", so probing tells a stranger nothing.
     */
    public function test_a_trader_cannot_see_the_rail_before_it_is_public(): void
    {
        $this->intentFor($this->uniId)->assertNotFound();

        $this->assertSame(0, PaymentIntent::count());
    }

    public function test_flipping_the_public_switch_opens_it_to_traders(): void
    {
        config(['payments.tron.public' => true, 'payments.force' => 'sandbox']);

        $this->intentFor($this->uniId)
            ->assertOk()
            ->assertJson(['success' => true, 'provider' => 'tron', 'network' => 'nile']);
    }

    public function test_methods_advertises_the_rail_and_the_default_provider(): void
    {
        $trader = $this->getJson('/api/payments/methods', $this->userHeaders($this->uniId));

        $trader->assertOk()
            ->assertJsonPath('default_provider', 'coinsbuy')
            ->assertJsonPath('tron.visible', false)
            ->assertJsonPath('tron.network', 'nile');

        // Never the address: a hidden rail should be hidden.
        $this->assertArrayNotHasKey('address', $trader->json('tron'));
        $this->assertArrayNotHasKey('debug', $trader->json());

        $this->getJson('/api/payments/methods', $this->userHeaders($this->devId))
            ->assertOk()
            ->assertJsonPath('tron.visible', true)
            ->assertJsonPath('debug.tron.address_valid', true);
    }

    // ---- issuing an intent -----------------------------------------------

    public function test_a_developer_is_pinned_to_the_test_network_even_on_production(): void
    {
        $this->usePayments(PaymentEnvironment::PRODUCTION);

        $response = $this->intentFor($this->devId)->assertOk();

        $response->assertJsonPath('network', 'nile')
            ->assertJsonPath('test_account', true)
            ->assertJsonPath('address', $this->tronNileAddress)
            ->assertJsonPath('amount', '12.340000')
            ->assertJsonPath('amount_units', '12340000')
            ->assertJsonPath('usd_amount', 12.34)
            ->assertJsonPath('reused', false);

        // The one that matters: never the live receiving address.
        $this->assertNotSame($this->tronMainnetAddress, $response->json('address'));
    }

    public function test_a_repeated_request_returns_the_same_reservation(): void
    {
        $first = $this->intentFor($this->devId)->assertOk();
        $second = $this->intentFor($this->devId)->assertOk();

        $this->assertSame($first->json('intent_id'), $second->json('intent_id'));
        $this->assertTrue($second->json('reused'));
        $this->assertSame(1, PaymentIntent::count());
    }

    /** A trader must not be able to name their own network. */
    public function test_a_client_supplied_network_is_ignored(): void
    {
        config(['payments.tron.public' => true, 'payments.force' => 'production']);
        config(['payments.tron.networks.mainnet.contract' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t']);

        $this->intentFor($this->uniId, ['network' => 'nile'])
            ->assertOk()
            ->assertJsonPath('network', 'mainnet')
            ->assertJsonPath('address', $this->tronMainnetAddress);
    }

    public function test_it_refuses_a_paid_invoice_but_still_reports_on_it(): void
    {
        $this->invoice->forceFill(['status' => 'paid', 'paid_at' => now()])->save();

        $this->intentFor($this->devId)
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'INVOICE_ALREADY_PAID');

        // The status endpoint deliberately diverges: "paid" is precisely the
        // state it exists to observe, so it answers 200 rather than 409.
        $this->getJson("/api/payments/tron/intent/{$this->invoice->id}", $this->userHeaders($this->devId))
            ->assertOk()
            ->assertJsonPath('invoice_status', 'paid');
    }

    public function test_a_stale_tab_amount_is_rejected(): void
    {
        $this->intentFor($this->devId, ['amount' => 99.99])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'AMOUNT_MISMATCH');
    }

    public function test_another_users_invoice_is_not_found(): void
    {
        $other = $this->makeUser(['type' => 'developer']);

        $this->intentFor($other)->assertNotFound();
        $this->getJson("/api/payments/tron/intent/{$this->invoice->id}", $this->userHeaders($other))
            ->assertNotFound();
    }

    // ---- configuration faults --------------------------------------------

    public function test_an_unconfigured_network_answers_503_with_a_developer_envelope(): void
    {
        config(['payments.tron.networks.nile.address' => null]);

        $response = $this->intentFor($this->devId)->assertStatus(503);

        $response->assertJsonPath('error_code', 'TRON_NOT_CONFIGURED');
        $this->assertNotNull($response->json('debug.hint'));
        $this->assertFalse($response->json('debug.tron.configured'));
    }

    /** A typo must not be mistaken for "not set up yet". */
    public function test_a_mistyped_address_is_refused_rather_than_displayed(): void
    {
        config(['payments.tron.networks.nile.address' => 'T9yD14Nj9j7xAB4dbGeiX9h8unkKT76qbX']);

        $this->intentFor($this->devId)
            ->assertStatus(503)
            ->assertJsonPath('error_code', 'TRON_BAD_ADDRESS')
            ->assertJsonPath('debug.tron.address_valid', false);
    }

    /** A trader gets the sentence; only a developer gets the wiring. */
    public function test_a_trader_never_receives_the_debug_envelope(): void
    {
        config(['payments.tron.public' => true, 'payments.tron.networks.nile.address' => null]);

        $response = $this->intentFor($this->uniId)->assertStatus(503);

        $this->assertArrayNotHasKey('debug', $response->json());
        $this->assertSame('Direct crypto payments are not available on this server yet.', $response->json('message'));
    }

    public function test_a_reserved_figure_is_refused_with_a_recoverable_error(): void
    {
        $other = $this->makeInvoice($this->accountId, $this->devId, ['month_year' => '2026-05']);
        $this->intentFor($this->devId)->assertOk();

        $this->postJson('/api/payments/tron/intent', ['invoice_id' => $other->id], $this->userHeaders($this->devId))
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'TRON_AMOUNT_UNAVAILABLE');
    }

    // ---- polling ---------------------------------------------------------

    public function test_the_status_endpoint_reports_a_stalled_watcher(): void
    {
        $this->intentFor($this->devId)->assertOk();

        $response = $this->getJson(
            "/api/payments/tron/intent/{$this->invoice->id}",
            $this->userHeaders($this->devId),
        )->assertOk();

        $response->assertJsonPath('invoice_status', 'pending')
            ->assertJsonPath('intent.status', 'open')
            ->assertJsonPath('intent.amount', '12.340000')
            // Nothing has ever scanned, so the sheet can say so instead of
            // spinning: the cron entry is load-bearing for money now.
            ->assertJsonPath('scan_stale', true);

        $this->assertNull($response->json('transfer'));
        $this->assertGreaterThan(0, $response->json('intent.seconds_remaining'));
    }

    // ---- admin -----------------------------------------------------------

    private function seedTransfer(array $overrides = []): TronTransfer
    {
        return TronTransfer::create(array_merge([
            'network' => 'nile',
            'event_key' => hash('sha256', 'seed-'.uniqid('', true)),
            'tx_hash' => 'tx-'.bin2hex(random_bytes(6)),
            'contract_address' => $this->tronNileContract,
            'token_symbol' => 'USDT',
            'token_decimals' => 6,
            'from_address' => $this->tronPayer,
            'to_address' => $this->tronNileAddress,
            'value_raw' => '12340000',
            'value_units' => '12340000',
            'block_timestamp' => now()->getTimestampMs(),
            'confirmed' => true,
            'status' => TronTransfer::STATUS_UNMATCHED,
        ], $overrides));
    }

    public function test_the_admin_listing_is_closed_to_traders(): void
    {
        $this->getJson('/api/admin/tron-transfers', $this->userHeaders($this->uniId))
            ->assertStatus(403);
    }

    public function test_the_listing_reports_health_counts_and_a_suggestion(): void
    {
        $this->makeIntent($this->invoice, ['expires_at' => now()->subHour(), 'status' => PaymentIntent::STATUS_EXPIRED, 'open_units' => null]);
        $this->seedTransfer();
        $this->seedTransfer(['status' => TronTransfer::STATUS_REJECTED, 'reject_reason' => 'wrong_contract']);

        $response = $this->getJson('/api/admin/tron-transfers', $this->userHeaders($this->devId))->assertOk();

        $response->assertJsonPath('counts.all', 2)
            ->assertJsonPath('counts.unmatched', 1)
            ->assertJsonPath('counts.rejected', 1);

        $rows = collect($response->json('transfers'));
        $unmatched = $rows->firstWhere('status', 'unmatched');
        $rejected = $rows->firstWhere('status', 'rejected');

        $this->assertTrue($unmatched['attributable']);
        $this->assertSame('12.340000', $unmatched['amount']);
        $this->assertTrue($unmatched['contract_trusted']);
        // The late-payment hint: the reservation expired but its expectation
        // survived, so we can still name the invoice.
        $this->assertSame($this->invoice->id, $unmatched['suggestions'][0]['invoice_id']);
        $this->assertSame('expired', $unmatched['suggestions'][0]['intent_status']);
        $this->assertNotNull($unmatched['suggestions'][0]['owner']['email']);

        $this->assertFalse($rejected['attributable']);
        $this->assertStringContainsString('wrong_contract', $rejected['attribution_blocked_reason']);

        $nile = collect($response->json('networks'))->firstWhere('name', 'nile');
        $this->assertTrue($nile['configured']);
        $this->assertTrue($nile['scan_stale']);
    }

    public function test_an_admin_can_attribute_a_transfer_by_hand(): void
    {
        $transfer = $this->seedTransfer();

        $this->postJson(
            "/api/admin/tron-transfers/{$transfer->id}/attribute",
            ['invoice_id' => $this->invoice->id],
            $this->userHeaders($this->devId),
        )->assertOk()->assertJsonPath('settled', true);

        $invoice = $this->invoice->fresh();
        $this->assertSame('paid', $invoice->status);
        $this->assertSame('tron', $invoice->payment_provider);
        $this->assertSame($transfer->tx_hash, $invoice->payment_reference);
        // Settling re-enables trading, exactly as the automatic path does.
        $this->assertSame(1, (int) DB::table('binance_accounts')->find($this->accountId)->enabled);

        $fresh = $transfer->fresh();
        $this->assertSame(TronTransfer::STATUS_SETTLED, $fresh->status);
        $this->assertSame('admin:'.$this->devId, $fresh->settled_by);
    }

    /**
     * The guard is re-evaluated when the action arrives, never trusted from the
     * row the admin was looking at — an invoice can be paid another way between
     * the page loading and the button being pressed.
     */
    public function test_attributing_an_invoice_paid_since_page_load_is_refused(): void
    {
        $transfer = $this->seedTransfer();
        $this->invoice->forceFill(['status' => 'paid', 'paid_at' => now()])->save();

        $this->postJson(
            "/api/admin/tron-transfers/{$transfer->id}/attribute",
            ['invoice_id' => $this->invoice->id],
            $this->userHeaders($this->devId),
        )->assertStatus(422)->assertJsonPath('error_code', 'ATTRIBUTION_REFUSED');

        $this->assertSame(TronTransfer::STATUS_UNMATCHED, $transfer->fresh()->status);
    }

    public function test_the_same_transfer_cannot_be_attributed_twice(): void
    {
        $second = $this->makeInvoice($this->accountId, $this->devId, ['month_year' => '2026-05']);
        $transfer = $this->seedTransfer();

        $this->postJson(
            "/api/admin/tron-transfers/{$transfer->id}/attribute",
            ['invoice_id' => $this->invoice->id],
            $this->userHeaders($this->devId),
        )->assertOk();

        $this->postJson(
            "/api/admin/tron-transfers/{$transfer->id}/attribute",
            ['invoice_id' => $second->id],
            $this->userHeaders($this->devId),
        )->assertStatus(422);

        $this->assertSame('pending', $second->fresh()->status);
    }

    public function test_ignoring_removes_a_transfer_from_the_queue(): void
    {
        $transfer = $this->seedTransfer();

        $this->postJson(
            "/api/admin/tron-transfers/{$transfer->id}/ignore",
            ['note' => 'Test send from my own wallet.'],
            $this->userHeaders($this->devId),
        )->assertOk();

        $fresh = $transfer->fresh();
        $this->assertSame(TronTransfer::STATUS_IGNORED, $fresh->status);
        $this->assertSame('Test send from my own wallet.', $fresh->note);
        $this->assertNotNull(app(\App\Services\Payments\TronWatcher::class)->attributionBlockedReason($fresh));
    }

    // ---- the developer simulate button ----------------------------------

    private function simulate(string $uniId, ?int $invoiceId = null)
    {
        $invoiceId ??= $uniId === $this->devId ? $this->invoice->id : $this->traderInvoice->id;

        return $this->postJson(
            "/api/payments/tron/intent/{$invoiceId}/simulate",
            [],
            $this->userHeaders($uniId),
        );
    }

    public function test_a_developer_can_settle_a_test_payment_without_money(): void
    {
        $this->intentFor($this->devId)->assertOk();

        $this->simulate($this->devId)
            ->assertOk()
            ->assertJsonPath('simulated', true)
            ->assertJsonPath('amount', '12.340000');

        $invoice = $this->invoice->fresh();
        $this->assertSame('paid', $invoice->status);
        $this->assertSame('tron', $invoice->payment_provider);
        // Permanently distinguishable from a real payment in the ledger.
        $this->assertStringStartsWith('SIM-', (string) $invoice->payment_reference);

        $transfer = TronTransfer::firstOrFail();
        $this->assertSame('SIMULATED', $transfer->from_address);
        $this->assertSame('simulated:'.$this->devId, $transfer->settled_by);
        $this->assertStringContainsString('No funds moved', (string) $transfer->note);

        // Settled through the same path as a real payment, so the account is
        // re-enabled exactly as it would be.
        $this->assertSame(1, (int) DB::table('binance_accounts')->find($this->accountId)->enabled);
    }

    /**
     * THE GUARD THAT MATTERS. Simulating on the network that carries real money
     * would be forging revenue, so it is refused for everyone including a
     * developer — the role opens the button, it does not open the network.
     */
    public function test_simulating_is_refused_on_the_live_money_network(): void
    {
        // Force a developer onto mainnet, which is the only way to reach this.
        config([
            'payments.tron.developer_network' => 'mainnet',
            'payments.tron.networks.mainnet.contract' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
        ]);
        $this->intentFor($this->devId)->assertOk();

        $this->simulate($this->devId)
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'TRON_SIMULATION_REFUSED');

        $this->assertSame('pending', $this->invoice->fresh()->status);
    }

    /** Renaming the networks must not open the door either. */
    public function test_the_refusal_follows_the_money_not_the_network_name(): void
    {
        config([
            'payments.tron.default_network.production' => 'nile',
            'payments.tron.developer_network' => 'nile',
        ]);
        $this->intentFor($this->devId)->assertOk();

        $this->simulate($this->devId)->assertStatus(422);
        $this->assertSame('pending', $this->invoice->fresh()->status);
    }

    public function test_a_plain_trader_cannot_reach_the_simulate_endpoint(): void
    {
        config(['payments.tron.public' => true]);
        $this->intentFor($this->uniId)->assertOk();

        $this->simulate($this->uniId)->assertStatus(403);
        $this->assertSame('pending', $this->traderInvoice->fresh()->status);
    }

    /** `developer` exactly — admin and master are deliberately not enough. */
    public function test_an_admin_cannot_reach_the_simulate_endpoint(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);

        $this->postJson(
            "/api/payments/tron/intent/{$this->invoice->id}/simulate",
            [],
            $this->userHeaders($admin),
        )->assertStatus(403);

        $this->assertSame('pending', $this->invoice->fresh()->status);
    }

    public function test_simulating_without_a_quote_is_refused(): void
    {
        $this->simulate($this->devId)
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'TRON_NO_OPEN_INTENT');
    }

    public function test_a_simulated_invoice_cannot_be_settled_twice(): void
    {
        $this->intentFor($this->devId)->assertOk();
        $this->simulate($this->devId)->assertOk();

        $this->simulate($this->devId)
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'INVOICE_ALREADY_PAID');

        $this->assertSame(1, TronTransfer::count());
    }

    public function test_the_intent_payload_advertises_whether_simulation_is_allowed(): void
    {
        $this->intentFor($this->devId)->assertOk()->assertJsonPath('simulatable', true);

        config(['payments.tron.public' => true]);
        $this->intentFor($this->uniId)->assertOk()->assertJsonPath('simulatable', false);
    }
}
