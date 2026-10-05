<?php

namespace Tests\Feature;

use App\Models\UserCredential;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * The saved daily percentages (`daily_returns`) behind the Date Range card's
 * Period Return — which ADDS them, so a +1% day and a +4% day read +5%
 * (owner's team, 2026-10-05; until then the card compounded them, 5.04%).
 *
 * The promise these tests pin: a saved day IS the P&L calendar's day, and the
 * row follows the trades beneath it — rewritten when they change, dropped
 * when they are gone. Expected figures are derived by hand below.
 */
class DailyReturnsTest extends EngineTestCase
{
    private string $uniId;

    private const KEY = 'daily-returns-key';

    protected function setUp(): void
    {
        parent::setUp();

        $this->uniId = $this->makeUser();
        $this->makeAccount($this->uniId, [
            'api_key' => self::KEY,
            'balance' => 10504,
            'initial_deposit' => 0,
        ]);
        Sanctum::actingAs(UserCredential::query()->find($this->uniId));
    }

    private function trade(string $closedAt, float $pnl): int
    {
        return DB::table('binance_pastpositions')->insertGetId([
            'uni_id' => $this->uniId,
            'api_key' => self::KEY,
            'symbol' => 'LTCUSDT',
            'position_side' => 'LONG',
            'position_amt' => 1,
            'entry_price' => 100,
            'exit_price' => 110,
            'realized_pnl' => $pnl,
            // A recorded fee of 0: gross == net, no estimated commission.
            'exchange_fee' => 0.0,
            'side' => 'BUY',
            'strategy' => 'ABCD-v1',
            'closed_at' => $closedAt,
        ]);
    }

    /**
     * $10,000 in on 1 Aug. 10 Aug: +100 on 10,000 = +1.00%.
     * 11 Aug: +404 on 10,100 = +4.00%.
     */
    private function seedTwoDays(): void
    {
        DB::table('binance_transactions')->insert([
            'uni_id' => $this->uniId,
            'api_key' => self::KEY,
            'type' => 'DEPOSIT',
            'amount' => 10000,
            'currency' => 'USDT',
            'created_at' => '2026-08-01 09:00:00',
        ]);
        $this->trade('2026-08-10 12:00:00', 100);
        $this->trade('2026-08-11 12:00:00', 404);
    }

    private function returns(): array
    {
        return $this->getJson('/api/analytics')->assertOk()->json('analytics.daily_returns');
    }

    public function test_each_day_is_saved_as_its_percentage_of_the_starting_balance(): void
    {
        $this->seedTwoDays();

        $returns = $this->returns();

        $this->assertEquals(1.00, $returns['2026-08-10']['pct']);
        $this->assertEquals(4.00, $returns['2026-08-11']['pct']);
        $this->assertEquals(10100, $returns['2026-08-11']['start_balance']);
        // Added, as the team asked — not compounded (5.04).
        $this->assertEquals(5.00, $returns['2026-08-10']['pct'] + $returns['2026-08-11']['pct']);

        // And the response is what the table holds, not a parallel figure.
        $this->assertSame(2, DB::table('daily_returns')
            ->where('uni_id', $this->uniId)->where('scope', 'all')->count());
        $this->assertEquals(4.00, DB::table('daily_returns')
            ->where('uni_id', $this->uniId)->where('scope', 'all')
            ->where('day', '2026-08-11')->value('pct'));
    }

    public function test_a_saved_day_is_the_calendar_cell(): void
    {
        $this->seedTwoDays();

        $returns = $this->returns();
        $calendar = $this->getJson('/api/dashboard/daily-pnl')->assertOk()->json('days');

        foreach (['2026-08-10', '2026-08-11'] as $day) {
            $this->assertEquals($calendar[$day]['pct'], $returns[$day]['pct']);
            $this->assertEquals($calendar[$day]['pct_gross'], $returns[$day]['pct_gross']);
            $this->assertEquals($calendar[$day]['start_balance'], $returns[$day]['start_balance']);
            $this->assertEquals($calendar[$day]['total'], $returns[$day]['pnl']);
        }
    }

    public function test_a_corrected_trade_rewrites_its_day_and_a_deleted_one_drops_it(): void
    {
        $this->seedTwoDays();
        $this->returns();

        // An admin corrects 10 Aug to +200 (2.00% of 10,000) and deletes 11 Aug.
        DB::table('binance_pastpositions')->where('closed_at', '2026-08-10 12:00:00')
            ->update(['realized_pnl' => 200]);
        DB::table('binance_pastpositions')->where('closed_at', '2026-08-11 12:00:00')->delete();

        $returns = $this->returns();

        $this->assertEquals(2.00, $returns['2026-08-10']['pct']);
        $this->assertArrayNotHasKey('2026-08-11', $returns);
        $this->assertSame(0, DB::table('daily_returns')->where('day', '2026-08-11')->count());
    }

    public function test_the_filters_never_change_a_saved_day(): void
    {
        $this->seedTwoDays();

        $all = $this->returns();
        $narrowed = $this->getJson('/api/analytics?'.http_build_query([
            'from' => '2026-08-11',
            'symbols' => ['BTCUSDT'],
            'symbol_mode' => 'include',
        ]))->assertOk()->json('analytics.daily_returns');

        // A saved day is the calendar's day whatever the page is narrowed to.
        $this->assertEquals($all, $narrowed);
    }

    public function test_a_day_with_no_capital_on_record_has_no_percentage(): void
    {
        // No deposit and initial_deposit 0: nothing to divide by.
        $this->trade('2026-08-10 12:00:00', 100);

        $day = $this->returns()['2026-08-10'];

        // The walk records the zero (the calendar shows it too); only the
        // percentage is withheld, never a divide-by-zero.
        $this->assertNull($day['pct']);
        $this->assertEquals(0, $day['start_balance']);
        $this->assertEquals(100, $day['pnl']);
    }

    public function test_the_scheduled_pass_saves_users_who_never_opened_the_page(): void
    {
        $this->seedTwoDays();

        $this->artisan('pnl:daily-returns')->assertSuccessful();

        $rows = DB::table('daily_returns')->where('uni_id', $this->uniId);
        // 'all' plus the Binance scope; other venues have no account here.
        $this->assertEquals(4.00, (clone $rows)->where('scope', 'all')->where('day', '2026-08-11')->value('pct'));
        $this->assertEquals(4.00, (clone $rows)->where('scope', 'binance')->where('day', '2026-08-11')->value('pct'));
        $this->assertSame(0, (clone $rows)->where('scope', 'mexc')->count());
    }
}
