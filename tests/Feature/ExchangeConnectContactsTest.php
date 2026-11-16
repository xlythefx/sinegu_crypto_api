<?php

namespace Tests\Feature;

use App\Models\UserCredential;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * Telegram + WhatsApp before connecting an exchange (owner, 2026-11-16).
 *
 * A customer missing either is refused 422 CONTACTS_REQUIRED on every venue.
 * The lock is on CONNECTING only: an account already connected keeps
 * trading, renaming and disconnecting, because an existing customer is asked
 * to fill them in, never cut off. Staff are never locked out.
 */
class ExchangeConnectContactsTest extends EngineTestCase
{
    private function actingAsNew(array $overrides = [], bool $withContacts = false): string
    {
        $uniId = $this->makeUser($overrides, $withContacts);
        Sanctum::actingAs(UserCredential::query()->find($uniId));

        return $uniId;
    }

    private function connect(string $exchange = 'binance')
    {
        return $this->postJson("/api/exchange/{$exchange}", [
            'name' => 'Main '.uniqid(),
            'api_key' => 'contacts-key-'.uniqid(),
            'secret_key' => 'contacts-secret',
        ]);
    }

    public function test_a_customer_without_both_cannot_connect(): void
    {
        $uniId = $this->actingAsNew();

        $this->connect()
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'CONTACTS_REQUIRED')
            ->assertJsonPath('missing', ['telegram', 'whatsapp']);

        // One of the two is not enough.
        DB::table('user_contact_handles')->insert(['uni_id' => $uniId, 'telegram' => 'only_tg', 'created_at' => now(), 'updated_at' => now()]);
        $this->connect()
            ->assertStatus(422)
            ->assertJsonPath('missing', ['whatsapp']);

        $this->assertDatabaseCount('binance_accounts', 0);
    }

    public function test_the_lock_covers_every_venue(): void
    {
        $this->actingAsNew();

        foreach (['mexc', 'bybit'] as $exchange) {
            config(['exchanges.staff_only' => []]);
            $this->connect($exchange)->assertStatus(422)->assertJsonPath('error_code', 'CONTACTS_REQUIRED');
        }
    }

    public function test_once_both_are_given_the_customer_connects(): void
    {
        $uniId = $this->actingAsNew();

        $this->putJson('/api/user/contacts', ['telegram' => '@new_trader', 'whatsapp' => '+66 81 234 5678'])->assertOk();

        $this->connect()->assertStatus(201);
        $this->assertSame(1, DB::table('binance_accounts')->where('uni_id', $uniId)->count());
    }

    public function test_an_account_already_connected_keeps_working(): void
    {
        $uniId = $this->actingAsNew();
        $accountId = $this->makeAccount($uniId);

        $this->getJson('/api/exchange/accounts')->assertOk();
        $this->putJson("/api/exchange/binance/accounts/{$accountId}", ['name' => 'Renamed'])->assertOk();
        $this->deleteJson("/api/exchange/binance/accounts/{$accountId}")->assertOk();
    }

    public function test_staff_connect_without_them(): void
    {
        $this->actingAsNew(['type' => 'admin']);

        $this->connect()->assertStatus(201);
    }
}
