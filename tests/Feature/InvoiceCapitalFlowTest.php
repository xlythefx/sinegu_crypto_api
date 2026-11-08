<?php

namespace Tests\Feature;

use App\Models\BinanceAccount;
use App\Models\Invoice;
use App\Services\InvoiceService;
use App\Services\UserStatsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Money the customer adds or takes out is neither profit nor loss
 * (InvoiceService::applyHighWaterMark, 2026-11-08).
 *
 * The incident: October 2026, one customer closed +120.18, withdrew 254.99,
 * and was invoiced $0 — the high-water test compared the raw balance with the
 * mark, so the withdrawal read as a trading loss. Every figure below is
 * derived by hand from the rule, never from the code under test.
 */
class InvoiceCapitalFlowTest extends EngineTestCase
{
    private const RATES = ['realized' => 20.0, 'unrealized' => 6.0];

    private function account(array $overrides = []): BinanceAccount
    {
        $uniId = $this->makeUser();

        return BinanceAccount::find($this->makeAccount($uniId, $overrides + ['unrealized_pnl' => 0]));
    }

    private function trade(BinanceAccount $a, string $closedAt, float $pnl): void
    {
        static $orderId = 700000;

        DB::table('binance_pastpositions')->insert([
            'api_key' => $a->api_key, 'uni_id' => $a->uni_id, 'symbol' => 'BTCUSDT',
            'position_side' => 'LONG', 'position_amt' => 1, 'entry_price' => 30000,
            'exit_price' => 30000 + $pnl, 'realized_pnl' => $pnl, 'side' => 'SELL',
            'order_id' => ++$orderId, 'closed_at' => $closedAt, 'strategy' => 'Test',
            'is_sandbox' => true, 'created_at' => now(),
        ]);
    }

    /** + = deposit, − = withdrawal, dated when the money moved. */
    private function transfer(BinanceAccount $a, string $at, float $amount): void
    {
        static $tranId = 800000;

        DB::table('binance_transactions')->insert([
            'api_key' => $a->api_key, 'uni_id' => $a->uni_id,
            'type' => $amount >= 0 ? 'DEPOSIT' : 'WITHDRAWAL', 'amount' => abs($amount),
            'tran_id' => ++$tranId, 'currency' => 'USDT',
            'transaction_time' => Carbon::parse($at)->getTimestampMs(),
            'info' => 'TRANSFER', 'created_at' => $at,
        ]);
    }

    /** Last month's invoice, closed at `$hwm`. */
    private function priorInvoice(BinanceAccount $a, string $month, float $hwm): void
    {
        Invoice::create([
            'user_id' => $a->uni_id, 'account_id' => $a->id, 'exchange' => 'binance',
            'api_key' => $a->api_key, 'month_year' => $month,
            'equity_end' => $hwm, 'hwm_before' => $hwm, 'hwm_after' => $hwm,
            'total_fee' => 0, 'status' => 'paid',
        ]);
    }

    private function generate(BinanceAccount $a, string $month): Invoice
    {
        return app(InvoiceService::class)->generateForAccount($a->refresh(), $month, self::RATES);
    }

    public function test_a_withdrawal_in_a_winning_month_is_billed(): void
    {
        Carbon::setTestNow('2026-11-01 09:00:00');
        $a = $this->account(['initial_deposit' => 0, 'balance' => 1870]);
        $this->priorInvoice($a, '2026-09', 2000);
        $this->trade($a, '2026-10-15 12:00:00', 120);
        $this->transfer($a, '2026-10-06 11:00:00', -250);

        $inv = $this->generate($a, '2026-10');

        // 1,870 + the 250 taken out = 2,120 > the 2,000 mark → 20% of 120.
        $this->assertEqualsWithDelta(2120, (float) $inv->performance_equity, 0.001);
        $this->assertEqualsWithDelta(24, (float) $inv->total_fee, 0.001);
        $this->assertSame('pending', $inv->status);
        // The mark follows the money out: 2,120 − 250.
        $this->assertEqualsWithDelta(1870, (float) $inv->hwm_after, 0.001);
    }

    public function test_a_withdrawal_lowers_the_mark_for_the_next_month(): void
    {
        $a = $this->account(['initial_deposit' => 10000, 'balance' => 8700]);
        $this->priorInvoice($a, '2026-08', 11000);

        // September: −300 traded, 2,000 withdrawn → 10,700 < 11,000, nothing billed.
        Carbon::setTestNow('2026-10-01 09:00:00');
        $this->trade($a, '2026-09-10 12:00:00', -300);
        $this->transfer($a, '2026-09-12 08:00:00', -2000);
        $sep = $this->generate($a, '2026-09');
        $this->assertEqualsWithDelta(0, (float) $sep->total_fee, 0.001);
        $this->assertSame('paid', $sep->status);
        $this->assertEqualsWithDelta(9000, (float) $sep->hwm_after, 0.001, '11,000 − 2,000');

        // October: +400 → 9,100, above the 9,000 mark (the old rule wanted 11,000).
        Carbon::setTestNow('2026-11-01 09:00:00');
        DB::table('binance_accounts')->where('id', $a->id)->update(['balance' => 9100]);
        $this->trade($a, '2026-10-10 12:00:00', 400);
        $oct = $this->generate($a, '2026-10');
        $this->assertEqualsWithDelta(9000, (float) $oct->hwm_before, 0.001);
        $this->assertEqualsWithDelta(80, (float) $oct->total_fee, 0.001);
        $this->assertEqualsWithDelta(9100, (float) $oct->hwm_after, 0.001);
    }

