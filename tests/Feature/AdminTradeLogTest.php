<?php

namespace Tests\Feature;

use App\Models\TradeLog;
use App\Models\UserCredential;

/**
 * GET /api/admin/trade-logs — the engine's signal log.
 *
 * The point of this surface is the `sizing` block the engine writes into each
 * account's detail: without it a row shows *what* size was sent but never
 * *why*, which is exactly the question the log exists to answer.
 */
class AdminTradeLogTest extends EngineTestCase
{
    private function headersFor(string $uniId): array
    {
        $user = UserCredential::find($uniId);

        return ['Authorization' => 'Bearer '.$user->createToken('spa')->plainTextToken];
    }

    /** A BUY fan-out: one filled with sizing, one skipped at the stack cap. */
    private function seedEntryLog(string $uniId): TradeLog
    {
        return TradeLog::create([
            'exchange' => 'binance',
            'action' => 'BUY',
            'ticker' => 'BTCUSDT',
            'success' => true,
            'price' => 60000,
            'strategy' => 'ABCD-v1',
            'leverage' => 25,
            'category' => 'signal',
            'target_count' => 2,
            'filled' => 1,
            'skipped' => 1,
            'failed' => 0,
            'ts' => '2026-07-30 12:00:00',
            'details' => [
                [
                    'account' => 'Main Futures',
                    'uni_id' => $uniId,
                    'status' => 'filled',
                    'quantity' => 0.01,
                    'fill_price' => 60120.5,
                    'sizing' => [
                        'balance' => 1000.0,
                        'base_size' => 0.005,
                        'reference_balance' => 500.0,
                        'coarse_step' => true,
                        'quantity' => 0.01,
                        'size_multiple' => 2.0,
                        'stacks_now' => 0.0,
                        'max_increments' => 10.0,
                    ],
                ],
                [
                    'account' => 'Maxed Out',
                    'uni_id' => $uniId,
                    'status' => 'skipped',
                    'reason' => 'maxed sizing',
                    'sizing' => [
                        'balance' => 2500.0,
                        'base_size' => 0.005,
                        'reference_balance' => 500.0,
                        'coarse_step' => true,
                        'quantity' => 0.025,
                        'size_multiple' => 5.0,
                        'stacks_now' => 10.0,
                        'max_increments' => 10.0,
                    ],
                ],
            ],
        ]);
    }

    public function test_the_sizing_decision_survives_the_round_trip(): void
    {
        $admin = $this->makeUser(['type' => 'admin', 'name' => 'Ops Admin']);
        $this->seedEntryLog($admin);

        $response = $this->getJson('/api/admin/trade-logs', $this->headersFor($admin))
            ->assertOk()
            ->assertJsonPath('logs.0.ticker', 'BTCUSDT')
            ->assertJsonPath('logs.0.action', 'BUY');

        // json_encode drops the zero fraction (1000.0 -> 1000), so compare as
        // floats rather than asserting on the decoded PHP type.
        $filled = $response->json('logs.0.details.0');
        $this->assertSame('filled', $filled['status']);
        $this->assertSame(0.01, (float) $filled['quantity']);
        // 1000 / 500 = 2 whole steps of 0.005.
        $this->assertSame(1000.0, (float) $filled['sizing']['balance']);
        $this->assertSame(0.005, (float) $filled['sizing']['base_size']);
        $this->assertSame(2.0, (float) $filled['sizing']['size_multiple']);
        $this->assertTrue($filled['sizing']['coarse_step']);
        // uni_id is resolved to a display name for the admin table.
        $this->assertSame('Ops Admin', $filled['user_name']);

        $skipped = $response->json('logs.0.details.1');
        $this->assertSame('maxed sizing', $skipped['reason']);
        $this->assertSame(10.0, (float) $skipped['sizing']['stacks_now']);
        $this->assertSame(10.0, (float) $skipped['sizing']['max_increments']);
    }

    public function test_summary_counts_the_whole_filtered_set(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);
        $this->seedEntryLog($admin);
        $this->seedEntryLog($admin);

