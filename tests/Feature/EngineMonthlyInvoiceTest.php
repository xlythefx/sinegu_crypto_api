<?php

namespace Tests\Feature;

use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

/** POST /api/engine/binance/invoices/monthly — the engine's monthly run. */
class EngineMonthlyInvoiceTest extends EngineTestCase
{
    private const MONTH = '2026-09';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-01 15:00:00'); // the 1st, 23:00 Asia/Manila
    }

    /** A customer account that made $500 realized in September on a 1000 deposit. */
    private function profitableCustomer(array $user = [], array $account = []): int
    {
        $uni = $this->makeUser($user);
        $id = $this->makeAccount($uni, array_merge(['balance' => 1500, 'created_at' => '2026-08-01'], $account));
        $apiKey = DB::table('binance_accounts')->where('id', $id)->value('api_key');
        DB::table('binance_pastpositions')->insert([
            'api_key' => $apiKey, 'uni_id' => $uni, 'symbol' => 'LTCUSDT', 'position_side' => 'LONG',
            'position_amt' => 1, 'realized_pnl' => 500, 'side' => 'SELL', 'order_id' => random_int(1, 1_000_000),
            'closed_at' => '2026-09-15 10:00:00',
        ]);

        return $id;
    }

    private function run_(array $body = ['month_year' => self::MONTH])
    {
        return $this->postJson('/api/engine/binance/invoices/monthly', $body, $this->engineHeaders());
    }

    public function test_requires_the_engine_secret(): void
    {
        $this->postJson('/api/engine/binance/invoices/monthly', ['month_year' => self::MONTH])->assertStatus(401);
    }

    public function test_bills_customers_through_the_invoice_service(): void
    {
        $id = $this->profitableCustomer();

        $this->run_()->assertOk()
            ->assertJsonPath('totals.created', 1)
            ->assertJsonPath('totals.billed', 1)
            ->assertJsonPath('totals.amount', 100) // 20% of 500 realized
            ->assertJsonPath('created.0.due_date', '2026-10-04');

        $invoice = Invoice::where(['account_id' => $id, 'month_year' => self::MONTH])->firstOrFail();
        $this->assertSame('pending', $invoice->status);
        $this->assertSame('pnl', $invoice->fee_source);
    }

    public function test_never_bills_master_staff_demo_or_sandbox(): void
    {
        $this->profitableCustomer(['type' => 'master']);
        $this->profitableCustomer(['type' => 'admin']);
        $this->profitableCustomer(['type' => 'developer']);
        $this->profitableCustomer([], ['demo' => 1]);
        $this->profitableCustomer([], ['is_sandbox' => 1]);

        $this->run_()->assertOk()->assertJsonPath('totals.created', 0);
        $this->assertSame(0, Invoice::count());
    }

    public function test_an_existing_invoice_is_skipped_never_regenerated(): void
    {
        $id = $this->profitableCustomer();
        $this->run_()->assertOk();
        // An admin replaces it with a typed fee…
        Invoice::where('account_id', $id)->update(['total_fee' => 42, 'fee_source' => 'manual']);

        // …and a second run (catch-up, retry) leaves it exactly as it is.
        $this->run_()->assertOk()
            ->assertJsonPath('totals.created', 0)
            ->assertJsonPath('skipped.0.reason', 'already invoiced');
        $this->assertSame('manual', Invoice::where('account_id', $id)->value('fee_source'));
        $this->assertEquals(42, (float) Invoice::where('account_id', $id)->value('total_fee'));
    }

    public function test_the_running_month_is_refused(): void
    {
        $this->profitableCustomer();
        $this->run_(['month_year' => '2026-10'])->assertStatus(422)->assertJson(['error_code' => 'MONTH_NOT_ENDED']);
        $this->assertSame(0, Invoice::count());
    }

    public function test_an_account_connected_after_the_month_is_not_billed_for_it(): void
    {
        $this->profitableCustomer([], ['created_at' => '2026-10-01 01:00:00']);
        $this->run_()->assertOk()->assertJsonPath('totals.created', 0);
    }

    public function test_a_disconnected_account_that_traded_is_named_for_a_human(): void
    {
        $id = $this->profitableCustomer(['name' => 'Gone Gary']);
        DB::table('binance_accounts')->where('id', $id)->update(['deleted_at' => '2026-09-20']);

        $this->run_()->assertOk()
            ->assertJsonPath('totals.created', 0)
            ->assertJsonPath('skipped.0.owner_name', 'Gone Gary');
    }

    public function test_other_venues_are_refused_until_they_have_a_pnl_source(): void
    {
        $this->postJson('/api/engine/mexc/invoices/monthly', ['month_year' => self::MONTH], $this->engineHeaders())
            ->assertStatus(422)->assertJson(['error_code' => 'EXCHANGE_UNSUPPORTED']);
    }
}
