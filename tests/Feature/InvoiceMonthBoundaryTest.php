<?php

namespace Tests\Feature;

use App\Models\BinanceAccount;
use App\Services\InvoiceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A billing month must be read from its own 'YYYY-MM' string and nothing else.
 *
 * The trap these tests pin: `Carbon::createFromFormat('Y-m', '2026-06')` fills
 * the missing day from TODAY, so on the 31st it produces June 31 → overflows to
 * July 1. An invoice generated on a 31st then billed the *following* month's
 * trades. The fix is the '!' modifier, which zeroes every unspecified field.
 */
class InvoiceMonthBoundaryTest extends EngineTestCase
{
    private const RATES = ['realized' => 20.0, 'unrealized' => 6.0];

    /** Insert a closed trade on a given date. */
    private function trade(string $apiKey, string $uniId, string $closedAt, float $pnl): void
    {
        static $orderId = 900000;

        DB::table('binance_pastpositions')->insert([
            'api_key' => $apiKey,
            'uni_id' => $uniId,
            'symbol' => 'BTCUSDT',
            'position_side' => 'LONG',
            'position_amt' => 1,
            'entry_price' => 30000,
            'exit_price' => 30000 + $pnl,
            'realized_pnl' => $pnl,
            'side' => 'SELL',
            'order_id' => ++$orderId,
            'closed_at' => $closedAt,
            'strategy' => 'Test',
            'is_sandbox' => true,
            'created_at' => now(),
        ]);
    }

    /**
     * Generating June's invoice on the 31st of July must bill June's trades.
     * Before the fix this billed July and produced a 200 fee instead of 100.
     */
    public function test_generating_on_a_31st_bills_the_requested_month(): void
    {
        Carbon::setTestNow('2026-07-31 10:00:00');

        $uniId = $this->makeUser();
        $accountId = $this->makeAccount($uniId, [
            'balance' => 10500,
            'unrealized_pnl' => 0,
            'initial_deposit' => 10000,
        ]);
        $account = BinanceAccount::find($accountId);

        $this->trade($account->api_key, $uniId, '2026-06-15 12:00:00', 500);
        $this->trade($account->api_key, $uniId, '2026-07-15 12:00:00', 1000);

        $invoice = app(InvoiceService::class)
            ->generateForAccount($account, '2026-06', self::RATES);

        $this->assertSame('2026-06', $invoice->month_year);
        $this->assertEqualsWithDelta(500, (float) $invoice->realized_pnl, 0.01, 'June trade only');
        $this->assertEqualsWithDelta(100, (float) $invoice->total_fee, 0.01);
        // Due on the 8th of the month after the period, never a month later.
        $this->assertSame('2026-07-04', $invoice->due_date->toDateString());
    }

    /** February is the sharpest edge: no 29th, 30th or 31st in a common year. */
    public function test_short_month_is_parsed_on_a_31st(): void
    {
        Carbon::setTestNow('2026-03-31 10:00:00');

        $uniId = $this->makeUser();
        $accountId = $this->makeAccount($uniId, [
            'balance' => 10300,
            'unrealized_pnl' => 0,
            'initial_deposit' => 10000,
        ]);
        $account = BinanceAccount::find($accountId);

        $this->trade($account->api_key, $uniId, '2026-02-20 12:00:00', 300);
        $this->trade($account->api_key, $uniId, '2026-03-20 12:00:00', 900);

        $invoice = app(InvoiceService::class)
            ->generateForAccount($account, '2026-02', self::RATES);

        $this->assertEqualsWithDelta(300, (float) $invoice->realized_pnl, 0.01);
        $this->assertEqualsWithDelta(60, (float) $invoice->total_fee, 0.01);
    }

    /** The label the trader reads must match the period that was billed. */
    public function test_api_payload_labels_the_correct_month_on_a_31st(): void
    {
        Carbon::setTestNow('2026-07-31 10:00:00');

        $uniId = $this->makeUser();
        $accountId = $this->makeAccount($uniId, [
            'balance' => 10500,
            'unrealized_pnl' => 0,
            'initial_deposit' => 10000,
        ]);
        $account = BinanceAccount::find($accountId);

        $invoice = app(InvoiceService::class)
            ->generateForAccount($account, '2026-06', self::RATES);

        $payload = $invoice->toApiArray('Test Account', true);

        $this->assertSame('June 2026', $payload['month_label']);
        $this->assertSame('2026-07-01', $payload['invoice_date']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
