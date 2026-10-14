<?php

namespace Tests\Feature;

use App\Models\TradeLog;
use App\Models\UserCredential;
use App\Services\Positions\CloseSource;
use Illuminate\Support\Facades\DB;

/**
 * Admin → Signal Log as one timeline (owner, 2026-10-14): every row says
 * LONG / SHORT and entry / exit, retries fold under the signal they re-run,
 * closes made OUTSIDE the bot (a customer closing on Binance) sit in the same
 * list as their own kind of row, and an exit shows each account's entry →
 * exit price and P&L.
 */
class AdminTradeLogTimelineTest extends EngineTestCase
{
    private string $admin;

    private string $alex;

    private int $order = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeUser(['type' => 'admin', 'name' => 'Ops Admin']);
        $this->alex = $this->makeUser(['name' => 'Alex']);
        $this->makeAccount($this->alex, ['api_key' => 'alex-key', 'name' => 'BOT ALEX G']);
    }

    private function headers(): array
    {
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.UserCredential::find($this->admin)->createToken('t')->plainTextToken];
    }

    private function log(string $action, string $ts, array $overrides = []): TradeLog
    {
        return TradeLog::create(array_merge([
            'exchange' => 'binance', 'action' => $action, 'ticker' => 'RENDERUSDT', 'success' => true,
            'price' => 2.1, 'category' => 'signal', 'target_count' => 1, 'filled' => 1, 'failed' => 0,
            'skipped' => 0, 'ts' => $ts,
            'details' => [['account' => 'BOT ALEX G', 'uni_id' => $this->alex, 'status' => 'filled', 'exchange' => 'binance']],
        ], $overrides));
    }

    private function close(string $closedAt, array $overrides = []): int
    {
        return DB::table('binance_pastpositions')->insertGetId(array_merge([
            'api_key' => 'alex-key', 'uni_id' => $this->alex, 'symbol' => 'RENDERUSDT',
            'position_side' => 'SHORT', 'position_amt' => 720, 'entry_price' => 2.055, 'exit_price' => 2.029,
            'realized_pnl' => 13.94, 'exchange_fee' => 1.42, 'side' => 'BUY', 'order_id' => ++$this->order,
            'closed_at' => $closedAt, 'is_sandbox' => 0, 'created_at' => now(),
        ], $overrides));
    }

    private function list(string $query = ''): array
    {
        return $this->getJson('/api/admin/trade-logs'.($query ? "?{$query}" : ''), $this->headers())
            ->assertOk()
            ->json();
    }

    public function test_every_row_names_its_side_and_whether_it_opens_or_closes(): void
    {
        $this->log('BUY', '2026-10-10 01:00:00');
        $this->log('SELL', '2026-10-10 02:00:00');
        $this->log('EXIT_LONG', '2026-10-10 03:00:00');
        $this->log('EXIT_SHORT', '2026-10-10 04:00:00');

        $rows = $this->list()['logs'];
        $this->assertSame(
            [['SHORT', 'exit'], ['LONG', 'exit'], ['SHORT', 'entry'], ['LONG', 'entry']],
            array_map(fn ($r) => [$r['side'], $r['type']], $rows),
        );

        $this->assertSame(['EXIT_SHORT', 'SELL'], array_column($this->list('side=short')['logs'], 'action'));
        $this->assertSame(['EXIT_SHORT', 'EXIT_LONG'], array_column($this->list('type=exit')['logs'], 'action'));
        $this->assertSame(['BUY'], array_column($this->list('side=long&type=entry')['logs'], 'action'));
    }

    public function test_retries_fold_under_the_signal_they_re_run(): void
    {
        $this->log('EXIT_LONG', '2026-10-14 03:15:00', ['exchange' => 'mexc', 'success' => false, 'filled' => 0, 'failed' => 1]);
        foreach (['03:16:00', '03:17:00', '03:18:00'] as $t) {
            $this->log('EXIT_LONG', "2026-10-14 {$t}", ['exchange' => 'mexc', 'category' => 'retry', 'success' => false, 'filled' => 0, 'failed' => 1]);
        }
        // Hours later, with no signal before it in reach: a row of its own.
        $this->log('EXIT_LONG', '2026-10-14 09:00:00', ['exchange' => 'mexc', 'category' => 'retry']);

        $body = $this->list();

        $this->assertCount(2, $body['logs']);
        $this->assertSame('retry', $body['logs'][0]['category']);
        $this->assertSame([], $body['logs'][0]['retries']);
        $this->assertCount(3, $body['logs'][1]['retries']);
        $this->assertSame('2026-10-14T03:16:00+00:00', $body['logs'][1]['retries'][0]['ts']);
        $this->assertSame(2, $body['summary']['signals']);
        $this->assertSame(1, $body['summary']['failed']); // the first run's failure, not four
    }

    public function test_a_close_made_outside_the_bot_sits_in_the_timeline_as_its_own_row(): void
    {
        $this->log('SELL', '2026-10-12 08:15:00');
        $this->close('2026-10-12 14:56:06', ['close_source' => CloseSource::OUTSIDE]);
        $this->log('SELL', '2026-10-13 05:30:00');

        $body = $this->list();

        $this->assertSame(['signal', 'outside_close', 'signal'], array_column($body['logs'], 'kind'));
        $outside = $body['logs'][1];
        $this->assertSame('BOT ALEX G', $outside['account']);
        $this->assertSame('Alex', $outside['user_name']);
        $this->assertSame('SHORT', $outside['side']);
        $this->assertSame('exit', $outside['type']);
        $this->assertEquals(2.029, $outside['exit_price']);
        $this->assertEquals(13.94, $outside['realized_pnl']);
        $this->assertSame(2, $body['summary']['signals']);
        $this->assertSame(1, $body['summary']['outside_closes']);
        $this->assertSame(3, $body['pagination']['total']);
    }

    public function test_the_filters_decide_whether_outside_closes_are_listed(): void
    {
        $this->log('SELL', '2026-10-12 08:15:00');
        $this->close('2026-10-12 14:56:06', ['close_source' => CloseSource::OUTSIDE]);
        $this->close('2026-10-12 15:00:00', ['close_source' => CloseSource::BOT]);

        $kinds = fn (string $q) => array_column($this->list($q)['logs'], 'kind');

        $this->assertSame(['outside_close', 'signal'], $kinds(''));
        $this->assertSame(['signal'], $kinds('source=signals'));
        $this->assertSame(['outside_close'], $kinds('source=outside'));
        $this->assertSame(['signal'], $kinds('type=entry'));
        $this->assertSame(['signal'], $kinds('outcome=filled'));
        $this->assertSame([], $kinds('side=long'));
        $this->assertSame(['outside_close'], $kinds('source=outside&account=alex'));
        $this->assertSame([], $kinds('source=outside&account=nobody'));
        $this->assertSame([], $kinds('source=outside&exchange=mexc'));
        $this->assertSame([], $kinds('source=outside&from=2026-10-13'));
    }

    public function test_an_exit_shows_each_accounts_closed_trade(): void
    {
        $this->log('EXIT_SHORT', '2026-10-13 07:30:16');
        $this->close('2026-10-13 07:30:40', ['position_amt' => 240, 'entry_price' => 2.212, 'exit_price' => 2.12, 'realized_pnl' => 21.56, 'exchange_fee' => 0.52]);
        // A close made outside the bot near the same time is never mistaken for it.
        $this->close('2026-10-13 07:29:00', ['close_source' => CloseSource::OUTSIDE, 'exit_price' => 9.99]);

        $close = $this->list('source=signals')['logs'][0]['details'][0]['close'];

        $this->assertEquals(240, $close['quantity']);
        $this->assertEquals(2.212, $close['entry_price']);
        $this->assertEquals(2.12, $close['exit_price']);
        $this->assertEquals(21.56, $close['realized_pnl']);
        $this->assertEquals(0.52, $close['exchange_fee']);
    }

    public function test_an_account_filter_also_looks_inside_the_retries(): void
    {
        $this->log('EXIT_LONG', '2026-10-14 03:15:00', ['success' => false, 'filled' => 0, 'failed' => 1,
            'details' => [['account' => 'Other', 'uni_id' => 'x', 'status' => 'failed', 'error' => 'no response']]]);
        $this->log('EXIT_LONG', '2026-10-14 03:16:00', ['category' => 'retry']);

        $rows = $this->list('account=alex&source=signals')['logs'];

        $this->assertCount(1, $rows);
        $this->assertCount(1, $rows[0]['retries']);
    }
}
