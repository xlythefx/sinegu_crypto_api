<?php

namespace Tests\Feature;

use App\Models\UserCredential;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * Covers POST /api/exchange/binance — connecting an account from the wizard.
 *
 * The flag under test is `demo`: it decides which NETWORK the engine talks to
 * for this account (testnet vs mainnet), and the two key sets are not
 * interchangeable. Getting it wrong is silent in opposite directions — a live
 * key sent to the testnet never trades, a testnet key on mainnet is refused —
 * so the value has to arrive from the user's choice and be stored verbatim,
 * and its absence has to mean live rather than "whatever was left over".
 */
class ExchangeAccountConnectTest extends EngineTestCase
{
    private string $uniId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->uniId = $this->makeUser();
        Sanctum::actingAs(UserCredential::query()->find($this->uniId));
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Main Trading',
            'api_key' => 'connect-key-'.uniqid(),
            'secret_key' => 'connect-secret',
        ], $overrides);
    }

    public function test_it_connects_a_live_account_by_default(): void
    {
        $this->postJson('/api/exchange/binance', $this->payload())
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('account.demo', false);

        $this->assertSame(0, (int) DB::table('binance_accounts')
            ->where('uni_id', $this->uniId)
            ->value('demo'));
    }

    public function test_it_connects_a_demo_account_when_the_wizard_asks_for_one(): void
    {
        $this->postJson('/api/exchange/binance', $this->payload(['demo' => true]))
            ->assertStatus(201)
            ->assertJsonPath('account.demo', true);

        $this->assertSame(1, (int) DB::table('binance_accounts')
            ->where('uni_id', $this->uniId)
            ->value('demo'));
    }

    public function test_it_connects_a_live_account_when_demo_is_explicitly_false(): void
    {
        $this->postJson('/api/exchange/binance', $this->payload(['demo' => false]))
            ->assertStatus(201)
            ->assertJsonPath('account.demo', false);
    }

    public function test_it_rejects_a_non_boolean_demo_flag(): void
    {
        $this->postJson('/api/exchange/binance', $this->payload(['demo' => 'maybe']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('demo');

        $this->assertDatabaseCount('binance_accounts', 0);
    }

    /**
     * The mode belongs to the account, not to the platform: a demo account must
     * not let its owner sneak a second (live) one past the one-per-user rule.
     */
    public function test_a_demo_account_still_occupies_the_one_account_slot(): void
    {
        $this->postJson('/api/exchange/binance', $this->payload(['demo' => true]))
            ->assertStatus(201);

        $this->postJson('/api/exchange/binance', $this->payload([
            'name' => 'Second Account',
            'demo' => false,
        ]))->assertStatus(422);

        $this->assertDatabaseCount('binance_accounts', 1);
    }

    public function test_it_never_returns_the_secret_key(): void
    {
        $this->postJson('/api/exchange/binance', $this->payload(['demo' => true]))
            ->assertStatus(201)
            ->assertJsonMissingPath('account.secret_key');
    }

    /** Approval gates the exchange, whichever network it points at. */
    public function test_a_pending_user_cannot_connect_even_a_demo_account(): void
    {
        $pending = $this->makeUser(['status' => 'pending']);
        Sanctum::actingAs(UserCredential::query()->find($pending));

        $this->postJson('/api/exchange/binance', $this->payload(['demo' => true]))
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'PENDING_APPROVAL');

        $this->assertDatabaseCount('binance_accounts', 0);
    }

    public function test_it_requires_authentication(): void
    {
        app('auth')->forgetGuards();

        $this->postJson('/api/exchange/binance', $this->payload())
            ->assertStatus(401);
    }
}
