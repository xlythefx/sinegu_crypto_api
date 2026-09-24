<?php

namespace Tests\Feature;

use App\Services\Pnl\FeeRebase;
use App\Services\Pnl\TradingFee;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/engine/{exchange}/fees — the fee-receipts ingest.
 *
 * Append-only and idempotent on (exchange, api_key, symbol, kind, ref); the
 * exchange comes from the route; and the attribution it triggers can fail
 * without failing the request, because a 500 would make the engine re-send
 * the same rows into the same failure every tick.
 */
class EngineFeesTest extends EngineTestCase
{
    private function fillRow(int $ref, int $orderId, string $side, float $qty, float $commission, int $time, float $pnl = 0.0, array $overrides = []): array
    {
        return array_merge([
            'api_key' => 'key-a',
            'uni_id' => 'uni-key-a',
            'symbol' => 'ltcusdt',
            'kind' => 'fill',
            'ref' => $ref,
            'order_id' => $orderId,
            'side' => $side,
            'position_side' => 'LONG',
            'qty' => $qty,
            'price' => 50,
            'realized_pnl' => $pnl,
            'amount' => $commission,
            'asset' => 'usdt',
            'charged_at' => $time,
        ], $overrides);
    }

    private function seedClose(int $orderId, string $closedAt, float $pnl, float $fee, string $source = TradingFee::SOURCE_ESTIMATED, string $apiKey = 'key-a'): int
    {
        return DB::table('binance_pastpositions')->insertGetId([
            'api_key' => $apiKey,
            'uni_id' => 'uni-'.$apiKey,
            'symbol' => 'LTCUSDT',
            'position_side' => 'LONG',
            'position_amt' => 10,
            'exit_price' => 50,
            'realized_pnl' => $pnl,
            'exchange_fee' => $fee,
            'fee_source' => $source,
            'side' => 'SELL',
            'order_id' => $orderId,
            'closed_at' => $closedAt,
            'created_at' => now(),
        ]);
    }

    public function test_receipts_are_idempotent_on_their_unique_key(): void
    {
        $rows = ['rows' => [
            $this->fillRow(1, 100, 'BUY', 10, 0.25, 1000),
            ['api_key' => 'key-a', 'uni_id' => 'uni-key-a', 'symbol' => 'LTCUSDT', 'kind' => 'funding',
                'ref' => 77, 'amount' => 0.3, 'asset' => 'USDT', 'charged_at' => 1500],
        ]];

        $this->postJson('/api/engine/binance/fees', $rows, $this->engineHeaders())
            ->assertOk()->assertJson(['success' => true, 'inserted' => 2, 'pairs' => 1]);
        $this->postJson('/api/engine/binance/fees', $rows, $this->engineHeaders())
            ->assertOk()->assertJson(['inserted' => 0]);

        $this->assertSame(2, DB::table('exchange_fee_receipts')->count());

        $fill = DB::table('exchange_fee_receipts')->where('kind', 'fill')->first();
        $this->assertSame('binance', $fill->exchange);
        $this->assertSame('LTCUSDT', $fill->symbol);   // upper-cased
        $this->assertSame('USDT', $fill->asset);       // upper-cased
        $this->assertSame(100, (int) $fill->order_id);

        $funding = DB::table('exchange_fee_receipts')->where('kind', 'funding')->first();
        $this->assertNull($funding->order_id);
        $this->assertNull($funding->qty);
        $this->assertSame(0.3, (float) $funding->amount);
    }

    public function test_the_same_fill_id_on_two_symbols_is_two_receipts(): void
    {
        // Binance's userTrades id is per symbol, so 1 on LTC and 1 on ETH are
        // different fills and must both land.
        $this->postJson('/api/engine/binance/fees', ['rows' => [
            $this->fillRow(1, 100, 'BUY', 10, 0.25, 1000),
            $this->fillRow(1, 200, 'BUY', 2, 0.30, 1000, 0.0, ['symbol' => 'ETHUSDT']),
        ]], $this->engineHeaders())->assertOk()->assertJson(['inserted' => 2, 'pairs' => 2]);
    }

    public function test_validation_rejects_an_unknown_kind_and_a_missing_amount(): void
    {
        $this->postJson('/api/engine/binance/fees', ['rows' => [
            $this->fillRow(1, 100, 'BUY', 10, 0.25, 1000, 0.0, ['kind' => 'rebate']),
        ]], $this->engineHeaders())->assertStatus(422);

        $row = $this->fillRow(1, 100, 'BUY', 10, 0.25, 1000);
        unset($row['amount']);
        $this->postJson('/api/engine/binance/fees', ['rows' => [$row]], $this->engineHeaders())
            ->assertStatus(422);

        $this->assertSame(0, DB::table('exchange_fee_receipts')->count());
    }

