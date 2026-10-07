<?php

namespace Tests\Feature;

use App\Models\BinanceAccount;
use App\Models\UserCredential;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

/**
 * Reconnecting a key you previously disconnected (POST /api/exchange/binance).
 *
 * A disconnect is a soft delete, and `api_key` / `name` carry table-wide UNIQUE
 * indexes — so the old row is the ONLY row those credentials can live in. The
 * connect flow therefore revives it rather than refusing "already connected",
 * which used to lock a user out of their own key permanently. That is the exact
 * path a blocked-key recovery walks: fix the IP allow-list, reconnect.
 *
 * What must survive the revive is the money: balance, initial_deposit and the
 * invoice high-water mark. What must NOT is the stale key verdict.
 */
class ExchangeAccountReconnectTest extends EngineTestCase
{
    private string $uniId;

    protected function setUp(): void
    {
        parent::setUp();
        // Connecting pings the engine cache on 127.0.0.1:5010.
        Http::fake();

        $this->uniId = $this->makeUser();
        Sanctum::actingAs(UserCredential::query()->find($this->uniId));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Main Trading',
            'api_key' => 'reconnect-key-1',
            'secret_key' => 'reconnect-secret',
        ], $overrides);
    }

    public function test_reconnecting_a_disconnected_key_revives_the_same_row(): void
    {
        $this->postJson('/api/exchange/binance', $this->payload())->assertStatus(201);
        $id = (int) DB::table('binance_accounts')->where('uni_id', $this->uniId)->value('id');

        $this->deleteJson("/api/exchange/accounts/{$id}")->assertOk();
        $this->assertNotNull(BinanceAccount::withTrashed()->find($id)->deleted_at);

        $this->postJson('/api/exchange/binance', $this->payload(['name' => 'Back Again']))
            ->assertStatus(201)
            ->assertJsonPath('reconnected', true)
            ->assertJsonPath('account.id', $id)
            ->assertJsonPath('account.name', 'Back Again')
            ->assertJsonPath('account.enabled', true);

        // Revived, not duplicated — the UNIQUE index makes a second row impossible.
        $this->assertDatabaseCount('binance_accounts', 1);
        $this->assertNull(BinanceAccount::find($id)->deleted_at);
    }

    /** The fresh connect is what a fixed IP allow-list looks like. */
    public function test_reconnecting_clears_a_stale_blocked_verdict(): void
    {
        $id = $this->makeAccount($this->uniId, [
            'api_key' => 'blocked-key-1',
            'key_status' => BinanceAccount::KEY_BLOCKED,
            'key_error_code' => '-2015',
            'key_error_reason' => 'invalid_key_or_ip',
            'key_error_message' => 'Invalid API-key, IP, or permissions',
            'key_blocked_at' => now()->subDays(2),
            'deleted_at' => now(),
        ]);

        $this->postJson('/api/exchange/binance', $this->payload([
            'name' => 'Recovered',
            'api_key' => 'blocked-key-1',
        ]))->assertStatus(201);

        $account = BinanceAccount::find($id);
        $this->assertSame(BinanceAccount::KEY_OK, $account->key_status);
        $this->assertNull($account->key_blocked_at);
        $this->assertNull($account->key_error_code);
    }

    /** Disconnect/reconnect must not be a way to wipe the billing baseline. */
    public function test_the_high_water_mark_and_deposit_survive_a_reconnect(): void
    {
        $id = $this->makeAccount($this->uniId, [
            'api_key' => 'moneyed-key-1',
            'balance' => 5000,
            'initial_deposit' => 4000,
            'deleted_at' => now(),
        ]);

        DB::table('invoices')->insert([
            'user_id' => $this->uniId, 'account_id' => $id, 'exchange' => 'binance',
            'api_key' => 'moneyed-key-1', 'month_year' => '2026-07',
            'hwm_after' => 5000, 'total_fee' => 200, 'status' => 'paid',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postJson('/api/exchange/binance', $this->payload([
            'name' => 'Second Go',
            'api_key' => 'moneyed-key-1',
        ]))->assertStatus(201);

        $account = BinanceAccount::find($id);
        $this->assertSame(4000.0, (float) $account->initial_deposit);
        $this->assertSame(5000.0, (float) $account->balance);
        // The invoice — and with it the high-water mark — is untouched.
        $this->assertSame(
            5000.0,
            (float) DB::table('invoices')->where('account_id', $id)->value('hwm_after')
        );
    }

    /** Someone else's disconnected row carries their trades — never revive it. */
    public function test_another_users_disconnected_key_is_refused(): void
    {
        $other = $this->makeUser();
        $this->makeAccount($other, [
            'api_key' => 'someone-elses-key',
            'deleted_at' => now(),
        ]);

        $this->postJson('/api/exchange/binance', $this->payload([
            'api_key' => 'someone-elses-key',
        ]))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'API_KEY_TAKEN');

        $this->assertDatabaseCount('binance_accounts', 1);
    }

    /** A key someone is actively trading with is still "already connected". */
    public function test_a_live_key_is_still_refused(): void
    {
        $other = $this->makeUser();
        $this->makeAccount($other, ['api_key' => 'live-elsewhere-key']);

        $this->postJson('/api/exchange/binance', $this->payload([
            'api_key' => 'live-elsewhere-key',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('api_key');
    }

    /** The one-account rule still applies — a revive is not a second account. */
    public function test_you_cannot_revive_while_another_account_is_connected(): void
    {
        $this->makeAccount($this->uniId, ['api_key' => 'old-key-1', 'deleted_at' => now()]);
        $this->makeAccount($this->uniId, ['api_key' => 'current-key-1']);

        $this->postJson('/api/exchange/binance', $this->payload([
            'api_key' => 'old-key-1',
        ]))->assertStatus(422);

        $this->assertNotNull(
            BinanceAccount::withTrashed()->where('api_key', 'old-key-1')->first()->deleted_at
        );
    }

    /** A live row's name is still taken, revive or not. */
    public function test_a_name_used_by_a_live_account_is_still_refused(): void
    {
        $other = $this->makeUser();
        $this->makeAccount($other, ['name' => 'Taken Name']);
        $this->makeAccount($this->uniId, ['api_key' => 'mine-old-key', 'deleted_at' => now()]);

        $this->postJson('/api/exchange/binance', $this->payload([
            'name' => 'Taken Name',
            'api_key' => 'mine-old-key',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    // ── Paused for non-payment means paused ────────────────────────────────
    //
    // The overdue sweep switches an account off and InvoiceService::settle
    // switches it back on. Until 2026-10-07 nothing here looked at the
    // invoices, so a disconnect + connect — the same key revived, or a fresh
    // one — came back `enabled`, undoing the pause in two clicks.

    public function test_a_user_paused_for_non_payment_cannot_revive_their_key(): void
    {
        $id = $this->makeAccount($this->uniId, [
            'api_key' => 'paused-key-1', 'enabled' => 0, 'deleted_at' => now(),
        ]);
        $this->unpaidInvoice($id, 'paused-key-1');

        $this->postJson('/api/exchange/binance', $this->payload(['api_key' => 'paused-key-1']))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'INVOICE_OVERDUE')
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'August 2026'));

        $row = BinanceAccount::withTrashed()->find($id);
        $this->assertNotNull($row->deleted_at);
        $this->assertSame(0, (int) $row->enabled);
    }

    public function test_a_paused_user_cannot_connect_a_fresh_key_either(): void
    {
        $id = $this->makeAccount($this->uniId, [
            'api_key' => 'paused-key-2', 'enabled' => 0, 'deleted_at' => now(),
        ]);
        $this->unpaidInvoice($id, 'paused-key-2');

        $this->postJson('/api/exchange/binance', $this->payload(['api_key' => 'brand-new-key']))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'INVOICE_OVERDUE');

        $this->assertDatabaseCount('binance_accounts', 1);
    }

    /** The gate is on the USER: owing on Binance is no reason to start on MEXC. */
    public function test_an_unpaid_binance_invoice_blocks_connecting_another_venue(): void
    {
        config(['exchanges.staff_only' => []]);
        $id = $this->makeAccount($this->uniId, ['api_key' => 'paused-key-3', 'enabled' => 0]);
        $this->unpaidInvoice($id, 'paused-key-3');

        $this->postJson('/api/exchange/mexc', $this->payload(['api_key' => 'mexc-key-1']))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'INVOICE_OVERDUE');

        $this->assertDatabaseCount('mexc_accounts', 0);
    }

    /** 'pending' past its due date is owed money too — the sweep just has not run yet. */
    public function test_a_pending_invoice_past_its_due_date_blocks(): void
    {
        $id = $this->makeAccount($this->uniId, ['api_key' => 'paused-key-4', 'deleted_at' => now()]);
        $this->unpaidInvoice($id, 'paused-key-4', [
            'status' => 'pending', 'due_date' => now()->subDay()->toDateString(),
        ]);

        $this->postJson('/api/exchange/binance', $this->payload(['api_key' => 'paused-key-4']))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'INVOICE_OVERDUE');
    }

    public function test_a_paid_or_not_yet_due_invoice_does_not_block(): void
    {
        $id = $this->makeAccount($this->uniId, ['api_key' => 'fine-key-1', 'deleted_at' => now()]);
        $this->unpaidInvoice($id, 'fine-key-1', ['month_year' => '2026-07', 'status' => 'paid']);
        $this->unpaidInvoice($id, 'fine-key-1', [
            'month_year' => '2026-08', 'status' => 'pending', 'due_date' => now()->addDays(5)->toDateString(),
        ]);

        $this->postJson('/api/exchange/binance', $this->payload(['api_key' => 'fine-key-1']))
            ->assertStatus(201)
            ->assertJsonPath('account.enabled', true);
    }

    /** A $0 row owes nothing, whatever its status says. */
    public function test_a_zero_fee_overdue_row_does_not_block(): void
    {
        $id = $this->makeAccount($this->uniId, ['api_key' => 'zero-key-1', 'deleted_at' => now()]);
        $this->unpaidInvoice($id, 'zero-key-1', ['total_fee' => 0]);

        $this->postJson('/api/exchange/binance', $this->payload(['api_key' => 'zero-key-1']))
            ->assertStatus(201);
    }

    /** Invoice-scenario scratch rows are nobody's real invoice. */
    public function test_a_scenario_scratch_invoice_never_blocks(): void
    {
        $scratch = $this->makeAccount($this->uniId, ['api_key' => 'SBXINV-scratch-1', 'deleted_at' => now()]);
        $this->unpaidInvoice($scratch, 'SBXINV-scratch-1');

        $this->postJson('/api/exchange/binance', $this->payload(['api_key' => 'real-key-1']))
            ->assertStatus(201);
    }

    /** A sandbox rehearsal left unpaid must not stop the house — same rule as the sweep. */
    public function test_the_master_is_never_blocked_by_a_rehearsal_invoice(): void
    {
        DB::table('user_credentials')->where('uni_id', $this->uniId)->update(['type' => 'master']);
        $id = $this->makeAccount($this->uniId, ['api_key' => 'master-key-1', 'deleted_at' => now()]);
        $this->unpaidInvoice($id, 'master-key-1');

        $this->postJson('/api/exchange/binance', $this->payload(['api_key' => 'master-key-1']))
            ->assertStatus(201);
    }

    private function unpaidInvoice(int $accountId, string $apiKey, array $overrides = []): void
    {
        DB::table('invoices')->insert(array_merge([
            'user_id' => $this->uniId, 'account_id' => $accountId, 'exchange' => 'binance',
            'api_key' => $apiKey, 'month_year' => '2026-08', 'total_fee' => 120,
            'status' => 'overdue', 'due_date' => now()->subDays(3)->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }
}
