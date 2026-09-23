<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;

/** GET /api/engine/binance/accounts — the fan-out account list and its filters. */
class EngineAccountsTest extends EngineTestCase
{
    public function test_only_tradeable_accounts_are_returned(): void
    {
        $activeUser = $this->makeUser();
        $suspendedUser = $this->makeUser(['status' => 'suspended']);

        $live = $this->makeAccount($activeUser);
        $demo = $this->makeAccount($activeUser, ['demo' => 1]);
        $this->makeAccount($activeUser, ['enabled' => 0]);              // disabled
        $this->makeAccount($activeUser, ['is_sandbox' => 1]);           // sandbox
        $this->makeAccount($activeUser, ['deleted_at' => now()]);       // disconnected
        $this->makeAccount($suspendedUser);                             // suspended owner

        $response = $this->getJson('/api/engine/binance/accounts', $this->engineHeaders())
            ->assertOk()
            ->assertJson(['success' => true]);

        $accounts = collect($response->json('accounts'));
        $this->assertCount(2, $accounts);

        $returnedKeys = $accounts->pluck('api_key')->all();
        $expectedKeys = DB::table('binance_accounts')
            ->whereIn('id', [$live, $demo])->pluck('api_key')->all();
        $this->assertEqualsCanonicalizing($expectedKeys, $returnedKeys);
    }

    public function test_engine_payload_includes_signing_material_and_demo_flag(): void
    {
        $user = $this->makeUser();
        $id = $this->makeAccount($user, ['demo' => 1, 'balance' => 250.5]);
        $row = DB::table('binance_accounts')->find($id);

        $account = $this->getJson('/api/engine/binance/accounts', $this->engineHeaders())
            ->assertOk()
            ->json('accounts.0');

        $this->assertSame($row->secret_key, $account['secret_key']);
        $this->assertTrue($account['demo']);
        $this->assertSame(250.5, $account['balance']);
        $this->assertSame($user, $account['uni_id']);
        $this->assertArrayHasKey('initial_deposit', $account);
        $this->assertArrayHasKey('currency_type', $account);
    }

    /**
     * The engine needs to know WHOSE figures the public channel publishes: the
     * track record is the master's alone, so a close percentage blended across
     * every filled account could never reconcile with the daily recap built
     * from it. The flag is the owner's role, never the account's name.
     */
    public function test_payload_marks_the_master_account(): void
    {
        $master = $this->makeUser(['type' => 'master']);
        $trader = $this->makeUser();
        $this->makeAccount($master, ['name' => 'not-called-master']);
        $this->makeAccount($trader, ['name' => 'master']);

        $accounts = $this->getJson('/api/engine/binance/accounts', $this->engineHeaders())
            ->assertOk()
            ->json('accounts');

        $byUni = collect($accounts)->keyBy('uni_id');
        $this->assertTrue($byUni[$master]['is_master']);
        $this->assertFalse($byUni[$trader]['is_master']);
    }

    /** Insert a DEPOSIT/WITHDRAWAL row against an account's api_key. */
    private function transaction(int $accountId, string $type, float $amount): void
    {
        $row = DB::table('binance_accounts')->find($accountId);

        DB::table('binance_transactions')->insert([
            'api_key' => $row->api_key,
            'uni_id' => $row->uni_id,
            'type' => $type,
            'amount' => $amount,
            'currency' => 'USDT',
            'created_at' => now(),
        ]);
    }

    /**
     * total_deposit drives the engine's minimum-deposit gate. It must track
     * later top-ups and withdrawals — initial_deposit alone never changes once
     * set, so an account topped up past the minimum would stay blocked forever.
     */
    public function test_total_deposit_includes_later_deposits_and_withdrawals(): void
    {
        $user = $this->makeUser();
        $id = $this->makeAccount($user, ['initial_deposit' => 600]);
        $this->transaction($id, 'DEPOSIT', 700);
        $this->transaction($id, 'WITHDRAWAL', 100);

        $account = $this->getJson('/api/engine/binance/accounts', $this->engineHeaders())
            ->assertOk()
            ->json('accounts.0');

        // 600 + 700 - 100 = 1200 — over the gate, though it started under it.
        $this->assertSame(1200.0, (float) $account['total_deposit']);
        $this->assertSame(600.0, (float) $account['initial_deposit']);
    }

    public function test_total_deposit_equals_initial_deposit_without_transactions(): void
    {
        $user = $this->makeUser();
        $this->makeAccount($user, ['initial_deposit' => 1500]);

        $account = $this->getJson('/api/engine/binance/accounts', $this->engineHeaders())
            ->assertOk()
            ->json('accounts.0');

        $this->assertSame(1500.0, (float) $account['total_deposit']);
    }

    /** Unknown deposit stays null — the engine fails closed on it. */
    public function test_total_deposit_is_null_when_the_account_has_no_deposit(): void
    {
        $user = $this->makeUser();
        $this->makeAccount($user, ['initial_deposit' => null]);

        $account = $this->getJson('/api/engine/binance/accounts', $this->engineHeaders())
            ->assertOk()
            ->json('accounts.0');

        $this->assertNull($account['total_deposit']);
    }

    /** Transactions must not bleed across accounts. */
    public function test_total_deposit_is_scoped_per_account(): void
    {
        $user = $this->makeUser();
        $a = $this->makeAccount($user, ['initial_deposit' => 1000]);
        $this->makeAccount($user, ['initial_deposit' => 1000]);
        $this->transaction($a, 'DEPOSIT', 5000);

        $accounts = collect(
            $this->getJson('/api/engine/binance/accounts', $this->engineHeaders())
                ->assertOk()
                ->json('accounts')
        )->keyBy('api_key');

        $aKey = DB::table('binance_accounts')->find($a)->api_key;
        $this->assertSame(6000.0, (float) $accounts[$aKey]['total_deposit']);
        $this->assertSame(
            1000.0,
            (float) $accounts->first(fn ($x) => $x['api_key'] !== $aKey)['total_deposit']
        );
    }
}
