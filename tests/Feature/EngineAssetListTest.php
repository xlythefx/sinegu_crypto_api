<?php

namespace Tests\Feature;

use App\Models\Asset;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/engine/{exchange}/assets names the coins that exist but are
 * switched off (2026-11-09), so a rejected entry can say "turned off" — or
 * "switched off by the kill switch" — instead of `asset_not_configured`,
 * which read the same for a paused coin and one nobody ever added.
 */
class EngineAssetListTest extends EngineTestCase
{
    private function asset(array $overrides = []): Asset
    {
        return Asset::create(array_merge([
            'ticker' => 'LTCUSDT', 'broker' => 'Binance', 'side' => 'ALL',
            'base_size' => 5, 'max_increments' => 15, 'enabled' => 1,
        ], $overrides));
    }

    public function test_tradeable_coins_stay_in_assets_and_switched_off_ones_are_listed_apart(): void
    {
        $this->asset();
        $this->asset(['ticker' => 'RENDERUSDT', 'enabled' => 0]);

        $res = $this->getJson('/api/engine/binance/assets?broker=Binance', $this->engineHeaders())->assertOk();

        // What may be traded is unchanged: enabled rows only.
        $this->assertSame(['LTCUSDT'], array_column($res->json('assets'), 'ticker'));
        $this->assertSame(
            [['ticker' => 'RENDERUSDT', 'killed' => false, 'killed_reason' => null]],
            $res->json('disabled'),
        );
    }

    public function test_a_coin_the_kill_switch_turned_off_says_so(): void
    {
        $asset = $this->asset(['kill_switch_losses' => 3]);
        DB::table('assets')->where('asset_id', $asset->asset_id)->update([
            'enabled' => 0,
            'killed_at' => now(),
            'killed_reason' => 'The master lost 3 trades in a row on LTCUSDT.',
        ]);

        $disabled = $this->getJson('/api/engine/binance/assets?broker=Binance', $this->engineHeaders())
            ->assertOk()->json('disabled');

        $this->assertSame([[
            'ticker' => 'LTCUSDT',
            'killed' => true,
            'killed_reason' => 'The master lost 3 trades in a row on LTCUSDT.',
        ]], $disabled);
    }

    public function test_each_venue_lists_only_its_own_switched_off_coins(): void
    {
        $this->asset(['ticker' => 'LTCUSDT', 'enabled' => 0]);
        $this->asset(['ticker' => 'LTC_USDT', 'broker' => 'MEXC', 'enabled' => 0]);

        $binance = $this->getJson('/api/engine/binance/assets?broker=Binance', $this->engineHeaders())->json('disabled');
        $mexc = $this->getJson('/api/engine/mexc/assets?broker=MEXC', $this->engineHeaders())->json('disabled');

        $this->assertSame(['LTCUSDT'], array_column($binance, 'ticker'));
        $this->assertSame(['LTC_USDT'], array_column($mexc, 'ticker'));
    }
}
