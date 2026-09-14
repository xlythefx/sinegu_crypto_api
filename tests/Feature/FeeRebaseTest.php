<?php

namespace Tests\Feature;

use App\Services\Pnl\FeeRebase;
use App\Services\Pnl\TradingFee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * FeeRebase is the one writer that changes a stored realized_pnl after ingest.
 * These tests pin down WHICH rows it may touch and that every move keeps
 * `gross = realized_pnl + exchange_fee`.
 */
class FeeRebaseTest extends TestCase
{
    use RefreshDatabase;

    private function receipt(string $kind, int $ref, ?int $orderId, ?string $side, float $amount, int $time, float $qty = 10, float $pnl = 0.0, string $asset = 'USDT', string $apiKey = 'key-a', string $symbol = 'LTCUSDT'): void
    {
        DB::table('exchange_fee_receipts')->insert([
            'exchange' => 'binance',
            'api_key' => $apiKey,
            'uni_id' => 'uni-'.$apiKey,
            'symbol' => $symbol,
            'kind' => $kind,
            'ref' => $ref,
            'order_id' => $orderId,
            'side' => $side,
            'position_side' => $kind === 'fill' ? 'LONG' : null,
            'qty' => $kind === 'fill' ? $qty : null,
            'price' => $kind === 'fill' ? 50 : null,
            'realized_pnl' => $kind === 'fill' ? $pnl : null,
            'amount' => $amount,
            'asset' => $asset,
            'charged_at' => $time,
            'created_at' => now(),
        ]);
    }

    /** A matchable round trip: entry order 100 (0.25), funding 0.12, exit order 200 (0.26) = 0.63. */
    private function seedRoundTrip(string $apiKey = 'key-a', string $symbol = 'LTCUSDT'): void
    {
        $this->receipt('fill', 1, 100, 'BUY', 0.25, 1000, 10, 0.0, 'USDT', $apiKey, $symbol);
        $this->receipt('funding', 77, null, null, 0.12, 1500, 10, 0.0, 'USDT', $apiKey, $symbol);
        $this->receipt('fill', 2, 200, 'SELL', 0.26, 2000, 10, 25.0, 'USDT', $apiKey, $symbol);
    }

    private function seedClose(array $overrides = []): int
    {
        return DB::table('binance_pastpositions')->insertGetId(array_merge([
            'api_key' => 'key-a',
            'uni_id' => 'uni-key-a',
            'symbol' => 'LTCUSDT',
            'position_side' => 'LONG',
            'position_amt' => 10,
            'exit_price' => 50,
            'realized_pnl' => 24.5,
            'exchange_fee' => 0.5,
            'fee_source' => TradingFee::SOURCE_ESTIMATED,
            'side' => 'SELL',
            'order_id' => 200,
            'closed_at' => '2026-09-12 10:00:00',
            'is_sandbox' => 0,
            'created_at' => now(),
        ], $overrides));
    }

    private function row(int $id): object
    {
        return DB::table('binance_pastpositions')->find($id);
    }

    private function assertUntouched(int $id, float $pnl = 24.5, ?float $fee = 0.5, ?string $source = TradingFee::SOURCE_ESTIMATED): void
    {
        $row = $this->row($id);
        $this->assertSame($pnl, (float) $row->realized_pnl);
        $this->assertSame($fee, $row->exchange_fee === null ? null : (float) $row->exchange_fee);
        $this->assertSame($source, $row->fee_source);
    }

    public function test_it_rebases_an_estimated_row_and_keeps_the_gross_invariant(): void
    {
        $this->seedRoundTrip();
        $id = $this->seedClose();

        $report = (new FeeRebase)->pair('key-a', 'LTCUSDT');

        $this->assertSame(1, $report['rebased']);
        $row = $this->row($id);
        $this->assertSame(0.63, (float) $row->exchange_fee);
        $this->assertSame(24.37, (float) $row->realized_pnl);
        $this->assertSame(TradingFee::SOURCE_ACTUAL, $row->fee_source);
        // gross before == gross after
        $this->assertSame(25.0, round((float) $row->realized_pnl + (float) $row->exchange_fee, 8));

        $this->assertSame([[
            'id' => $id, 'order_id' => 200, 'closed_at' => '2026-09-12 10:00:00',
            'fee_before' => 0.5, 'fee_after' => 0.63, 'pnl_before' => 24.5, 'pnl_after' => 24.37,
            'source_before' => 'estimated',
        ]], $report['changes']);
    }

    public function test_a_second_run_is_unchanged(): void
    {
        $this->seedRoundTrip();
        $id = $this->seedClose();
        $rebase = new FeeRebase;

        $rebase->pair('key-a', 'LTCUSDT');
        $again = $rebase->pair('key-a', 'LTCUSDT');

        $this->assertSame(0, $again['rebased']);
        $this->assertSame(1, $again['unchanged']);
        $this->assertSame([], $again['changes']);
        $this->assertSame(24.37, (float) $this->row($id)->realized_pnl);
    }

