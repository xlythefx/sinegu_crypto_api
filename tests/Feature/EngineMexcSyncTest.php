<?php

namespace Tests\Feature;

use App\Services\Pnl\TradingFee;
use Illuminate\Support\Facades\DB;

/**
 * /api/engine/mexc/* — the same engine surface as Binance, answered from the
 * mexc_* tables and priced at MEXC's own taker rate.
 *
 * Every test also asserts the binance_* tables are untouched: the route's
 * {exchange} is the ONLY thing that picks the table, and a MEXC poller must
 * never be able to write a Binance row (or read one) by accident.
 */
class EngineMexcSyncTest extends EngineTestCase
{
    private const BINANCE_TABLES = ['binance_accounts', 'binance_positions', 'binance_pastpositions', 'binance_transactions'];

    /** @return array<string, int> */
    private function binanceCounts(): array
    {
        $counts = [];
        foreach (self::BINANCE_TABLES as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    private function assertBinanceUntouched(array $before): void
    {
        $this->assertSame($before, $this->binanceCounts(), 'binance_* tables must not change on a mexc request');
    }

    public function test_accounts_come_from_mexc_accounts_with_total_deposit_from_mexc_transactions(): void
    {
        $user = $this->makeUser();
        $this->makeAccount($user, ['name' => 'Binance only'], 'binance');
        $mexcId = $this->makeAccount($user, ['initial_deposit' => 600, 'name' => 'MEXC one'], 'mexc');
        $mexc = DB::table('mexc_accounts')->find($mexcId);

        DB::table('mexc_transactions')->insert([
            ['api_key' => $mexc->api_key, 'uni_id' => $user, 'type' => 'DEPOSIT', 'amount' => 700, 'tran_id' => 1, 'created_at' => now()],
            ['api_key' => $mexc->api_key, 'uni_id' => $user, 'type' => 'WITHDRAWAL', 'amount' => 100, 'tran_id' => 2, 'created_at' => now()],
        ]);
        // A Binance transaction on the SAME api_key must not leak into the MEXC figure.
        DB::table('binance_transactions')->insert([
            'api_key' => $mexc->api_key, 'uni_id' => $user, 'type' => 'DEPOSIT', 'amount' => 9999, 'tran_id' => 3, 'created_at' => now(),
        ]);

        $before = $this->binanceCounts();
        $accounts = $this->getJson('/api/engine/mexc/accounts', $this->engineHeaders())
            ->assertOk()
            ->json('accounts');

        $this->assertCount(1, $accounts);
        $this->assertSame($mexc->api_key, $accounts[0]['api_key']);
        $this->assertSame($mexc->secret_key, $accounts[0]['secret_key']);
        $this->assertSame(1200.0, (float) $accounts[0]['total_deposit']);
        $this->assertFalse($accounts[0]['key_blocked']);

        $binance = $this->getJson('/api/engine/binance/accounts', $this->engineHeaders())->json('accounts');
        $this->assertCount(1, $binance);
        $this->assertSame('Binance only', $binance[0]['name']);
        $this->assertBinanceUntouched($before);
    }

    public function test_positions_sync_and_check_use_mexc_positions(): void
    {
        // Writes file under the MEXC account's owner; an unknown key is skipped.
        $this->makeAccount($this->makeUser(), ['api_key' => 'mx-key'], 'mexc');
        DB::table('binance_positions')->insert([
            'api_key' => 'mx-key', 'uni_id' => 'u1', 'symbol' => 'BTCUSDT', 'position_side' => 'LONG',
            'position_amt' => 0.5, 'created_at' => now(),
        ]);
        $before = $this->binanceCounts();

        $this->postJson('/api/engine/mexc/positions/sync', ['accounts' => [[
            'api_key' => 'mx-key',
            'uni_id' => 'u1',
            'positions' => [[
                'symbol' => 'BTCUSDT', 'position_side' => 'SHORT', 'position_amt' => -0.002,
                'entry_price' => 60000, 'mark_price' => 59900, 'unrealized_profit' => 0.2,
                'notional' => 119.8, 'initial_margin' => 4.8, 'maint_margin' => null,
                'isolated_margin' => 4.8, 'isolated_wallet' => null, 'update_time' => 1700000000000,
            ]],
        ]]], $this->engineHeaders())->assertOk()->assertJson(['success' => true, 'inserted' => 1]);

        $row = DB::table('mexc_positions')->where('api_key', 'mx-key')->first();
        $this->assertSame(-0.002, (float) $row->position_amt);
        $this->assertNull($row->maint_margin);

        $check = $this->getJson('/api/engine/mexc/positions/check?symbol=BTCUSDT&position_side=SHORT', $this->engineHeaders())
            ->assertOk()->json('positions');
        $this->assertCount(1, $check);
        $this->assertSame(-0.002, $check[0]['position_amt']);

        // The Binance LONG on the same key/symbol is invisible from the mexc route…
        $this->assertSame([], $this->getJson('/api/engine/mexc/positions/check?symbol=BTCUSDT&position_side=LONG', $this->engineHeaders())->json('positions'));
        // …and still there.
        $this->assertBinanceUntouched($before);

        $this->postJson('/api/engine/mexc/positions/upsert', [
            'api_key' => 'mx-key', 'uni_id' => 'u1', 'symbol' => 'BTCUSDT', 'position_side' => 'SHORT', 'position_amt' => 0,
        ], $this->engineHeaders())->assertOk()->assertJson(['result' => 'deleted']);
        $this->assertSame(0, DB::table('mexc_positions')->count());
        $this->assertBinanceUntouched($before);
    }

    public function test_past_positions_are_netted_at_the_mexc_taker_rate(): void
    {
        config(['services.mexc.taker_fee_rate' => 0.0002]);
        $this->makeAccount($this->makeUser(), ['api_key' => 'mx-key'], 'mexc');
        $before = $this->binanceCounts();

        $this->postJson('/api/engine/mexc/past-positions/sync', ['rows' => [[
            'api_key' => 'mx-key', 'uni_id' => 'u1', 'symbol' => 'LTCUSDT', 'position_side' => 'LONG',
            'position_amt' => 10, 'entry_price' => 48, 'exit_price' => 50, 'realized_pnl' => 20.0,
            'side' => 'SELL', 'order_id' => 800746480548793856, 'closed_at' => '2026-09-16 10:00:00', 'strategy' => 'ABCD-v1',
        ]]], $this->engineHeaders())->assertOk()->assertJson(['inserted' => 1]);

        $row = DB::table('mexc_pastpositions')->first();
        // 10 × 50 × 0.0002 × 2 = 0.2 — MEXC's rate, not Binance's 0.0005 (which would be 0.5).
        $this->assertSame(0.2, (float) $row->exchange_fee);
        $this->assertSame(19.8, (float) $row->realized_pnl);
        $this->assertSame(TradingFee::SOURCE_ESTIMATED, $row->fee_source);
        $this->assertSame(800746480548793856, (int) $row->order_id);
        $this->assertBinanceUntouched($before);
    }

    /**
     * The owner lookup is per exchange: a key that exists only in
     * binance_accounts has no owner on the MEXC route, so nothing is written
     * there — and nothing is guessed from the payload's uni_id either.
     */
    public function test_a_binance_accounts_key_is_unknown_on_the_mexc_route(): void
    {
        $user = $this->makeUser();
        $this->makeAccount($user, ['api_key' => 'bn-only-key'], 'binance');
        $before = $this->binanceCounts();

        $this->postJson('/api/engine/mexc/past-positions/sync', ['rows' => [[
            'api_key' => 'bn-only-key', 'uni_id' => $user, 'symbol' => 'LTCUSDT', 'position_side' => 'LONG',
            'position_amt' => 10, 'exit_price' => 50, 'realized_pnl' => 20.0, 'side' => 'SELL',
            'order_id' => 1, 'closed_at' => '2026-09-16 10:00:00',
        ]]], $this->engineHeaders())->assertOk()->assertJson(['inserted' => 0, 'unknown' => 1]);

        $this->assertSame(0, DB::table('mexc_pastpositions')->count());
        $this->assertBinanceUntouched($before);
    }

    public function test_fee_receipts_are_stamped_mexc_and_rebase_mexc_pastpositions_only(): void
    {
        // Same api_key + symbol + order_id on both exchanges' tables: only the MEXC row may move.
        $seed = fn (string $table) => DB::table($table)->insertGetId([
            'api_key' => 'mx-key', 'uni_id' => 'u1', 'symbol' => 'LTCUSDT', 'position_side' => 'LONG',
            'position_amt' => 10, 'exit_price' => 50, 'realized_pnl' => 24.8, 'exchange_fee' => 0.2,
            'fee_source' => TradingFee::SOURCE_ESTIMATED, 'side' => 'SELL', 'order_id' => 200,
            'closed_at' => '2026-09-16 10:00:00', 'created_at' => now(),
        ]);
        $mexcId = $seed('mexc_pastpositions');
        $binanceId = $seed('binance_pastpositions');

        $fill = fn (int $ref, int $orderId, string $side, float $fee, int $time, float $pnl = 0.0) => [
            'api_key' => 'mx-key', 'uni_id' => 'u1', 'symbol' => 'LTCUSDT', 'kind' => 'fill', 'ref' => $ref,
            'order_id' => $orderId, 'side' => $side, 'position_side' => 'LONG', 'qty' => 10, 'price' => 50,
            'realized_pnl' => $pnl, 'amount' => $fee, 'asset' => 'USDT', 'charged_at' => $time,
        ];

        $this->postJson('/api/engine/mexc/fees', ['rows' => [
            $fill(1, 100, 'BUY', 0.10, 1000),
            $fill(2, 200, 'SELL', 0.11, 2000, 25.0),
        ]], $this->engineHeaders())->assertOk()->assertJson(['inserted' => 2, 'rebased' => 1, 'errors' => 0]);

        $this->assertSame(2, DB::table('exchange_fee_receipts')->where('exchange', 'mexc')->count());

        $mexc = DB::table('mexc_pastpositions')->find($mexcId);
        $this->assertSame(0.21, (float) $mexc->exchange_fee);
        $this->assertSame(24.79, (float) $mexc->realized_pnl);   // gross 25 − 0.21
        $this->assertSame(TradingFee::SOURCE_ACTUAL, $mexc->fee_source);

        $binance = DB::table('binance_pastpositions')->find($binanceId);
        $this->assertSame(0.2, (float) $binance->exchange_fee);
        $this->assertSame(TradingFee::SOURCE_ESTIMATED, $binance->fee_source);
    }

    public function test_a_binance_receipt_never_rebases_a_mexc_close(): void
    {
        $mexcId = DB::table('mexc_pastpositions')->insertGetId([
            'api_key' => 'mx-key', 'uni_id' => 'u1', 'symbol' => 'LTCUSDT', 'position_side' => 'LONG',
            'position_amt' => 10, 'exit_price' => 50, 'realized_pnl' => 24.8, 'exchange_fee' => 0.2,
            'fee_source' => TradingFee::SOURCE_ESTIMATED, 'side' => 'SELL', 'order_id' => 200,
            'closed_at' => '2026-09-16 10:00:00', 'created_at' => now(),
        ]);

        // Entry + exit receipts that WOULD confirm order 200 — posted on the binance route.
        $fill = fn (int $ref, int $orderId, string $side, float $fee, int $time, float $pnl = 0.0) => [
            'api_key' => 'mx-key', 'uni_id' => 'u1', 'symbol' => 'LTCUSDT', 'kind' => 'fill', 'ref' => $ref,
            'order_id' => $orderId, 'side' => $side, 'position_side' => 'LONG', 'qty' => 10, 'price' => 50,
            'realized_pnl' => $pnl, 'amount' => $fee, 'asset' => 'USDT', 'charged_at' => $time,
        ];
        $this->postJson('/api/engine/binance/fees', ['rows' => [
            $fill(1, 100, 'BUY', 0.25, 1000),
            $fill(2, 200, 'SELL', 0.26, 2000, 25.0),
        ]], $this->engineHeaders())->assertOk()->assertJson(['rebased' => 0]);

        $mexc = DB::table('mexc_pastpositions')->find($mexcId);
        $this->assertSame(0.2, (float) $mexc->exchange_fee);
        $this->assertSame(TradingFee::SOURCE_ESTIMATED, $mexc->fee_source);
    }

    public function test_balances_and_key_status_write_mexc_accounts(): void
    {
        $user = $this->makeUser();
        $binanceId = $this->makeAccount($user, ['api_key' => 'shared-key', 'balance' => 1000], 'binance');
        $mexcId = $this->makeAccount($user, ['api_key' => 'shared-key', 'balance' => 1000, 'initial_deposit' => null], 'mexc');

        $this->postJson('/api/engine/mexc/balances', ['rows' => [
            ['api_key' => 'shared-key', 'balance' => 512.5, 'unrealized_pnl' => -3.25, 'initial_deposit' => 512.5],
        ]], $this->engineHeaders())->assertOk()->assertJson(['updated' => 1]);

        $mexc = DB::table('mexc_accounts')->find($mexcId);
        $this->assertSame(512.5, (float) $mexc->balance);
        $this->assertSame(-3.25, (float) $mexc->unrealized_pnl);
        $this->assertSame(512.5, (float) $mexc->initial_deposit);
        $this->assertSame(1000.0, (float) DB::table('binance_accounts')->find($binanceId)->balance);

        $this->postJson('/api/engine/mexc/key-status', [
            'api_key' => 'shared-key', 'status' => 'blocked', 'code' => '406', 'reason' => 'IP_NOT_WHITELISTED',
            'message' => 'Accessing IP is not in the whitelist',
        ], $this->engineHeaders())->assertOk()->assertJson(['updated' => true, 'status' => 'blocked']);

        $this->assertSame('blocked', DB::table('mexc_accounts')->find($mexcId)->key_status);
        $this->assertSame('406', DB::table('mexc_accounts')->find($mexcId)->key_error_code);
        $this->assertSame('ok', DB::table('binance_accounts')->find($binanceId)->key_status);

        // Blocked keys are still listed for the engine, flagged.
        $accounts = $this->getJson('/api/engine/mexc/accounts', $this->engineHeaders())->json('accounts');
        $this->assertTrue($accounts[0]['key_blocked']);
    }

    public function test_transactions_land_in_mexc_transactions(): void
    {
        $before = $this->binanceCounts();

        $rows = ['rows' => [[
            'api_key' => 'mx-key', 'uni_id' => 'u1', 'type' => 'DEPOSIT', 'amount' => 250,
            'tran_id' => 987654321, 'currency' => 'USDT', 'transaction_time' => 1700000000000, 'info' => 'txid-abc',
        ]]];
        $this->postJson('/api/engine/mexc/transactions', $rows, $this->engineHeaders())->assertOk()->assertJson(['inserted' => 1]);
        $this->postJson('/api/engine/mexc/transactions', $rows, $this->engineHeaders())->assertOk()->assertJson(['inserted' => 0]);

        $this->assertSame(1, DB::table('mexc_transactions')->count());
        $this->assertBinanceUntouched($before);
    }

    public function test_trade_logs_and_open_strategies_carry_the_mexc_discriminator(): void
    {
        $this->postJson('/api/engine/mexc/trade-logs', [
            'action' => 'BUY', 'ticker' => 'BTCUSDT', 'success' => true, 'category' => 'signal',
            'target_count' => 1, 'filled' => 1, 'failed' => 0, 'skipped' => 0, 'details' => [], 'ts' => '2026-09-16 10:00:00',
        ], $this->engineHeaders())->assertStatus(201);
        $this->assertSame('mexc', DB::table('trade_logs')->value('exchange'));

        $this->postJson('/api/engine/mexc/open-strategies', [
            'api_key' => 'mx-key', 'symbol' => 'BTCUSDT', 'position_side' => 'LONG', 'strategy' => 'ABCD-v1',
        ], $this->engineHeaders())->assertOk();
        $this->assertSame('mexc', DB::table('open_strategies')->value('exchange'));

        // Invisible from the binance route.
        $this->assertSame([], $this->getJson('/api/engine/binance/open-strategies?api_key=mx-key&symbol=BTCUSDT', $this->engineHeaders())->json('open_strategies'));
    }
}
