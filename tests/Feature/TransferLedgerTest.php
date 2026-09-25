<?php

namespace Tests\Feature;

use App\Models\UserCredential;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

/**
 * An account's capital made to agree with the exchange's own ledger
 * ({@see \App\Services\Exchanges\TransferLedger}).
 *
 * The incident (2026-09-25): `initial_deposit` was the wallet at the first
 * poll, and the transfers poller then stored the deposit that funded it — two
 * customers were counted at twice their capital. And the master's transfers
 * older than its connection were never stored at all.
 *
 * Figures mirror the master's real history in miniature: +1000, −995, +1050
 * before trading, then the transfers the poller already knew about.
 */
class TransferLedgerTest extends EngineTestCase
{
    private const T = 1_780_000_000_000; // a fixed ms timestamp, Jun 2026

    private function ledger(array $overrides = []): array
    {
        return array_merge([
            'wallet_balance' => 1555.0,
            'income_total' => 1555.0,
            'opening_balance' => 0.0,
            'ledger_start' => self::T,
            'ledger_end' => self::T + 900_000,
            'rows' => 40,
            'sums' => ['TRANSFER' => 1555.0],
            'counts' => ['TRANSFER' => 4],
            'unclassified_types' => [],
            'non_usdt_rows' => [],
            'other_wallets' => [],
            'truncated' => false,
            'transfers' => [
                $this->t('DEPOSIT', 1000, 1, 0),
                $this->t('WITHDRAWAL', 995, 2, 100_000),
                $this->t('DEPOSIT', 1050, 3, 200_000),
                $this->t('DEPOSIT', 500, 4, 800_000),
            ],
        ], $overrides);
    }

    private function t(string $type, float $amount, int $id, int $offset): array
    {
        return ['type' => $type, 'amount' => $amount, 'tran_id' => $id, 'currency' => 'USDT',
            'transaction_time' => self::T + $offset, 'info' => 'TRANSFER'];
    }

    private function storeTransfer(string $apiKey, string $uniId, string $type, float $amount, int $id, int $offset): void
    {
        DB::table('binance_transactions')->insert([
            'api_key' => $apiKey, 'uni_id' => $uniId, 'type' => $type, 'amount' => $amount,
            'tran_id' => $id, 'currency' => 'USDT', 'transaction_time' => self::T + $offset,
            'created_at' => now(),
        ]);
    }

