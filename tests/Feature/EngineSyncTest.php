<?php

namespace Tests\Feature;

use App\Services\Pnl\TradingFee;
use Illuminate\Support\Facades\DB;

/** The engine's bookkeeping writes: positions, past positions, balances, transactions. */
class EngineSyncTest extends EngineTestCase
{
    private function seedPosition(string $apiKey, string $symbol = 'BTCUSDT', float $amt = 0.5, string $side = 'LONG'): void
    {
        DB::table('binance_positions')->insert([
            'api_key' => $apiKey,
            'uni_id' => 'uni-'.$apiKey,
            'symbol' => $symbol,
            'position_side' => $side,
            'position_amt' => $amt,
            'created_at' => now(),
        ]);
    }

    public function test_positions_sync_replaces_only_the_posted_account(): void
    {
        $this->seedPosition('key-a', 'ETHUSDT');
        $this->seedPosition('key-b', 'BTCUSDT');

        $this->postJson('/api/engine/binance/positions/sync', [
            'accounts' => [[
                'api_key' => 'key-a',
                'uni_id' => 'uni-key-a',
                'positions' => [
                    ['symbol' => 'BTCUSDT', 'position_side' => 'LONG', 'position_amt' => 1.25, 'entry_price' => 60000],
                ],
            ]],
        ], $this->engineHeaders())->assertOk()->assertJson(['success' => true]);

        $a = DB::table('binance_positions')->where('api_key', 'key-a')->get();
        $this->assertCount(1, $a);
        $this->assertSame('BTCUSDT', $a[0]->symbol);
        $this->assertSame(1.25, (float) $a[0]->position_amt);

        // The other account's snapshot is untouched.
        $this->assertSame(1, DB::table('binance_positions')->where('api_key', 'key-b')->count());
    }

    public function test_position_upsert_inserts_updates_and_deletes(): void
    {
        $headers = $this->engineHeaders();
        $base = ['api_key' => 'key-a', 'symbol' => 'BTCUSDT', 'position_side' => 'LONG'];
        $payload = $base + ['uni_id' => 'uni-key-a'];

        // Insert.
        $this->postJson('/api/engine/binance/positions/upsert', $payload + ['position_amt' => 0.5, 'entry_price' => 61000], $headers)
            ->assertOk()->assertJson(['result' => 'inserted']);

        // Update (additive amount computed engine-side).
        $this->postJson('/api/engine/binance/positions/upsert', $payload + ['position_amt' => 0.75], $headers)
            ->assertOk()->assertJson(['result' => 'updated']);
        $this->assertSame(0.75, (float) DB::table('binance_positions')->where($base)->value('position_amt'));

        // Zero amount removes the row.
        $this->postJson('/api/engine/binance/positions/upsert', $payload + ['position_amt' => 0], $headers)
            ->assertOk()->assertJson(['result' => 'deleted']);
        $this->assertSame(0, DB::table('binance_positions')->where($base)->count());
    }

    public function test_positions_check_is_batched_per_symbol(): void
    {
        $this->seedPosition('key-a', 'BTCUSDT', 0.5, 'LONG');
        $this->seedPosition('key-b', 'BTCUSDT', 0.25, 'LONG');
        $this->seedPosition('key-c', 'BTCUSDT', 0.1, 'SHORT');
        $this->seedPosition('key-d', 'ETHUSDT', 2.0, 'LONG');
        $this->seedPosition('key-e', 'BTCUSDT', 0.0, 'LONG'); // dust — excluded

        $positions = $this->getJson('/api/engine/binance/positions/check?symbol=BTCUSDT&position_side=LONG', $this->engineHeaders())
            ->assertOk()
            ->json('positions');

        $this->assertEqualsCanonicalizing(
            ['key-a', 'key-b'],
            array_column($positions, 'api_key')
        );
    }

    public function test_past_positions_sync_is_idempotent_and_fills_nulls_only(): void
    {
        $headers = $this->engineHeaders();
        $row = [
            'api_key' => 'key-a',
            'uni_id' => 'uni-key-a',
            'symbol' => 'BTCUSDT',
            'position_side' => 'LONG',
            'position_amt' => 0.5,
            'entry_price' => null,
            'exit_price' => 61000,
            'realized_pnl' => 120.5,
            'side' => 'SELL',
            'order_id' => 987654,
            'closed_at' => '2026-09-12 10:00:00', // after TradingFee::NET_SINCE
            'strategy' => null,
        ];

        $this->postJson('/api/engine/binance/past-positions/sync', ['rows' => [$row]], $headers)
            ->assertOk()->assertJson(['inserted' => 1]);

        // Same order again: no duplicate; null strategy gets filled, existing pnl untouched.
        $again = array_merge($row, ['strategy' => 'VWMA-Reversion', 'realized_pnl' => 999.0]);
        $this->postJson('/api/engine/binance/past-positions/sync', ['rows' => [$again]], $headers)
            ->assertOk()->assertJson(['inserted' => 0, 'updated' => 1]);

        $stored = DB::table('binance_pastpositions')
            ->where('api_key', 'key-a')->where('order_id', 987654)->get();
        $this->assertCount(1, $stored);
        $this->assertSame('VWMA-Reversion', $stored[0]->strategy);

        // The engine posted GROSS 120.5; the row holds it net of the round-trip
        // commission on 0.5 @ 61000 (0.05% a side = 30.5), which is the figure
        // Binance's own Position History shows for the same trade.
        $this->assertSame(30.5, (float) $stored[0]->exchange_fee);
        $this->assertSame(90.0, (float) $stored[0]->realized_pnl);
        // ...and says so: the receipts have not been matched yet.
        $this->assertSame(TradingFee::SOURCE_ESTIMATED, $stored[0]->fee_source);
    }

