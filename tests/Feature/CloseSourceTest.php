<?php

namespace Tests\Feature;

use App\Services\Positions\CloseSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "Closed by the bot" vs "closed outside the bot" (owner, 2026-10-14). On 12
 * Oct a customer closed their RENDER short by hand on Binance; the next
 * signal then opened a fresh entry on that one account. Such closes are
 * marked, shown on the admin screens, and left out of streak sizing.
 */
class CloseSourceTest extends EngineTestCase
{
    private int $order = 0;

    private string $uni;

    private string $key = 'alex-key';

    protected function setUp(): void
    {
        parent::setUp();
        $this->uni = $this->makeUser(['name' => 'Alex']);
        $this->makeAccount($this->uni, ['api_key' => $this->key, 'name' => 'BOT ALEX G']);
        // The signal log has existed for a while.
        $this->exitLog('LTCUSDT', now()->subDays(10), []);
    }

    private function close(Carbon $at, array $overrides = []): int
    {
        return DB::table('binance_pastpositions')->insertGetId(array_merge([
            'api_key' => $this->key, 'uni_id' => $this->uni, 'symbol' => 'RENDERUSDT',
            'position_side' => 'SHORT', 'position_amt' => 720, 'realized_pnl' => 13.94,
            'side' => 'BUY', 'order_id' => ++$this->order, 'closed_at' => $at,
            'is_sandbox' => 0, 'created_at' => now(),
        ], $overrides));
    }

    /** @param  list<array<string, mixed>>  $details */
    private function exitLog(string $ticker, Carbon $ts, array $details, string $action = 'EXIT_SHORT'): void
    {
        DB::table('trade_logs')->insert([
            'exchange' => 'binance', 'action' => $action, 'ticker' => $ticker, 'category' => 'signal',
            'details' => json_encode($details), 'ts' => $ts, 'created_at' => $ts,
        ]);
    }

    private function filled(?string $uni = null): array
    {
        return ['uni_id' => $uni ?? $this->uni, 'status' => 'filled', 'exchange' => 'binance', 'account' => 'BOT ALEX G'];
    }

    private function sourceOf(int $id): ?string
    {
        return DB::table('binance_pastpositions')->where('id', $id)->value('close_source');
    }

    private function classify(): void
    {
        $this->artisan('positions:classify-closes')->assertSuccessful();
    }

    public function test_a_close_the_bot_exit_filled_is_by_the_bot(): void
    {
        $at = now()->subHour();
        $id = $this->close($at);
        $this->exitLog('RENDERUSDT', $at->copy()->addSeconds(20), [$this->filled()]);

        $this->classify();

        $this->assertSame(CloseSource::BOT, $this->sourceOf($id));
    }

    public function test_a_close_written_by_the_engines_close_path_is_by_the_bot(): void
    {
        $id = $this->close(now()->subHour(), ['increments_closed' => 3]);

        $this->classify();

        $this->assertSame(CloseSource::BOT, $this->sourceOf($id));
    }

    public function test_a_close_with_no_bot_exit_is_outside(): void
    {
        $id = $this->close(now()->subHour());

        $this->classify();

        $this->assertSame(CloseSource::OUTSIDE, $this->sourceOf($id));
    }

    public function test_an_exit_that_filled_another_account_does_not_count(): void
    {
        $at = now()->subHour();
        $id = $this->close($at);
        $other = $this->makeUser();
        $this->exitLog('RENDERUSDT', $at->copy()->addSeconds(20), [
            $this->filled($other),
            ['uni_id' => $this->uni, 'status' => 'skipped', 'reason' => 'nothing to close'],
        ]);

        $this->classify();

        $this->assertSame(CloseSource::OUTSIDE, $this->sourceOf($id));
    }

    public function test_an_exit_far_from_the_close_does_not_count(): void
    {
        $at = now()->subHours(5);
        $id = $this->close($at);
        $this->exitLog('RENDERUSDT', $at->copy()->addHours(2), [$this->filled()]);

        $this->classify();

        $this->assertSame(CloseSource::OUTSIDE, $this->sourceOf($id));
    }

    public function test_a_close_too_recent_for_its_log_to_have_landed_waits(): void
    {
        $id = $this->close(now()->subMinute());

        $this->classify();

        $this->assertNull($this->sourceOf($id));
    }

    public function test_a_close_from_before_the_signal_log_existed_is_never_judged(): void
    {
        $id = $this->close(now()->subDays(20));

        $this->artisan('positions:classify-closes', ['--days' => 30])->assertSuccessful();

        $this->assertNull($this->sourceOf($id));
    }

    public function test_sandbox_and_scenario_rows_are_never_judged(): void
    {
        $sandbox = $this->close(now()->subHour(), ['is_sandbox' => 1]);
        $scenario = $this->close(now()->subHour(), ['api_key' => 'SBXINV-1']);

        $this->classify();

        $this->assertNull($this->sourceOf($sandbox));
        $this->assertNull($this->sourceOf($scenario));
    }

    public function test_a_judged_close_is_never_judged_again(): void
    {
        $at = now()->subHour();
        $id = $this->close($at, ['close_source' => CloseSource::OUTSIDE]);
        $this->exitLog('RENDERUSDT', $at->copy()->addSeconds(20), [$this->filled()]);

        $this->classify();

        $this->assertSame(CloseSource::OUTSIDE, $this->sourceOf($id));
    }

    public function test_an_outside_close_does_not_move_the_streak_run(): void
    {
        // Oldest first: a bot loss, then a customer's own winning close.
        $this->close(now()->subHours(3), ['realized_pnl' => -20, 'close_source' => CloseSource::BOT]);
        $this->close(now()->subHour(), ['realized_pnl' => 13.94, 'close_source' => CloseSource::OUTSIDE]);

        $this->getJson('/api/engine/binance/streaks?symbol=RENDERUSDT&depth=5', $this->engineHeaders())
            ->assertOk()
            ->assertJsonPath("streaks.{$this->key}", -1);
    }

    public function test_the_admin_screens_carry_the_mark(): void
    {
        $this->close(now()->subHour(), ['close_source' => CloseSource::OUTSIDE]);
        $admin = $this->makeUser(['type' => 'admin']);
        $user = \App\Models\UserCredential::where('uni_id', $admin)->firstOrFail();
        $headers = ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken];

        $this->withHeaders($headers)->getJson('/api/admin/positions')
            ->assertOk()->assertJsonPath('trades.0.close_source', 'outside');
        $this->app['auth']->forgetGuards();
        $this->withHeaders($headers)->getJson("/api/admin/users/{$this->uni}/positions")
            ->assertOk()->assertJsonPath('trades.0.close_source', 'outside');
        $this->app['auth']->forgetGuards();
        $this->withHeaders($headers)->getJson('/api/admin/insights/overview')
            ->assertOk()
            ->assertJsonPath('attention.outside_closes.count', 1)
            ->assertJsonPath('attention.outside_closes.latest.0.account_name', 'BOT ALEX G')
            ->assertJsonPath('attention.outside_closes.latest.0.symbol', 'RENDERUSDT');
    }
}
