<?php

namespace Tests\Feature;

use App\Mail\TradeExitStuckNotice;
use App\Mail\TradeFailureSummary;
use App\Models\TradeLog;
use App\Services\Admin\TradeFailureDigest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

/**
 * Failed-trade emails (TradeAlerts, 2026-11-12): an hourly summary sent only
 * when something needs a look, and an immediate email for a stuck close.
 */
class TradeAlertsTest extends EngineTestCase
{
    private string $uni;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.trade_alerts' => 'watcher@example.com, not-an-email']);
        Mail::fake();
        $this->uni = $this->makeUser(['name' => 'Lloyd Sample']);
        $this->makeAccount($this->uni, ['name' => 'Main']);
    }

    private function log(array $attrs): TradeLog
    {
        return TradeLog::create($attrs + [
            'exchange' => 'binance', 'feed' => 'main', 'success' => false, 'category' => 'signal',
            'target_count' => 1, 'filled' => 0, 'failed' => 0, 'skipped' => 0,
        ]);
    }

    private function digest(): array
    {
        return app(TradeFailureDigest::class)->build(
            CarbonImmutable::parse('2026-11-12 09:00:00', 'UTC'),
            CarbonImmutable::parse('2026-11-12 10:00:00', 'UTC'),
        );
    }

    public function test_a_failed_order_shrunk_entry_and_limit_skip_are_listed_with_names(): void
    {
        $this->log(['action' => 'SELL', 'ticker' => 'SANTOSUSDT', 'ts' => '2026-11-12 09:14:00', 'details' => [
            ['status' => 'filled', 'uni_id' => $this->uni, 'account' => 'Main', 'exchange' => 'binance', 'quantity' => 4250,
                'shrunk_by_limit' => true, 'sizing' => ['limit' => ['cap_usd' => 10000, 'leverage' => 10, 'requested_qty' => 10000]]],
            ['status' => 'skipped', 'reason' => 'exchange limit reached', 'uni_id' => 'u-2', 'account' => 'Whale', 'exchange' => 'binance'],
            ['status' => 'failed', 'error' => '-2019 Margin is insufficient.', 'uni_id' => 'u-3', 'account' => 'Small', 'exchange' => 'binance'],
            ['status' => 'skipped', 'reason' => 'api key blocked', 'uni_id' => 'u-4', 'account' => 'Blocked', 'exchange' => 'binance'],
            ['status' => 'skipped', 'reason' => 'maxed sizing', 'uni_id' => 'u-5', 'account' => 'Full', 'exchange' => 'binance'],
        ]]);

        $d = $this->digest();

        $this->assertSame(['failed' => 1, 'resolved' => 0, 'shrunk' => 1, 'limit' => 1, 'rejected' => 0, 'other_problems' => 1], $d['counts']);
        $items = $d['signals'][0]['items'];
        $this->assertSame(['shrunk', 'limit', 'failed'], array_column($items, 'kind'));
        $this->assertSame('Lloyd Sample', $items[0]['owner']);
        $this->assertSame('Not enough margin on the account', $items[2]['label']);
        $this->assertTrue(TradeFailureDigest::hasNews($d));
    }

    public function test_a_failure_a_retry_fixed_is_marked_resolved_and_alone_sends_nothing(): void
    {
        $this->log(['action' => 'EXIT_LONG', 'ticker' => 'LTCUSDT', 'ts' => '2026-11-12 09:40:00', 'details' => [
            ['status' => 'failed', 'error' => '408 -1007 Timeout', 'retryable' => true, 'uni_id' => $this->uni, 'account' => 'Main', 'exchange' => 'binance'],
        ]]);
        $this->log(['action' => 'EXIT_LONG', 'ticker' => 'LTCUSDT', 'category' => 'retry', 'ts' => '2026-11-12 10:01:00', 'details' => [
            ['status' => 'filled', 'uni_id' => $this->uni, 'account' => 'Main', 'exchange' => 'binance'],
        ]]);

        $d = $this->digest();
        $this->assertSame(1, $d['counts']['resolved']);
        $this->assertSame(0, $d['counts']['failed']);
        $this->assertTrue($d['signals'][0]['items'][0]['resolved']);
        $this->assertFalse(TradeFailureDigest::hasNews($d));
    }

    public function test_the_test_feed_and_normal_skips_are_left_out(): void
    {
        $this->log(['action' => 'BUY', 'ticker' => 'LTCUSDT', 'feed' => 'test', 'ts' => '2026-11-12 09:10:00', 'details' => [
            ['status' => 'failed', 'error' => '-2019 Margin is insufficient.', 'uni_id' => 'u-9', 'exchange' => 'binance'],
        ]]);
        $this->log(['action' => 'BUY', 'ticker' => 'LTCUSDT', 'ts' => '2026-11-12 09:20:00', 'details' => [
            ['status' => 'skipped', 'reason' => 'deposit below minimum', 'uni_id' => 'u-8', 'exchange' => 'binance'],
        ]]);

        $this->assertSame([], $this->digest()['signals']);
    }

    public function test_the_command_sends_once_per_hour_and_never_for_a_clean_hour(): void
    {
        $this->artisan('trade-alerts:digest', ['--hour' => '2026-11-12 09'])->assertSuccessful();
        Mail::assertNothingSent();

        $this->log(['action' => 'SELL', 'ticker' => 'SANTOSUSDT', 'ts' => '2026-11-12 09:14:00', 'details' => [
            ['status' => 'failed', 'error' => '-2027 Exceeded the maximum allowable position', 'uni_id' => $this->uni, 'account' => 'Main', 'exchange' => 'binance'],
        ]]);
        $this->artisan('trade-alerts:digest', ['--hour' => '2026-11-12 09'])->assertSuccessful();
        $this->artisan('trade-alerts:digest', ['--hour' => '2026-11-12 09'])->assertSuccessful();

        Mail::assertSent(TradeFailureSummary::class, 1);
        Mail::assertSent(TradeFailureSummary::class, fn ($m) => $m->hasTo('watcher@example.com') && ! $m->hasTo('not-an-email'));
    }

    public function test_no_recipients_means_no_email(): void
    {
        config(['mail.trade_alerts' => '']);
        $this->log(['action' => 'SELL', 'ticker' => 'SANTOSUSDT', 'ts' => '2026-11-12 09:14:00', 'details' => [
            ['status' => 'failed', 'error' => '-2019', 'uni_id' => $this->uni, 'exchange' => 'binance'],
        ]]);
        $this->artisan('trade-alerts:digest', ['--hour' => '2026-11-12 09'])->assertSuccessful();
        Mail::assertNothingSent();
    }

    public function test_a_stuck_close_is_emailed_at_once_with_names_resolved_from_ids(): void
    {
        $this->withHeaders($this->engineHeaders())->postJson('/api/engine/binance/alerts/exit-stuck', [
            'action' => 'EXIT_LONG', 'ticker' => 'LTCUSDT', 'why' => 'abandoned', 'attempts' => 5, 'total' => 1,
            'accounts' => [['uni_id' => $this->uni]],
        ])->assertOk()->assertJson(['success' => true, 'sent' => true]);

        Mail::assertSent(TradeExitStuckNotice::class, fn (TradeExitStuckNotice $m) => $m->hasTo('watcher@example.com')
            && $m->side === 'LONG' && $m->accounts[0]['owner'] === 'Lloyd Sample' && $m->accounts[0]['account'] === 'Main');
    }

    public function test_the_stuck_close_endpoint_takes_exits_only_and_needs_the_engine_secret(): void
    {
        $body = ['action' => 'SELL', 'ticker' => 'LTCUSDT', 'why' => 'rejected', 'accounts' => [['uni_id' => $this->uni]]];
        $this->withHeaders($this->engineHeaders())->postJson('/api/engine/binance/alerts/exit-stuck', $body)->assertStatus(422);
        $this->flushHeaders()->postJson('/api/engine/binance/alerts/exit-stuck', ['action' => 'EXIT_LONG'] + $body)->assertStatus(401);
        Mail::assertNothingSent();
    }
}
