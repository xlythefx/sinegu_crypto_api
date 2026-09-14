<?php

namespace Tests\Feature;

use App\Services\Pnl\TradingFee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** `fees:reconcile` — the daily safety net behind the ingest-time rebase. */
class FeesReconcileCommandTest extends TestCase
{
    use RefreshDatabase;

    private function roundTrip(string $apiKey, string $symbol): void
    {
        $rows = [
            ['kind' => 'fill', 'ref' => 1, 'order_id' => 100, 'side' => 'BUY', 'position_side' => 'LONG', 'qty' => 10, 'price' => 50, 'realized_pnl' => 0, 'amount' => 0.25, 'charged_at' => 1000],
            ['kind' => 'funding', 'ref' => 77, 'order_id' => null, 'side' => null, 'position_side' => null, 'qty' => null, 'price' => null, 'realized_pnl' => null, 'amount' => 0.12, 'charged_at' => 1500],
            ['kind' => 'fill', 'ref' => 2, 'order_id' => 200, 'side' => 'SELL', 'position_side' => 'LONG', 'qty' => 10, 'price' => 50, 'realized_pnl' => 25, 'amount' => 0.26, 'charged_at' => 2000],
        ];
        foreach ($rows as $r) {
            DB::table('exchange_fee_receipts')->insert($r + [
                'exchange' => 'binance', 'api_key' => $apiKey, 'uni_id' => 'uni-'.$apiKey,
                'symbol' => $symbol, 'asset' => 'USDT', 'created_at' => now(),
            ]);
        }
    }

    private function close(string $apiKey, string $symbol, int $orderId = 200): int
    {
        return DB::table('binance_pastpositions')->insertGetId([
            'api_key' => $apiKey, 'uni_id' => 'uni-'.$apiKey, 'symbol' => $symbol,
            'position_side' => 'LONG', 'position_amt' => 10, 'exit_price' => 50,
            'realized_pnl' => 24.5, 'exchange_fee' => 0.5, 'fee_source' => TradingFee::SOURCE_ESTIMATED,
            'side' => 'SELL', 'order_id' => $orderId, 'closed_at' => '2026-09-12 10:00:00',
            'is_sandbox' => 0, 'created_at' => now(),
        ]);
    }

    private function pnl(int $id): float
    {
        return (float) DB::table('binance_pastpositions')->find($id)->realized_pnl;
    }

    public function test_dry_run_prints_the_change_and_writes_nothing(): void
    {
        $this->roundTrip('key-a', 'LTCUSDT');
        $id = $this->close('key-a', 'LTCUSDT');

        $this->artisan('fees:reconcile', ['--dry-run' => true])
            ->expectsOutputToContain('would-rebase=1')
            ->expectsOutputToContain('0.50000000 → 0.63000000')
            ->assertSuccessful();

        $this->assertSame(24.5, $this->pnl($id));
    }

    public function test_a_real_run_rebases_and_lists_unconfirmed_rows_with_a_reason(): void
    {
        $this->roundTrip('key-a', 'LTCUSDT');
        $rebasable = $this->close('key-a', 'LTCUSDT');
        // ETH has only an exit receipt: unconfirmable, listed with its reason.
        DB::table('exchange_fee_receipts')->insert([
            'exchange' => 'binance', 'api_key' => 'key-a', 'uni_id' => 'uni-key-a', 'symbol' => 'ETHUSDT',
            'kind' => 'fill', 'ref' => 9, 'order_id' => 300, 'side' => 'SELL', 'position_side' => 'LONG',
            'qty' => 10, 'price' => 50, 'realized_pnl' => 25, 'amount' => 0.26, 'asset' => 'USDT',
            'charged_at' => 2000, 'created_at' => now(),
        ]);
        $stuck = $this->close('key-a', 'ETHUSDT', 300);

        $this->artisan('fees:reconcile')
            ->expectsOutputToContain('entry_missing')
            ->expectsOutputToContain('2 pair(s): rows=2 rebased=1 unchanged=0 unconfirmed=1')
            ->assertSuccessful();

        $this->assertSame(24.37, $this->pnl($rebasable));
        $this->assertSame(24.5, $this->pnl($stuck));
    }

    public function test_api_key_option_restricts_the_run(): void
    {
        $this->roundTrip('key-a', 'LTCUSDT');
        $this->roundTrip('key-b', 'LTCUSDT');
        $a = $this->close('key-a', 'LTCUSDT');
        $b = $this->close('key-b', 'LTCUSDT');

        $this->artisan('fees:reconcile', ['--api-key' => 'key-b'])->assertSuccessful();

        $this->assertSame(24.5, $this->pnl($a));
        $this->assertSame(24.37, $this->pnl($b));
    }

    public function test_pairs_without_receipts_are_not_visited(): void
    {
        $this->close('key-a', 'LTCUSDT');

        $this->artisan('fees:reconcile')
            ->expectsOutputToContain('No closed trades with receipts to reconcile.')
            ->assertSuccessful();
    }
}
