<?php

namespace Tests\Feature;

use App\Models\UserCredential;
use App\Services\Pnl\TradingFee;
use Illuminate\Support\Facades\DB;

/**
 * PUT /api/admin/positions/{id} and /api/admin/past-positions/{id} — the admin
 * Positions page's row editor.
 *
 * The load-bearing properties: only the whitelisted columns move, ownership
 * (api_key / uni_id) can never be rewritten through this endpoint — those
 * columns are what join a row to its account's invoices and to the published
 * track record — and a plain user cannot reach it at all.
 */
class AdminPositionEditTest extends EngineTestCase
{
    private function headersFor(string $uniId): array
    {
        $user = UserCredential::find($uniId);

        return ['Authorization' => 'Bearer '.$user->createToken('spa')->plainTextToken];
    }

    private function admin(): string
    {
        $this->app['auth']->forgetGuards();

        return $this->makeUser(['type' => 'admin']);
    }

    private function makePosition(string $uniId, string $apiKey, array $overrides = []): int
    {
        return DB::table('binance_positions')->insertGetId(array_merge([
            'api_key' => $apiKey,
            'uni_id' => $uniId,
            'symbol' => 'LTCUSDT',
            'position_side' => 'LONG',
            'position_amt' => 10,
            'entry_price' => 50,
            'mark_price' => 51,
            'unrealized_profit' => 10,
            'created_at' => now(),
        ], $overrides));
    }

    private function makeTrade(string $uniId, string $apiKey, array $overrides = []): int
    {
        return DB::table('binance_pastpositions')->insertGetId(array_merge([
            'api_key' => $apiKey,
            'uni_id' => $uniId,
            'symbol' => 'LTCUSDT',
            'position_side' => 'LONG',
            'position_amt' => 10,
            'entry_price' => 50,
            'exit_price' => 52,
            'realized_pnl' => 25.09,
            'side' => 'SELL',
            'order_id' => 900001,
            'closed_at' => '2026-09-04 17:00:00',
            'strategy' => 'VWMA-Reversion',
            'created_at' => now(),
        ], $overrides));
    }

    public function test_it_updates_an_open_position(): void
    {
        $admin = $this->admin();
        $owner = $this->makeUser();
        $accountId = $this->makeAccount($owner);
        $apiKey = DB::table('binance_accounts')->where('id', $accountId)->value('api_key');
        $id = $this->makePosition($owner, $apiKey);

        $this->putJson("/api/admin/positions/{$id}", [
            'symbol' => 'btcusdt',
            'position_side' => 'SHORT',
            'position_amt' => -2.5,
            'mark_price' => 61000,
            'unrealized_profit' => -120.5,
        ], $this->headersFor($admin))->assertOk();

        $row = DB::table('binance_positions')->where('id', $id)->first();

        $this->assertSame('BTCUSDT', $row->symbol);        // normalized upper-case
        $this->assertSame('SHORT', $row->position_side);
        $this->assertSame(-2.5, (float) $row->position_amt);
        $this->assertSame(61000.0, (float) $row->mark_price);
        $this->assertSame(-120.5, (float) $row->unrealized_profit);
        // untouched columns stay put
        $this->assertSame($apiKey, $row->api_key);
        $this->assertSame($owner, $row->uni_id);
        $this->assertSame(50.0, (float) $row->entry_price);
    }

    public function test_it_updates_a_closed_trade(): void
    {
        $admin = $this->admin();
        $owner = $this->makeUser();
        $accountId = $this->makeAccount($owner);
        $apiKey = DB::table('binance_accounts')->where('id', $accountId)->value('api_key');
        $id = $this->makeTrade($owner, $apiKey);

        $this->putJson("/api/admin/past-positions/{$id}", [
            'symbol' => 'LTCUSDT',
            'side' => 'BUY',
            'position_amt' => 12,
            'exit_price' => 53.5,
            'realized_pnl' => 30,
            'strategy' => '  vwma-reversion  ',
            'closed_at' => '2026-09-05T09:30',
        ], $this->headersFor($admin))->assertOk();

        $row = DB::table('binance_pastpositions')->where('id', $id)->first();

        $this->assertSame('BUY', $row->side);
        $this->assertSame(12.0, (float) $row->position_amt);
        $this->assertSame(53.5, (float) $row->exit_price);
        $this->assertSame(30.0, (float) $row->realized_pnl);
        $this->assertSame('vwma-reversion', $row->strategy);
        // the datetime-local value is stored as a MySQL datetime, same wall clock
        $this->assertStringStartsWith('2026-09-05 09:30:00', (string) $row->closed_at);
        $this->assertSame($apiKey, $row->api_key);
        $this->assertSame($owner, $row->uni_id);
    }

