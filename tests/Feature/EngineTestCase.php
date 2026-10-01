<?php

namespace Tests\Feature;

use App\Services\Exchanges\ExchangeSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Shared plumbing for /api/engine/* tests: a configured engine secret,
 * header helper, and seeders for user_credentials / {exchange}_accounts
 * (no factories exist for these tables — rows are inserted directly).
 */
abstract class EngineTestCase extends TestCase
{
    use RefreshDatabase;

    protected string $engineSecret = 'test-engine-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.engine.secret' => $this->engineSecret]);
    }

    /** @return array<string, string> */
    protected function engineHeaders(): array
    {
        return ['X-Engine-Secret' => $this->engineSecret];
    }

    /** Insert a user_credentials row; returns its uni_id. */
    protected function makeUser(array $overrides = []): string
    {
        $uniId = (string) Str::uuid();

        DB::table('user_credentials')->insert(array_merge([
            'uni_id' => $uniId,
            'name' => 'Engine Test User',
            'email' => $uniId.'@test.local',
            'password' => bcrypt('secret-password'),
            'status' => 'active',
            'type' => 'user',
            // Every guarded route requires it (EnsureEmailVerified); a test
            // about an unverified account overrides it explicitly.
            'email_verified' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return $uniId;
    }

    /** Insert an {exchange}_accounts row (binance by default); returns its id. */
    protected function makeAccount(string $uniId, array $overrides = [], string $exchange = 'binance'): int
    {
        static $n = 0;
        $n++;

        return DB::table(ExchangeSchema::for($exchange)->accountsTable)->insertGetId(array_merge([
            'uni_id' => $uniId,
            'api_key' => "test-api-key-{$n}-".Str::random(8),
            'secret_key' => "test-secret-key-{$n}",
            'name' => "Test Account {$n} ".Str::random(4),
            'balance' => 1000,
            'initial_deposit' => 1000,
            'currency_type' => 'USDT',
            'demo' => 0,
            'enabled' => 1,
            'is_sandbox' => 0,
            'created_at' => now(),
        ], $overrides));
    }
}
