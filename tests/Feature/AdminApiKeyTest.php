<?php

namespace Tests\Feature;

use App\Models\BinanceAccount;
use App\Models\UserCredential;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * /api/admin/api-keys — the admin API-key inventory page.
 *
 * The load-bearing properties: the full key and the secret never appear in a
 * response, soft-deleted accounts are listed (that is the point of an
 * inventory) but never counted as problems, and bulk delete acts on the ids it
 * was given and nothing else.
 */
class AdminApiKeyTest extends EngineTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // EngineCache pings the engine on 127.0.0.1:5010 after a disconnect —
        // fake it so the suite never depends on the engine being up.
        Http::fake();
    }

    private function headersFor(string $uniId): array
    {
        $user = UserCredential::find($uniId);

        return ['Authorization' => 'Bearer '.$user->createToken('spa')->plainTextToken];
    }

    private function admin(): string
    {
        $this->app['auth']->forgetGuards();

        return $this->makeUser(['type' => 'admin']);
    }

    public function test_it_lists_every_account_with_its_owner(): void
    {
        $admin = $this->admin();
        $owner = $this->makeUser(['name' => 'Jane Cooper', 'email' => 'jane@example.test']);
        $id = $this->makeAccount($owner, ['api_key' => 'ABCDEF0123456789WXYZ']);

        $response = $this->getJson('/api/admin/api-keys', $this->headersFor($admin))
            ->assertOk()
            ->assertJsonPath('keys.0.owner.name', 'Jane Cooper')
            ->assertJsonPath('keys.0.owner.email', 'jane@example.test')
            ->assertJsonPath('keys.0.id', $id)
            ->assertJsonPath('keys.0.exchange', 'binance');

        // Hint only — the whole key and the secret stay server-side.
        $this->assertSame('ABCDEF…WXYZ', $response->json('keys.0.api_key_hint'));
        $body = $response->getContent();
        $this->assertStringNotContainsString('ABCDEF0123456789WXYZ', $body);
        $this->assertStringNotContainsString('secret_key', $body);
        $this->assertStringNotContainsString('test-secret-key', $body);
    }

    public function test_counts_separate_faulty_disabled_and_disconnected(): void
    {
        $admin = $this->admin();
        $owner = $this->makeUser();

        $this->makeAccount($owner);                                   // healthy
        $this->makeAccount($owner, ['enabled' => 0]);                  // disabled
        $this->makeAccount($owner, [
            'key_status' => BinanceAccount::KEY_BLOCKED,
            'key_error_code' => '-2015',
            'key_blocked_at' => now()->subDay(),
        ]);                                                            // faulty
        $this->makeAccount($owner, ['deleted_at' => now()]);           // disconnected

        $response = $this->getJson('/api/admin/api-keys', $this->headersFor($admin))->assertOk();

        $this->assertSame(4, $response->json('counts.all'));
        $this->assertSame(3, $response->json('counts.connected'));
        $this->assertSame(1, $response->json('counts.faulty'));
        $this->assertSame(1, $response->json('counts.disabled'));
        $this->assertSame(1, $response->json('counts.disconnected'));
    }

    /** A disconnected account must not keep showing up as a problem to fix. */
    public function test_a_disconnected_faulty_account_is_not_counted_as_faulty(): void
    {
        $admin = $this->admin();
        $owner = $this->makeUser();
        $this->makeAccount($owner, [
            'key_status' => BinanceAccount::KEY_BLOCKED,
            'key_blocked_at' => now()->subDays(5),
            'deleted_at' => now(),
        ]);

        $response = $this->getJson('/api/admin/api-keys', $this->headersFor($admin))->assertOk();

        $this->assertSame(0, $response->json('counts.faulty'));
        $this->assertSame(1, $response->json('counts.disconnected'));
        // Still listed — the inventory shows history, the counts show work.
        $this->assertCount(1, $response->json('keys'));
    }

    public function test_a_faulty_row_carries_its_grace_deadline(): void
    {
        $admin = $this->admin();
        $owner = $this->makeUser();
        $this->makeAccount($owner, [
            'key_status' => BinanceAccount::KEY_BLOCKED,
            'key_error_code' => '-2015',
            'key_error_reason' => 'invalid_key_or_ip',
            'key_blocked_at' => now()->subDay(),
        ]);

        $response = $this->getJson('/api/admin/api-keys', $this->headersFor($admin))->assertOk();

        $this->assertTrue($response->json('keys.0.key_blocked'));
        $this->assertSame('-2015', $response->json('keys.0.error_code'));
        $this->assertNotNull($response->json('keys.0.grace_ends_at'));
        // 3-day grace, blocked yesterday → 2 days left.
        $this->assertSame(2, $response->json('keys.0.days_left'));
        $this->assertSame(BinanceAccount::KEY_GRACE_DAYS, $response->json('grace_days'));
    }

    public function test_an_admin_can_rename_and_disable_a_key(): void
    {
        $admin = $this->admin();
        $owner = $this->makeUser();
        $id = $this->makeAccount($owner, ['name' => 'Old Name']);

        $this->putJson(
            "/api/admin/api-keys/{$id}",
            ['name' => 'Renamed Account', 'enabled' => false],
            $this->headersFor($admin)
        )
            ->assertOk()
            ->assertJsonPath('key.name', 'Renamed Account')
            ->assertJsonPath('key.enabled', false);

        $this->assertDatabaseHas('binance_accounts', [
            'id' => $id,
            'name' => 'Renamed Account',
            'enabled' => 0,
        ]);
    }

    /** Re-keying is the owner's job; this screen must not become a vault. */
    public function test_the_key_and_secret_cannot_be_edited_here(): void
    {
        $admin = $this->admin();
        $owner = $this->makeUser();
        $id = $this->makeAccount($owner, ['api_key' => 'ORIGINALKEY123456', 'demo' => 0]);

        $this->putJson(
            "/api/admin/api-keys/{$id}",
            ['api_key' => 'HACKEDKEY', 'secret_key' => 'HACKEDSECRET', 'demo' => true],
            $this->headersFor($admin)
        )->assertOk();

        $this->assertDatabaseHas('binance_accounts', [
            'id' => $id,
            'api_key' => 'ORIGINALKEY123456',
            'secret_key' => 'test-secret-key-'.$this->keyIndexFor($id),
            'demo' => 0,
        ]);
    }

    public function test_a_duplicate_name_is_refused(): void
    {
        $admin = $this->admin();
        $owner = $this->makeUser();
        $this->makeAccount($owner, ['name' => 'Taken']);
        $id = $this->makeAccount($owner, ['name' => 'Mine']);

        $this->putJson("/api/admin/api-keys/{$id}", ['name' => 'Taken'], $this->headersFor($admin))
            ->assertStatus(422);

        $this->assertDatabaseHas('binance_accounts', ['id' => $id, 'name' => 'Mine']);
    }

    public function test_delete_soft_deletes_so_history_survives(): void
    {
        $admin = $this->admin();
        $owner = $this->makeUser();
        $id = $this->makeAccount($owner);

        $this->deleteJson("/api/admin/api-keys/{$id}", [], $this->headersFor($admin))->assertOk();

        // Row still there (positions/invoices join on api_key), flagged gone.
        $this->assertDatabaseHas('binance_accounts', ['id' => $id]);
        $this->assertNotNull(BinanceAccount::withTrashed()->find($id)->deleted_at);
    }

    public function test_deleting_an_already_disconnected_key_is_refused(): void
    {
        $admin = $this->admin();
        $owner = $this->makeUser();
        $id = $this->makeAccount($owner, ['deleted_at' => now()]);

        $this->deleteJson("/api/admin/api-keys/{$id}", [], $this->headersFor($admin))
            ->assertStatus(422);
    }

    public function test_bulk_delete_removes_only_the_ids_it_was_given(): void
    {
        $admin = $this->admin();
        $owner = $this->makeUser();
        $doomed = [$this->makeAccount($owner), $this->makeAccount($owner)];
        $spared = $this->makeAccount($owner);

        $this->postJson('/api/admin/api-keys/bulk-delete', ['ids' => $doomed], $this->headersFor($admin))
            ->assertOk()
            ->assertJsonPath('deleted', 2);

        foreach ($doomed as $id) {
            $this->assertNotNull(BinanceAccount::withTrashed()->find($id)->deleted_at);
        }
        $this->assertNull(BinanceAccount::withTrashed()->find($spared)->deleted_at);
    }

    /** Two admins pressing the same button must not produce a failure. */
    public function test_bulk_delete_skips_already_disconnected_ids(): void
    {
        $admin = $this->admin();
        $owner = $this->makeUser();
        $live = $this->makeAccount($owner);
        $gone = $this->makeAccount($owner, ['deleted_at' => now()]);

        $this->postJson(
            '/api/admin/api-keys/bulk-delete',
            ['ids' => [$live, $gone, 999999]],
            $this->headersFor($admin)
        )
            ->assertOk()
            ->assertJsonPath('deleted', 1)
            ->assertJsonPath('skipped', 2);
    }

    public function test_bulk_delete_requires_ids(): void
    {
        $admin = $this->admin();

        $this->postJson('/api/admin/api-keys/bulk-delete', ['ids' => []], $this->headersFor($admin))
            ->assertStatus(422);
    }

    /* ---- permanent delete (purge) ---- */

    public function test_a_working_key_cannot_be_purged(): void
    {
        $admin = $this->admin();
        $owner = $this->makeUser();
        $id = $this->makeAccount($owner);

        $this->deleteJson("/api/admin/api-keys/{$id}/purge", [], $this->headersFor($admin))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'PURGE_REFUSED');

        $this->assertDatabaseHas('binance_accounts', ['id' => $id]);
    }

    public function test_a_faulty_key_is_purged_with_its_market_data(): void
    {
        $admin = $this->admin();
        $owner = $this->makeUser();
        $id = $this->makeAccount($owner, [
            'api_key' => 'FAULTYKEY0001',
            'key_status' => BinanceAccount::KEY_BLOCKED,
            'key_blocked_at' => now()->subDays(4),
        ]);

        DB::table('binance_pastpositions')->insert([
            'api_key' => 'FAULTYKEY0001', 'uni_id' => $owner, 'symbol' => 'BTCUSDT',
            'position_side' => 'LONG', 'position_amt' => 0.01, 'entry_price' => 100,
            'exit_price' => 110, 'realized_pnl' => 10, 'side' => 'BUY',
            'closed_at' => now(), 'created_at' => now(),
        ]);
        DB::table('binance_positions')->insert([
            'api_key' => 'FAULTYKEY0001', 'uni_id' => $owner, 'symbol' => 'ETHUSDT',
            'position_side' => 'LONG', 'position_amt' => 1, 'entry_price' => 100,
            'created_at' => now(),
        ]);

        $this->deleteJson("/api/admin/api-keys/{$id}/purge", [], $this->headersFor($admin))
            ->assertOk()
            ->assertJsonPath('removed.trades', 1)
            ->assertJsonPath('removed.positions', 1);

        // Gone for real — not soft-deleted.
        $this->assertNull(BinanceAccount::withTrashed()->find($id));
        $this->assertDatabaseMissing('binance_pastpositions', ['api_key' => 'FAULTYKEY0001']);
        $this->assertDatabaseMissing('binance_positions', ['api_key' => 'FAULTYKEY0001']);
    }

    public function test_an_already_disconnected_key_can_be_purged(): void
    {
        $admin = $this->admin();
        $owner = $this->makeUser();
        $id = $this->makeAccount($owner, ['deleted_at' => now()]);

        $this->deleteJson("/api/admin/api-keys/{$id}/purge", [], $this->headersFor($admin))
            ->assertOk();

        $this->assertNull(BinanceAccount::withTrashed()->find($id));
    }

    /** Billing is the audit trail — an invoice must never outlive its account. */
    public function test_a_key_with_invoices_cannot_be_purged(): void
    {
        $admin = $this->admin();
        $owner = $this->makeUser();
        $id = $this->makeAccount($owner, [
            'api_key' => 'BILLEDKEY0001',
            'deleted_at' => now(),
        ]);

        DB::table('invoices')->insert([
            'user_id' => $owner, 'account_id' => $id, 'exchange' => 'binance',
            'api_key' => 'BILLEDKEY0001', 'month_year' => '2026-07',
            'total_fee' => 100, 'status' => 'paid',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->deleteJson("/api/admin/api-keys/{$id}/purge", [], $this->headersFor($admin))
            ->assertStatus(422);

        $this->assertStringContainsString('invoice', $response->json('message'));
        $this->assertDatabaseHas('binance_accounts', ['id' => $id]);
    }

    /** The listing tells the UI which rows the button may act on, and why not. */
    public function test_the_listing_reports_purgeability_per_row(): void
    {
        $admin = $this->admin();
        $owner = $this->makeUser();
        $working = $this->makeAccount($owner);
        $faulty = $this->makeAccount($owner, [
            'key_status' => BinanceAccount::KEY_BLOCKED,
            'key_blocked_at' => now(),
        ]);

        $keys = collect(
            $this->getJson('/api/admin/api-keys', $this->headersFor($admin))
                ->assertOk()
                ->json('keys')
        )->keyBy('id');

        $this->assertFalse($keys[$working]['purgeable']);
        $this->assertNotNull($keys[$working]['purge_blocked_reason']);
        $this->assertTrue($keys[$faulty]['purgeable']);
        $this->assertNull($keys[$faulty]['purge_blocked_reason']);
    }

    public function test_a_plain_user_cannot_purge(): void
    {
        $plain = $this->makeUser();
        $owner = $this->makeUser();
        $id = $this->makeAccount($owner, ['deleted_at' => now()]);

        $this->deleteJson("/api/admin/api-keys/{$id}/purge", [], $this->headersFor($plain))
            ->assertStatus(403);

        $this->assertNotNull(BinanceAccount::withTrashed()->find($id));
    }

    public function test_a_plain_user_cannot_see_or_delete_api_keys(): void
    {
        $plain = $this->makeUser();
        $owner = $this->makeUser();
        $id = $this->makeAccount($owner);

        $headers = $this->headersFor($plain);

        $this->getJson('/api/admin/api-keys', $headers)->assertStatus(403);
        $this->deleteJson("/api/admin/api-keys/{$id}", [], $headers)->assertStatus(403);
        $this->postJson('/api/admin/api-keys/bulk-delete', ['ids' => [$id]], $headers)->assertStatus(403);

        $this->assertNull(BinanceAccount::withTrashed()->find($id)->deleted_at);
    }

    /** makeAccount numbers its secrets; recover the counter for one row. */
    private function keyIndexFor(int $id): string
    {
        $secret = BinanceAccount::withTrashed()->find($id)->secret_key;

        return str_replace('test-secret-key-', '', $secret);
    }
}