    public function test_a_late_funding_receipt_moves_an_actual_row_again(): void
    {
        $this->seedRoundTrip();
        $id = $this->seedClose();
        $rebase = new FeeRebase;
        $rebase->pair('key-a', 'LTCUSDT');

        // Funding for the held window indexed after the close was attributed.
        $this->receipt('funding', 78, null, null, 0.10, 1800);
        $report = $rebase->pair('key-a', 'LTCUSDT');

        $this->assertSame(1, $report['rebased']);
        $row = $this->row($id);
        $this->assertSame(0.73, (float) $row->exchange_fee);
        $this->assertSame(24.27, (float) $row->realized_pnl);
        $this->assertSame(TradingFee::SOURCE_ACTUAL, $row->fee_source);
    }

    public function test_an_unconfirmable_close_leaves_estimated_and_reports_why(): void
    {
        $this->receipt('fill', 2, 200, 'SELL', 0.26, 2000, 10, 25.0); // exit only
        $id = $this->seedClose();

        $report = (new FeeRebase)->pair('key-a', 'LTCUSDT');

        $this->assertSame(0, $report['rebased']);
        $this->assertSame([['order_id' => 200, 'reason' => 'entry_missing']], $report['unconfirmed']);
        $this->assertUntouched($id);
    }

    public function test_an_actual_row_is_never_downgraded(): void
    {
        $this->receipt('fill', 2, 200, 'SELL', 0.26, 2000, 10, 25.0); // entry receipts vanished (they cannot, but)
        $id = $this->seedClose(['realized_pnl' => 24.37, 'exchange_fee' => 0.63, 'fee_source' => TradingFee::SOURCE_ACTUAL]);

        $report = (new FeeRebase)->pair('key-a', 'LTCUSDT');

        $this->assertSame(1, count($report['unconfirmed']));
        $this->assertUntouched($id, 24.37, 0.63, TradingFee::SOURCE_ACTUAL);
    }

    public function test_a_close_whose_receipts_have_not_arrived_is_skipped(): void
    {
        $this->seedRoundTrip();
        $id = $this->seedClose(['order_id' => 999]); // a different close, no receipts yet

        $report = (new FeeRebase)->pair('key-a', 'LTCUSDT');

        $this->assertSame(1, $report['skipped']);
        $this->assertUntouched($id);
    }

    public function test_pre_cutoff_rows_stay_gross_even_with_receipts(): void
    {
        $this->seedRoundTrip();
        $id = $this->seedClose(['closed_at' => '2026-09-10 23:59:59', 'realized_pnl' => 25.0, 'exchange_fee' => null, 'fee_source' => null]);

        $report = (new FeeRebase)->pair('key-a', 'LTCUSDT');

        $this->assertSame(0, $report['rows']);
        $this->assertUntouched($id, 25.0, null, null);
    }

    public function test_manual_sandbox_scratch_null_pnl_and_orderless_rows_are_never_touched(): void
    {
        $this->seedRoundTrip();
        $this->seedRoundTrip('SBXINV-scratch');
        $manual = $this->seedClose(['fee_source' => TradingFee::SOURCE_MANUAL, 'realized_pnl' => 30.0]);
        $sandbox = $this->seedClose(['is_sandbox' => 1, 'order_id' => 201]);
        $scratch = $this->seedClose(['api_key' => 'SBXINV-scratch', 'uni_id' => 'uni-SBXINV-scratch']);
        $pending = $this->seedClose(['order_id' => 202, 'realized_pnl' => null, 'exchange_fee' => null, 'fee_source' => null]);
        $orderless = $this->seedClose(['order_id' => 0]);

        (new FeeRebase)->pair('key-a', 'LTCUSDT');
        (new FeeRebase)->pair('SBXINV-scratch', 'LTCUSDT');

        $this->assertUntouched($manual, 30.0, 0.5, TradingFee::SOURCE_MANUAL);
        $this->assertUntouched($sandbox);
        $this->assertUntouched($scratch);
        $this->assertUntouched($pending, 0.0, null, null);
        $this->assertNull($this->row($pending)->realized_pnl);
        $this->assertUntouched($orderless);
    }

    public function test_an_admin_edit_landing_between_the_read_and_the_write_wins(): void
    {
        $this->seedRoundTrip();
        $id = $this->seedClose();

        // The race: FeeRebase reads the qualifying rows, then updates them with
        // a WHERE on the values it read. Land an admin edit right after that
        // SELECT completes (query events fire on completion), so the UPDATE's
        // optimistic WHERE no longer matches.
        $armed = true;
        DB::listen(function ($query) use ($id, &$armed) {
            if ($armed && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'binance_pastpositions')) {
                $armed = false;
                DB::table('binance_pastpositions')->where('id', $id)
                    ->update(['realized_pnl' => 30.0, 'fee_source' => TradingFee::SOURCE_MANUAL]);
            }
        });

        $report = (new FeeRebase)->pair('key-a', 'LTCUSDT');

        $this->assertSame(0, $report['rebased']);
        $this->assertSame(1, $report['skipped']);
        $this->assertUntouched($id, 30.0, 0.5, TradingFee::SOURCE_MANUAL);
    }

    public function test_dry_run_reports_without_writing(): void
    {
        $this->seedRoundTrip();
        $id = $this->seedClose();

        $report = (new FeeRebase)->pair('key-a', 'LTCUSDT', dryRun: true);

        $this->assertSame(1, $report['rebased']);
        $this->assertCount(1, $report['changes']);
        $this->assertSame(0.63, $report['changes'][0]['fee_after']);
        $this->assertUntouched($id);
    }
}
