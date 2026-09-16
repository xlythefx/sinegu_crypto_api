<?php

namespace Tests\Feature;

use App\Models\MexcAccount;
use App\Models\UserCredential;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

/**
 * The trader-facing account routes across two exchanges:
 * POST /api/exchange/{exchange}, GET /api/exchange/accounts, and the
 * (exchange, id)-addressed rename / refresh / disconnect.
 *
 * Accounts live in one table per exchange, so the rules that were "one
 * account per user" become "one per user PER exchange", every listed row
 * says which exchange it is on, and an id is only meaningful together with
 * its exchange. MEXC adds one rule of its own: it has no futures testnet, so
 * a demo MEXC account cannot exist.
 */
class ExchangeAccountMexcTest extends EngineTestCase
{
    private string $uniId;

    protected function setUp(): void
    {
        parent::setUp();
        // Connecting / disconnecting / refreshing pings the engine on 127.0.0.1:5010.
        Http::fake();

        $this->uniId = $this->makeUser();
        Sanctum::actingAs(UserCredential::query()->find($this->uniId));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'MEXC Main',
            'api_key' => 'mx0-key-'.uniqid(),
            'secret_key' => 'mx0-secret',
        ], $overrides);
    }

    public function test_it_connects_a_mexc_account_into_mexc_accounts(): void
    {
        $this->postJson('/api/exchange/mexc', $this->payload())
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('account.exchange', 'mexc')
            ->assertJsonPath('account.demo', false)
            ->assertJsonPath('message', 'MEXC account connected')
            ->assertJsonMissingPath('account.secret_key');

        $this->assertDatabaseCount('mexc_accounts', 1);
        $this->assertDatabaseCount('binance_accounts', 0);
        $this->assertSame($this->uniId, DB::table('mexc_accounts')->value('uni_id'));
    }

    public function test_a_demo_mexc_account_is_refused_because_there_is_no_testnet(): void
    {
        $this->postJson('/api/exchange/mexc', $this->payload(['demo' => true]))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'DEMO_NOT_AVAILABLE')
            ->assertJsonValidationErrors('demo');

        $this->assertDatabaseCount('mexc_accounts', 0);

        // Binance keeps its testnet.
        $this->postJson('/api/exchange/binance', $this->payload(['name' => 'Testnet', 'demo' => true]))
            ->assertStatus(201)
            ->assertJsonPath('account.demo', true);
    }

    public function test_one_account_per_user_per_exchange(): void
    {
        $this->postJson('/api/exchange/binance', $this->payload(['name' => 'Binance Main']))->assertStatus(201);
        // A Binance account does not use up the MEXC slot…
        $this->postJson('/api/exchange/mexc', $this->payload(['name' => 'MEXC Main']))->assertStatus(201);
        // …but a second MEXC account does.
        $this->postJson('/api/exchange/mexc', $this->payload(['name' => 'MEXC Second']))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'ALREADY_CONNECTED')
            ->assertJsonPath('message', 'You already have a MEXC account connected. Disconnect it first to connect a different one.');

        $this->assertDatabaseCount('binance_accounts', 1);
        $this->assertDatabaseCount('mexc_accounts', 1);
    }

    public function test_names_and_keys_are_unique_per_exchange_not_across(): void
    {
        $this->postJson('/api/exchange/binance', $this->payload(['name' => 'Main', 'api_key' => 'same-key']))->assertStatus(201);
        // The same label and even the same key string on the other venue is fine —
        // they are different tables and different credentials.
        $this->postJson('/api/exchange/mexc', $this->payload(['name' => 'Main', 'api_key' => 'same-key']))->assertStatus(201);
    }

    public function test_the_listing_carries_every_exchange_with_its_label(): void
    {
        $this->postJson('/api/exchange/binance', $this->payload(['name' => 'Binance Main']))->assertStatus(201);
        $this->postJson('/api/exchange/mexc', $this->payload(['name' => 'MEXC Main']))->assertStatus(201);

        $accounts = $this->getJson('/api/exchange/accounts')->assertOk()->json('accounts');
        $byName = collect($accounts)->keyBy('name');
        $this->assertSame('binance', $byName['Binance Main']['exchange']);
        $this->assertSame('mexc', $byName['MEXC Main']['exchange']);
        $this->assertArrayHasKey('key_grace_ends_at', $byName['MEXC Main']);
        $this->assertArrayNotHasKey('secret_key', $byName['MEXC Main']);
    }

    public function test_rename_refresh_and_disconnect_are_addressed_by_exchange_and_id(): void
    {
        $this->postJson('/api/exchange/binance', $this->payload(['name' => 'Binance Main']))->assertStatus(201);
        $mexcId = $this->postJson('/api/exchange/mexc', $this->payload(['name' => 'MEXC Main']))->assertStatus(201)->json('account.id');
        $binanceId = (int) DB::table('binance_accounts')->value('id');
        // Both tables start at id 1 — the whole reason the exchange is in the URL.
        $this->assertSame($binanceId, (int) $mexcId);

        $this->putJson("/api/exchange/mexc/accounts/{$mexcId}", ['name' => 'MEXC Renamed'])
            ->assertOk()
            ->assertJsonPath('account.exchange', 'mexc')
            ->assertJsonPath('account.name', 'MEXC Renamed');
        $this->assertSame('Binance Main', DB::table('binance_accounts')->value('name'));

        // Http::fake() stands in for the engine, so only the routing is under test here.
        $this->postJson("/api/exchange/mexc/accounts/{$mexcId}/refresh-balance")
            ->assertJsonPath('account.exchange', 'mexc')
            ->assertJsonPath('account.id', $mexcId);

        $this->deleteJson("/api/exchange/mexc/accounts/{$mexcId}")->assertOk();
        $this->assertNotNull(MexcAccount::withTrashed()->find($mexcId)->deleted_at);
        $this->assertDatabaseCount('binance_accounts', 1);
        $this->assertNull(DB::table('binance_accounts')->value('deleted_at'));
    }

    public function test_the_legacy_binance_routes_still_address_binance_rows(): void
    {
        $id = $this->postJson('/api/exchange/binance', $this->payload(['name' => 'Binance Main']))->json('account.id');
        $this->postJson('/api/exchange/mexc', $this->payload(['name' => 'MEXC Main']))->assertStatus(201);

        $this->putJson("/api/exchange/accounts/{$id}", ['name' => 'Legacy Rename'])->assertOk()->assertJsonPath('account.exchange', 'binance');
        $this->assertSame('MEXC Main', DB::table('mexc_accounts')->value('name'));
        $this->deleteJson("/api/exchange/accounts/{$id}")->assertOk();
        $this->assertDatabaseCount('mexc_accounts', 1);
        $this->assertNull(DB::table('mexc_accounts')->value('deleted_at'));
    }

    public function test_reconnecting_a_disconnected_mexc_key_revives_the_same_row(): void
    {
        $id = $this->postJson('/api/exchange/mexc', $this->payload(['api_key' => 'mx0-revive']))->json('account.id');
        DB::table('mexc_accounts')->where('id', $id)->update(['balance' => 321.5, 'initial_deposit' => 300]);
        $this->deleteJson("/api/exchange/mexc/accounts/{$id}")->assertOk();

        $this->postJson('/api/exchange/mexc', $this->payload(['api_key' => 'mx0-revive', 'name' => 'Back']))
            ->assertStatus(201)
            ->assertJsonPath('reconnected', true)
            ->assertJsonPath('account.id', $id)
            ->assertJsonPath('message', 'MEXC account reconnected');

        $row = DB::table('mexc_accounts')->find($id);
        $this->assertNull($row->deleted_at);
        $this->assertSame(321.5, (float) $row->balance);   // money survives a reconnect
        $this->assertSame(300.0, (float) $row->initial_deposit);
    }

    public function test_bybit_is_still_coming_soon_and_unknown_exchanges_are_404(): void
    {
        $this->postJson('/api/exchange/bybit', $this->payload())
            ->assertStatus(400)
            ->assertJsonPath('error_code', 'EXCHANGE_NOT_SUPPORTED');
        $this->postJson('/api/exchange/kraken', $this->payload())->assertStatus(404);
        $this->putJson('/api/exchange/bybit/accounts/1', ['name' => 'x'])->assertStatus(400);
    }

    public function test_has_exchange_account_counts_any_venue(): void
    {
        $this->assertFalse(UserCredential::find($this->uniId)->toAuthPayload()['has_exchange_account']);
        $this->postJson('/api/exchange/mexc', $this->payload())->assertStatus(201);
        $this->assertTrue(UserCredential::find($this->uniId)->toAuthPayload()['has_exchange_account']);
    }

    public function test_the_engine_lists_a_connected_mexc_account_under_its_own_route(): void
    {
        $this->postJson('/api/exchange/mexc', $this->payload(['api_key' => 'mx0-engine']))->assertStatus(201);

        $mexc = $this->getJson('/api/engine/mexc/accounts', $this->engineHeaders())->assertOk()->json('accounts');
        $binance = $this->getJson('/api/engine/binance/accounts', $this->engineHeaders())->assertOk()->json('accounts');
        $this->assertSame(['mx0-engine'], array_column($mexc, 'api_key'));
        $this->assertSame([], $binance);
    }
}
