<?php

namespace Tests\Feature;

use App\Models\TradeLog;
use App\Models\UserCredential;
use App\Services\Admin\SignalDelivery;

/**
 * Delivery timing on trade_logs — the Signal Log's proof that a signal
 * TradingView marked "failed — timed out" still arrived and traded.
 *
 * The engine posts `sent_at` (TradingView's {{timenow}}), `received_at` and
 * `ack_ms`; the API derives `transit_ms`, serves a `delivery` block per row
 * and filters `?delivery=slow`. A malformed timing value is dropped, never a
 * 422: the signal's row matters more than its stopwatch.
 */
class TradeLogDeliveryTest extends EngineTestCase
{
    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeUser(['type' => 'admin']);
    }

    private function headers(): array
    {
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.UserCredential::find($this->admin)->createToken('t')->plainTextToken];
    }

    private function ingest(array $overrides = [])
    {
        return $this->postJson('/api/engine/binance/trade-logs', array_merge([
            'action' => 'BUY', 'ticker' => 'LTCUSDT', 'success' => true, 'category' => 'signal',
            'target_count' => 6, 'filled' => 5, 'failed' => 0, 'skipped' => 1, 'details' => [],
            'ts' => '2026-10-24 14:15:11',
        ], $overrides), $this->engineHeaders());
    }

    public function test_the_24_october_signal_reads_as_a_slow_delivery_that_traded(): void
    {
        $id = $this->ingest([
            'sent_at' => '2026-10-24T14:15:01.000Z',
            'received_at' => '2026-10-24T14:15:09.120Z',
            'ack_ms' => 2.5,
        ])->assertStatus(201)->json('trade_log_id');

        $log = TradeLog::find($id);
        $this->assertSame(8120, $log->transit_ms);
        $this->assertSame(2.5, $log->ack_ms);
        $this->assertSame('2026-10-24 14:15:01', $log->sent_at->format('Y-m-d H:i:s'));

        $row = $this->getJson('/api/admin/trade-logs', $this->headers())->assertOk()->json('logs.0');
        $this->assertSame(5, $row['filled']);
        $this->assertSame(8120, $row['delivery']['transit_ms']);
        $this->assertTrue($row['delivery']['slow']);
        $this->assertStringStartsWith('2026-10-24T14:15:01', $row['delivery']['sent_at']);
        $this->assertStringStartsWith('2026-10-24T14:15:09', $row['delivery']['received_at']);
    }

    public function test_a_fast_delivery_is_not_slow_and_no_sent_at_still_records_arrival(): void
    {
        $fast = $this->ingest([
            'sent_at' => '2026-10-24T12:30:11Z', 'received_at' => '2026-10-24T12:30:11.900Z', 'ack_ms' => 1.2,
        ])->json('trade_log_id');
        $bare = $this->ingest(['received_at' => '2026-10-24T12:31:00.000Z', 'ack_ms' => 1])->json('trade_log_id');

        $this->assertFalse(SignalDelivery::present(TradeLog::find($fast))['slow']);
        $this->assertSame(900, TradeLog::find($fast)->transit_ms);

        $delivery = SignalDelivery::present(TradeLog::find($bare));
        $this->assertNull($delivery['sent_at']);
        $this->assertNull($delivery['transit_ms']);
        $this->assertFalse($delivery['slow']);
    }

    public function test_a_slow_answer_alone_is_slow(): void
    {
        $this->assertTrue(SignalDelivery::isSlow(null, 3500.0));
        $this->assertFalse(SignalDelivery::isSlow(2000, 900.0));
        $this->assertTrue(SignalDelivery::isSlow(2900, 200.0));
    }

    public function test_malformed_timing_is_dropped_never_a_422(): void
    {
        $id = $this->ingest([
            'sent_at' => '{{timenow}}', 'received_at' => 'soon', 'ack_ms' => 'fast',
        ])->assertStatus(201)->json('trade_log_id');

        $log = TradeLog::find($id);
        $this->assertNull($log->sent_at);
        $this->assertNull($log->received_at);
        $this->assertNull($log->ack_ms);
        $this->assertNull($log->transit_ms);
    }

    public function test_an_older_engine_without_timing_shows_no_delivery_block(): void
    {
        $this->ingest()->assertStatus(201);

        $row = $this->getJson('/api/admin/trade-logs', $this->headers())->assertOk()->json('logs.0');
        $this->assertArrayHasKey('delivery', $row);
        $this->assertNull($row['delivery']);
    }

    public function test_the_slow_filter_lists_only_slow_deliveries(): void
    {
        $this->ingest(['ts' => '2026-10-24 14:15:11', 'sent_at' => '2026-10-24T14:15:01Z', 'received_at' => '2026-10-24T14:15:09Z', 'ack_ms' => 2]);
        $this->ingest(['ts' => '2026-10-24 12:30:12', 'sent_at' => '2026-10-24T12:30:11Z', 'received_at' => '2026-10-24T12:30:12Z', 'ack_ms' => 2]);
        $this->ingest(['ts' => '2026-10-24 08:00:10']);

        $logs = $this->getJson('/api/admin/trade-logs?delivery=slow', $this->headers())->assertOk()->json('logs');
        $this->assertCount(1, $logs);
        $this->assertSame(8000, $logs[0]['delivery']['transit_ms']);

        $this->assertCount(3, $this->getJson('/api/admin/trade-logs', $this->headers())->json('logs'));
    }
}
