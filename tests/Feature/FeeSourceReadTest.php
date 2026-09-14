<?php

namespace Tests\Feature;

use App\Models\UserCredential;
use App\Services\Pnl\TradingFee;
use Illuminate\Support\Facades\DB;

/**
 * Every screen that lists a closed trade can now say whether its fee is the
 * estimate or the exchange's receipts: `fee_source` (and `exchange_fee`, where
 * it was missing) rides along on the four endpoints that feed them.
 */
class FeeSourceReadTest extends EngineTestCase
{
    private function headersFor(string $uniId): array
    {
        $user = UserCredential::find($uniId);

        return ['Authorization' => 'Bearer '.$user->createToken('spa')->plainTextToken];
    }

    private function seedTrade(string $uniId, string $apiKey, int $orderId, ?string $source, ?float $fee): void
    {
        DB::table('binance_pastpositions')->insert([
            'api_key' => $apiKey,
            'uni_id' => $uniId,
            'symbol' => 'LTCUSDT',
            'position_side' => 'LONG',
            'position_amt' => 10,
            'exit_price' => 50,
            'realized_pnl' => 24.37,
            'exchange_fee' => $fee,
            'fee_source' => $source,
            'side' => 'SELL',
            'order_id' => $orderId,
            'closed_at' => '2026-09-12 10:00:00',
            'created_at' => now(),
        ]);
    }

    public function test_user_endpoints_carry_the_fee_and_its_source(): void
    {
        $user = $this->makeUser();
        $apiKey = DB::table('binance_accounts')->where('id', $this->makeAccount($user))->value('api_key');
        $this->seedTrade($user, $apiKey, 1, TradingFee::SOURCE_ACTUAL, 0.63);
        $this->seedTrade($user, $apiKey, 2, TradingFee::SOURCE_ESTIMATED, 0.5);
        $this->seedTrade($user, $apiKey, 3, null, null);

        $positions = $this->getJson('/api/binance/past-positions', $this->headersFor($user))
            ->assertOk()->json('positions');
        $bySource = collect($positions)->keyBy('order_id');
        $this->assertSame('actual', $bySource[1]['fee_source']);
        $this->assertSame('estimated', $bySource[2]['fee_source']);
        $this->assertNull($bySource[3]['fee_source']);
        $this->assertNull($bySource[3]['exchange_fee']);

        $days = $this->getJson('/api/dashboard/daily-pnl', $this->headersFor($user))
            ->assertOk()->json('days');
        $trades = collect($days['2026-09-12']['trades'])->keyBy('id');
        $this->assertCount(3, $trades);
        $this->assertEqualsCanonicalizing(
            [['actual', 0.63], ['estimated', 0.5], [null, null]],
            $trades->map(fn ($t) => [$t['fee_source'], $t['exchange_fee']])->values()->all(),
        );
    }

    public function test_admin_endpoints_carry_the_fee_source(): void
    {
        $this->app['auth']->forgetGuards();
        $admin = $this->makeUser(['type' => 'admin']);
        $master = $this->makeUser(['type' => 'master']);
        $apiKey = DB::table('binance_accounts')->where('id', $this->makeAccount($master))->value('api_key');
        $this->seedTrade($master, $apiKey, 1, TradingFee::SOURCE_MANUAL, 0.5);

        $trades = $this->getJson('/api/admin/positions', $this->headersFor($admin))
            ->assertOk()->json('trades');
        $this->assertSame('manual', $trades[0]['fee_source']);
        $this->assertSame(0.5, $trades[0]['exchange_fee']);

        $days = $this->getJson('/api/admin/daily-pnl', $this->headersFor($admin))
            ->assertOk()->json('days');
        $this->assertSame('manual', $days['2026-09-12']['trades'][0]['fee_source']);

        $userTrades = $this->getJson("/api/admin/users/{$master}/positions", $this->headersFor($admin))
            ->assertOk()->json('trades');
        $this->assertSame('manual', $userTrades[0]['fee_source']);
    }
}