    private function postLedger(array $ledger, string $apiKey): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/engine/binance/ledger', ['api_key' => $apiKey, 'ledger' => $ledger], $this->engineHeaders());
    }

    /* ============ The engine seeding a NEW account ============ */

    public function test_a_new_account_starts_from_its_ledger_not_its_wallet(): void
    {
        $uniId = $this->makeUser();
        $id = $this->makeAccount($uniId, ['api_key' => 'new-key', 'initial_deposit' => null, 'balance' => 1555]);
        // The poller already found the newest deposit in its 3-day window.
        $this->storeTransfer('new-key', $uniId, 'DEPOSIT', 500, 4, 800_000);

        $this->postLedger($this->ledger(), 'new-key')->assertOk()->assertJsonPath('applied', true);

        $account = DB::table('binance_accounts')->find($id);
        // Every dollar is a stored transfer, so nothing is left for the start.
        $this->assertEquals(0, (float) $account->initial_deposit);
        $this->assertEquals(4, DB::table('binance_transactions')->where('api_key', 'new-key')->count());

        // The deposit gate's figure is the real capital, not wallet + deposit.
        $engine = $this->getJson('/api/engine/binance/accounts', $this->engineHeaders())->json('accounts');
        $this->assertEquals(1555, collect($engine)->firstWhere('api_key', 'new-key')['total_deposit']);
    }

    public function test_backfilled_transfers_are_dated_when_the_money_moved(): void
    {
        $uniId = $this->makeUser();
        $this->makeAccount($uniId, ['api_key' => 'new-key', 'initial_deposit' => null]);

        $this->postLedger($this->ledger(), 'new-key')->assertOk();

        $first = DB::table('binance_transactions')->where('tran_id', 1)->first();
        $this->assertSame('2026-05-28 20:26:40', (string) $first->created_at);
    }

    public function test_a_seed_never_overwrites_an_existing_figure(): void
    {
        $uniId = $this->makeUser();
        $id = $this->makeAccount($uniId, ['api_key' => 'old-key', 'initial_deposit' => 1052.59]);

        $this->postLedger($this->ledger(), 'old-key')->assertOk()
            ->assertJsonPath('applied', false)->assertJsonPath('reason', 'ALREADY_SET');

        $this->assertEquals(1052.59, (float) DB::table('binance_accounts')->find($id)->initial_deposit);
        $this->assertSame(0, DB::table('binance_transactions')->where('api_key', 'old-key')->count());
    }

    public function test_a_ledger_that_does_not_reconcile_leaves_the_start_unset(): void
    {
        $uniId = $this->makeUser();
        $id = $this->makeAccount($uniId, ['api_key' => 'new-key', 'initial_deposit' => null]);

        $this->postLedger($this->ledger(['opening_balance' => -250]), 'new-key')->assertOk()
            ->assertJsonPath('applied', false);

        // Unset = unknown = the deposit gate fails closed. Never a guess.
        $this->assertNull(DB::table('binance_accounts')->find($id)->initial_deposit);
    }

    public function test_a_zero_start_is_not_overwritten_by_the_next_balance_poll(): void
    {
        $uniId = $this->makeUser();
        $id = $this->makeAccount($uniId, ['api_key' => 'zero-key', 'initial_deposit' => 0]);

        $this->postJson('/api/engine/binance/balances', ['rows' => [
            ['api_key' => 'zero-key', 'balance' => 1555, 'initial_deposit' => 1555],
        ]], $this->engineHeaders())->assertOk();

        // 0 is an answer ("all of it arrived by transfer"), not "unset".
        $this->assertEquals(0, (float) DB::table('binance_accounts')->find($id)->initial_deposit);
    }

    public function test_the_transfers_poller_dates_rows_by_the_exchange_time(): void
    {
        $uniId = $this->makeUser();
        $this->makeAccount($uniId, ['api_key' => 'k']);

        $this->postJson('/api/engine/binance/transactions', ['rows' => [
            ['api_key' => 'k', 'uni_id' => $uniId, 'type' => 'DEPOSIT', 'amount' => 10,
                'tran_id' => 77, 'transaction_time' => self::T],
        ]], $this->engineHeaders())->assertOk();

        $this->assertSame('2026-05-28 20:26:40', (string) DB::table('binance_transactions')->where('tran_id', 77)->value('created_at'));
    }

    /* ============ The admin backfill of an EXISTING account ============ */

    private function actingAdmin(): void
    {
        Sanctum::actingAs(UserCredential::query()->find($this->makeUser(['type' => 'admin'])));
        config(['services.engine.webhook_secrets.binance' => 'hook-secret']);
    }

    private function fakeEngine(array $ledger): void
    {
        Http::fake(['*/admin/ledger' => Http::response(['success' => true, 'exchange' => 'binance', 'ledger' => $ledger])]);
    }

    /** The master's shape: an old snapshot standing in for transfers never stored. */
    private function master(): array
    {
        $uniId = $this->makeUser(['type' => 'master']);
        $id = $this->makeAccount($uniId, ['api_key' => 'master-key', 'initial_deposit' => 1055, 'balance' => 1555]);
        $this->storeTransfer('master-key', $uniId, 'DEPOSIT', 500, 4, 800_000);

        return [$uniId, $id];
    }

    public function test_the_preview_shows_what_would_change_and_writes_nothing(): void
    {
        [, $id] = $this->master();
        $this->actingAdmin();
        $this->fakeEngine($this->ledger());

        $plan = $this->getJson("/api/admin/api-keys/binance/{$id}/ledger")->assertOk()->json('plan');

        $this->assertSame(3, $plan['missing_count']);
        $this->assertEquals(['before' => 1055, 'after' => 0], $plan['initial_deposit']);
        // Same capital either way — the money just moves to where it belongs.
        $this->assertEquals(1555, $plan['total_deposit']['before']);
        $this->assertEquals(1555, $plan['total_deposit']['after']);
        $this->assertSame([], $plan['problems']);
        $this->assertArrayNotHasKey('_missing', $plan);

        $this->assertEquals(1055, (float) DB::table('binance_accounts')->find($id)->initial_deposit);
        $this->assertSame(1, DB::table('binance_transactions')->where('api_key', 'master-key')->count());
    }

    public function test_apply_stores_the_missing_transfers_and_resets_the_start(): void
    {
        [, $id] = $this->master();
        $this->actingAdmin();
        $this->fakeEngine($this->ledger());

        $this->postJson("/api/admin/api-keys/binance/{$id}/ledger", [
            'expected_initial' => 0,
            'expected_missing' => ['1', '2', '3'],
        ])->assertOk()->assertJsonPath('inserted', 3);

        $this->assertEquals(0, (float) DB::table('binance_accounts')->find($id)->initial_deposit);
        $this->assertSame(4, DB::table('binance_transactions')->where('api_key', 'master-key')->count());
    }

    public function test_apply_refuses_when_the_history_changed_since_the_preview(): void
    {
        [, $id] = $this->master();
        $this->actingAdmin();
        // A new transfer landed after the admin looked.
        $ledger = $this->ledger();
        $ledger['transfers'][] = $this->t('DEPOSIT', 50, 5, 900_000);
        $this->fakeEngine($ledger);

        $this->postJson("/api/admin/api-keys/binance/{$id}/ledger", [
            'expected_initial' => 0,
            'expected_missing' => ['1', '2', '3'],
        ])->assertStatus(409)->assertJsonPath('error', 'LEDGER_CHANGED');

        $this->assertEquals(1055, (float) DB::table('binance_accounts')->find($id)->initial_deposit);
    }

    public function test_a_stored_transfer_the_exchange_does_not_know_blocks_apply(): void
    {
        [$uniId, $id] = $this->master();
        // Inside the ledger's window, but not in it.
        $this->storeTransfer('master-key', $uniId, 'DEPOSIT', 5000, 999, 500_000);
        $this->actingAdmin();
        $this->fakeEngine($this->ledger());

        $plan = $this->getJson("/api/admin/api-keys/binance/{$id}/ledger")->json('plan');
        $this->assertCount(1, $plan['unknown_stored']);

        $this->postJson("/api/admin/api-keys/binance/{$id}/ledger", [
            'expected_initial' => 0, 'expected_missing' => ['1', '2', '3'],
        ])->assertStatus(422)->assertJsonPath('error', 'LEDGER_PROBLEMS');
    }

    public function test_stored_transfers_older_than_the_ledger_come_off_the_start(): void
    {
        [$uniId, $id] = $this->master();
        // Stored long ago, before the oldest row Binance still keeps: that money
        // is inside the opening balance already, so it must not count twice.
        $this->storeTransfer('master-key', $uniId, 'DEPOSIT', 300, 42, -50_000_000);
        $this->actingAdmin();
        $this->fakeEngine($this->ledger(['opening_balance' => 300]));

        $plan = $this->getJson("/api/admin/api-keys/binance/{$id}/ledger")->json('plan');
        $this->assertEquals(0, $plan['initial_deposit']['after']);
        $this->assertSame([], $plan['problems']);
    }

    public function test_a_plain_user_cannot_reach_it(): void
    {
        [, $id] = $this->master();
        Sanctum::actingAs(UserCredential::query()->find($this->makeUser()));

        $this->getJson("/api/admin/api-keys/binance/{$id}/ledger")->assertForbidden();
    }
}
