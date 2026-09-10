<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\PaymentEvent;
use App\Models\PaymentIntent;
use App\Models\TronTransfer;
use App\Services\Payments\TronWatcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * The watcher, against a faked TronGrid. Nothing here touches a chain, a wallet
 * or an address that exists.
 */
class TronWatcherTest extends PaymentTestCase
{
    private string $uniId;

    private int $accountId;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->uniId = $this->makeUser();
        $this->accountId = $this->makeAccount($this->uniId, ['enabled' => 0]);
        $this->invoice = $this->makeInvoice($this->accountId, $this->uniId);
    }

    private function scan(array $arguments = []): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan('payments:watch-tron', array_merge(['--network' => 'nile'], $arguments));
    }

    private function queryOfLastRequest(): array
    {
        $query = [];
        Http::assertSent(function (Request $request) use (&$query) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $parsed);
            $query = $parsed;

            return true;
        });

        return $query;
    }

    // ---- the happy path --------------------------------------------------

    public function test_a_matching_transfer_settles_its_invoice(): void
    {
        $this->makeIntent($this->invoice);
        $item = $this->tronItem();
        $this->fakeTron([$this->tronPage([$item])]);

        $this->scan()->assertSuccessful();

        $invoice = $this->invoice->fresh();
        $this->assertSame('paid', $invoice->status);
        $this->assertSame('tron', $invoice->payment_provider);
        $this->assertSame($item['transaction_id'], $invoice->payment_reference);
        $this->assertNotNull($invoice->paid_at);
        // The invoice's own USD figure, not the USDT amount reinterpreted.
        // Compared numerically because MySQL hands decimals back as strings and
        // SQLite (the test driver) as floats.
        $this->assertEquals(12.34, (float) $invoice->paid_amount);

        // The billing gate re-opens: settling is what re-enables trading.
        $this->assertSame(1, (int) DB::table('binance_accounts')->find($this->accountId)->enabled);

        $transfer = TronTransfer::first();
        $this->assertSame(TronTransfer::STATUS_SETTLED, $transfer->status);
        $this->assertSame('watcher', $transfer->settled_by);
        $this->assertSame($this->invoice->id, $transfer->invoice_id);
        $this->assertNotNull($transfer->intent_id);

        $intent = PaymentIntent::first();
        $this->assertSame(PaymentIntent::STATUS_SETTLED, $intent->status);
        // The reservation is released so the figure can be quoted again.
        $this->assertNull($intent->open_units);

        $event = PaymentEvent::where('provider', 'tron')->firstOrFail();
        $this->assertSame('paid', $event->outcome);
        $this->assertSame($transfer->event_key, $event->event_id);
        $this->assertEquals(12.34, (float) $event->crypto_amount);
    }

    // ---- what is not money -----------------------------------------------

    /**
     * The single most important rejection. Anyone can deploy a token that
     * reports the symbol "USDT"; only the contract address is trustworthy.
     */
    public function test_a_lookalike_contract_is_rejected_however_it_labels_itself(): void
    {
        $this->makeIntent($this->invoice);
        $this->fakeTron([$this->tronPage([$this->tronItem([
            'token_info' => [
                'symbol' => 'USDT',
                'name' => 'Tether USD',
                'address' => $this->tronLookalikeContract,
                'decimals' => 6,
            ],
        ])])]);

        $this->scan()->assertSuccessful();

        $this->assertSame('pending', $this->invoice->fresh()->status);
        $transfer = TronTransfer::firstOrFail();
        $this->assertSame(TronTransfer::STATUS_REJECTED, $transfer->status);
        $this->assertSame('wrong_contract', $transfer->reject_reason);
        // The symbol IS recorded — just never consulted.
        $this->assertSame('USDT', $transfer->token_symbol);

        // Junk must not be able to inflate the audit table: the address is
        // public and anyone can spray it for free.
        $this->assertSame(0, PaymentEvent::where('provider', 'tron')->count());
    }

    /** A contract reporting `decimals: 0` would make 1 base unit look like $1. */
    public function test_reported_decimals_are_ignored_in_favour_of_the_configured_ones(): void
    {
        $this->makeIntent($this->invoice);
        $this->fakeTron([$this->tronPage([$this->tronItem([
            'token_info' => [
                'symbol' => 'USDT',
                'address' => $this->tronNileContract,
                'decimals' => 0,
                'name' => 'Tether USD',
            ],
            'value' => '12340000',
        ])])]);

        $this->scan()->assertSuccessful();

        $this->assertSame('paid', $this->invoice->fresh()->status);
        $this->assertSame(0, (int) TronTransfer::firstOrFail()->token_decimals);
        $this->assertEquals(
            12.34,
            (float) PaymentEvent::where('provider', 'tron')->firstOrFail()->crypto_amount,
        );
    }

    public function test_it_rejects_wrong_recipients_approvals_and_self_transfers(): void
    {
        $this->makeIntent($this->invoice);
        $this->fakeTron([$this->tronPage([
            $this->tronItem(['to' => $this->tronMainnetAddress]),
            $this->tronItem(['type' => 'Approval']),
            $this->tronItem(['from' => $this->tronNileAddress]),
        ])]);

        $this->scan()->assertSuccessful();

        $this->assertSame('pending', $this->invoice->fresh()->status);
        $this->assertEqualsCanonicalizing(
            ['wrong_recipient', 'not_transfer', 'self_transfer'],
            TronTransfer::pluck('reject_reason')->all(),
        );
    }

    public function test_a_value_beyond_int64_is_recorded_but_never_arithmeticked(): void
    {
        $this->makeIntent($this->invoice);
        $huge = str_repeat('9', 78);
        $this->fakeTron([$this->tronPage([$this->tronItem(['value' => $huge])])]);

        $this->scan()->assertSuccessful();

        $transfer = TronTransfer::firstOrFail();
        $this->assertSame('value_out_of_range', $transfer->reject_reason);
        $this->assertSame($huge, $transfer->value_raw);
        $this->assertNull($transfer->value_units);
        $this->assertSame('pending', $this->invoice->fresh()->status);
    }

    public function test_dust_is_dropped_without_an_audit_row(): void
    {
        $this->makeIntent($this->invoice);
        $this->fakeTron([$this->tronPage([$this->tronItem(['value' => '1'])])]);

        $this->scan()->assertSuccessful();

        $this->assertSame('dust', TronTransfer::firstOrFail()->reject_reason);
        $this->assertSame(0, PaymentEvent::where('provider', 'tron')->count());
    }

    // ---- matching --------------------------------------------------------

    /**
     * Two reservations whose tolerance bands both accept the amount. Guessing
     * would pay off the wrong customer's invoice, so nothing settles and a human
     * decides.
     */
    public function test_an_ambiguous_amount_settles_nothing(): void
    {
        $second = $this->makeInvoice($this->accountId, $this->uniId, ['month_year' => '2026-05']);
        $this->makeIntent($this->invoice);
        // A different expected figure whose band still contains 12.34.
        $this->makeIntent($second, ['expected_units' => '12500000', 'open_units' => '12500000']);

        $this->fakeTron([$this->tronPage([$this->tronItem()])]);
        $this->scan()->assertSuccessful();

        $this->assertSame('pending', $this->invoice->fresh()->status);
        $this->assertSame('pending', $second->fresh()->status);
        $this->assertSame(TronTransfer::STATUS_UNMATCHED, TronTransfer::firstOrFail()->status);

        $event = PaymentEvent::where('provider', 'tron')->firstOrFail();
        $this->assertSame('tracking_unknown', $event->outcome);
        $this->assertStringContainsString('Ambiguous', $event->message);
    }

    public function test_an_underpayment_beyond_tolerance_is_reported_as_a_mismatch(): void
    {
        $this->makeIntent($this->invoice);   // floor is 11.34 ($1.00 shortfall)
        $this->fakeTron([$this->tronPage([$this->tronItem(['value' => '11000000'])])]);

        $this->scan()->assertSuccessful();

        $this->assertSame('pending', $this->invoice->fresh()->status);
        $this->assertSame(TronTransfer::STATUS_UNMATCHED, TronTransfer::firstOrFail()->status);

        $event = PaymentEvent::where('provider', 'tron')->firstOrFail();
        $this->assertSame('amount_mismatch', $event->outcome);
        $this->assertStringContainsString('short', $event->message);
    }

    /**
     * Within the accepted overpay band. The customer is not left unpaid over
     * change, and the audit row says what happened.
     */
    public function test_an_overpayment_within_tolerance_settles(): void
    {
        $this->makeIntent($this->invoice);   // ceiling is 12.957 (5%)
        $this->fakeTron([$this->tronPage([$this->tronItem(['value' => '12500000'])])]);

        $this->scan()->assertSuccessful();

        $this->assertSame('paid', $this->invoice->fresh()->status);
        $this->assertSame('overpaid', PaymentEvent::where('provider', 'tron')->firstOrFail()->outcome);
        // Still recorded as the invoice's USD, not the larger amount received.
        $this->assertEquals(12.34, (float) $this->invoice->fresh()->paid_amount);
    }

    /**
     * Underpayment is the ORDINARY case for anyone paying from an exchange —
     * every major one deducts its withdrawal fee from the amount typed — so the
     * flat $1.00 floor has to swallow it.
     */
    public function test_a_payment_short_by_an_exchange_withdrawal_fee_still_settles(): void
    {
        $this->makeIntent($this->invoice);
        // 12.34 typed, 1 USDT taken as the network fee.
        $this->fakeTron([$this->tronPage([$this->tronItem(['value' => '11340000'])])]);

        $this->scan()->assertSuccessful();

        $this->assertSame('paid', $this->invoice->fresh()->status);
    }

    public function test_an_expired_intent_does_not_settle_but_is_suggested(): void
    {
        $intent = $this->makeIntent($this->invoice, ['expires_at' => now()->subHour()]);
        $this->fakeTron([$this->tronPage([$this->tronItem()])]);

        $this->scan()->assertSuccessful();

        $this->assertSame('pending', $this->invoice->fresh()->status);
        $this->assertSame(PaymentIntent::STATUS_EXPIRED, $intent->fresh()->status);
        // The reservation is released, but the expectation survives — which is
        // exactly what makes the late payment traceable below.
        $this->assertNull($intent->fresh()->open_units);
        $this->assertSame((string) $intent->expected_units, (string) $intent->fresh()->expected_units);

        $transfer = TronTransfer::firstOrFail();
        $this->assertSame(TronTransfer::STATUS_UNMATCHED, $transfer->status);

        $suggestions = app(TronWatcher::class)->suggestionsFor($transfer);
        $this->assertCount(1, $suggestions);
        $this->assertSame($this->invoice->id, $suggestions[0]['invoice_id']);
        $this->assertSame('expired', $suggestions[0]['intent_status']);
        $this->assertSame(0.0, $suggestions[0]['delta_usd']);
    }

    /** Paid by card or by hand between issuing the intent and the money landing. */
    public function test_a_transfer_for_an_already_paid_invoice_is_accounted_not_lost(): void
    {
        $this->makeIntent($this->invoice);
        $this->invoice->forceFill(['status' => 'paid', 'paid_at' => now(), 'payment_provider' => 'manual'])->save();

        $this->fakeTron([$this->tronPage([$this->tronItem()])]);
        $this->scan()->assertSuccessful();

        // The first settlement keeps its provenance.
        $this->assertSame('manual', $this->invoice->fresh()->payment_provider);

        $transfer = TronTransfer::firstOrFail();
        $this->assertSame(TronTransfer::STATUS_SETTLED, $transfer->status);
        $this->assertSame($this->invoice->id, $transfer->invoice_id);
        $this->assertSame('already_paid', PaymentEvent::where('provider', 'tron')->firstOrFail()->outcome);
    }

    public function test_a_transfer_with_no_reservation_at_all_is_left_for_a_human(): void
    {
        $this->fakeTron([$this->tronPage([$this->tronItem()])]);
        $this->scan()->assertSuccessful();

        $this->assertSame(TronTransfer::STATUS_UNMATCHED, TronTransfer::firstOrFail()->status);
        $event = PaymentEvent::where('provider', 'tron')->firstOrFail();
        $this->assertSame('tracking_unknown', $event->outcome);
        $this->assertStringContainsString('No reservation', $event->message);
    }

    // ---- identity and idempotency ----------------------------------------

    public function test_rescanning_the_same_page_changes_nothing(): void
    {
        $this->makeIntent($this->invoice);
        $page = $this->tronPage([$this->tronItem()]);
        $this->fakeTron([$page]);

        $this->scan()->assertSuccessful();
        $paidAt = $this->invoice->fresh()->paid_at;

        $this->scan()->assertSuccessful();

        $this->assertSame(1, TronTransfer::count());
        $this->assertSame(1, PaymentEvent::where('provider', 'tron')->count());
        $this->assertEquals($paidAt, $this->invoice->fresh()->paid_at);
    }

    /**
     * ONE TRANSACTION CAN CARRY SEVERAL TRC-20 TRANSFERS. Keying identity on the
     * bare transaction id would collapse them into one row and silently swallow
     * the second — money received, never recorded.
     */
    public function test_two_transfers_sharing_a_transaction_id_are_two_rows(): void
    {
        $txId = 'tx-shared-0001';
        $this->fakeTron([$this->tronPage([
            $this->tronItem(['transaction_id' => $txId, 'value' => '12340000']),
            $this->tronItem(['transaction_id' => $txId, 'value' => '9990000', 'from' => $this->tronMainnetAddress]),
        ])]);

        $this->scan()->assertSuccessful();

        $this->assertSame(2, TronTransfer::where('tx_hash', $txId)->count());
        $this->assertSame(2, TronTransfer::distinct()->count('event_key'));
    }

    // ---- the request itself ----------------------------------------------

    public function test_it_asks_for_confirmed_transfers_in_ascending_order(): void
    {
        $this->fakeTron([$this->tronPage([])]);
        $this->scan()->assertSuccessful();

        $query = $this->queryOfLastRequest();

        // Finality: TRON's own rule is to read solidified blocks.
        $this->assertSame('true', $query['only_confirmed']);
        $this->assertSame('true', $query['only_to']);
        // Load-bearing. Descending order plus a page limit would advance the
        // cursor past transfers we never fetched.
        $this->assertSame('block_timestamp,asc', $query['order_by']);
    }

    public function test_the_cursor_resumes_from_the_newest_stored_transfer_less_an_overlap(): void
    {
        $blockTs = now()->subMinutes(5)->getTimestampMs();
        $this->fakeTron([$this->tronPage([$this->tronItem(['block_timestamp' => $blockTs])])]);
        $this->scan()->assertSuccessful();

        Http::fake(['nile.trongrid.test/*' => Http::response($this->tronPage([]))]);
        $this->scan()->assertSuccessful();

        $overlapMs = config('payments.tron.scan_overlap_minutes') * 60 * 1000;
        $this->assertSame($blockTs - $overlapMs, (int) $this->queryOfLastRequest()['min_timestamp']);
    }

    public function test_a_first_run_does_not_ask_for_more_history_than_the_lookback(): void
    {
        $this->fakeTron([$this->tronPage([])]);
        $this->scan()->assertSuccessful();

        $lookbackMs = config('payments.tron.scan_max_lookback_hours') * 3600 * 1000;
        $expected = now()->getTimestampMs() - $lookbackMs;

        $this->assertEqualsWithDelta($expected, (int) $this->queryOfLastRequest()['min_timestamp'], 5000);
    }

    /**
     * A dust flood larger than the page budget must not push real payments past
     * the cursor. Ascending order is what makes an exhausted budget merely a
     * pause: the next run resumes exactly where this one stopped.
     */
    public function test_an_exhausted_page_budget_loses_nothing(): void
    {
        config(['payments.tron.page_limit' => 2, 'payments.tron.max_pages' => 1]);
        $this->makeIntent($this->invoice);

        $old = now()->subMinutes(20)->getTimestampMs();
        $new = now()->subMinutes(2)->getTimestampMs();

        $this->fakeTron([
            $this->tronPage([
                $this->tronItem(['value' => '1', 'block_timestamp' => $old]),
                $this->tronItem(['value' => '1', 'block_timestamp' => $old + 1]),
            ], 'more-to-come'),
            // The real payment is on the page the first run never reached.
            $this->tronPage([$this->tronItem(['block_timestamp' => $new])]),
        ]);

        $this->scan()->assertSuccessful();
        $this->assertSame(2, TronTransfer::count());
        $this->assertSame('pending', $this->invoice->fresh()->status);

        $this->scan()->assertSuccessful();
        $this->assertSame(3, TronTransfer::count());
        $this->assertSame('paid', $this->invoice->fresh()->status);
    }

    // ---- failure ---------------------------------------------------------

    public function test_an_unreachable_trongrid_is_reported_without_failing_the_command(): void
    {
        $this->makeIntent($this->invoice);
        Http::fake(fn () => throw new ConnectionException('connection refused'));

        // A command that exits non-zero every minute floods cron mail until
        // someone silences the job that settles invoices.
        $this->scan()->assertSuccessful();

        $this->assertSame(0, TronTransfer::count());
        $this->assertSame('pending', $this->invoice->fresh()->status);
        // Nothing was stored, so the cursor is untouched and the next run asks
        // for exactly the same window.
        $this->assertNull(\App\Services\Payments\TronGateway::lastScanAt('nile'));
        $this->assertTrue(\App\Services\Payments\TronGateway::scanIsStale('nile'));
    }

    public function test_one_broken_network_does_not_stop_the_other(): void
    {
        $this->makeIntent($this->invoice);

        Http::fake([
            'api.trongrid.test/*' => fn () => throw new ConnectionException('mainnet down'),
            'nile.trongrid.test/*' => Http::response($this->tronPage([$this->tronItem()])),
        ]);

        // No --network: every configured one.
        $this->artisan('payments:watch-tron')->assertSuccessful();

        $this->assertSame('paid', $this->invoice->fresh()->status);
    }

    // ---- isolation -------------------------------------------------------

    /** Test money must never settle a real invoice, or the reverse. */
    public function test_a_transfer_on_one_network_cannot_settle_an_intent_on_another(): void
    {
        $this->makeIntent($this->invoice, ['network' => 'nile']);

        Http::fake([
            'api.trongrid.test/*' => Http::response($this->tronPage([$this->tronItem([
                'to' => $this->tronMainnetAddress,
                'token_info' => [
                    'symbol' => 'USDT',
                    'address' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
                    'decimals' => 6,
                    'name' => 'Tether USD',
                ],
            ])])),
            'nile.trongrid.test/*' => Http::response($this->tronPage([])),
        ]);

        $this->artisan('payments:watch-tron')->assertSuccessful();

        $this->assertSame('pending', $this->invoice->fresh()->status);
        $mainnetTransfer = TronTransfer::forNetwork('mainnet')->firstOrFail();
        $this->assertSame(TronTransfer::STATUS_UNMATCHED, $mainnetTransfer->status);
    }

    // ---- dry run ---------------------------------------------------------

    public function test_dry_run_writes_nothing(): void
    {
        $this->makeIntent($this->invoice);
        $this->fakeTron([$this->tronPage([$this->tronItem()])]);

        $this->scan(['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, TronTransfer::count());
        $this->assertSame(0, PaymentEvent::where('provider', 'tron')->count());
        $this->assertSame('pending', $this->invoice->fresh()->status);
        $this->assertSame(PaymentIntent::STATUS_OPEN, PaymentIntent::firstOrFail()->status);
    }

    public function test_an_unconfigured_network_is_skipped_rather_than_polled(): void
    {
        config(['payments.tron.networks.nile.address' => null]);
        Http::fake();

        $this->scan()->assertSuccessful();

        Http::assertNothingSent();
    }

    /** A checksum failure must not be mistaken for "not set up yet". */
    public function test_a_mistyped_address_is_reported_as_a_checksum_failure(): void
    {
        config(['payments.tron.networks.nile.address' => 'T9yD14Nj9j7xAB4dbGeiX9h8unkKT76qbX']);
        Http::fake();

        $this->scan()->expectsOutputToContain('checksum')->assertSuccessful();

        Http::assertNothingSent();
    }
}
