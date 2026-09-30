<?php

namespace Tests\Feature;

use App\Models\UserCredential;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * POST /admin/invoices/manual — an invoice for one account + month at a fee
 * the admin typed (InvoiceService::generateManual).
 */
class ManualInvoiceTest extends EngineTestCase
{
    private function admin(): void
    {
        Sanctum::actingAs(UserCredential::query()->find($this->makeUser(['type' => 'admin'])));
    }

    private function manual(int $accountId, float $amount, string $month = '2026-08')
    {
        return $this->postJson('/api/admin/invoices/manual', [
            'account_id' => $accountId, 'month_year' => $month, 'amount' => $amount,
        ]);
    }

    public function test_it_bills_the_typed_amount_and_says_so(): void
    {
        $id = $this->makeAccount($this->makeUser());
        $this->admin();

        $this->manual($id, 15)
            ->assertCreated()
            ->assertJsonPath('invoices.0.total_fee', 15)
            ->assertJsonPath('invoices.0.fee_source', 'manual')
            ->assertJsonPath('invoices.0.fee_realized', 0)
            ->assertJsonPath('invoices.0.fee_unrealized', 0)
            ->assertJsonPath('invoices.0.status', 'pending')
            ->assertJsonPath('invoices.0.is_overdue', false);

        $row = DB::table('invoices')->first();
        $this->assertSame('manual', $row->fee_source);
        // Due a week from today, never from the (past) billing month — or the
        // overdue sweep would disable the account the night it was created.
        $this->assertSame(now()->addDays(7)->toDateString(), substr((string) $row->due_date, 0, 10));
    }

    public function test_it_replaces_an_unpaid_invoice_and_regenerating_resets_the_source(): void
    {
        $id = $this->makeAccount($this->makeUser());
        $this->admin();

        $this->postJson('/api/admin/invoices/generate', ['account_id' => $id, 'month_year' => '2026-08'])->assertCreated();
        $this->manual($id, 42.5)->assertCreated();

        $this->assertSame(1, DB::table('invoices')->count());
        $this->assertEquals(42.5, (float) DB::table('invoices')->value('total_fee'));

        $this->postJson('/api/admin/invoices/generate', ['account_id' => $id, 'month_year' => '2026-08'])
            ->assertCreated()
            ->assertJsonPath('invoices.0.fee_source', 'pnl');
    }

    public function test_a_paid_invoice_is_never_overwritten(): void
    {
        $id = $this->makeAccount($this->makeUser());
        $this->admin();
        $this->manual($id, 10)->assertCreated();
        DB::table('invoices')->update(['status' => 'paid']);

        $this->manual($id, 99)->assertStatus(409)->assertJsonPath('error_code', 'INVOICE_ALREADY_PAID');
        $this->assertEquals(10.0, (float) DB::table('invoices')->value('total_fee'));
    }

    public function test_a_zero_fee_month_marked_paid_can_still_be_billed_by_hand(): void
    {
        $id = $this->makeAccount($this->makeUser());
        $this->admin();
        // No P&L → generate stores a $0 row as 'paid'; nothing was collected.
        $this->postJson('/api/admin/invoices/generate', ['account_id' => $id, 'month_year' => '2026-08'])
            ->assertCreated()->assertJsonPath('invoices.0.status', 'paid');

        $this->manual($id, 15)->assertCreated()->assertJsonPath('invoices.0.status', 'pending');
    }

    public function test_the_master_is_refused(): void
    {
        $id = $this->makeAccount($this->makeUser(['type' => 'master']));
        $this->admin();

        $this->manual($id, 10)->assertStatus(422)->assertJsonPath('error_code', 'NOT_INVOICEABLE');
        $this->assertSame(0, DB::table('invoices')->count());
    }

    public function test_the_amount_must_be_positive(): void
    {
        $id = $this->makeAccount($this->makeUser());
        $this->admin();

        $this->manual($id, 0)->assertStatus(422);
        $this->assertSame(0, DB::table('invoices')->count());
    }

    public function test_a_plain_user_cannot_call_it(): void
    {
        $id = $this->makeAccount($this->makeUser());
        Sanctum::actingAs(UserCredential::query()->find($this->makeUser()));

        $this->manual($id, 10)->assertForbidden();
    }
}