    /**
     * The webhook close path knows how many entry-sized increments a close took
     * off (`Increments Closed (3/3)`); the reconciliation poller does not. The
     * row keeps whichever arrived, and a later sync without it never blanks it.
     */
    public function test_past_positions_keep_the_increments_the_close_reported(): void
    {
        $headers = $this->engineHeaders();
        $base = [
            'api_key' => 'key-a', 'uni_id' => 'uni-key-a', 'symbol' => 'LTCUSDT',
            'position_side' => 'LONG', 'position_amt' => 42.0, 'side' => 'SELL',
            'order_id' => 555, 'closed_at' => '2026-09-16 09:45:00',
        ];

        // Poller row first (no increments), then the webhook's bookkeeping fills it.
        $this->postJson('/api/engine/binance/past-positions/sync', ['rows' => [$base]], $headers)
            ->assertOk()->assertJson(['inserted' => 1]);
        $this->assertNull(DB::table('binance_pastpositions')->where('order_id', 555)->value('increments_closed'));

        $this->postJson('/api/engine/binance/past-positions/sync', ['rows' => [$base + ['increments_closed' => 3]]], $headers)
            ->assertOk()->assertJson(['updated' => 1]);
        $this->assertSame(3, (int) DB::table('binance_pastpositions')->where('order_id', 555)->value('increments_closed'));

        // A re-sync without the field (the poller again) leaves it alone.
        $this->postJson('/api/engine/binance/past-positions/sync', ['rows' => [$base]], $headers)
            ->assertOk()->assertJson(['skipped' => 1]);
        $this->assertSame(3, (int) DB::table('binance_pastpositions')->where('order_id', 555)->value('increments_closed'));

        $this->postJson('/api/engine/binance/past-positions/sync', ['rows' => [$base + ['increments_closed' => 0]]], $headers)
            ->assertStatus(422);
    }

    public function test_past_positions_net_the_fee_once_even_when_backfilled(): void
    {
        $headers = $this->engineHeaders();
        $base = [
            'api_key' => 'key-a',
            'uni_id' => 'uni-key-a',
            'symbol' => 'ETHUSDT',
            'position_side' => 'LONG',
            'position_amt' => 2,
            'entry_price' => null,
            'side' => 'SELL',
            'order_id' => 424242,
            'closed_at' => '2026-09-12 11:00:00', // after TradingFee::NET_SINCE
        ];

        // The live close path writes the row before Binance has indexed its
        // fills: no exit price, so no P&L and no fee to estimate yet.
        $this->postJson('/api/engine/binance/past-positions/sync', [
            'rows' => [$base + ['exit_price' => null, 'realized_pnl' => null]],
        ], $headers)->assertOk()->assertJson(['inserted' => 1]);

        $row = DB::table('binance_pastpositions')->where('order_id', 424242)->first();
        $this->assertNull($row->realized_pnl);
        $this->assertNull($row->exchange_fee);
        $this->assertNull($row->fee_source);

        // The poller backfills both — and the fill is netted exactly like an insert.
        $this->postJson('/api/engine/binance/past-positions/sync', [
            'rows' => [$base + ['exit_price' => 3000, 'realized_pnl' => 400]],
        ], $headers)->assertOk()->assertJson(['updated' => 1]);

        $row = DB::table('binance_pastpositions')->where('order_id', 424242)->first();
        $this->assertSame(6.0, (float) $row->exchange_fee);   // 2 * 3000 * 0.0005 * 2
        $this->assertSame(394.0, (float) $row->realized_pnl);
        $this->assertSame(TradingFee::SOURCE_ESTIMATED, $row->fee_source);

        // A repeat of the same sync must not deduct the fee a second time.
        $this->postJson('/api/engine/binance/past-positions/sync', [
            'rows' => [$base + ['exit_price' => 3000, 'realized_pnl' => 400]],
        ], $headers)->assertOk()->assertJson(['skipped' => 1]);

        $row = DB::table('binance_pastpositions')->where('order_id', 424242)->first();
        $this->assertSame(394.0, (float) $row->realized_pnl);
    }

