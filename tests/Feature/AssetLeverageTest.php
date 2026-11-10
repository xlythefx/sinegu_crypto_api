<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetChange;
use Illuminate\Support\Facades\Http;

/**
 * Leverage per coin (2026-11-10). When set, the engine asks for it on every
 * entry instead of the alert's leverage; NULL = the alert's (25x by default).
 * Prompted by Binance refusing 25x on SANTOSUSDT, which failed every entry.
 */
class AssetLeverageTest extends PaymentTestCase
{
    private const ENGINE = 'http://127.0.0.1:5010';

    private string $adminUniId;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.engine.targets.local' => self::ENGINE,
            'services.engine.webhook_secrets.binance' => 'engine-hook-secret',
            'services.engine.admin_secret' => null,
        ]);
        Http::fake([self::ENGINE.'/*' => Http::response(['success' => true], 200)]);
        $this->adminUniId = $this->makeUser(['type' => 'admin', 'name' => 'Ops Admin']);
    }

    private function asset(array $overrides = []): Asset
    {
        return Asset::create(array_merge([
            'ticker' => 'SANTOSUSDT', 'broker' => 'Binance', 'side' => 'ALL',
            'base_size' => 150, 'max_increments' => 450, 'enabled' => 1,
        ], $overrides));
    }

    /** The asset form (or the card's on/off switch, which sends no leverage). */
    private function save(Asset $asset, array $fields): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders($this->userHeaders($this->adminUniId))
            ->putJson("/api/admin/assets/{$asset->asset_id}", array_merge([
                'ticker' => $asset->ticker, 'broker' => $asset->broker, 'side' => $asset->side,
                'enabled' => $asset->enabled ? 1 : 0,
            ], $fields));
    }

    public function test_a_leverage_is_saved_shown_and_recorded_in_the_history(): void
    {
        $asset = $this->asset();

        $this->save($asset, ['leverage' => 10])->assertOk()->assertJsonPath('asset.leverage', 10);

        $this->assertSame(10, $asset->fresh()->leverage);
        $change = AssetChange::where('asset_id', $asset->asset_id)->latest('id')->first();
        $this->assertSame(['from' => null, 'to' => 10], $change->changes['leverage']);
    }

    public function test_absent_means_unchanged_and_empty_means_not_set(): void
    {
        $asset = $this->asset(['leverage' => 10]);

        // The card's on/off switch posts no leverage: it must not clear it.
        $this->save($asset, ['enabled' => 0])->assertOk();
        $this->assertSame(10, $asset->fresh()->leverage);

        // The form's emptied field: back to the alert's leverage.
        $this->save($asset->fresh(), ['leverage' => ''])->assertOk()->assertJsonPath('asset.leverage', null);
        $this->assertNull($asset->fresh()->leverage);
    }

    public function test_a_leverage_outside_1_to_125_is_refused(): void
    {
        $asset = $this->asset();

        $this->save($asset, ['leverage' => 0])->assertStatus(422)->assertJsonValidationErrors('leverage');
        $this->save($asset, ['leverage' => 126])->assertStatus(422)->assertJsonValidationErrors('leverage');
        $this->save($asset, ['leverage' => 2.5])->assertStatus(422)->assertJsonValidationErrors('leverage');
        $this->assertNull($asset->fresh()->leverage);
    }

    public function test_a_new_coin_can_be_created_with_a_leverage(): void
    {
        $this->withHeaders($this->userHeaders($this->adminUniId))->postJson('/api/admin/assets', [
            'ticker' => 'QNTUSDT', 'broker' => 'Binance', 'side' => 'ALL',
            'base_size' => 1, 'max_increments' => 3, 'enabled' => 1, 'leverage' => 20,
        ])->assertCreated()->assertJsonPath('asset.leverage', 20);
    }

    public function test_the_engine_reads_each_coins_leverage(): void
    {
        $this->asset(['leverage' => 10]);
        $this->asset(['ticker' => 'LTCUSDT']);

        $assets = collect($this->getJson('/api/engine/binance/assets?broker=Binance', $this->engineHeaders())
            ->assertOk()->json('assets'))->keyBy('ticker');

        $this->assertSame(10, $assets['SANTOSUSDT']['leverage']);
        $this->assertNull($assets['LTCUSDT']['leverage']);
    }
}
