<?php

namespace Tests\Feature;

use App\Models\UserCredential;
use App\Services\Exchanges\ExchangeSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;

/**
 * Covers /api/admin/database/* — the phpMyAdmin-style console.
 *
 * These run on SQLite :memory: (phpunit.xml), so nothing here may depend on
 * MySQL-only surfaces: no SHOW TABLES, no information_schema, no SET SESSION.
 * The gate tests work regardless because a blocked statement is rejected by
 * string analysis BEFORE it reaches the driver.
 */
class AdminDatabaseTest extends EngineTestCase
{
    /**
     * Developer bearer token — the console sits behind the `developer`
     * middleware, which admin and master do NOT satisfy.
     */
    private function admin(): array
    {
        return $this->tokenFor('developer');
    }

    private function tokenFor(string $type): array
    {
        $uniId = $this->makeUser(['type' => $type]);
        $user = UserCredential::find($uniId);

        return ['Authorization' => 'Bearer '.$user->createToken('spa')->plainTextToken];
    }

    private function sql(array $headers, string $sql, array $extra = []): TestResponse
    {
        return $this->postJson('/api/admin/database/query', array_merge(['sql' => $sql], $extra), $headers);
    }

    /* ============ auth ============ */

    public function test_requires_developer(): void
    {
        $this->getJson('/api/admin/database/tables')->assertStatus(401);

        $plainUniId = $this->makeUser();
        $plain = UserCredential::find($plainUniId);

        $this->getJson('/api/admin/database/tables', [
            'Authorization' => 'Bearer '.$plain->createToken('spa')->plainTextToken,
        ])->assertStatus(403)->assertJson(['error_code' => 'FORBIDDEN']);

        // Running the business is not the same as maintaining the schema:
        // admin and master reach every other /admin route but not this one.
        foreach (['admin', 'master'] as $role) {
            $this->asRole($role)
                ->assertStatus(403)
                ->assertJson(['error_code' => 'FORBIDDEN']);
        }

        $this->asRole('developer')->assertOk();
    }

