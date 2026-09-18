<?php

namespace Tests\Feature;

use App\Models\BinanceAccount;
use App\Models\MexcAccount;
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
            "/api/admin/api-keys/binance/{$id}",
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
            "/api/admin/api-keys/binance/{$id}",
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

        $this->putJson("/api/admin/api-keys/binance/{$id}", ['name' => 'Taken'], $this->headersFor($admin))
            ->assertStatus(422);

        $this->assertDatabaseHas('binance_accounts', ['id' => $id, 'name' => 'Mine']);
    }

    public function test_delete_soft_deletes_so_history_survives(): void
    {
        $admin = $this->admin();
        $owner = $this->makeUser();
        $id = $this->makeAccount($owner);

        $this->deleteJson("/api/admin/api-keys/binance/{$id}", [], $this->headersFor($admin))->assertOk();

        // Row still there (positions/invoices join on api_key), flagged gone.
        $this->assertDatabaseHas('binance_accounts', ['id' => $id]);
        $this->assertNotNull(BinanceAccount::withTrashed()->find($id)->deleted_at);
    }

    public function test_deleting_an_already_disconnected_key_is_refused(): void
    {
        $admin = $this->admin();
        $owner = $this->makeUser();
        $id = $this->makeAccount($owner, ['deleted_at' => now()]);

        $this->deleteJson("/api/admin/api-keys/binance/{$id}", [], $this->headersFor($admin))
            ->assertStatus(422);
    }

    public function test_bulk_delete_removes_only_the_keys_it_was_given(): void
    {
        $admin = $this->admin();
        $owner = $this->makeUser();
        $doomed = [$this->makeAccount($owner), $this->makeAccount($owner)];
        $spared = $this->makeAccount($owner);

        $this->postJson(
            '/api/admin/api-keys/bulk-delete',
            ['keys' => $this->binanceRefs($doomed)],
            $this->headersFor($admin)
        )
            ->assertOk()
            ->assertJsonPath('deleted', 2);

        foreach ($doomed as $id) {
            $this->assertNotNull(BinanceAccount::withTrashed()->find($id)->deleted_at);
        }
        $this->assertNull(BinanceAccount::withTrashed()->find($spared)->deleted_at);
    }

    /** Two admins pressing the same button must not produce a failure. */
    public function test_bulk_delete_skips_already_disconnected_keys(): void
    {
        $admin = $this->admin();
        $owner = $this->makeUser();
        $live = $this->makeAccount($owner);
        $gone = $this->makeAccount($owner, ['deleted_at' => now()]);

        $this->postJson(
            '/api/admin/api-keys/bulk-delete',
            ['keys' => $this->binanceRefs([$live, $gone, 999999])],
            $this->headersFor($admin)
        )
            ->assertOk()
            ->assertJsonPath('deleted', 1)
            ->assertJsonPath('skipped', 2);
    }

    public function test_bulk_delete_requires_keys(): void
    {
        $admin = $this->admin();

        $this->postJson('/api/admin/api-keys/bulk-delete', ['keys' => []], $this->headersFor($admin))
            ->assertStatus(422);
        // An id without its exchange names a different row on every venue.
        $this->postJson('/api/admin/api-keys/bulk-delete', ['keys' => [['id' => 1]]], $this->headersFor($admin))
            ->assertStatus(422);
        $this->postJson('/api/admin/api-keys/bulk-delete', ['keys' => [['exchange' => 'kraken', 'id' => 1]]], $this->headersFor($admin))
            ->assertStatus(422);
    }

    /* ---- every exchange, addressed by (exchange, id) ---- */

    public function test_it_lists_mexc_keys_beside_binance_ones(): void
    {
        $admin = $this->admin();
        $owner = $this->makeUser(['name' => 'Two Venues']);
        $binance = $this->makeAccount($owner, ['api_key' => 'BINANCEKEY0123456789']);
        $mexc = $this->makeAccount($owner, ['api_key' => 'MEXCKEY0123456789ABCD'], 'mexc');

        $response = $this->getJson('/api/admin/api-keys', $this->headersFor($admin))->assertOk();

        $keys = collect($response->json('keys'))->keyBy(fn ($k) => $k['exchange'].':'.$k['id']);
        $this->assertCount(2, $keys);
        $this->assertSame('Two Venues', $keys["mexc:{$mexc}"]['owner']['name']);
        $this->assertSame('MEXCKE…ABCD', $keys["mexc:{$mexc}"]['api_key_hint']);
        $this->assertSame('binance', $keys["binance:{$binance}"]['exchange']);
        $this->assertSame(2, $response->json('counts.connected'));
        $this->assertSame(['binance', 'mexc'], $response->json('exchanges'));

        $body = $response->getContent();
        $this->assertStringNotContainsString('MEXCKEY0123456789ABCD', $body);
        $this->assertStringNotContainsString('secret_key', $body);
    }

    /**
     * Ids repeat across the per-exchange tables: the same number is a Binance
     * row in one and a MEXC row in the other. A write addressed to one
     * exchange must never land on the other's row.
     */
    public function test_a_write_addressed_to_one_exchange_never_touches_the_others_row(): void
    {
        $admin = $this->admin();
        $owner = $this->makeUser();
        $binance = $this->makeAccount($owner, ['name' => 'Binance Main']);
        // Same id on purpose — the collision under test.
        $mexc = $this->makeAccount($owner, ['id' => $binance, 'name' => 'MEXC Main'], 'mexc');

        $this->putJson(
            "/api/admin/api-keys/mexc/{$mexc}",
            ['name' => 'MEXC Renamed', 'enabled' => false],
            $this->headersFor($admin)
        )
            ->assertOk()
            ->assertJsonPath('key.exchange', 'mexc')
            ->assertJsonPath('key.name', 'MEXC Renamed');

        $this->assertDatabaseHas('mexc_accounts', ['id' => $mexc, 'name' => 'MEXC Renamed', 'enabled' => 0]);
        $this->assertDatabaseHas('binance_accounts', ['id' => $binance, 'name' => 'Binance Main', 'enabled' => 1]);

        $this->deleteJson("/api/admin/api-keys/mexc/{$mexc}", [], $this->headersFor($admin))->assertOk();
        $this->assertNotNull(MexcAccount::withTrashed()->find($mexc)->deleted_at);
        $this->assertNull(BinanceAccount::withTrashed()->find($binance)->deleted_at);
    }

    public function test_bulk_delete_takes_exchange_id_pairs(): void
    {
        $admin = $this->admin();
        $owner = $this->makeUser();
        $binance = $this->makeAccount($owner);
        $mexc = $this->makeAccount($owner, [], 'mexc');
        $sparedMexc = $this->makeAccount($owner, [], 'mexc');

        $this->postJson(
            '/api/admin/api-keys/bulk-delete',
            ['keys' => [
                ['exchange' => 'binance', 'id' => $binance],
                ['exchange' => 'mexc', 'id' => $mexc],
            ]],
            $this->headersFor($admin)
        )
            ->assertOk()
            ->assertJsonPath('deleted', 2)
            ->assertJsonPath('skipped', 0);

        $this->assertNotNull(BinanceAccount::withTrashed()->find($binance)->deleted_at);
        $this->assertNotNull(MexcAccount::withTrashed()->find($mexc)->deleted_at);
        $this->assertNull(MexcAccount::withTrashed()->find($sparedMexc)->deleted_at);
    }

    /** A purge clears the exchange's OWN tables — and only those. */
    public function test_a_faulty_mexc_key_is_purged_from_the_mexc_tables_only(): void
    {
        $admin = $this->admin();
        $owner = $this->makeUser();
        $id = $this->makeAccount($owner, [
            'api_key' => 'MEXCFAULTY0001',
            'key_status' => MexcAccount::KEY_BLOCKED,
            'key_blocked_at' => now()->subDays(4),
        ], 'mexc');
        // Same api_key string in the Binance table (cannot happen — keys are
        // venue specific — but it is exactly what a cross-table delete would hit).
        $this->makeAccount($owner, ['api_key' => 'MEXCFAULTY0001']);

        foreach (['mexc_pastpositions', 'binance_pastpositions'] as $table) {
            DB::table($table)->insert([
                'api_key' => 'MEXCFAULTY0001', 'uni_id' => $owner, 'symbol' => 'BTCUSDT',
                'position_side' => 'LONG', 'position_amt' => 0.01, 'entry_price' => 100,
                'exit_price' => 110, 'realized_pnl' => 10, 'side' => 'BUY',
                'closed_at' => now(), 'created_at' => now(),
            ]);
        }

        $this->deleteJson("/api/admin/api-keys/mexc/{$id}/purge", [], $this->headersFor($admin))
            ->assertOk()
            ->assertJsonPath('removed.trades', 1);

        $this->assertNull(MexcAccount::withTrashed()->find($id));
        $this->assertDatabaseMissing('mexc_pastpositions', ['api_key' => 'MEXCFAULTY0001']);
        $this->assertDatabaseHas('binance_pastpositions', ['api_key' => 'MEXCFAULTY0001']);
        $this->assertDatabaseHas('binance_accounts', ['api_key' => 'MEXCFAULTY0001']);
    }

    /**
     * `invoices.account_id` repeats across exchanges too: a Binance invoice
     * on account 1 must not shield the MEXC account that shares the number.
     */
    public function test_an_invoice_on_another_exchanges_same_id_does_not_block_a_purge(): void
    {
        $admin = $this->admin();
        $owner = $this->makeUser();
        $binance = $this->makeAccount($owner, ['api_key' => 'BILLEDBINANCE01', 'deleted_at' => now()]);
        $mexc = $this->makeAccount($owner, [
            'id' => $binance, 'api_key' => 'UNBILLEDMEXC01', 'deleted_at' => now(),
        ], 'mexc');

        DB::table('invoices')->insert([
            'user_id' => $owner, 'account_id' => $binance, 'exchange' => 'binance',
            'api_key' => 'BILLEDBINANCE01', 'month_year' => '2026-07',
            'total_fee' => 100, 'status' => 'paid',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $keys = collect($this->getJson('/api/admin/api-keys', $this->headersFor($admin))->json('keys'))
            ->keyBy(fn ($k) => $k['exchange'].':'.$k['id']);
        $this->assertSame(1, $keys["binance:{$binance}"]['usage']['invoices']);
        $this->assertSame(0, $keys["mexc:{$mexc}"]['usage']['invoices']);
        $this->assertFalse($keys["binance:{$binance}"]['purgeable']);
        $this->assertTrue($keys["mexc:{$mexc}"]['purgeable']);

        $this->deleteJson("/api/admin/api-keys/mexc/{$mexc}/purge", [], $this->headersFor($admin))->assertOk();
        $this->deleteJson("/api/admin/api-keys/binance/{$binance}/purge", [], $this->headersFor($admin))
            ->assertStatus(422);
    }

    public function test_an_unwired_exchange_answers_400_and_an_unknown_one_404(): void
    {
        $admin = $this->admin();

        $this->putJson('/api/admin/api-keys/bybit/1', ['name' => 'x'], $this->headersFor($admin))
            ->assertStatus(400)
            ->assertJsonPath('error_code', 'EXCHANGE_NOT_SUPPORTED');
        $this->deleteJson('/api/admin/api-keys/kraken/1', [], $this->headersFor($admin))
            ->assertStatus(404);
    }

    /** @param  int[]  $ids  @return list<array{exchange: string, id: int}> */
    private function binanceRefs(array $ids): array
    {
        return array_map(fn (int $id) => ['exchange' => 'binance', 'id' => $id], $ids);
    }

    /* ---- permanent delete (purge) ---- */

    public function test_a_working_key_cannot_be_purged(): void
    {
        $admin = $this->admin();
        $owner = $this->makeUser();
        $id = $this->makeAccount($owner);

        $this->deleteJson("/api/admin/api-keys/binance/{$id}/purge", [], $this->headersFor($admin))
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

        $this->deleteJson("/api/admin/api-keys/binance/{$id}/purge", [], $this->headersFor($admin))
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

        $this->deleteJson("/api/admin/api-keys/binance/{$id}/purge", [], $this->headersFor($admin))
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

        $response = $this->deleteJson("/api/admin/api-keys/binance/{$id}/purge", [], $this->headersFor($admin))
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

        $this->deleteJson("/api/admin/api-keys/binance/{$id}/purge", [], $this->headersFor($plain))
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
        $this->deleteJson("/api/admin/api-keys/binance/{$id}", [], $headers)->assertStatus(403);
        $this->postJson('/api/admin/api-keys/bulk-delete', ['keys' => $this->binanceRefs([$id])], $headers)->assertStatus(403);

        $this->assertNull(BinanceAccount::withTrashed()->find($id)->deleted_at);
    }

    /** makeAccount numbers its secrets; recover the counter for one row. */
    private function keyIndexFor(int $id): string
    {
        $secret = BinanceAccount::withTrashed()->find($id)->secret_key;

        return str_replace('test-secret-key-', '', $secret);
    }
}
