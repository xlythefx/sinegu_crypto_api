<?php

namespace Tests\Feature;

use App\Models\UserCredential;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * Covers PUT /api/exchange/accounts/{id} — renaming a connected exchange
 * account. The name is a display label, so the only things worth guarding are
 * ownership (no renaming someone else's account) and the table's unique name.
 */
class ExchangeAccountRenameTest extends EngineTestCase
{
    private string $uniId;

    private int $accountId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->uniId = $this->makeUser();
        $this->accountId = $this->makeAccount($this->uniId, ['name' => 'Old Name']);
        Sanctum::actingAs(UserCredential::query()->find($this->uniId));
    }

    public function test_it_renames_the_account(): void
    {
        $this->putJson("/api/exchange/accounts/{$this->accountId}", ['name' => 'Main Trading'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('account.name', 'Main Trading');

        $this->assertSame(
            'Main Trading',
            DB::table('binance_accounts')->where('id', $this->accountId)->value('name'),
        );
    }

    public function test_it_never_returns_the_secret_key(): void
    {
        $this->putJson("/api/exchange/accounts/{$this->accountId}", ['name' => 'Main Trading'])
            ->assertOk()
            ->assertJsonMissingPath('account.secret_key');
    }

    public function test_it_rejects_a_name_taken_by_another_account(): void
    {
        $this->makeAccount($this->makeUser(), ['name' => 'Taken Name']);

        $this->putJson("/api/exchange/accounts/{$this->accountId}", ['name' => 'Taken Name'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->assertSame(
            'Old Name',
            DB::table('binance_accounts')->where('id', $this->accountId)->value('name'),
        );
    }

    public function test_it_allows_resubmitting_the_same_name(): void
    {
        $this->putJson("/api/exchange/accounts/{$this->accountId}", ['name' => 'Old Name'])
            ->assertOk();
    }

    public function test_it_requires_a_name(): void
    {
        $this->putJson("/api/exchange/accounts/{$this->accountId}", ['name' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_it_cannot_rename_another_users_account(): void
    {
        $otherAccountId = $this->makeAccount($this->makeUser(), ['name' => 'Their Account']);

        $this->putJson("/api/exchange/accounts/{$otherAccountId}", ['name' => 'Hijacked'])
            ->assertStatus(404);

        $this->assertSame(
            'Their Account',
            DB::table('binance_accounts')->where('id', $otherAccountId)->value('name'),
        );
    }

    public function test_it_requires_authentication(): void
    {
        app('auth')->forgetGuards();

        $this->putJson("/api/exchange/accounts/{$this->accountId}", ['name' => 'Anon'])
            ->assertStatus(401);
    }
}
