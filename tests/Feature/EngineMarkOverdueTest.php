<?php

namespace Tests\Feature;

use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

/** engine:mark-overdue — the billing gate's disable half. */
class EngineMarkOverdueTest extends EngineTestCase
{
    private function makeInvoice(int $accountId, string $apiKey, string $userId, array $overrides = []): Invoice
    {
        return Invoice::create(array_merge([
            'user_id' => $userId,
            'account_id' => $accountId,
            'exchange' => 'binance',
            'api_key' => $apiKey,
            'month_year' => '2026-06',
            'total_fee' => 50,
            'status' => 'pending',
            'due_date' => now()->subDays(3)->toDateString(),
        ], $overrides));
    }

    public function test_past_due_invoice_disables_the_account(): void
    {
        $user = $this->makeUser();
        $accountId = $this->makeAccount($user);
        $apiKey = DB::table('binance_accounts')->find($accountId)->api_key;
        $invoice = $this->makeInvoice($accountId, $apiKey, $user);

        $this->artisan('engine:mark-overdue')->assertSuccessful();

        $this->assertSame('overdue', $invoice->fresh()->status);
        $this->assertSame(0, (int) DB::table('binance_accounts')->find($accountId)->enabled);
    }

    /**
     * A sandbox-billed master invoice left unpaid goes overdue, but must never
     * stop the house trading.
     */
    public function test_an_overdue_master_invoice_never_disables_the_master(): void
    {
        $master = $this->makeUser(['type' => 'master']);
        $accountId = $this->makeAccount($master);
        $apiKey = DB::table('binance_accounts')->find($accountId)->api_key;
        $invoice = $this->makeInvoice($accountId, $apiKey, $master);

        $this->artisan('engine:mark-overdue')->assertSuccessful();

        $this->assertSame('overdue', $invoice->fresh()->status);
        $this->assertSame(1, (int) DB::table('binance_accounts')->find($accountId)->enabled);
    }

    public function test_paid_and_not_yet_due_invoices_are_untouched(): void
    {
        $user = $this->makeUser();
        $accountId = $this->makeAccount($user);
        $apiKey = DB::table('binance_accounts')->find($accountId)->api_key;

        $paid = $this->makeInvoice($accountId, $apiKey, $user, [
            'status' => 'paid', 'month_year' => '2026-05',
        ]);
        $future = $this->makeInvoice($accountId, $apiKey, $user, [
            'due_date' => now()->addDays(5)->toDateString(), 'month_year' => '2026-07',
        ]);

        $this->artisan('engine:mark-overdue')->assertSuccessful();

        $this->assertSame('paid', $paid->fresh()->status);
        $this->assertSame('pending', $future->fresh()->status);
        $this->assertSame(1, (int) DB::table('binance_accounts')->find($accountId)->enabled);
    }

    public function test_suspended_owner_accounts_are_disabled(): void
    {
        $suspended = $this->makeUser(['status' => 'suspended']);
        $active = $this->makeUser();
        $suspendedAccount = $this->makeAccount($suspended);
        $activeAccount = $this->makeAccount($active);

        $this->artisan('engine:mark-overdue')->assertSuccessful();

        $this->assertSame(0, (int) DB::table('binance_accounts')->find($suspendedAccount)->enabled);
        $this->assertSame(1, (int) DB::table('binance_accounts')->find($activeAccount)->enabled);
    }

    public function test_settle_reenables_after_overdue(): void
    {
        $user = $this->makeUser();
        $accountId = $this->makeAccount($user);
        $apiKey = DB::table('binance_accounts')->find($accountId)->api_key;
        $invoice = $this->makeInvoice($accountId, $apiKey, $user);

        $this->artisan('engine:mark-overdue')->assertSuccessful();
        $this->assertSame(0, (int) DB::table('binance_accounts')->find($accountId)->enabled);

        app(\App\Services\InvoiceService::class)->settle($invoice->fresh());

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(1, (int) DB::table('binance_accounts')->find($accountId)->enabled);
    }

    /** Paying one month must not resume trading while an older one is still owed. */
    public function test_settle_keeps_the_account_off_while_another_invoice_is_overdue(): void
    {
        $user = $this->makeUser();
        $accountId = $this->makeAccount($user);
        $apiKey = DB::table('binance_accounts')->find($accountId)->api_key;
        $june = $this->makeInvoice($accountId, $apiKey, $user, ['month_year' => '2026-06']);
        $july = $this->makeInvoice($accountId, $apiKey, $user, [
            'month_year' => '2026-07', 'due_date' => now()->subDay()->toDateString(),
        ]);

        $this->artisan('engine:mark-overdue')->assertSuccessful();
        $this->assertSame('overdue', $june->fresh()->status);
        $this->assertSame('overdue', $july->fresh()->status);
        $this->assertSame(0, (int) DB::table('binance_accounts')->find($accountId)->enabled);

        app(\App\Services\InvoiceService::class)->settle($june->fresh());
        $this->assertSame('paid', $june->fresh()->status);
        $this->assertSame(0, (int) DB::table('binance_accounts')->find($accountId)->enabled);

        app(\App\Services\InvoiceService::class)->settle($july->fresh());
        $this->assertSame(1, (int) DB::table('binance_accounts')->find($accountId)->enabled);
    }

    /**
     * Ids repeat across the per-exchange tables: a MEXC invoice pauses MEXC
     * account #N and settling it resumes MEXC account #N — never Binance #N.
     */
    public function test_an_overdue_mexc_invoice_pauses_the_mexc_account_not_binance(): void
    {
        $user = $this->makeUser();
        $binanceId = $this->makeAccount($user);
        $mexcId = $this->makeAccount($user, [], 'mexc');
        $this->assertSame($binanceId, $mexcId, 'the test needs colliding ids to mean anything');
        $mexcKey = DB::table('mexc_accounts')->find($mexcId)->api_key;
        $invoice = $this->makeInvoice($mexcId, $mexcKey, $user, ['exchange' => 'mexc']);

        $this->artisan('engine:mark-overdue')->assertSuccessful();

        $this->assertSame('overdue', $invoice->fresh()->status);
        $this->assertSame(0, (int) DB::table('mexc_accounts')->find($mexcId)->enabled);
        $this->assertSame(1, (int) DB::table('binance_accounts')->find($binanceId)->enabled);

        DB::table('binance_accounts')->where('id', $binanceId)->update(['enabled' => 0]);
        app(\App\Services\InvoiceService::class)->settle($invoice->fresh());

        $this->assertSame(1, (int) DB::table('mexc_accounts')->find($mexcId)->enabled);
        $this->assertSame(0, (int) DB::table('binance_accounts')->find($binanceId)->enabled);
    }

    public function test_suspended_owner_accounts_are_disabled_on_every_venue(): void
    {
        $suspended = $this->makeUser(['status' => 'suspended']);
        $mexcId = $this->makeAccount($suspended, [], 'mexc');

        $this->artisan('engine:mark-overdue')->assertSuccessful();

        $this->assertSame(0, (int) DB::table('mexc_accounts')->find($mexcId)->enabled);
    }
}
