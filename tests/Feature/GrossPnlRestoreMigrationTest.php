<?php

namespace Tests\Feature;

use App\Services\Pnl\TradingFee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 2026_09_11_000001 puts closes before TradingFee::NET_SINCE back on the gross
 * basis and leaves everything from the cutoff on net. Exercised here against
 * seeded rows because the migration is the one write that decides what the
 * historical track record says.
 */
class GrossPnlRestoreMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_09_11_000001_restore_gross_pnl_before_net_cutoff.php';

    private function seedClose(int $orderId, string $closedAt, ?float $pnl, ?float $fee, string $apiKey = 'key-a'): void
    {
        DB::table('binance_pastpositions')->insert([
            'api_key' => $apiKey,
            'uni_id' => 'uni-'.$apiKey,
            'symbol' => 'LTCUSDT',
            'position_side' => 'LONG',
            'position_amt' => 10,
            'entry_price' => null,
            'exit_price' => 50,
            'realized_pnl' => $pnl,
            'exchange_fee' => $fee,
            'side' => 'SELL',
            'order_id' => $orderId,
            'closed_at' => $closedAt,
            'created_at' => now(),
        ]);
    }

    private function row(int $orderId): object
    {
        return DB::table('binance_pastpositions')->where('order_id', $orderId)->first();
    }

    public function test_up_restores_gross_before_the_cutoff_and_keeps_net_after(): void
    {
        $this->seedClose(1, '2026-08-15 10:00:00', 64.5, 0.5);   // netted history
        $this->seedClose(2, '2026-09-10 23:59:59', 24.5, 0.5);   // ingested net, still pre-cutoff
        $this->seedClose(3, '2026-09-11 00:00:00', 24.5, 0.5);   // the cutoff instant: stays net
        $this->seedClose(4, '2026-08-20 10:00:00', 30.0, null);  // already gross (fee unknown)
        $this->seedClose(5, '2026-08-20 11:00:00', null, null);  // awaiting backfill
        $this->seedClose(6, '2026-08-01 10:00:00', 100.0, null, 'SBXINV-scratch'); // scenario scratch

        (require base_path(self::MIGRATION))->up();

        $this->assertSame(65.0, (float) $this->row(1)->realized_pnl);
        $this->assertNull($this->row(1)->exchange_fee);

        $this->assertSame(25.0, (float) $this->row(2)->realized_pnl);
        $this->assertNull($this->row(2)->exchange_fee);

        $this->assertSame(24.5, (float) $this->row(3)->realized_pnl);
        $this->assertSame(0.5, (float) $this->row(3)->exchange_fee);

        $this->assertSame(30.0, (float) $this->row(4)->realized_pnl);
        $this->assertNull($this->row(5)->realized_pnl);
        $this->assertSame(100.0, (float) $this->row(6)->realized_pnl);

        // Running it again is a no-op: nothing pre-cutoff carries a fee any more.
        (require base_path(self::MIGRATION))->up();
        $this->assertSame(65.0, (float) $this->row(1)->realized_pnl);
    }

    public function test_down_re_nets_only_the_pre_cutoff_rows(): void
    {
        $this->seedClose(1, '2026-08-15 10:00:00', 65.0, null);  // gross history: 10 × 50 × 0.0005 × 2 = 0.5
        $this->seedClose(3, '2026-09-11 00:00:00', 24.5, 0.5);   // already net
        $this->seedClose(6, '2026-08-01 10:00:00', 100.0, null, 'SBXINV-scratch');

        (require base_path(self::MIGRATION))->down();

        $this->assertSame(64.5, (float) $this->row(1)->realized_pnl);
        $this->assertSame(0.5, (float) $this->row(1)->exchange_fee);
        $this->assertSame(24.5, (float) $this->row(3)->realized_pnl);
        $this->assertSame(100.0, (float) $this->row(6)->realized_pnl);
    }

    public function test_cutoff_is_a_utc_day_boundary(): void
    {
        $this->assertSame('2026-09-11 00:00:00', TradingFee::NET_SINCE);
        $this->assertFalse(TradingFee::netsAt('2026-09-10 23:59:59'));
        $this->assertTrue(TradingFee::netsAt('2026-09-11 00:00:00'));
    }
}