        $this->getJson('/api/admin/trade-logs', $this->headersFor($admin))
            ->assertOk()
            ->assertJsonPath('summary.signals', 2)
            ->assertJsonPath('summary.filled', 2)
            ->assertJsonPath('summary.skipped', 2)
            ->assertJsonPath('summary.clean_signals', 2)
            ->assertJsonPath('pagination.total', 2);
    }

    public function test_filters_narrow_the_result_and_the_summary(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);
        $this->seedEntryLog($admin);
        TradeLog::create([
            'exchange' => 'binance', 'action' => 'EXIT_LONG', 'ticker' => 'ETHUSDT',
            'success' => false, 'category' => 'signal', 'target_count' => 1,
            'filled' => 0, 'failed' => 1, 'skipped' => 0,
            'ts' => '2026-07-31 09:00:00', 'details' => [],
        ]);

        $headers = $this->headersFor($admin);

        $this->getJson('/api/admin/trade-logs?ticker=ETHUSDT', $headers)
            ->assertOk()
            ->assertJsonCount(1, 'logs')
            ->assertJsonPath('logs.0.ticker', 'ETHUSDT')
            ->assertJsonPath('summary.signals', 1);

        $this->getJson('/api/admin/trade-logs?action=BUY', $headers)
            ->assertOk()
            ->assertJsonCount(1, 'logs')
            ->assertJsonPath('logs.0.action', 'BUY');

        // result=problem keeps only the signals that did not fill cleanly.
        $this->getJson('/api/admin/trade-logs?result=problem', $headers)
            ->assertOk()
            ->assertJsonCount(1, 'logs')
            ->assertJsonPath('logs.0.ticker', 'ETHUSDT');

        $this->getJson('/api/admin/trade-logs?from=2026-07-31', $headers)
            ->assertOk()
            ->assertJsonCount(1, 'logs')
            ->assertJsonPath('logs.0.ticker', 'ETHUSDT');
    }

    /** Rejected signals never reach an account, so they carry no sizing. */
    public function test_a_rejected_signal_maps_without_sizing(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);
        TradeLog::create([
            'exchange' => 'binance', 'action' => 'BUY', 'ticker' => 'NOPEUSDT',
            'success' => false, 'category' => 'rejected', 'target_count' => 0,
            'filled' => 0, 'failed' => 0, 'skipped' => 0,
            'ts' => '2026-07-30 12:00:00',
            'details' => [['reason' => 'asset_not_configured']],
        ]);

        $this->getJson('/api/admin/trade-logs', $this->headersFor($admin))
            ->assertOk()
            ->assertJsonPath('logs.0.details.0.reason', 'asset_not_configured')
            ->assertJsonPath('logs.0.details.0.sizing', null)
            ->assertJsonPath('logs.0.details.0.account', null);
    }

    /**
     * A deposit-gated skip writes a partial sizing block (no base_size, no
     * quantity). It must survive the mapper without inventing zeros.
     */
    /** Streak sizing rides in the same block, and is null on every other row. */
    public function test_the_streak_step_survives_the_round_trip(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);
        TradeLog::create([
            'exchange' => 'binance', 'action' => 'BUY', 'ticker' => 'LTCUSDT',
            'success' => true, 'category' => 'signal', 'target_count' => 1,
            'filled' => 1, 'failed' => 0, 'skipped' => 0,
            'ts' => '2026-10-09 10:00:00',
            'details' => [[
                'account' => 'On a streak',
                'status' => 'filled',
                'quantity' => 100.0,
                'sizing' => [
                    'balance' => 2500.0, 'base_size' => 50.0, 'reference_balance' => 1000.0,
                    'coarse_step' => false, 'quantity' => 100.0, 'size_multiple' => 2.5,
                    'stacks_now' => 0.0, 'max_increments' => 3.0,
                    'streak_run' => -2, 'streak_known' => true, 'streak_kind' => 'loss',
                    'streak_step' => 1, 'streak_size' => 40.0,
                ],
            ]],
        ]);
        $this->seedEntryLog($admin);

        $logs = $this->getJson('/api/admin/trade-logs', $this->headersFor($admin))->assertOk()->json('logs');
        $streak = collect($logs)->firstWhere('ticker', 'LTCUSDT')['details'][0]['sizing'];
        $plain = collect($logs)->firstWhere('ticker', 'BTCUSDT')['details'][0]['sizing'];

        $this->assertSame(-2, $streak['streak_run']);
        $this->assertTrue($streak['streak_known']);
        $this->assertSame('loss', $streak['streak_kind']);
        $this->assertSame(1, $streak['streak_step']);
        $this->assertSame(40.0, (float) $streak['streak_size']);
        $this->assertNull($plain['streak_run']);
        $this->assertNull($plain['streak_size']);
    }

    public function test_a_deposit_gated_skip_maps_its_partial_sizing(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);
        TradeLog::create([
            'exchange' => 'binance', 'action' => 'BUY', 'ticker' => 'BTCUSDT',
            'success' => false, 'category' => 'signal', 'target_count' => 1,
            'filled' => 0, 'failed' => 0, 'skipped' => 1,
            'ts' => '2026-07-31 10:00:00',
            'details' => [[
                'account' => 'Underfunded',
                'status' => 'skipped',
                'reason' => 'deposit below minimum',
                'sizing' => ['total_deposit' => 400.0, 'min_deposit' => 1000.0, 'balance' => 380.0],
            ]],
        ]);

        $detail = $this->getJson('/api/admin/trade-logs', $this->headersFor($admin))
            ->assertOk()
            ->json('logs.0.details.0');

        $this->assertSame('deposit below minimum', $detail['reason']);
        $this->assertSame(400.0, (float) $detail['sizing']['total_deposit']);
        $this->assertSame(1000.0, (float) $detail['sizing']['min_deposit']);
        // Never reached sizing, so these stay null rather than becoming 0.
        $this->assertNull($detail['sizing']['base_size']);
        $this->assertNull($detail['sizing']['quantity']);
        $this->assertNull($detail['quantity']);
    }

    public function test_a_plain_user_cannot_read_the_signal_log(): void
    {
        $plain = $this->makeUser();

        $this->getJson('/api/admin/trade-logs', $this->headersFor($plain))
            ->assertStatus(403);
    }

    public function test_the_log_is_read_only(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);
        $log = $this->seedEntryLog($admin);
        $headers = $this->headersFor($admin);

        $this->deleteJson("/api/admin/trade-logs/{$log->id}", [], $headers)
            ->assertStatus(405);
        $this->putJson("/api/admin/trade-logs/{$log->id}", ['ticker' => 'HACKED'], $headers)
            ->assertStatus(405);

        $this->assertDatabaseHas('trade_logs', ['id' => $log->id, 'ticker' => 'BTCUSDT']);
    }
}
