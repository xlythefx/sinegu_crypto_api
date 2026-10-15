<?php

namespace Tests\Feature;

use App\Models\TradeLog;
use App\Models\UserCredential;
use App\Services\Positions\CloseSource;
use Illuminate\Support\Facades\DB;

/**
 * trade_logs.feed — which signal feed a logged signal arrived on: `main`
 * (TradingView, everyone) or `test` (the testers-only webhooks).
 *
 * The engine reports it with every log (absent = main, for an engine that
 * predates the test feed); the Signal Log filters on it and never folds a
 * retry across feeds; the health banner counts MAIN signals only, so test
 * traffic can never turn it amber or stand in for a live main feed.
 */
class TradeLogFeedTest extends EngineTestCase
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

    private function log(string $feed, string $ts, array $overrides = []): TradeLog
    {
        return TradeLog::create(array_merge([
            'exchange' => 'binance', 'feed' => $feed, 'action' => 'BUY', 'ticker' => 'LTCUSDT',
            'success' => true, 'category' => 'signal', 'target_count' => 1, 'filled' => 1,
            'failed' => 0, 'skipped' => 0, 'ts' => $ts,
            'details' => [['account' => 'Acct', 'uni_id' => 'u-1', 'status' => 'filled', 'is_master' => false]],
        ], $overrides));
    }

    private function ingest(array $overrides = [])
    {
        return $this->postJson('/api/engine/binance/trade-logs', array_merge([
            'action' => 'BUY', 'ticker' => 'LTCUSDT', 'success' => true, 'category' => 'signal',
            'target_count' => 1, 'filled' => 1, 'failed' => 0, 'skipped' => 0, 'details' => [],
            'ts' => '2026-10-15 08:00:00',
        ], $overrides), $this->engineHeaders());
    }

    // ---- ingest ---------------------------------------------------------------

    public function test_the_engine_reports_the_feed_and_an_older_engine_defaults_to_main(): void
    {
        $test = $this->ingest(['feed' => 'test'])->assertStatus(201)->json('trade_log_id');
        $absent = $this->ingest()->assertStatus(201)->json('trade_log_id');
        $null = $this->ingest(['feed' => null])->assertStatus(201)->json('trade_log_id');

        $this->assertSame('test', DB::table('trade_logs')->where('id', $test)->value('feed'));
        $this->assertSame('main', DB::table('trade_logs')->where('id', $absent)->value('feed'));
        $this->assertSame('main', DB::table('trade_logs')->where('id', $null)->value('feed'));
    }

    public function test_an_unknown_feed_is_refused(): void
    {
        $this->ingest(['feed' => 'staging'])->assertStatus(422);
        $this->assertSame(0, DB::table('trade_logs')->count());
    }

    // ---- the Signal Log ---------------------------------------------------------

    public function test_the_signal_log_filters_by_feed_and_every_row_names_its_feed(): void
    {
        $this->log('main', '2026-10-15 08:00:00');
        $this->log('test', '2026-10-15 08:05:00', ['ticker' => 'RENDERUSDT']);
        $this->log('test', '2026-10-15 08:10:00', ['ticker' => 'RENDERUSDT', 'success' => false, 'filled' => 0, 'failed' => 1]);

        $all = $this->getJson('/api/admin/trade-logs', $this->headers())->assertOk()->json();
        $this->assertSame(['test', 'test', 'main'], array_column($all['logs'], 'feed'));
        $this->assertSame(3, $all['summary']['signals']);

        $test = $this->getJson('/api/admin/trade-logs?feed=test', $this->headers())->assertOk()->json();
        $this->assertSame(['test', 'test'], array_column($test['logs'], 'feed'));
        $this->assertSame(2, $test['summary']['signals']);
        $this->assertSame(1, $test['summary']['failed']);

        $main = $this->getJson('/api/admin/trade-logs?feed=main', $this->headers())->assertOk()->json();
        $this->assertSame(['main'], array_column($main['logs'], 'feed'));

        // Anything else is no filter at all.
        $this->assertCount(3, $this->getJson('/api/admin/trade-logs?feed=bogus', $this->headers())->json('logs'));

        $this->assertSame('test', $this->getJson('/api/admin/trade-logs/'.$test['logs'][0]['id'], $this->headers())
            ->assertOk()->json('log.feed'));
    }

    public function test_a_test_feed_retry_never_folds_under_a_main_signal(): void
    {
        $main = $this->log('main', '2026-10-15 08:00:00', ['action' => 'EXIT_LONG']);
        $this->log('test', '2026-10-15 08:01:00', ['action' => 'EXIT_LONG', 'category' => 'retry']);
        $this->log('main', '2026-10-15 08:02:00', ['action' => 'EXIT_LONG', 'category' => 'retry']);

        $logs = $this->getJson('/api/admin/trade-logs', $this->headers())->assertOk()->json('logs');

        $this->assertCount(2, $logs);
        $this->assertSame(['test', 'main'], array_column($logs, 'feed'));
        $this->assertSame('retry', $logs[0]['category']);           // stands alone
        $this->assertSame([], $logs[0]['retries']);
        $this->assertSame($main->id, $logs[1]['id']);
        $this->assertCount(1, $logs[1]['retries']);                  // only the main retry
    }

    public function test_a_feed_filter_leaves_out_closes_made_outside_the_bot(): void
    {
        $alex = $this->makeUser(['name' => 'Alex']);
        $this->makeAccount($alex, ['api_key' => 'alex-key']);
        DB::table('binance_pastpositions')->insert([
            'api_key' => 'alex-key', 'uni_id' => $alex, 'symbol' => 'LTCUSDT', 'position_side' => 'LONG',
            'position_amt' => 3, 'exit_price' => 101, 'realized_pnl' => 3, 'side' => 'SELL', 'order_id' => 1,
            'closed_at' => '2026-10-15 07:00:00', 'is_sandbox' => 0, 'close_source' => CloseSource::OUTSIDE,
            'created_at' => now(),
        ]);
        $this->log('main', '2026-10-15 08:00:00');

        $kinds = fn (string $q) => array_column($this->getJson("/api/admin/trade-logs{$q}", $this->headers())->json('logs'), 'kind');

        $this->assertSame(['signal', 'outside_close'], $kinds(''));
        $this->assertSame(['signal'], $kinds('?feed=main'));
        $this->assertSame([], $kinds('?feed=test'));
    }

    // ---- health -------------------------------------------------------------------

    public function test_health_ignores_the_test_feed_entirely(): void
    {
        // Only test traffic, and broken: the banner stays quiet and does not
        // claim a signal arrived.
        foreach ([30, 20, 10] as $m) {
            $this->log('test', now()->subMinutes($m)->toDateTimeString(), [
                'success' => false, 'filled' => 0, 'failed' => 1,
                'details' => [['account' => 'Tester', 'uni_id' => 'u-t', 'status' => 'failed', 'error' => 'no response', 'is_master' => false]],
            ]);
        }

        $health = $this->getJson('/api/admin/trade-logs/health', $this->headers())->assertOk()->json('health');
        $this->assertSame('quiet', $health['status']);
        $this->assertSame(0, $health['signals']);
        $this->assertSame(0, $health['problems']);
        $this->assertNull($health['last_signal_at']);

        // A healthy main signal is what the banner reports — test failures beside it change nothing.
        $this->log('main', now()->subMinutes(40)->toDateTimeString(), [
            'details' => [['account' => 'Master', 'uni_id' => 'u-m', 'status' => 'filled', 'is_master' => true]],
        ]);
        $health = $this->getJson('/api/admin/trade-logs/health', $this->headers())->assertOk()->json('health');
        $this->assertSame('healthy', $health['status']);
        $this->assertSame(1, $health['signals']);
        $this->assertSame(now()->subMinutes(40)->startOfSecond()->toIso8601String(), $health['last_signal_at']);
    }

    public function test_the_issues_view_follows_the_feed_filter(): void
    {
        $failed = [['account' => 'Tester', 'uni_id' => 'u-t', 'status' => 'failed', 'error' => 'no response', 'is_master' => false]];
        $this->log('test', now()->subHour()->toDateTimeString(), ['success' => false, 'filled' => 0, 'failed' => 1, 'details' => $failed]);

        $this->assertSame(1, $this->getJson('/api/admin/trade-logs/issues?feed=test', $this->headers())->json('signals'));
        $this->assertSame(0, $this->getJson('/api/admin/trade-logs/issues?feed=main', $this->headers())->json('signals'));
        $this->assertNotEmpty($this->getJson('/api/admin/trade-logs/issues?feed=test', $this->headers())->json('issues'));
    }
}
