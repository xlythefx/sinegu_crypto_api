<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;

/**
 * The open-position writes survive one MySQL deadlock (13 Nov 2026: a fill's
 * write-through and the poller's replace collided on the same account).
 *
 * Laravel only re-runs the OUTERMOST transaction, so these tests must not run
 * inside RefreshDatabase's wrapping one.
 */
class EngineSyncDeadlockTest extends EngineTestCase
{
    /** A freshly migrated in-memory database per test, with no wrapping transaction. */
    public function refreshDatabase(): void
    {
        $this->artisan('migrate');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeAccount($this->makeUser(), ['api_key' => 'key-a']);
    }

    /** Makes the next insert into binance_positions fail once as MySQL's deadlock victim. */
    private function deadlockNextPositionInsert(): void
    {
        $fired = false;
        DB::listen(function ($query) use (&$fired) {
            if (! $fired && str_starts_with(strtolower($query->sql), 'insert into "binance_positions"')) {
                $fired = true;
                throw new \RuntimeException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction');
            }
        });
    }

    public function test_position_upsert_is_retried_once_after_a_deadlock(): void
    {
        $this->deadlockNextPositionInsert();

        $this->postJson('/api/engine/binance/positions/upsert', [
            'api_key' => 'key-a', 'symbol' => 'RENDERUSDT', 'position_side' => 'LONG', 'position_amt' => 676,
        ], $this->engineHeaders())->assertOk()->assertJson(['result' => 'inserted']);

        $this->assertSame(1, DB::table('binance_positions')->where('api_key', 'key-a')->count());
    }

    public function test_positions_sync_is_retried_once_after_a_deadlock_and_counts_once(): void
    {
        DB::table('binance_positions')->insert([
            'api_key' => 'key-a', 'uni_id' => 'uni-key-a', 'symbol' => 'ETHUSDT',
            'position_side' => 'LONG', 'position_amt' => 0.5, 'created_at' => now(),
        ]);
        $this->deadlockNextPositionInsert();

        $this->postJson('/api/engine/binance/positions/sync', [
            'accounts' => [[
                'api_key' => 'key-a',
                'positions' => [['symbol' => 'BTCUSDT', 'position_side' => 'LONG', 'position_amt' => 1]],
            ]],
        ], $this->engineHeaders())->assertOk()->assertJson(['deleted' => 1, 'inserted' => 1]);

        $this->assertSame(['BTCUSDT'], DB::table('binance_positions')->where('api_key', 'key-a')->pluck('symbol')->all());
    }

    public function test_a_second_deadlock_still_fails_loudly(): void
    {
        DB::listen(function ($query) {
            if (str_starts_with(strtolower($query->sql), 'insert into "binance_positions"')) {
                throw new \RuntimeException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction');
            }
        });

        $this->postJson('/api/engine/binance/positions/upsert', [
            'api_key' => 'key-a', 'symbol' => 'RENDERUSDT', 'position_side' => 'LONG', 'position_amt' => 676,
        ], $this->engineHeaders())->assertStatus(500);

        $this->assertSame(0, DB::table('binance_positions')->count());
    }
}