    /**
     * One request as a freshly-minted account of `$type`.
     *
     * forgetGuards() is the load-bearing part: the guard caches the user it
     * resolved on the first request, so without it every later token in this
     * test would silently authenticate as the first one.
     */
    private function asRole(string $type): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->getJson('/api/admin/database/tables', $this->tokenFor($type));
    }

    /* ============ introspection ============ */

    public function test_tables_lists_only_this_schema_with_row_counts(): void
    {
        $headers = $this->admin();   // one user_credentials row
        $this->makeUser();           // a second

        $response = $this->getJson('/api/admin/database/tables', $headers)->assertOk();

        $tables = collect($response->json('tables'));
        $users = $tables->firstWhere('name', 'user_credentials');

        $this->assertNotNull($users);
        $this->assertSame(2, $users['rows']);
        $this->assertNotEmpty($response->json('database'));

        // Regression guard: an unscoped Schema::getTables() would leak every
        // database the connection can see (569 tables across 43 schemas on the
        // WAMP box). Everything returned must belong to OUR schema.
        $allowed = Schema::getTableListing(Schema::getCurrentSchemaName(), schemaQualified: false);
        foreach ($tables->pluck('name') as $name) {
            $this->assertContains($name, $allowed, "{$name} is not in this connection's schema");
        }
    }

    public function test_structure_returns_columns_indexes_and_key_columns(): void
    {
        $headers = $this->admin();

        $response = $this->getJson('/api/admin/database/tables/user_credentials/structure', $headers)
            ->assertOk()
            ->assertJsonPath('table', 'user_credentials')
            ->assertJsonPath('key_columns', ['uni_id'])
            ->assertJsonPath('editable', true);

        $this->assertContains('uni_id', array_column($response->json('columns'), 'name'));
        $this->assertContains('password', $response->json('masked_columns'));
        $this->assertContains('uni_id', $response->json('immutable_columns'));
    }

    public function test_structure_rejects_unknown_and_qualified_tables(): void
    {
        $headers = $this->admin();

        $this->getJson('/api/admin/database/tables/not_a_table/structure', $headers)
            ->assertStatus(404)
            ->assertJson(['error_code' => 'UNKNOWN_TABLE']);

        // The route constraint refuses a dotted cross-schema reference outright.
        $this->getJson('/api/admin/database/tables/mysql.user/structure', $headers)
            ->assertStatus(404);
    }

    /* ============ browse ============ */

    public function test_rows_paginates_sorts_and_masks_secrets(): void
    {
        $headers = $this->admin();
        $this->makeUser(['email' => 'b@test.local']);
        $this->makeUser(['email' => 'c@test.local']);

        $response = $this->getJson(
            '/api/admin/database/tables/user_credentials/rows?per_page=2&page=2&sort=email&direction=asc',
            $headers
        )->assertOk()
            ->assertJsonPath('total', 3)
            ->assertJsonPath('per_page', 2)
            ->assertJsonPath('page', 2)
            ->assertJsonPath('sort', 'email');

        $rows = $response->json('rows');
        $this->assertCount(1, $rows);
        $this->assertSame('••••••••', $rows[0]['password']);
    }

    public function test_rows_clamps_per_page_and_ignores_unknown_sort(): void
    {
        $headers = $this->admin();

        $this->getJson(
            '/api/admin/database/tables/user_credentials/rows?per_page=9999&sort='.urlencode('; drop table x'),
            $headers
        )->assertOk()
            ->assertJsonPath('per_page', 200)
            ->assertJsonPath('sort', 'uni_id');
    }

    /**
     * Every venue's accounts table carries the same two credential columns.
     * Until the mask was keyed by column NAME, only binance_accounts was
     * listed and mexc_accounts / bybit_accounts returned the exchange secret
     * in the clear. The loop reads the registry so a fourth venue is asserted
     * the day it is registered.
     */
    public function test_rows_mask_exchange_secrets_on_every_venue(): void
    {
        $headers = $this->admin();
        $uniId = $this->makeUser();

        foreach (ExchangeSchema::supported() as $exchange) {
            $table = ExchangeSchema::for($exchange)->accountsTable;

            $this->makeAccount($uniId, [
                'api_key' => 'abcdEFGHIJKLMNOPQRSTUVwxyz',
                'secret_key' => "the-{$exchange}-secret-never-shown",
            ], $exchange);

            $response = $this->getJson("/api/admin/database/tables/{$table}/rows", $headers)
                ->assertOk()
                ->assertJsonPath('total', 1);

            $row = $response->json('rows.0');
            $this->assertSame('••••••••', $row['secret_key'], "{$table} leaks secret_key");
            $this->assertSame('abcd…wxyz', $row['api_key'], "{$table} shows the full api_key");

            // The UI reads masked_columns to know which cells not to round-trip.
            $this->assertContains('secret_key', $response->json('masked_columns'), $table);
            $this->assertContains('api_key', $response->json('masked_columns'), $table);

            $this->assertContains(
                'secret_key',
                $this->getJson("/api/admin/database/tables/{$table}/structure", $headers)->assertOk()->json('masked_columns'),
                "{$table} structure omits secret_key"
            );
        }
    }

    /**
     * A masked cell is only hidden if it cannot be asked about either: the
     * search is a bound LIKE, so scanning secret_key would confirm a secret
     * one prefix at a time. Name and the api_key hint must still find the row.
     */
    public function test_rows_search_cannot_probe_a_masked_secret(): void
    {
        $headers = $this->admin();
        $uniId = $this->makeUser();

        foreach (ExchangeSchema::supported() as $exchange) {
            $table = ExchangeSchema::for($exchange)->accountsTable;

            $this->makeAccount($uniId, [
                'api_key' => 'KEYPREFIX-'.$exchange.'-0123456789',
                'secret_key' => 'SECRETPREFIX-'.$exchange.'-9876543210',
                'name' => "Findable {$exchange} account",
            ], $exchange);

            $search = fn (string $term) => $this->getJson(
                "/api/admin/database/tables/{$table}/rows?search=".urlencode($term),
                $headers
            )->assertOk()->json('total');

            $this->assertSame(0, $search('SECRETPREFIX'), "{$table}: a secret prefix matched a row");
            $this->assertSame(0, $search('SECRETPREFIX-'.$exchange), "{$table}: a longer secret prefix matched a row");
            $this->assertSame(1, $search('Findable'), "{$table}: search by name broke");
            $this->assertSame(1, $search('KEYPREFIX'), "{$table}: search by api_key hint broke");
        }
    }

    /**
     * The grid renders `abcd…wxyz` for a key; a cell saved unchanged must not
     * write that hint over the real key, any more than bullets over a hash.
     */
    public function test_row_update_rejects_round_tripped_mask_on_exchange_accounts(): void
    {
        $headers = $this->admin();
        $uniId = $this->makeUser();

        foreach (ExchangeSchema::supported() as $exchange) {
            $table = ExchangeSchema::for($exchange)->accountsTable;
            $id = $this->makeAccount($uniId, [
                'api_key' => 'abcdEFGHIJKLMNOPQRSTUVwxyz',
                'secret_key' => 'keep-this-secret',
            ], $exchange);

            $this->putJson("/api/admin/database/tables/{$table}/rows", [
                'key' => ['id' => $id], 'values' => ['secret_key' => '••••••••'],
            ], $headers)->assertStatus(422)->assertJson(['error_code' => 'MASKED_VALUE']);

            $this->putJson("/api/admin/database/tables/{$table}/rows", [
                'key' => ['id' => $id], 'values' => ['api_key' => 'abcd…wxyz'],
            ], $headers)->assertStatus(422)->assertJson(['error_code' => 'MASKED_VALUE']);

            $row = DB::table($table)->where('id', $id)->first();
            $this->assertSame('abcdEFGHIJKLMNOPQRSTUVwxyz', $row->api_key, $table);
            $this->assertSame('keep-this-secret', $row->secret_key, $table);
        }
    }

    /* ============ row writes ============ */

    public function test_row_update_writes_only_submitted_columns(): void
    {
        $headers = $this->admin();
        $uniId = $this->makeUser(['name' => 'Before', 'email' => 'keep@test.local']);

        $this->putJson('/api/admin/database/tables/user_credentials/rows', [
            'key' => ['uni_id' => $uniId],
            'values' => ['name' => 'Renamed'],
        ], $headers)->assertOk()->assertJsonPath('affected', 1);

        $row = DB::table('user_credentials')->where('uni_id', $uniId)->first();
        $this->assertSame('Renamed', $row->name);
        $this->assertSame('keep@test.local', $row->email);
    }

    public function test_row_update_rejects_masked_key_and_unknown_columns(): void
    {
        $headers = $this->admin();
        $uniId = $this->makeUser();
        $key = ['uni_id' => $uniId];

        // A round-tripped grid must never write the mask back over a hash.
        $this->putJson('/api/admin/database/tables/user_credentials/rows', [
            'key' => $key, 'values' => ['password' => '••••••••'],
        ], $headers)->assertStatus(422)->assertJson(['error_code' => 'MASKED_VALUE']);

        $this->putJson('/api/admin/database/tables/user_credentials/rows', [
            'key' => $key, 'values' => ['uni_id' => 'reassigned'],
        ], $headers)->assertStatus(422)->assertJson(['error_code' => 'IMMUTABLE_COLUMN']);

        $this->putJson('/api/admin/database/tables/user_credentials/rows', [
            'key' => $key, 'values' => ['nope' => 1],
        ], $headers)->assertStatus(422)->assertJson(['error_code' => 'UNKNOWN_COLUMN']);
    }

    public function test_row_delete_targets_exactly_one_row(): void
    {
        $headers = $this->admin();
        $doomed = $this->makeUser();
        $survivor = $this->makeUser();

        $this->deleteJson('/api/admin/database/tables/user_credentials/rows', [
            'key' => ['uni_id' => $doomed],
        ], $headers)->assertOk()->assertJsonPath('deleted', 1);

        $this->assertDatabaseMissing('user_credentials', ['uni_id' => $doomed]);
        $this->assertDatabaseHas('user_credentials', ['uni_id' => $survivor]);

        $this->deleteJson('/api/admin/database/tables/user_credentials/rows', [
            'key' => ['uni_id' => $doomed],
        ], $headers)->assertStatus(404)->assertJson(['error_code' => 'ROW_NOT_FOUND']);
    }

    public function test_row_delete_refuses_self(): void
    {
        $uniId = $this->makeUser(['type' => 'developer']);
        $user = UserCredential::find($uniId);
        $headers = ['Authorization' => 'Bearer '.$user->createToken('spa')->plainTextToken];

        $this->deleteJson('/api/admin/database/tables/user_credentials/rows', [
            'key' => ['uni_id' => $uniId],
        ], $headers)->assertStatus(422)->assertJson(['error_code' => 'SELF_DELETE_BLOCKED']);

        $this->assertDatabaseHas('user_credentials', ['uni_id' => $uniId]);
    }

    public function test_row_insert_returns_the_created_row(): void
    {
        $headers = $this->admin();

        // `assets` has a non-`id` auto-increment PK (asset_id) — the path most
        // likely to break a naive "assume id" implementation.
        $response = $this->postJson('/api/admin/database/tables/assets/rows', [
            'values' => ['ticker' => 'SOLUSDT', 'side' => 'ALL', 'base_size' => '0.1', 'max_increments' => '5'],
        ], $headers)->assertStatus(201);

        $assetId = $response->json('key.asset_id');
        $this->assertNotNull($assetId);
        $this->assertSame('SOLUSDT', $response->json('row.ticker'));
        $this->assertDatabaseHas('assets', ['asset_id' => $assetId, 'ticker' => 'SOLUSDT']);
    }

    public function test_keyless_table_is_not_editable(): void
    {
        $headers = $this->admin();
        DB::statement('create table tmp_keyless (a integer, b text)');
        DB::table('tmp_keyless')->insert(['a' => 1, 'b' => 'x']);

        $this->getJson('/api/admin/database/tables/tmp_keyless/structure', $headers)
            ->assertOk()
            ->assertJsonPath('editable', false)
            ->assertJsonPath('key_columns', []);

        $this->putJson('/api/admin/database/tables/tmp_keyless/rows', [
            'key' => ['a' => 1], 'values' => ['b' => 'y'],
        ], $headers)->assertStatus(422)->assertJson(['error_code' => 'TABLE_NOT_EDITABLE']);

        $this->deleteJson('/api/admin/database/tables/tmp_keyless/rows', [
            'key' => ['a' => 1],
        ], $headers)->assertStatus(422)->assertJson(['error_code' => 'TABLE_NOT_EDITABLE']);
    }

    /* ============ the SQL gate ============ */

    public function test_query_runs_a_select(): void
    {
        $headers = $this->admin();

        $response = $this->sql($headers, 'select uni_id, email from user_credentials')
            ->assertOk()
            ->assertJsonPath('kind', 'read')
            ->assertJsonPath('truncated', false);

        $this->assertNotEmpty($response->json('columns'));
        $this->assertSame(1, $response->json('row_count'));
    }

    public function test_query_blocks_ddl(): void
    {
        $headers = $this->admin();

        $statements = [
            'drop table user_credentials',
            'TRUNCATE TABLE assets',
            'alter table assets add column x int',
            'create table zzz (a int)',
            'rename table assets to assets2',
            "grant all on *.* to 'x'@'localhost'",
            'set global max_connections = 1',
        ];

        foreach ($statements as $statement) {
            $this->sql($headers, $statement)
                ->assertStatus(422)
                ->assertJson(['error_code' => 'SQL_BLOCKED']);
        }

        $this->assertTrue(Schema::hasTable('user_credentials'));
        $this->assertTrue(Schema::hasTable('assets'));
    }

    public function test_query_blocks_multi_statement_and_comment_smuggling(): void
    {
        $headers = $this->admin();
        $this->makeUser();

        $this->sql($headers, 'select 1; drop table assets')
            ->assertStatus(422)->assertJson(['error_code' => 'SQL_MULTI_STATEMENT']);

        // MySQL executes the body of a conditional comment, so it is refused
        // rather than stripped — stripping would run a different statement.
        $this->sql($headers, '/*!32302 drop table assets */select 1')
            ->assertStatus(422)->assertJson(['error_code' => 'SQL_BLOCKED']);

        $this->sql($headers, "select 1 -- x\n; delete from assets")
            ->assertStatus(422)->assertJson(['error_code' => 'SQL_MULTI_STATEMENT']);

        $this->sql($headers, "select 1 # x\n; delete from assets")
            ->assertStatus(422)->assertJson(['error_code' => 'SQL_MULTI_STATEMENT']);

        // A semicolon inside a literal is data, not a separator.
        $this->sql($headers, "select 'a; drop' as note")->assertOk();

        $this->assertTrue(Schema::hasTable('assets'));
    }

    public function test_query_allows_row_writes_and_reports_affected(): void
    {
        $headers = $this->admin();
        $this->makeUser(['name' => 'A']);
        $this->makeUser(['name' => 'B']);

        $this->sql($headers, "update user_credentials set name = 'Renamed' where type = 'user'")
            ->assertOk()
            ->assertJsonPath('kind', 'write')
            ->assertJsonPath('affected', 2);

        $this->assertSame(2, DB::table('user_credentials')->where('name', 'Renamed')->count());
    }

    public function test_query_requires_confirmation_for_unfiltered_writes(): void
    {
        $headers = $this->admin();
        DB::table('assets')->insert(['ticker' => 'BTCUSDT', 'side' => 'ALL', 'base_size' => 0.001, 'max_increments' => 10, 'enabled' => 1]);

        $this->sql($headers, 'delete from assets')
            ->assertStatus(422)->assertJson(['error_code' => 'UNFILTERED_WRITE']);

        $this->assertSame(1, DB::table('assets')->count());

        $this->sql($headers, 'delete from assets', ['confirm_unfiltered' => true])
            ->assertOk()->assertJsonPath('kind', 'write');

        $this->assertSame(0, DB::table('assets')->count());
    }

    public function test_query_rejects_file_and_system_schema_access(): void
    {
        $headers = $this->admin();

        // Gate-only rejections, so these assert identically under SQLite.
        foreach ([
            "select * from user_credentials into outfile '/tmp/x'",
            "select load_file('/etc/passwd')",
            'select * from mysql.user',
            'select benchmark(1000000, md5(1))',
        ] as $statement) {
            $this->sql($headers, $statement)
                ->assertStatus(422)
                ->assertJson(['error_code' => 'SQL_BLOCKED']);
        }
    }

    public function test_query_returns_a_clean_error_for_bad_sql(): void
    {
        $headers = $this->admin();

        $this->sql($headers, 'select * from nope_not_here')
            ->assertStatus(422)
            ->assertJson(['error_code' => 'SQL_ERROR']);
    }

    public function test_query_masks_secret_columns_in_results(): void
    {
        $headers = $this->admin();

        $response = $this->sql($headers, 'select uni_id, password from user_credentials')->assertOk();

        $this->assertSame('••••••••', $response->json('rows.0.password'));
    }

    /** MASKED_EVERYWHERE reaches the SQL tab too, on a table the per-table list never named. */
    public function test_query_masks_secret_key_from_any_venue_table(): void
    {
        $headers = $this->admin();
        $uniId = $this->makeUser();

        foreach (ExchangeSchema::supported() as $exchange) {
            $table = ExchangeSchema::for($exchange)->accountsTable;
            $this->makeAccount($uniId, ['secret_key' => 'loose-'.$exchange.'-secret'], $exchange);

            $response = $this->sql($headers, "select id, secret_key from {$table}")->assertOk();

            $this->assertSame('••••••••', $response->json('rows.0.secret_key'), $table);
        }
    }
}
