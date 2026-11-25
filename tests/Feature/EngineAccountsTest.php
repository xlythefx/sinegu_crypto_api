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

    /* ---- paused for an unpaid invoice: exits only (2026-11-25) ---- */

    /** An invoice on one account, unpaid past its due date unless told otherwise. */
    private function invoice(string $owner, int $accountId, array $overrides = []): void
    {
        DB::table('invoices')->insert($overrides + [
            'user_id' => $owner, 'account_id' => $accountId, 'exchange' => 'binance',
            'api_key' => 'INVKEY'.$accountId.uniqid(), 'month_year' => '2026-10',
            'total_fee' => 50, 'status' => 'overdue', 'due_date' => '2026-11-04',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * A pause stops new trades, never the exit of a position already open.
     * The account comes back flagged `exits_only` — but only to an engine
     * that asks, because an engine too old to know the flag would open
     * trades on it.
     */
    public function test_a_paused_account_is_listed_exits_only_when_the_engine_asks(): void
    {
        $owner = $this->makeUser();
        $paused = $this->makeAccount($owner, ['enabled' => 0]);
        $live = $this->makeAccount($this->makeUser());
        $this->invoice($owner, $paused);
        $pausedKey = DB::table('binance_accounts')->find($paused)->api_key;
        $liveKey = DB::table('binance_accounts')->find($live)->api_key;

        $old = collect($this->getJson('/api/engine/binance/accounts', $this->engineHeaders())
            ->assertOk()->json('accounts'))->keyBy('api_key');
        $this->assertFalse($old->has($pausedKey), 'an engine that does not ask never sees it');

        $new = collect($this->getJson('/api/engine/binance/accounts?include_paused=1', $this->engineHeaders())
            ->assertOk()->json('accounts'))->keyBy('api_key');
        $this->assertTrue($new[$pausedKey]['exits_only']);
        $this->assertFalse($new[$pausedKey]['enabled']);
        $this->assertFalse($new[$liveKey]['exits_only']);
    }

    /**
     * Only a pause for non-payment is exits-only. An account an admin
     * switched off with nothing owed, one whose invoice is paid, and one
     * whose unpaid invoice is on ANOTHER venue with the same id stay out.
     */
    public function test_only_an_unpaid_past_due_invoice_on_the_same_venue_lists_a_switched_off_account(): void
    {
        $owner = $this->makeUser();
        $switchedOff = $this->makeAccount($owner, ['enabled' => 0]);
        $paid = $this->makeAccount($this->makeUser(), ['enabled' => 0]);
        $otherVenue = $this->makeAccount($this->makeUser(), ['enabled' => 0]);
        $notDueYet = $this->makeAccount($this->makeUser(), ['enabled' => 0]);
        $scenario = $this->makeAccount($this->makeUser(), ['enabled' => 0]);

        $this->invoice(DB::table('binance_accounts')->find($paid)->uni_id, $paid, ['status' => 'paid']);
        $this->invoice(DB::table('binance_accounts')->find($otherVenue)->uni_id, $otherVenue, ['exchange' => 'mexc']);
        $this->invoice(DB::table('binance_accounts')->find($notDueYet)->uni_id, $notDueYet, [
            'status' => 'pending', 'due_date' => now()->addDays(3)->toDateString(),
        ]);
        $this->invoice(DB::table('binance_accounts')->find($scenario)->uni_id, $scenario, ['api_key' => 'SBXINV-1']);

        $listed = collect($this->getJson('/api/engine/binance/accounts?include_paused=1', $this->engineHeaders())
            ->assertOk()->json('accounts'))->pluck('api_key')->all();

        foreach ([$switchedOff, $paid, $otherVenue, $notDueYet, $scenario] as $id) {
            $this->assertNotContains(DB::table('binance_accounts')->find($id)->api_key, $listed);
        }
    }

    /** Sandbox accounts and suspended owners stay out even when paused. */
    public function test_a_paused_account_still_obeys_the_other_filters(): void
    {
        $suspended = $this->makeUser(['status' => 'suspended']);
        $a = $this->makeAccount($suspended, ['enabled' => 0]);
        $this->invoice($suspended, $a);
        $owner = $this->makeUser();
        $b = $this->makeAccount($owner, ['enabled' => 0, 'is_sandbox' => 1]);
        $this->invoice($owner, $b);

        $this->getJson('/api/engine/binance/accounts?include_paused=1', $this->engineHeaders())
            ->assertOk()
            ->assertJsonCount(0, 'accounts');
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
