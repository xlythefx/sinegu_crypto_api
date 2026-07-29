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
}
