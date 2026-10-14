<?php

namespace Tests\Feature;

use App\Models\UserCredential;
use Illuminate\Support\Facades\DB;

/**
 * Streak sizing travels from an ENTRY to its closed trade (2026-10-14).
 *
 * The engine knows at entry whether a streak step set the size; it stores
 * that on the entry's tag (`open-strategies`) and posts it with the close
 * (`past-positions/sync`). What must hold:
 *  - the tag keeps an earlier entry's strategy label when a later entry has
 *    none, and is not created for an entry with neither label nor streak;
 *  - the closed row stores all five fields, and a later sync without them
 *    (the poller) never erases them;
 *  - staff read every figure, a customer only kind / run / smaller-or-larger —
 *    never a size (base_size and the ladder stay staff-only).
 */
class EntryStreakTest extends EngineTestCase
{
    private const STREAK = [
        'streak_kind' => 'loss',
        'streak_run' => 3,
        'streak_step' => 2,
        'streak_size' => 40,
        'streak_base_size' => 60,
    ];

    private function headersFor(string $uniId): array
    {
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.UserCredential::find($uniId)->createToken('spa')->plainTextToken];
    }

    private function tag(string $apiKey): ?object
    {
        return DB::table('open_strategies')->where('api_key', $apiKey)->first();
    }

    private function closeRow(string $apiKey, array $extra = []): array
    {
        return array_merge([
            'api_key' => $apiKey,
            'symbol' => 'RENDERUSDT',
            'position_side' => 'SHORT',
            'position_amt' => 600,
            'entry_price' => 2.05,
            'exit_price' => 2.12,
            'realized_pnl' => -42,
            'side' => 'BUY',
            'order_id' => 5551,
            'closed_at' => '2026-10-13 15:30:00',
            'strategy' => 'VWMA-Reversion',
        ], $extra);
    }

    public function test_the_entry_tag_carries_the_streak_and_keeps_the_label(): void
    {
        $owner = $this->makeUser();
        $apiKey = DB::table('binance_accounts')->where('id', $this->makeAccount($owner))->value('api_key');
        $base = ['api_key' => $apiKey, 'symbol' => 'RENDERUSDT', 'position_side' => 'SHORT'];

        // Neither a label nor a streak: no tag is started.
        $this->postJson('/api/engine/binance/open-strategies', $base, $this->engineHeaders())->assertOk();
        $this->assertNull($this->tag($apiKey));

        $this->postJson('/api/engine/binance/open-strategies', $base + ['strategy' => 'VWMA-Reversion'] + self::STREAK, $this->engineHeaders())->assertOk();

        // A later entry of the same stack without a label keeps the label.
        $this->postJson('/api/engine/binance/open-strategies', $base + self::STREAK, $this->engineHeaders())->assertOk();
        $tag = $this->tag($apiKey);
        $this->assertSame('VWMA-Reversion', $tag->strategy);
        $this->assertSame('loss', $tag->streak_kind);
        $this->assertSame(2, (int) $tag->streak_step);

        $read = $this->getJson("/api/engine/binance/open-strategies?api_key={$apiKey}&symbol=RENDERUSDT", $this->engineHeaders())
            ->assertOk()->json('open_strategies.0');
        $this->assertSame('loss', $read['streak_kind']);
        $this->assertSame(3, (int) $read['streak_run']);
        $this->assertEqualsWithDelta(40, (float) $read['streak_size'], 1e-9);
        $this->assertEqualsWithDelta(60, (float) $read['streak_base_size'], 1e-9);

        // A normal-size entry clears the streak — the stack's step is the latest entry's.
        $this->postJson('/api/engine/binance/open-strategies', $base + ['strategy' => 'VWMA-Reversion'], $this->engineHeaders())->assertOk();
        $this->assertNull($this->tag($apiKey)->streak_kind);

        $this->postJson('/api/engine/binance/open-strategies', $base + ['streak_kind' => 'lose'], $this->engineHeaders())
            ->assertStatus(422);
    }

    public function test_the_closed_trade_keeps_the_streak_through_later_syncs(): void
    {
        $owner = $this->makeUser();
        $apiKey = DB::table('binance_accounts')->where('id', $this->makeAccount($owner))->value('api_key');

        $this->postJson('/api/engine/binance/past-positions/sync', ['rows' => [$this->closeRow($apiKey, self::STREAK)]], $this->engineHeaders())
            ->assertOk();

        // The poller's reconciliation row knows nothing about the streak.
        $this->postJson('/api/engine/binance/past-positions/sync', ['rows' => [$this->closeRow($apiKey, ['strategy' => null])]], $this->engineHeaders())
            ->assertOk();

        $row = DB::table('binance_pastpositions')->where('api_key', $apiKey)->first();
        $this->assertSame('loss', $row->streak_kind);
        $this->assertSame(3, (int) $row->streak_run);
        $this->assertSame(2, (int) $row->streak_step);
        $this->assertEqualsWithDelta(40, (float) $row->streak_size, 1e-9);
        $this->assertEqualsWithDelta(60, (float) $row->streak_base_size, 1e-9);
    }

    public function test_a_row_the_poller_wrote_first_takes_the_streak_from_the_close(): void
    {
        $owner = $this->makeUser();
        $apiKey = DB::table('binance_accounts')->where('id', $this->makeAccount($owner))->value('api_key');

        $this->postJson('/api/engine/binance/past-positions/sync', ['rows' => [$this->closeRow($apiKey, ['strategy' => null])]], $this->engineHeaders())
            ->assertOk();
        $this->assertNull(DB::table('binance_pastpositions')->where('api_key', $apiKey)->value('streak_kind'));

        $this->postJson('/api/engine/binance/past-positions/sync', ['rows' => [$this->closeRow($apiKey, self::STREAK)]], $this->engineHeaders())
            ->assertOk();

        $row = DB::table('binance_pastpositions')->where('api_key', $apiKey)->first();
        $this->assertSame('loss', $row->streak_kind);
        $this->assertEqualsWithDelta(40, (float) $row->streak_size, 1e-9);
    }

    public function test_staff_read_every_figure_and_a_customer_never_reads_a_size(): void
    {
        $owner = $this->makeUser();
        $admin = $this->makeUser(['type' => 'admin']);
        $apiKey = DB::table('binance_accounts')->where('id', $this->makeAccount($owner))->value('api_key');
        $this->postJson('/api/engine/binance/past-positions/sync', ['rows' => [
            $this->closeRow($apiKey, self::STREAK),
            $this->closeRow($apiKey, ['order_id' => 5552, 'symbol' => 'LTCUSDT']),
        ]], $this->engineHeaders())->assertOk();

        $mine = collect($this->getJson('/api/binance/past-positions', $this->headersFor($owner))->assertOk()->json('positions'));
        $render = $mine->firstWhere('symbol', 'RENDERUSDT');
        $this->assertSame(['kind' => 'loss', 'run' => 3, 'effect' => 'smaller'], $render['streak']);
        $this->assertNull($mine->firstWhere('symbol', 'LTCUSDT')['streak']);
        foreach (['streak_size', 'streak_base_size', 'streak_step', 'streak_kind'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $render);
        }

        $expected = ['kind' => 'loss', 'run' => 3, 'step' => 2, 'size' => 40.0, 'base_size' => 60.0, 'effect' => 'smaller'];
        $adminTrade = collect($this->getJson('/api/admin/positions', $this->headersFor($admin))->assertOk()->json('trades'))
            ->firstWhere('symbol', 'RENDERUSDT');
        // assertEquals: JSON carries 40.0 as 40.
        $this->assertEquals($expected, $adminTrade['streak']);

        $userTab = collect($this->getJson("/api/admin/users/{$owner}/positions", $this->headersFor($admin))->assertOk()->json('trades'))
            ->firstWhere('symbol', 'RENDERUSDT');
        $this->assertEquals($expected, $userTab['streak']);
    }

    public function test_a_larger_win_step_reads_larger(): void
    {
        $owner = $this->makeUser();
        $apiKey = DB::table('binance_accounts')->where('id', $this->makeAccount($owner))->value('api_key');
        $this->postJson('/api/engine/binance/past-positions/sync', ['rows' => [$this->closeRow($apiKey, [
            'streak_kind' => 'win', 'streak_run' => 2, 'streak_step' => 2, 'streak_size' => 80, 'streak_base_size' => 60,
        ])]], $this->engineHeaders())->assertOk();

        $trade = $this->getJson('/api/binance/past-positions', $this->headersFor($owner))->assertOk()->json('positions.0');
        $this->assertSame(['kind' => 'win', 'run' => 2, 'effect' => 'larger'], $trade['streak']);
    }

    public function test_the_summary_counts_demo_accounts(): void
    {
        $owner = $this->makeUser();
        $this->makeAccount($owner, ['demo' => 1]);

        $summary = $this->getJson('/api/dashboard/summary', $this->headersFor($owner))->assertOk()->json('summary');
        $this->assertSame(1, $summary['accounts']);
        $this->assertSame(1, $summary['demo_accounts']);

        $this->makeAccount($owner, [], 'bybit');
        $summary = $this->getJson('/api/dashboard/summary', $this->headersFor($owner))->assertOk()->json('summary');
        $this->assertSame(2, $summary['accounts']);
        $this->assertSame(1, $summary['demo_accounts']);
    }
}
