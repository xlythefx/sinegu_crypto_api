<?php

namespace Tests\Feature;

use App\Services\Pnl\TradingFee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 2026_09_14_000002 adds `fee_source` and stamps every post-cutoff row that
 * carries a fee as 'estimated' — which is exactly right at the moment it
 * runs, because TradingFee::applyTo was the only writer that ever netted a
 * row. Its down() must hand back the pre-feature figures, not merely drop the
 * column: a rollback returns the numbers that were on screen before.
 */
class FeeSourceMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_09_14_000002_add_fee_source_to_binance_pastpositions_table.php';

    private function seedClose(
        int $orderId,
        string $closedAt,
        ?float $pnl,
        ?float $fee,
        ?string $source = null,
        string $apiKey = 'key-a',
        bool $sandbox = false,
        ?float $exitPrice = 50,
    ): void {
        DB::table('binance_pastpositions')->insert([
            'api_key' => $apiKey,
            'uni_id' => 'uni-'.$apiKey,
            'symbol' => 'LTCUSDT',
            'position_side' => 'LONG',
            'position_amt' => 10,
            'entry_price' => null,
            'exit_price' => $exitPrice,
            'realized_pnl' => $pnl,
            'exchange_fee' => $fee,
            'fee_source' => $source,
            'side' => 'SELL',
            'order_id' => $orderId,
            'closed_at' => $closedAt,
            'is_sandbox' => $sandbox,
            'created_at' => now(),
        ]);
    }

    private function row(int $orderId): object
    {
        return DB::table('binance_pastpositions')->where('order_id', $orderId)->first();
    }

    public function test_up_stamps_only_post_cutoff_rows_that_carry_a_fee(): void
    {
        $this->seedClose(1, '2026-09-12 10:00:00', 24.5, 0.5);                 // netted by the ingest
        $this->seedClose(2, '2026-09-11 00:00:00', 24.5, 0.5);                 // the cutoff instant: in
        $this->seedClose(3, '2026-09-10 23:59:59', 25.0, null);                // gross history
        $this->seedClose(4, '2026-09-12 11:00:00', 25.0, null);                // fee unknown, still gross
        $this->seedClose(5, '2026-09-12 12:00:00', null, null);                // awaiting backfill
        $this->seedClose(6, '2026-09-12 13:00:00', 24.5, 0.5, null, 'key-a', true);        // sandbox
        $this->seedClose(7, '2026-09-12 14:00:00', 100.0, 0.5, null, 'SBXINV-scratch');    // scenario scratch

        // RefreshDatabase already ran the migration, so the column exists and
        // every seeded row is NULL. Take it away and run up() for real.
        $migration = require base_path(self::MIGRATION);
        $migration->down();
        $this->assertFalse(Schema::hasColumn('binance_pastpositions', 'fee_source'));
        $migration->up();

        $this->assertSame(TradingFee::SOURCE_ESTIMATED, $this->row(1)->fee_source);
        $this->assertSame(TradingFee::SOURCE_ESTIMATED, $this->row(2)->fee_source);
        $this->assertNull($this->row(3)->fee_source);
        $this->assertNull($this->row(4)->fee_source);
        $this->assertNull($this->row(5)->fee_source);
        $this->assertNull($this->row(6)->fee_source);
        $this->assertNull($this->row(7)->fee_source);

        // The stamp is a label; no figure moved.
        $this->assertSame(24.5, (float) $this->row(1)->realized_pnl);
        $this->assertSame(0.5, (float) $this->row(1)->exchange_fee);
    }

    public function test_down_puts_actual_rows_back_on_the_estimate(): void
    {
        // Ledger-confirmed: gross 25, actual fee 0.62 (commission + funding).
        $this->seedClose(1, '2026-09-12 10:00:00', 24.38, 0.62, TradingFee::SOURCE_ACTUAL);
        // Still estimated: untouched by down().
        $this->seedClose(2, '2026-09-12 11:00:00', 24.5, 0.5, TradingFee::SOURCE_ESTIMATED);
        // Admin-typed figure: untouched.
        $this->seedClose(3, '2026-09-12 12:00:00', 30.0, 0.5, TradingFee::SOURCE_MANUAL);
        // Actual but no exit price to re-estimate from: stays net of actual.
        $this->seedClose(4, '2026-09-12 13:00:00', 24.38, 0.62, TradingFee::SOURCE_ACTUAL, 'key-a', false, null);

        $migration = require base_path(self::MIGRATION);
        $migration->down();

        // 10 × 50 × 0.0005 × 2 = 0.5; gross 25 − 0.5 = 24.5 — the pre-feature figure.
        $this->assertSame(24.5, (float) $this->row(1)->realized_pnl);
        $this->assertSame(0.5, (float) $this->row(1)->exchange_fee);

        $this->assertSame(24.5, (float) $this->row(2)->realized_pnl);
        $this->assertSame(0.5, (float) $this->row(2)->exchange_fee);

        $this->assertSame(30.0, (float) $this->row(3)->realized_pnl);
        $this->assertSame(0.5, (float) $this->row(3)->exchange_fee);

        $this->assertSame(24.38, (float) $this->row(4)->realized_pnl);
        $this->assertSame(0.62, (float) $this->row(4)->exchange_fee);

        $this->assertFalse(Schema::hasColumn('binance_pastpositions', 'fee_source'));

        // Leave the schema as the suite expects it.
        $migration->up();
        $this->assertSame(TradingFee::SOURCE_ESTIMATED, $this->row(1)->fee_source);
    }

    public function test_source_constants_are_the_stored_words(): void
    {
        $this->assertSame('estimated', TradingFee::SOURCE_ESTIMATED);
        $this->assertSame('actual', TradingFee::SOURCE_ACTUAL);
        $this->assertSame('manual', TradingFee::SOURCE_MANUAL);
        $this->assertSame(25.0, TradingFee::gross(24.5, 0.5));
        $this->assertNull(TradingFee::gross(24.5, null));
        $this->assertNull(TradingFee::gross(null, 0.5));
    }
}