    public function test_a_deposit_cannot_open_billing_on_a_losing_month(): void
    {
        Carbon::setTestNow('2026-11-01 09:00:00');
        // 10,000 − 800 closed + 5,000 deposited = 14,200, plus 500 open = 14,700.
        $a = $this->account(['initial_deposit' => 10000, 'balance' => 14200, 'unrealized_pnl' => 500]);
        $this->trade($a, '2026-10-08 12:00:00', -800);
        $this->transfer($a, '2026-10-20 08:00:00', 5000);

        $inv = $this->generate($a, '2026-10');

        // 14,700 − 5,000 = 9,700 < 10,000: not even the 6% on the open 500.
        $this->assertEqualsWithDelta(9700, (float) $inv->performance_equity, 0.001);
        $this->assertEqualsWithDelta(0, (float) $inv->total_fee, 0.001);
        $this->assertSame('paid', $inv->status);
        $this->assertEqualsWithDelta(15000, (float) $inv->hwm_after, 0.001, 'the mark rises with the deposit');
    }

    public function test_a_transfer_after_the_month_ended_but_before_the_run_is_set_aside(): void
    {
        // The run reads the balance on the 1st; a withdrawal made that morning
        // is already out of it but belongs to November.
        Carbon::setTestNow('2026-11-01 09:00:00');
        $a = $this->account(['initial_deposit' => 10000, 'balance' => 7100]);
        $this->priorInvoice($a, '2026-09', 10000);
        $this->trade($a, '2026-10-12 12:00:00', 100);
        $this->transfer($a, '2026-11-01 03:00:00', -3000);

        $inv = $this->generate($a, '2026-10');

        $this->assertEqualsWithDelta(0, (float) $inv->capital_flow, 0.001, 'not an October transfer');
        $this->assertEqualsWithDelta(10100, (float) $inv->performance_equity, 0.001);
        $this->assertEqualsWithDelta(20, (float) $inv->total_fee, 0.001);
        // October-end money: November's own flow (−3,000) moves it next month.
        $this->assertEqualsWithDelta(10100, (float) $inv->hwm_after, 0.001);
    }

    public function test_a_first_month_funded_inside_the_month_bills_only_the_trading(): void
    {
        // Every real customer: connected empty (ledger seed 0), funded that month.
        Carbon::setTestNow('2026-10-01 09:00:00');
        $a = $this->account(['initial_deposit' => 0, 'balance' => 2040]);
        $this->transfer($a, '2026-09-24 06:00:00', 2000);
        $this->trade($a, '2026-09-26 12:00:00', 40);

        $inv = $this->generate($a, '2026-09');

        $this->assertEqualsWithDelta(0, (float) $inv->hwm_before, 0.001);
        $this->assertEqualsWithDelta(2000, (float) $inv->capital_flow, 0.001);
        $this->assertEqualsWithDelta(8, (float) $inv->total_fee, 0.001);
        $this->assertEqualsWithDelta(2040, (float) $inv->hwm_after, 0.001);
    }

    public function test_a_first_invoice_starts_from_the_capital_already_there(): void
    {
        // Funded in August, first billed for September.
        Carbon::setTestNow('2026-10-01 09:00:00');
        $a = $this->account(['initial_deposit' => 0, 'balance' => 1050]);
        $this->transfer($a, '2026-08-20 06:00:00', 1000);
        $this->trade($a, '2026-09-10 12:00:00', 50);

        $inv = $this->generate($a, '2026-09');

        $this->assertEqualsWithDelta(1000, (float) $inv->hwm_before, 0.001);
        $this->assertEqualsWithDelta(10, (float) $inv->total_fee, 0.001);
        $this->assertEqualsWithDelta(1050, (float) $inv->hwm_after, 0.001);
    }

    public function test_screens_show_the_latest_mark_not_the_highest(): void
    {
        $a = $this->account(['initial_deposit' => 0, 'balance' => 5000]);
        $this->priorInvoice($a, '2026-10', 5381.16);
        $this->priorInvoice($a, '2026-09', 11291.63); // older, higher, no longer applies

        $this->assertSame(
            ['binance:'.$a->id => 5381.16],
            Invoice::latestHwmByAccount(DB::table('invoices')->get()),
        );
        $this->assertEqualsWithDelta(5381.16, app(UserStatsService::class)->summary($a->uni_id)['hwm'], 0.001);
    }

    public function test_the_invoice_payload_states_the_money_moved(): void
    {
        Carbon::setTestNow('2026-11-01 09:00:00');
        $a = $this->account(['initial_deposit' => 0, 'balance' => 1870]);
        $this->priorInvoice($a, '2026-09', 2000);
        $this->trade($a, '2026-10-15 12:00:00', 120);
        $this->transfer($a, '2026-10-06 11:00:00', -250);

        $payload = $this->generate($a, '2026-10')->toApiArray('Test', false);

        $this->assertSame(-250.0, $payload['capital_flow']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
