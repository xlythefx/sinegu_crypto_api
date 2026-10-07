<?php

namespace Tests\Feature;

use App\Mail\InvoiceIssued;
use App\Mail\PaymentReminder;
use App\Models\Invoice;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/** POST /api/engine/binance/invoices/monthly — the engine's monthly run. */
class EngineMonthlyInvoiceTest extends EngineTestCase
{
    private const MONTH = '2026-09';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-01 09:00:00'); // the 1st, 16:00 Asia/Bangkok
        Mail::fake();
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

    /**
     * Only the month that just ended is billed on the robot's word. An older
     * month's due date (the 4th after it) is already past — the invoice would
     * be born overdue and the next enforce step would pause the account — and
     * it would be priced from today's balance, not that month's.
     */
    public function test_only_the_month_that_just_ended_is_billed_without_force(): void
    {
        $this->profitableCustomer();

        // Two months back, on 1 October: refused, naming the month expected.
        $this->run_(['month_year' => '2026-08'])->assertStatus(422)
            ->assertJson(['error_code' => 'MONTH_NOT_PREVIOUS', 'expected_month_year' => '2026-09']);
        $this->assertSame(0, Invoice::count());

        // The month that just ended — what the engine sends — is accepted.
        $this->run_(['month_year' => '2026-09'])->assertOk()->assertJsonPath('totals.created', 1);
    }

    public function test_force_lets_a_human_bill_an_older_month(): void
    {
        $id = $this->profitableCustomer();

        $this->run_(['month_year' => '2026-08', 'force' => true])->assertOk()
            ->assertJsonPath('month_year', '2026-08')
            ->assertJsonPath('totals.created', 1)
            ->assertJsonPath('totals.failed', 0);
        $this->assertTrue(Invoice::where(['account_id' => $id, 'month_year' => '2026-08'])->exists());

        // Force never reaches into a month that has not ended.
        $this->run_(['month_year' => '2026-10', 'force' => true])->assertStatus(422)
            ->assertJson(['error_code' => 'MONTH_NOT_ENDED']);
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

    public function test_the_invoice_email_goes_to_each_billed_customer(): void
    {
        $this->profitableCustomer(['email' => 'alice@example.com']);

        $this->run_()->assertOk()->assertJsonPath('totals.emailed', 1);
        Mail::assertSent(InvoiceIssued::class, fn ($m) => $m->hasTo('alice@example.com')
            && $m->amount === 100.0 && $m->dueDate === '4 Oct 2026');
    }

    private function remind(string $stage)
    {
        return $this->postJson('/api/engine/binance/invoices/remind', ['month_year' => self::MONTH, 'stage' => $stage], $this->engineHeaders());
    }

    public function test_reminders_on_the_2nd_and_3rd_reach_only_the_unpaid(): void
    {
        $this->profitableCustomer(['email' => 'unpaid@example.com']);
        $paid = $this->profitableCustomer(['email' => 'paid@example.com']);
        $this->run_()->assertOk();
        Invoice::where('account_id', $paid)->update(['status' => 'paid']);

        $this->travelTo('2026-10-02 09:00:00');
        $this->remind('gentle')->assertOk()->assertJsonPath('totals.unpaid', 1)->assertJsonPath('totals.emailed', 1);
        Mail::assertSent(PaymentReminder::class, fn ($m) => $m->hasTo('unpaid@example.com') && $m->stage === PaymentReminder::STAGE_GENTLE);
        Mail::assertNotSent(PaymentReminder::class, fn ($m) => $m->hasTo('paid@example.com'));

        $this->travelTo('2026-10-03 09:00:00');
        $this->remind('firm')->assertOk();
        Mail::assertSent(PaymentReminder::class, fn ($m) => $m->stage === PaymentReminder::STAGE_FIRM && $m->pauseDate === '4 Oct 2026');
    }

    public function test_the_4th_pauses_unpaid_accounts_and_says_so_but_the_night_before_does_not(): void
    {
        $id = $this->profitableCustomer(['email' => 'late@example.com']);
        $this->run_()->assertOk();

        // 00:10 UTC on the 4th: the nightly safety net must not pause an invoice due TODAY.
        $this->travelTo('2026-10-04 00:10:00');
        Artisan::call('engine:mark-overdue');
        $this->assertSame(1, (int) DB::table('binance_accounts')->where('id', $id)->value('enabled'));

        $this->travelTo('2026-10-04 09:00:00');
        $this->postJson('/api/engine/binance/invoices/enforce', [], $this->engineHeaders())->assertOk()
            ->assertJsonPath('totals.overdue', 1)
            ->assertJsonPath('totals.disabled', 1)
            ->assertJsonPath('totals.emailed', 1);
        $this->assertSame(0, (int) DB::table('binance_accounts')->where('id', $id)->value('enabled'));
        $this->assertSame('overdue', Invoice::where('account_id', $id)->value('status'));
        Mail::assertSent(PaymentReminder::class, fn ($m) => $m->hasTo('late@example.com') && $m->stage === PaymentReminder::STAGE_PAUSED);
    }

    public function test_a_paid_invoice_is_never_paused(): void
    {
        $id = $this->profitableCustomer();
        $this->run_()->assertOk();
        Invoice::where('account_id', $id)->update(['status' => 'paid']);

        $this->travelTo('2026-10-04 09:00:00');
        $this->postJson('/api/engine/binance/invoices/enforce', [], $this->engineHeaders())->assertOk()->assertJsonPath('totals.overdue', 0);
        $this->assertSame(1, (int) DB::table('binance_accounts')->where('id', $id)->value('enabled'));
    }
}
