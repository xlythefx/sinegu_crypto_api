<?php

namespace Tests\Feature;

use App\Models\BinanceAccount;
use App\Models\UserCredential;
use App\Services\InvoiceService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * The master account is the house's own trading and is never invoiced
 * (owner's decision, 2026-09-25) — enforced in InvoiceService, so the admin
 * screen and any future monthly run agree.
 */
class MasterNotInvoicedTest extends EngineTestCase
{
    private function admin(): void
    {
        Sanctum::actingAs(UserCredential::query()->find($this->makeUser(['type' => 'admin'])));
    }

    public function test_the_admin_generate_refuses_the_master(): void
    {
        $master = $this->makeUser(['type' => 'master']);
        $id = $this->makeAccount($master);
        $this->admin();

        $this->postJson('/api/admin/invoices/generate', ['account_id' => $id, 'month_year' => '2026-08'])
            ->assertStatus(422)->assertJsonPath('error_code', 'NOT_INVOICEABLE');

        $this->assertSame(0, DB::table('invoices')->count());
    }

    public function test_the_service_itself_refuses_the_master(): void
    {
        $master = $this->makeUser(['type' => 'master']);
        $account = BinanceAccount::query()->find($this->makeAccount($master));

        $this->expectException(\DomainException::class);
        app(InvoiceService::class)->generateForAccount($account, '2026-08', ['realized' => 20, 'unrealized' => 6]);
    }

    public function test_a_customer_is_still_invoiced(): void
    {
        $user = $this->makeUser();
        $id = $this->makeAccount($user);
        $this->admin();

        $this->postJson('/api/admin/invoices/generate', ['account_id' => $id, 'month_year' => '2026-08'])
            ->assertCreated();
    }

    public function test_scenario_scratch_accounts_are_exempt(): void
    {
        $master = $this->makeUser(['type' => 'master']);
        $account = BinanceAccount::query()->find($this->makeAccount($master, ['api_key' => 'SBXINV-master']));

        $this->assertNull(InvoiceService::notInvoiceableReason($account));
    }
}
