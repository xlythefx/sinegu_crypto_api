<?php

namespace Tests\Feature;

use App\Models\UserCredential;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * GET /api/admin/insights/vs-master — Customers vs master (2026-11-23), and
 * the `side` the strategy page's Long / Short filter reads.
 */
class AdminVsMasterTest extends EngineTestCase
{
    private function headersFor(string $uniId): array
    {
        return ['Authorization' => 'Bearer '.UserCredential::find($uniId)->createToken('spa')->plainTextToken];
    }

    private function closeTrade(string $exchange, int $accountId, array $overrides = []): void
    {
        $account = DB::table("{$exchange}_accounts")->find($accountId);
        static $order = 800000;

        DB::table("{$exchange}_pastpositions")->insert(array_merge([
            'api_key' => $account->api_key,
            'uni_id' => $account->uni_id,
            'symbol' => 'LTCUSDT',
            'position_side' => 'LONG',
            'position_amt' => 10,
            'entry_price' => 50,
            'exit_price' => 52,
            'realized_pnl' => 20,
            'exchange_fee' => 0,
            'fee_source' => 'actual',
            'side' => 'SELL',
            'order_id' => ++$order,
            'closed_at' => '2026-11-03 10:00:00',
            'strategy' => 'ABCD-v1',
            'is_sandbox' => 0,
            'created_at' => now(),
        ], $overrides));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{admin: string, alice: string, bob: string, master: string} */
    private function scenario(): array
    {
        Carbon::setTestNow('2026-11-11 12:00:00');
        $admin = $this->makeUser(['type' => 'admin']);
        $since = ['created_at' => '2026-10-01 00:00:00'];

        // Master: +200 on 10,000 (2%), then −100 on 10,200 (−0.98%) → 1.02%.
        $master = $this->makeUser(['type' => 'master']);
        $m = $this->makeAccount($master, ['initial_deposit' => 10000, 'balance' => 10100] + $since);
        $this->closeTrade('binance', $m, ['realized_pnl' => 200, 'closed_at' => '2026-11-03 10:00:00']);
        $this->closeTrade('binance', $m, ['realized_pnl' => -100, 'symbol' => 'SANTOSUSDT',
            'position_side' => 'SHORT', 'side' => 'BUY', 'closed_at' => '2026-11-04 10:00:00']);

        // Alice took both, a minute or five later: +3%, then −0.97% → 2.03%.
        $alice = $this->makeUser(['name' => 'Alice Sample']);
        $a = $this->makeAccount($alice, ['initial_deposit' => 1000, 'balance' => 1020] + $since);
        $this->closeTrade('binance', $a, ['realized_pnl' => 30, 'closed_at' => '2026-11-03 10:05:00']);
        $this->closeTrade('binance', $a, ['realized_pnl' => -10, 'symbol' => 'SANTOSUSDT',
            'position_side' => 'SHORT', 'side' => 'BUY', 'closed_at' => '2026-11-04 10:01:00']);

        // Bob connected on the 4th: the 3rd's trade is not his to miss. His
        // SANTOS short closed two hours after the master's — not the same signal.
        $bob = $this->makeUser(['name' => 'Bob Sample']);
        $b = $this->makeAccount($bob, ['initial_deposit' => 2000, 'balance' => 1960, 'created_at' => '2026-11-04 00:00:00']);
        $this->closeTrade('binance', $b, ['realized_pnl' => -40, 'symbol' => 'SANTOSUSDT',
            'position_side' => 'SHORT', 'side' => 'BUY', 'closed_at' => '2026-11-04 12:00:00']);

        // Staff and play money are never ranked.
        $staff = $this->makeUser(['type' => 'admin', 'name' => 'Staff Sample']);
        $this->closeTrade('binance', $this->makeAccount($staff, $since), ['realized_pnl' => 999]);
        $demo = $this->makeAccount($alice, ['demo' => 1] + $since, 'mexc');
        $this->closeTrade('mexc', $demo, ['realized_pnl' => -900, 'symbol' => 'LTC_USDT']);

        return compact('admin', 'alice', 'bob', 'master');
    }

    public function test_ranks_customers_by_return_beside_the_master_with_followed_and_missed(): void
    {
        ['admin' => $admin] = $this->scenario();

        $res = $this->getJson('/api/admin/insights/vs-master', $this->headersFor($admin))->assertOk()->json();

        $this->assertSame(['2026-11-01', '2026-11-11'], [$res['from'], $res['to']]);
        $this->assertEquals(1.02, $res['master']['pct']);
        $this->assertSame(2, $res['master']['trades']);
        $this->assertSame(['Alice Sample', 'Bob Sample'], array_column($res['customers'], 'name'));

        [$first, $second] = $res['customers'];
        $this->assertSame(1, $first['rank']);
        $this->assertEquals(2.03, $first['pct']);
        $this->assertEquals(1.01, $first['gap']);
        $this->assertSame([2, 0, 2, 0], [$first['followed'], $first['missed'], $first['master_closes'], $first['extra']]);
        $this->assertSame([2, 0], [$first['days_ahead'], $first['days_behind']]);
        // Same trades, a slightly better result on each: the whole gap is "same".
        $this->assertEquals(['same' => 1.01, 'missed' => 0.0, 'extra' => 0.0, 'before' => 0.0], $first['gap_parts']);

        $this->assertEquals(-2.0, $second['pct']);
        $this->assertEquals(-3.02, $second['gap']);
        $this->assertSame([0, 1, 1, 1], [$second['followed'], $second['missed'], $second['master_closes'], $second['extra']]);
        $this->assertSame([0, 1], [$second['days_ahead'], $second['days_behind']]);
        // Missing the master's −0.98% helped him; his own close cost 2 pp, and
        // the master's +2% on the 3rd came before he joined. Parts add up to the gap.
        $this->assertEquals(['same' => 0.0, 'missed' => 0.98, 'extra' => -2.0, 'before' => -2.0], $second['gap_parts']);
        $this->assertEqualsWithDelta($second['gap'], array_sum($second['gap_parts']), 0.02);

        // A window and an exchange narrow it; no master there = no master row.
        $oct = $this->getJson('/api/admin/insights/vs-master?from=2026-11-03&to=2026-11-03', $this->headersFor($admin))->json();
        $this->assertEquals(2.0, $oct['master']['pct']);
        $this->assertSame(1, $oct['customers'][0]['trades']);
        $mexc = $this->getJson('/api/admin/insights/vs-master?exchange=mexc', $this->headersFor($admin))->assertOk()->json();
        $this->assertNull($mexc['master']);
        $this->assertSame([], $mexc['customers']);

        $this->getJson('/api/admin/insights/vs-master?from=2026-11-05&to=2026-11-01', $this->headersFor($admin))
            ->assertStatus(422);
    }

    public function test_head_to_head_gives_both_sides_day_by_day(): void
    {
        ['admin' => $admin, 'alice' => $alice, 'master' => $master] = $this->scenario();

        $h2h = $this->getJson("/api/admin/insights/vs-master/{$alice}", $this->headersFor($admin))->assertOk()->json();
        $this->assertSame('Alice Sample', $h2h['name']);
        $this->assertSame(['2026-11-03', '2026-11-04'], array_column($h2h['customer'], 'date'));
        $this->assertEquals([3.0, -0.97], array_column($h2h['customer'], 'pct'));
        $this->assertEquals([2.0, -0.98], array_column($h2h['master'], 'pct'));

        // The master is not a customer.
        $this->getJson("/api/admin/insights/vs-master/{$master}", $this->headersFor($admin))->assertNotFound();
    }

    public function test_the_weekly_summary_names_who_fell_behind_and_goes_to_the_engine(): void
    {
        $this->scenario();
        config([
            'services.engine.targets.local' => 'http://engine.test',
            'services.engine.admin_secret' => 'engine-admin-token',
        ]);
        Http::fake(['http://engine.test/*' => Http::response(['success' => true], 200)]);

        $this->artisan('insights:vs-master-weekly', ['--from' => '2026-11-01', '--to' => '2026-11-07'])->assertSuccessful();

        Http::assertSent(function (HttpRequest $r) {
            if (! str_ends_with($r->url(), '/admin/team-report')) {
                return false;
            }
            $lines = implode("\n", $r['lines']);

            return $r['title'] === 'Customers vs master — 1 Nov – 7 Nov 2026'
                && str_contains($lines, 'Master: +1.02% · 2 trades')
                && str_contains($lines, 'Ahead of or level with the master: 1 of 2')
                && str_contains($lines, '• Bob Sample −3.02 pp')
                && ! str_contains($lines, 'Alice')
                && str_contains($r['link'], '/admin/vs-master?date=custom&from=2026-11-01&to=2026-11-07&show=behind');
        });
    }

    public function test_collaborators_and_customers_cannot_read_it(): void
    {
        $collab = $this->makeUser(['type' => 'collaborator']);
        $this->getJson('/api/admin/insights/vs-master', $this->headersFor($collab))->assertForbidden();
        $user = $this->makeUser();
        $this->getJson('/api/admin/insights/vs-master', $this->headersFor($user))->assertForbidden();
    }

    public function test_strategy_trades_carry_their_side(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);
        $master = $this->makeUser(['type' => 'master']);
        $acc = $this->makeAccount($master);
        $this->closeTrade('binance', $acc, ['closed_at' => now()->subHour()]);
        // One-way account: BOTH + the BUY that closed it = a short.
        $this->closeTrade('binance', $acc, ['position_side' => 'BOTH', 'side' => 'BUY', 'closed_at' => now()->subHour()]);

        $sides = collect($this->getJson('/api/admin/strategies', $this->headersFor($admin))->assertOk()->json('trades'))
            ->pluck('side')->sort()->values()->all();
        $this->assertSame(['LONG', 'SHORT'], $sides);
    }
}