    /**
     * History before the cutoff is GROSS, and a pre-cutoff close discovered
     * late must land on that basis too — otherwise the poller's lookback could
     * plant the one net row among gross ones. Both the insert and the backfill
     * paths are checked, on either side of the boundary.
     */
    public function test_past_positions_closed_before_cutoff_stay_gross(): void
    {
        $headers = $this->engineHeaders();
        $base = [
            'api_key' => 'key-a',
            'uni_id' => 'uni-key-a',
            'symbol' => 'LTCUSDT',
            'position_side' => 'LONG',
            'position_amt' => 10,
            'entry_price' => null,
            'exit_price' => 50,
            'side' => 'SELL',
        ];

        // A second before the cutoff: gross, no fee.
        $this->postJson('/api/engine/binance/past-positions/sync', [
            'rows' => [$base + ['order_id' => 1001, 'closed_at' => '2026-09-10 23:59:59', 'realized_pnl' => 25]],
        ], $headers)->assertOk()->assertJson(['inserted' => 1]);

        $old = DB::table('binance_pastpositions')->where('order_id', 1001)->first();
        $this->assertSame(25.0, (float) $old->realized_pnl);
        $this->assertNull($old->exchange_fee);
        $this->assertNull($old->fee_source);

        // The cutoff instant itself: net (10 × 50 × 0.0005 × 2 = 0.5).
        $this->postJson('/api/engine/binance/past-positions/sync', [
            'rows' => [$base + ['order_id' => 1002, 'closed_at' => '2026-09-11 00:00:00', 'realized_pnl' => 25]],
        ], $headers)->assertOk()->assertJson(['inserted' => 1]);

        $new = DB::table('binance_pastpositions')->where('order_id', 1002)->first();
        $this->assertSame(24.5, (float) $new->realized_pnl);
        $this->assertSame(0.5, (float) $new->exchange_fee);
        $this->assertSame(TradingFee::SOURCE_ESTIMATED, $new->fee_source);

        // Backfill of a pre-cutoff row written before its fills were indexed:
        // the P&L arrives later and is still stored gross.
        $this->postJson('/api/engine/binance/past-positions/sync', [
            'rows' => [$base + ['order_id' => 1003, 'closed_at' => '2026-09-01 12:00:00', 'exit_price' => null, 'realized_pnl' => null]],
        ], $headers)->assertOk()->assertJson(['inserted' => 1]);
        $this->postJson('/api/engine/binance/past-positions/sync', [
            'rows' => [$base + ['order_id' => 1003, 'closed_at' => '2026-09-01 12:00:00', 'realized_pnl' => 25]],
        ], $headers)->assertOk()->assertJson(['updated' => 1]);

        $filled = DB::table('binance_pastpositions')->where('order_id', 1003)->first();
        $this->assertSame(25.0, (float) $filled->realized_pnl);
        $this->assertNull($filled->exchange_fee);
        $this->assertNull($filled->fee_source);
    }

    public function test_balances_update_guards_initial_deposit(): void
    {
        $user = $this->makeUser();
        $seasoned = $this->makeAccount($user, ['initial_deposit' => 1000]);
        $fresh = $this->makeAccount($user, ['initial_deposit' => null, 'balance' => null]);
        [$seasonedKey, $freshKey] = [
            DB::table('binance_accounts')->find($seasoned)->api_key,
            DB::table('binance_accounts')->find($fresh)->api_key,
        ];

        $this->postJson('/api/engine/binance/balances', ['rows' => [
            ['api_key' => $seasonedKey, 'balance' => 1500, 'unrealized_pnl' => 12.5, 'initial_deposit' => 777],
            ['api_key' => $freshKey, 'balance' => 300, 'initial_deposit' => 300],
        ]], $this->engineHeaders())->assertOk()->assertJson(['updated' => 2]);

        $seasonedRow = DB::table('binance_accounts')->find($seasoned);
        $this->assertSame(1500.0, (float) $seasonedRow->balance);
        $this->assertSame(12.5, (float) $seasonedRow->unrealized_pnl);
        $this->assertSame(1000.0, (float) $seasonedRow->initial_deposit); // NOT overwritten

        $freshRow = DB::table('binance_accounts')->find($fresh);
        $this->assertSame(300.0, (float) $freshRow->initial_deposit);     // first fill allowed
    }

    public function test_transactions_are_idempotent_per_tran_id(): void
    {
        $rows = ['rows' => [[
            'api_key' => 'key-a',
            'uni_id' => 'uni-key-a',
            'type' => 'DEPOSIT',
            'amount' => 250,
            'tran_id' => 424242,
            'transaction_time' => 1753776000000,
        ]]];

        $this->postJson('/api/engine/binance/transactions', $rows, $this->engineHeaders())
            ->assertOk()->assertJson(['inserted' => 1]);
        $this->postJson('/api/engine/binance/transactions', $rows, $this->engineHeaders())
            ->assertOk()->assertJson(['inserted' => 0]);

        $this->assertSame(1, DB::table('binance_transactions')->where('tran_id', 424242)->count());
    }
}
