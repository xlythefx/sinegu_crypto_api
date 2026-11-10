<?php

namespace Tests\Feature;

use App\Models\BinanceAccount;
use App\Models\Invoice;
use App\Models\UserCredential;
use App\Services\InvoiceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * Two things Dima could not read off a customer's page on 2026-11-10:
 *
 *  1. How an invoice gets from "+$190 in October" to a $24.04 bill. The
 *     invoice now stores the month's closes BEFORE exchange fees and the fees
 *     themselves, so it can show "+189.70 − 69.52 = +120.18" from its own row.
 *  2. Why the Capital Flow card listed a "+$0.02 deposit". It was 0.0196 BNB
 *     (a fee-discount top-up) printed as dollars. Transfers now carry their
 *     currency, non-dollar ones stay out of the dollar totals, and the card
 *     gets every transfer, not the last five.
 *
 * Expected figures are worked out by hand, never read back from the code.
 */
class InvoiceFeeSplitTest extends EngineTestCase
{
    private function account(array $overrides = []): BinanceAccount
    {
        $uniId = $this->makeUser();

        return BinanceAccount::find($this->makeAccount($uniId, $overrides + ['unrealized_pnl' => 0]));
    }

    /** `$pnl` is the stored (after-fees) figure; `$fee` null = not recorded yet. */
    private function close(BinanceAccount $a, string $closedAt, float $pnl, ?float $fee): void
    {
        static $orderId = 910000;

        DB::table('binance_pastpositions')->insert([
            'api_key' => $a->api_key, 'uni_id' => $a->uni_id, 'symbol' => 'LTCUSDT',
            'position_side' => 'LONG', 'position_amt' => 1, 'entry_price' => 100,
            'exit_price' => 101, 'realized_pnl' => $pnl, 'exchange_fee' => $fee,
            'side' => 'SELL', 'order_id' => ++$orderId, 'closed_at' => $closedAt,
            'strategy' => 'Test', 'created_at' => now(),
        ]);
    }

    private function transfer(BinanceAccount $a, string $at, float $amount, string $currency = 'USDT'): void
    {
        static $tranId = 920000;

        DB::table('binance_transactions')->insert([
            'api_key' => $a->api_key, 'uni_id' => $a->uni_id,
            'type' => $amount >= 0 ? 'DEPOSIT' : 'WITHDRAWAL', 'amount' => abs($amount),
            'tran_id' => ++$tranId, 'currency' => $currency,
            'transaction_time' => Carbon::parse($at)->getTimestampMs(),
            'info' => 'TRANSFER', 'created_at' => $at,
        ]);
    }

    public function test_an_invoice_records_the_months_profit_before_fees_and_the_fees(): void
    {
        Carbon::setTestNow('2026-11-01 09:00:00');
        $a = $this->account(['initial_deposit' => 0, 'balance' => 2110]);
        $this->transfer($a, '2026-09-20 08:00:00', 2000);

        $this->close($a, '2026-10-05 12:00:00', 100, 30);   // 130 before, 30 fees
        $this->close($a, '2026-10-09 12:00:00', -10, 5);    // −5 before, 5 fees
        $this->close($a, '2026-10-20 12:00:00', 20, null);  // fee not recorded: counts once
        $this->close($a, '2026-09-28 12:00:00', 999, 1);    // September: not this invoice
        $this->close($a, '2026-11-01 01:00:00', 999, 1);    // November: not this invoice

        $inv = app(InvoiceService::class)->generateForAccount($a->refresh(), '2026-10', ['realized' => 20, 'unrealized' => 6]);

        // After fees 100 − 10 + 20 = 110; before fees 130 − 5 + 20 = 145.
        $this->assertEqualsWithDelta(110, (float) $inv->realized_pnl, 0.001);
        $this->assertEqualsWithDelta(145, (float) $inv->realized_pnl_gross, 0.001);
        $this->assertEqualsWithDelta(35, (float) $inv->exchange_fees, 0.001);

        $payload = $inv->toApiArray('Test', false);
        $this->assertSame(145.0, $payload['realized_pnl_gross']);
        $this->assertSame(35.0, $payload['exchange_fees']);
        $this->assertSame(110.0, $payload['realized_pnl']);
        $this->assertArrayHasKey('performance_equity', $payload);
    }

    public function test_an_invoice_made_before_the_split_existed_says_not_recorded(): void
    {
        $a = $this->account();
        $inv = Invoice::create([
            'user_id' => $a->uni_id, 'account_id' => $a->id, 'exchange' => 'binance',
            'api_key' => $a->api_key, 'month_year' => '2026-08', 'realized_pnl' => 50,
            'total_fee' => 10, 'status' => 'paid',
        ]);

        $payload = $inv->toApiArray('Test', false);

        // Null, never 0: a $0 fee line would read as free trading.
        $this->assertNull($payload['realized_pnl_gross']);
        $this->assertNull($payload['exchange_fees']);
    }

    public function test_the_capital_flow_card_lists_coins_in_their_own_currency(): void
    {
        $a = $this->account(['initial_deposit' => 0, 'balance' => 2025.45]);
        Sanctum::actingAs(UserCredential::query()->find($a->uni_id));

        // Alex's real history, 2026-09-13 → 2026-10-06.
        $this->transfer($a, '2026-09-13 10:34:33', 0.15606164);
        $this->transfer($a, '2026-09-23 09:23:20', 1000.7780736);
        $this->transfer($a, '2026-09-28 10:37:47', 1100);
        $this->transfer($a, '2026-09-28 11:19:33', -15);
        $this->transfer($a, '2026-09-28 11:23:52', 0.01957863, 'BNB');
        $this->transfer($a, '2026-10-02 09:04:48', -5);
        $this->transfer($a, '2026-10-06 11:16:13', 0.00635962, 'BNB');
        $this->transfer($a, '2026-10-06 11:18:10', -250);

        $flows = $this->getJson('/api/analytics')->assertOk()->json('analytics.flows');

        // Dollars only: 0.16 + 1,000.78 + 1,100 = 2,100.93 in; 270 out.
        $this->assertSame(2100.93, (float) $flows['deposits']);
        $this->assertSame(270.0, (float) $flows['withdrawals']);
        $this->assertSame(1830.93, (float) $flows['net_flow']);

        // Every transfer, newest first — not the last five.
        $this->assertSame(8, $flows['count']);
        $this->assertCount(8, $flows['recent']);
        $this->assertSame('WITHDRAWAL', $flows['recent'][0]['type']);
        $this->assertSame(250.0, (float) $flows['recent'][0]['amount']);

        $bnb = collect($flows['recent'])->firstWhere('currency', 'BNB');
        $this->assertSame(0.00635962, (float) $bnb['amount'], 'a coin amount keeps its decimals');
        $this->assertFalse($bnb['in_totals']);
        $this->assertTrue(collect($flows['recent'])->firstWhere('currency', 'USDT')['in_totals']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