    public function test_it_ignores_ownership_columns_in_the_payload(): void
    {
        $admin = $this->admin();
        $owner = $this->makeUser();
        $thief = $this->makeUser();
        $accountId = $this->makeAccount($owner);
        $apiKey = DB::table('binance_accounts')->where('id', $accountId)->value('api_key');
        $id = $this->makeTrade($owner, $apiKey);

        $this->putJson("/api/admin/past-positions/{$id}", [
            'realized_pnl' => 40,
            'uni_id' => $thief,
            'api_key' => 'stolen-key',
        ], $this->headersFor($admin))->assertOk();

        $row = DB::table('binance_pastpositions')->where('id', $id)->first();

        $this->assertSame(40.0, (float) $row->realized_pnl);
        $this->assertSame($owner, $row->uni_id);
        $this->assertSame($apiKey, $row->api_key);
    }

    public function test_it_rejects_an_unknown_side_and_a_missing_row(): void
    {
        $admin = $this->admin();
        $owner = $this->makeUser();
        $accountId = $this->makeAccount($owner);
        $apiKey = DB::table('binance_accounts')->where('id', $accountId)->value('api_key');
        $id = $this->makeTrade($owner, $apiKey);

        $this->putJson("/api/admin/past-positions/{$id}", [
            'side' => 'HOLD',
        ], $this->headersFor($admin))->assertStatus(422);

        $this->putJson('/api/admin/past-positions/999999', [
            'realized_pnl' => 1,
        ], $this->headersFor($admin))->assertStatus(404);
    }

    /**
     * A typed P&L stamps the row `manual`, which is what keeps the fee
     * reconciler from later "correcting" the correction. Other fields leave
     * the label alone; clearing the P&L clears it.
     */
    public function test_editing_realized_pnl_marks_the_row_manual(): void
    {
        $admin = $this->admin();
        $owner = $this->makeUser();
        $apiKey = DB::table('binance_accounts')->where('id', $this->makeAccount($owner))->value('api_key');
        $id = $this->makeTrade($owner, $apiKey, [
            'closed_at' => '2026-09-12 10:00:00',
            'realized_pnl' => 24.5,
            'exchange_fee' => 0.5,
            'fee_source' => TradingFee::SOURCE_ESTIMATED,
        ]);

        // Same value re-submitted: not an edit, still estimated.
        $this->putJson("/api/admin/past-positions/{$id}", ['realized_pnl' => 24.5], $this->headersFor($admin))->assertOk();
        $this->assertSame(TradingFee::SOURCE_ESTIMATED, DB::table('binance_pastpositions')->find($id)->fee_source);

        // Strategy / price edits never touch the label.
        $this->putJson("/api/admin/past-positions/{$id}", ['strategy' => 'x', 'exit_price' => 51], $this->headersFor($admin))->assertOk();
        $this->assertSame(TradingFee::SOURCE_ESTIMATED, DB::table('binance_pastpositions')->find($id)->fee_source);

        // A different P&L is a hand correction.
        $this->putJson("/api/admin/past-positions/{$id}", ['realized_pnl' => 30], $this->headersFor($admin))->assertOk();
        $row = DB::table('binance_pastpositions')->find($id);
        $this->assertSame(TradingFee::SOURCE_MANUAL, $row->fee_source);
        $this->assertSame(0.5, (float) $row->exchange_fee); // left as it was

        // Clearing it puts the row back to "unknown".
        $this->putJson("/api/admin/past-positions/{$id}", ['realized_pnl' => null], $this->headersFor($admin))->assertOk();
        $row = DB::table('binance_pastpositions')->find($id);
        $this->assertNull($row->realized_pnl);
        $this->assertNull($row->fee_source);
    }

    public function test_a_plain_user_cannot_edit_positions(): void
    {
        $owner = $this->makeUser();
        $accountId = $this->makeAccount($owner);
        $apiKey = DB::table('binance_accounts')->where('id', $accountId)->value('api_key');
        $id = $this->makeTrade($owner, $apiKey);

        $this->putJson("/api/admin/past-positions/{$id}", [
            'realized_pnl' => 9999,
        ], $this->headersFor($owner))->assertStatus(403);

        $this->assertSame(
            25.09,
            (float) DB::table('binance_pastpositions')->where('id', $id)->value('realized_pnl'),
        );
    }
}
