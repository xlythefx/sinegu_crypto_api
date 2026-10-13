<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetChange;
use App\Services\Assets\KillSwitch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Size changes wait for the open position to close, and every asset change
 * is written to the history (owner, 2026-10-14).
 *
 * Changing the size mid-stack makes the engine re-measure the open stack in
 * the new entry size and allow more entries than the cap intends, so a sizing
 * change saved while the MAIN account holds the coin waits for the close.
 */
class AssetPendingSizingTest extends PaymentTestCase
{
    private const ENGINE = 'http://127.0.0.1:5010';

    private string $masterKey = 'master-key';

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

        $master = $this->makeUser(['type' => 'master']);
        $this->makeAccount($master, ['api_key' => $this->masterKey]);
        $this->adminUniId = $this->makeUser(['type' => 'admin', 'name' => 'Ops Admin']);
    }

    private function admin(): array
    {
        return $this->userHeaders($this->adminUniId);
    }

    private function asset(array $overrides = []): Asset
    {
        return Asset::create(array_merge([
            'ticker' => 'LTCUSDT', 'broker' => 'Binance', 'side' => 'ALL',
            'base_size' => 5, 'max_increments' => 15, 'enabled' => 1,
        ], $overrides));
    }

    private function open(string $apiKey, float $amount, string $symbol = 'LTCUSDT', string $table = 'binance_positions'): void
    {
        DB::table($table)->insert([
            'api_key' => $apiKey, 'uni_id' => 'uni-'.$apiKey, 'symbol' => $symbol,
            'position_side' => $amount < 0 ? 'SHORT' : 'LONG', 'position_amt' => $amount,
            'created_at' => now(),
        ]);
    }

    /** The asset form as the admin screen posts it. */
    private function save(Asset $asset, array $fields): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders($this->admin())->putJson("/api/admin/assets/{$asset->asset_id}", array_merge([
            'ticker' => $asset->ticker, 'broker' => $asset->broker, 'side' => $asset->side,
            'enabled' => $asset->enabled ? 1 : 0,
        ], $fields));
    }

    private function events(Asset $asset): array
    {
        return AssetChange::where('asset_id', $asset->asset_id)->orderBy('id')->pluck('event')->all();
    }

    // ---- waiting -----------------------------------------------------------

    public function test_with_nothing_open_a_size_change_applies_at_once(): void
    {
        $asset = $this->asset();

        $this->save($asset, ['base_size' => 10, 'max_increments' => 30])
            ->assertOk()->assertJsonPath('sizing', 'applied')
            ->assertJsonPath('asset.pending_sizing', null);

        $asset->refresh();
        $this->assertSame(10.0, $asset->base_size);
        $this->assertSame(30.0, $asset->max_increments);
        $change = AssetChange::where('asset_id', $asset->asset_id)->sole();
        $this->assertSame('updated', $change->event);
        $this->assertSame(['from' => 5, 'to' => 10], $change->changes['base_size']);
        $this->assertSame('Ops Admin', $change->actor_name);
    }

    public function test_while_the_main_account_holds_the_coin_the_change_waits(): void
    {
        $asset = $this->asset();
        $this->open($this->masterKey, -15);

        $this->save($asset, ['base_size' => 10, 'max_increments' => 30])
            ->assertOk()
            ->assertJsonPath('sizing', 'waiting')
            ->assertJsonPath('asset.base_size', 5)
            ->assertJsonPath('asset.pending_sizing.base_size', 10)
            ->assertJsonPath('asset.pending_sizing.max_increments', 30)
            ->assertJsonPath('asset.pending_sizing.by_name', 'Ops Admin')
            ->assertJsonPath('asset.pending_sizing.waits_for.who', 'main')
            ->assertJsonPath('asset.pending_sizing.waits_for.side', 'SHORT');

        // The engine keeps trading the live size until the close.
        $this->getJson('/api/engine/binance/assets', $this->engineHeaders())
            ->assertOk()->assertJsonPath('assets.0.base_size', 5);
        $this->assertSame(['waiting'], $this->events($asset));
    }

    public function test_the_change_goes_live_when_the_exit_write_through_closes_the_position(): void
    {
        $asset = $this->asset();
        $this->open($this->masterKey, 15);
        $this->save($asset, ['base_size' => 10, 'max_increments' => 30])->assertJsonPath('sizing', 'waiting');

        $this->postJson('/api/engine/binance/positions/upsert', [
            'api_key' => $this->masterKey, 'symbol' => 'LTCUSDT', 'position_side' => 'LONG', 'position_amt' => 0,
        ], $this->engineHeaders())->assertOk()->assertJsonPath('result', 'deleted');

        $asset->refresh();
        $this->assertSame(10.0, $asset->base_size);
        $this->assertNull($asset->pending_sizing);
        $applied = AssetChange::where('asset_id', $asset->asset_id)->where('event', 'applied')->sole();
        $this->assertNull($applied->actor_uni_id);
        $this->assertSame('Automatic', $applied->actor_name);
        $this->assertSame(['from' => 5, 'to' => 10], $applied->changes['base_size']);
    }

    public function test_the_scheduler_puts_it_live_once_the_position_is_gone(): void
    {
        $asset = $this->asset();
        $this->open($this->masterKey, 15);
        $this->save($asset, ['base_size' => 10, 'max_increments' => 30]);

        $this->artisan('assets:apply-pending')->assertSuccessful();
        $this->assertSame(5.0, $asset->fresh()->base_size, 'still open — still waiting');

        DB::table('binance_positions')->where('api_key', $this->masterKey)->delete();
        $this->artisan('assets:apply-pending')->assertSuccessful();
        $this->assertSame(10.0, $asset->fresh()->base_size);
        $this->assertNull($asset->fresh()->pending_sizing);
    }

    public function test_a_customer_position_does_not_hold_it_when_the_exchange_has_a_main_account(): void
    {
        $asset = $this->asset();
        $this->open('customer-key', 15);

        $this->save($asset, ['base_size' => 10, 'max_increments' => 30])->assertJsonPath('sizing', 'applied');
    }

    public function test_on_an_exchange_with_no_main_account_any_open_position_holds_it(): void
    {
        $asset = $this->asset(['broker' => 'MEXC']);
        $this->open('mexc-customer', 15, 'LTCUSDT', 'mexc_positions');

        $this->save($asset, ['base_size' => 10, 'max_increments' => 30])
            ->assertJsonPath('sizing', 'waiting')
            ->assertJsonPath('asset.pending_sizing.waits_for.who', 'accounts')
            ->assertJsonPath('asset.pending_sizing.waits_for.accounts', 1);
    }

    public function test_the_on_off_switch_applies_at_once_and_never_touches_a_waiting_change(): void
    {
        $asset = $this->asset();
        $this->open($this->masterKey, 15);
        $this->save($asset, ['base_size' => 10, 'max_increments' => 30]);

        // What the card's switch posts: no sizing at all.
        $this->save($asset->fresh(), ['enabled' => 0])->assertOk()->assertJsonPath('sizing', 'unchanged');

        $asset->refresh();
        $this->assertFalse($asset->enabled);
        $this->assertSame(5.0, $asset->base_size);
        $this->assertSame(10.0, (float) $asset->pending_sizing['base_size']);
    }

    public function test_re_saving_the_form_with_the_waiting_values_keeps_it_waiting(): void
    {
        $asset = $this->asset();
        $this->open($this->masterKey, 15);
        $this->save($asset, ['base_size' => 10, 'max_increments' => 30]);

        $this->save($asset->fresh(), ['base_size' => 10, 'max_increments' => 30, 'side' => 'LONG'])
            ->assertJsonPath('sizing', 'unchanged');

        $asset->refresh();
        $this->assertSame('LONG', $asset->side);
        $this->assertSame(10.0, (float) $asset->pending_sizing['base_size']);
        $this->assertSame(['waiting', 'updated'], $this->events($asset));
    }

    public function test_saving_the_live_sizes_back_drops_the_waiting_change(): void
    {
        $asset = $this->asset();
        $this->open($this->masterKey, 15);
        $this->save($asset, ['base_size' => 10, 'max_increments' => 30]);

        $this->save($asset->fresh(), ['base_size' => 5, 'max_increments' => 15])
            ->assertJsonPath('sizing', 'cancelled')
            ->assertJsonPath('asset.pending_sizing', null);
        $this->assertSame(['waiting', 'cancelled'], $this->events($asset));
    }

    public function test_apply_now_and_cancel(): void
    {
        $asset = $this->asset();
        $this->open($this->masterKey, 15);
        $headers = $this->admin();

        $this->withHeaders($headers)->postJson("/api/admin/assets/{$asset->asset_id}/pending-sizing/apply")
            ->assertStatus(422)->assertJsonPath('error_code', 'NOTHING_WAITING');

        $this->save($asset, ['base_size' => 10, 'max_increments' => 30]);
        $this->withHeaders($headers)->postJson("/api/admin/assets/{$asset->asset_id}/pending-sizing/apply")
            ->assertOk()->assertJsonPath('asset.base_size', 10)->assertJsonPath('asset.pending_sizing', null);

        $this->save($asset->fresh(), ['base_size' => 20, 'max_increments' => 60]);
        $this->withHeaders($headers)->deleteJson("/api/admin/assets/{$asset->asset_id}/pending-sizing")
            ->assertOk()->assertJsonPath('asset.base_size', 10)->assertJsonPath('asset.pending_sizing', null);

        $this->assertSame(['waiting', 'applied', 'waiting', 'cancelled'], $this->events($asset));
        $this->assertSame('Ops Admin', AssetChange::where('event', 'applied')->sole()->actor_name);
    }

    public function test_a_max_position_smaller_than_one_entry_is_refused_and_nothing_changes(): void
    {
        $asset = $this->asset();

        $this->save($asset, ['base_size' => 180, 'max_increments' => 60])
            ->assertStatus(422)->assertJsonValidationErrors('max_increments');

        $this->assertSame(5.0, $asset->fresh()->base_size);
        $this->assertSame([], $this->events($asset));
    }

    public function test_the_streak_tab_ladder_waits_but_its_kill_switch_applies_at_once(): void
    {
        $asset = $this->asset();
        $this->open($this->masterKey, 15);

        $this->withHeaders($this->admin())->putJson("/api/admin/assets/{$asset->asset_id}/streak-sizing", [
            'streak_sizing_enabled' => true,
            'streak_sizes' => [['kind' => 'loss', 'streak' => 1, 'size' => 3]],
            'kill_switch_losses' => 3,
        ])->assertOk()->assertJsonPath('sizing', 'waiting')
            ->assertJsonPath('asset.streak_sizes', [])
            ->assertJsonPath('asset.pending_sizing.streak_sizes.0.size', 3);

        $asset->refresh();
        $this->assertSame(3, $asset->kill_switch_losses);
        $this->assertFalse($asset->streak_sizing_enabled);
        $this->assertSame(['waiting', 'updated'], $this->events($asset));
    }

    // ---- history -----------------------------------------------------------

    public function test_the_history_lists_every_change_newest_first(): void
    {
        $headers = $this->admin();
        $id = $this->withHeaders($headers)->postJson('/api/admin/assets', [
            'ticker' => 'renderusdt', 'broker' => 'Binance', 'side' => 'ALL',
            'base_size' => 60, 'max_increments' => 180, 'enabled' => 1,
        ])->assertCreated()->json('asset.asset_id');
        $asset = Asset::findOrFail($id);
        $this->save($asset, ['base_size' => 120, 'max_increments' => 360]);
        $this->save($asset->fresh(), ['enabled' => 0]);

        $changes = $this->withHeaders($headers)->getJson("/api/admin/assets/history?asset_id={$id}")
            ->assertOk()->json('changes');

        $this->assertSame(['updated', 'updated', 'created'], array_column($changes, 'event'));
        $this->assertSame(['from' => true, 'to' => false], $changes[0]['changes']['enabled']);
        $this->assertSame(['from' => 60, 'to' => 120], $changes[1]['changes']['base_size']);
        $this->assertSame('RENDERUSDT', $changes[2]['ticker']);
        $this->assertSame('Ops Admin', $changes[2]['actor_name']);
        $this->assertFalse($changes[2]['is_system']);
    }

    public function test_the_kill_switch_is_recorded_as_a_system_change(): void
    {
        $asset = $this->asset(['kill_switch_losses' => 2]);

        app(KillSwitch::class)->trip($asset, 2, 'binance');

        $change = AssetChange::where('asset_id', $asset->asset_id)->sole();
        $this->assertSame('kill_switch', $change->event);
        $this->assertSame('Kill switch', $change->actor_name);
        $this->assertNull($change->actor_uni_id);
        $this->assertSame(['from' => true, 'to' => false], $change->changes['enabled']);
    }

    public function test_deleting_an_asset_keeps_its_history(): void
    {
        $asset = $this->asset();
        $this->withHeaders($this->admin())->deleteJson("/api/admin/assets/{$asset->asset_id}")->assertOk();

        $change = AssetChange::where('asset_id', $asset->asset_id)->sole();
        $this->assertSame('deleted', $change->event);
        $this->assertSame('LTCUSDT', $change->ticker);
    }

    public function test_the_history_is_admin_only(): void
    {
        $trader = $this->userHeaders($this->makeUser());
        $this->withHeaders($trader)->getJson('/api/admin/assets/history')->assertForbidden();
    }

    public function test_the_admin_listing_carries_the_main_accounts_balance_and_the_last_price(): void
    {
        $asset = $this->asset();
        DB::table('trade_logs')->insert([
            'exchange' => 'binance', 'action' => 'BUY', 'ticker' => 'LTCUSDT', 'price' => 61.5,
            'ts' => now(), 'created_at' => now(),
        ]);

        $this->withHeaders($this->admin())->getJson('/api/admin/assets')
            ->assertOk()
            ->assertJsonPath('assets.0.main_account.balance', 1000)
            ->assertJsonPath('assets.0.main_account.price', 61.5);
    }
}