    /**
     * Bybit identifies a fill by `execId`, a UUID — the reason `ref` is a
     * string column. A numeric ref from Binance must still work unchanged, and
     * the two must not collide with each other.
     */
    public function test_a_uuid_ref_is_stored_verbatim_beside_numeric_ones(): void
    {
        $uuid = '8c48b6ba-a6a5-5ba9-a3f3-0f9e4f0e8f1a';

        $this->postJson('/api/engine/bybit/fees', ['rows' => [
            $this->fillRow(1, 100, 'BUY', 10, 0.25, 1000, 0.0, ['ref' => $uuid]),
        ]], $this->engineHeaders())->assertOk();

        $this->postJson('/api/engine/binance/fees', ['rows' => [
            $this->fillRow(1, 100, 'BUY', 10, 0.25, 1000),
        ]], $this->engineHeaders())->assertOk();

        $this->assertSame($uuid, DB::table('exchange_fee_receipts')->where('exchange', 'bybit')->value('ref'));
        $this->assertSame('1', (string) DB::table('exchange_fee_receipts')->where('exchange', 'binance')->value('ref'));

        // Re-sending the same UUID is a no-op — the unique key still holds.
        $this->postJson('/api/engine/bybit/fees', ['rows' => [
            $this->fillRow(1, 100, 'BUY', 10, 0.25, 1000, 0.0, ['ref' => $uuid]),
        ]], $this->engineHeaders())->assertOk();

        $this->assertSame(2, DB::table('exchange_fee_receipts')->count());
    }

    /** A ref longer than the column is refused, not silently truncated. */
    public function test_an_overlong_ref_is_refused(): void
    {
        $this->postJson('/api/engine/bybit/fees', ['rows' => [
            $this->fillRow(1, 100, 'BUY', 10, 0.25, 1000, 0.0, ['ref' => str_repeat('a', 65)]),
        ]], $this->engineHeaders())->assertStatus(422);

        $this->assertSame(0, DB::table('exchange_fee_receipts')->count());
    }

    public function test_exchange_comes_from_the_route_never_the_payload(): void
    {
        // A payload that names a DIFFERENT venue than the route is ignored —
        // the route wins. Asserted in both directions now that bybit is wired,
        // which is stronger than the old "bybit is unsupported" half.
        $this->postJson('/api/engine/binance/fees', ['rows' => [
            $this->fillRow(1, 100, 'BUY', 10, 0.25, 1000, 0.0, ['exchange' => 'bybit']),
        ]], $this->engineHeaders())->assertOk();

        $this->postJson('/api/engine/bybit/fees', ['rows' => [
            $this->fillRow(2, 100, 'BUY', 10, 0.25, 1000, 0.0, ['exchange' => 'binance']),
        ]], $this->engineHeaders())->assertOk();

        $this->assertSame(
            ['binance', 'bybit'],
            DB::table('exchange_fee_receipts')->orderBy('ref')->pluck('exchange')->all()
        );
    }

    public function test_it_requires_the_engine_secret(): void
    {
        $this->postJson('/api/engine/binance/fees', ['rows' => [
            $this->fillRow(1, 100, 'BUY', 10, 0.25, 1000),
        ]])->assertStatus(401);
    }

    public function test_ingest_rebases_the_posted_pair_to_the_actual_fee(): void
    {
        // Estimated: gross 25, est. fee 0.5 → stored 24.5.
        $id = $this->seedClose(200, '2026-09-12 10:00:00', 24.5, 0.5);

        $this->postJson('/api/engine/binance/fees', ['rows' => [
            $this->fillRow(1, 100, 'BUY', 10, 0.25, 1000),
            ['api_key' => 'key-a', 'uni_id' => 'uni-key-a', 'symbol' => 'LTCUSDT', 'kind' => 'funding',
                'ref' => 77, 'amount' => 0.12, 'asset' => 'USDT', 'charged_at' => 1500],
            $this->fillRow(2, 200, 'SELL', 10, 0.26, 2000, 25.0),
        ]], $this->engineHeaders())->assertOk()->assertJson(['rebased' => 1, 'unconfirmed' => 0, 'errors' => 0]);

        $row = DB::table('binance_pastpositions')->find($id);
        $this->assertSame(0.63, (float) $row->exchange_fee);      // 0.25 + 0.12 + 0.26
        $this->assertSame(24.37, (float) $row->realized_pnl);      // gross 25 − 0.63
        $this->assertSame(TradingFee::SOURCE_ACTUAL, $row->fee_source);
    }

    public function test_an_unmatchable_close_stays_estimated_and_is_counted(): void
    {
        $id = $this->seedClose(200, '2026-09-12 10:00:00', 24.5, 0.5);

        // Only the exit fills are known — the position opened before the ledger.
        $this->postJson('/api/engine/binance/fees', ['rows' => [
            $this->fillRow(2, 200, 'SELL', 10, 0.26, 2000, 25.0),
        ]], $this->engineHeaders())->assertOk()->assertJson(['rebased' => 0, 'unconfirmed' => 1]);

        $row = DB::table('binance_pastpositions')->find($id);
        $this->assertSame(0.5, (float) $row->exchange_fee);
        $this->assertSame(TradingFee::SOURCE_ESTIMATED, $row->fee_source);
    }

    public function test_an_attribution_failure_does_not_fail_the_request(): void
    {
        $this->app->instance(FeeRebase::class, new class extends FeeRebase
        {
            public function pair(string $apiKey, string $symbol, bool $dryRun = false, string $exchange = 'binance'): array
            {
                throw new \RuntimeException('boom');
            }
        });

        $this->postJson('/api/engine/binance/fees', ['rows' => [
            $this->fillRow(1, 100, 'BUY', 10, 0.25, 1000),
        ]], $this->engineHeaders())->assertOk()->assertJson(['success' => true, 'inserted' => 1, 'errors' => 1]);

        // The receipts are stored regardless; the daily reconcile retries the pair.
        $this->assertSame(1, DB::table('exchange_fee_receipts')->count());
    }
}
