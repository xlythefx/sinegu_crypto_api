<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetLossSize;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Loss-streak sizing: the ladder an admin types per asset, and the streak the
 * engine reads per account. The streak rules are the owner's (2026-10-09) —
 * own trades, one coin, long and short together, a stacked close is one
 * trade, any profit after fees is a win — and every case below is one of them.
 */
class LossSizingTest extends PaymentTestCase
{
    private const ENGINE = 'http://127.0.0.1:5010';

    private int $order = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.engine.targets.local' => self::ENGINE,
            'services.engine.webhook_secrets.binance' => 'engine-hook-secret',
            'services.engine.admin_secret' => null,
        ]);
        Http::fake([self::ENGINE.'/*' => Http::response(['success' => true], 200)]);
    }

    private function adminHeaders(): array
    {
        return $this->userHeaders($this->makeUser(['type' => 'admin']));
    }

    private function makeAsset(array $overrides = []): Asset
    {
        return Asset::create(array_merge([
            'ticker' => 'LTCUSDT', 'broker' => 'Binance', 'side' => 'ALL',
            'base_size' => 50, 'max_increments' => 150, 'enabled' => 1,
        ], $overrides));
    }

    /**
     * One closed trade, `$minutesAgo` before now. Newest-first ordering is
     * what the streak reads, so every test states the age explicitly.
     */
    private function close(string $apiKey, ?float $pnl, int $minutesAgo, array $overrides = [], string $table = 'binance_pastpositions'): void
    {
        DB::table($table)->insert(array_merge([
            'api_key' => $apiKey,
            'uni_id' => 'uni-'.$apiKey,
            'symbol' => 'LTCUSDT',
            'position_side' => 'LONG',
            'position_amt' => 14,
            'realized_pnl' => $pnl,
            'side' => 'SELL',
            'order_id' => ++$this->order,
            'closed_at' => now()->subMinutes($minutesAgo),
            'is_sandbox' => 0,
            'created_at' => now(),
        ], $overrides));
    }

    private function streaks(string $symbol = 'LTCUSDT', int $depth = 10, string $exchange = 'binance'): array
    {
        return $this->getJson("/api/engine/{$exchange}/loss-streaks?symbol={$symbol}&depth={$depth}", $this->engineHeaders())
            ->assertOk()
            ->json('streaks');
    }

    private function pings(): int
    {
        $count = 0;
        Http::assertSent(function ($request) use (&$count) {
            if ($request->url() === self::ENGINE.'/admin/refresh-assets') {
                $count++;
            }

            return true;
        });

        return $count;
    }

    // ---- the streak -------------------------------------------------------

    public function test_counts_losses_newest_first_until_the_first_win(): void
    {
        $this->close('acct', -5, 50);   // older loss — behind the win, never counted
        $this->close('acct', 12, 40);   // win
        $this->close('acct', -3, 30);
        $this->close('acct', -1, 20);
        $this->close('acct', -2, 10);

        $this->assertSame(['acct' => 3], $this->streaks());
    }

    public function test_one_win_resets_the_streak_to_zero(): void
    {
        $this->close('acct', -3, 30);
        $this->close('acct', -1, 20);
        $this->close('acct', 0.01, 10);   // a tiny win is still a win

        $this->assertSame(['acct' => 0], $this->streaks());
    }

    public function test_zero_pnl_is_a_loss(): void
    {
        $this->close('acct', 0, 10);

        $this->assertSame(['acct' => 1], $this->streaks());
    }

    public function test_a_stacked_close_is_one_trade(): void
    {
        // Three increments closed in one order = one row = one loss.
        $this->close('acct', -30, 10, ['position_amt' => 42, 'increments_closed' => 3]);

        $this->assertSame(['acct' => 1], $this->streaks());
    }

    public function test_long_and_short_share_one_streak_per_coin(): void
    {
        $this->close('acct', -2, 20, ['position_side' => 'SHORT', 'side' => 'BUY']);
        $this->close('acct', -1, 10, ['position_side' => 'LONG']);

        $this->assertSame(['acct' => 2], $this->streaks());
    }

    public function test_other_coins_and_other_accounts_never_count(): void
    {
        $this->close('acct', -1, 30);
        $this->close('acct', -9, 20, ['symbol' => 'RENDERUSDT']);
        $this->close('other', 5, 10);

        $this->assertSame(['acct' => 1, 'other' => 0], $this->streaks());
        $this->assertSame(['acct' => 1], $this->streaks('RENDERUSDT'));
    }

    public function test_a_close_without_pnl_yet_is_skipped_not_guessed(): void
    {
        $this->close('acct', 4, 30);     // win
        $this->close('acct', -1, 20);
        $this->close('acct', null, 10);  // webhook close, P&L not written yet

        $this->assertSame(['acct' => 1], $this->streaks());
    }

    public function test_sandbox_rows_never_count(): void
    {
        $this->close('acct', -1, 20, ['is_sandbox' => 1]);
        $this->close('acct', -1, 10, ['is_sandbox' => 1]);

        $this->assertSame([], $this->streaks());
    }

    public function test_the_streak_is_capped_at_the_requested_depth(): void
    {
        foreach (range(1, 6) as $i) {
            $this->close('acct', -1, $i * 10);
        }

        $this->assertSame(['acct' => 3], $this->streaks('LTCUSDT', 3));
        $this->assertSame(['acct' => 6], $this->streaks('LTCUSDT', 10));
    }

    public function test_each_exchange_reads_its_own_table(): void
    {
        $this->close('acct', -1, 10);
        $this->close('acct', -1, 10, [], 'mexc_pastpositions');
        $this->close('acct', -1, 5, [], 'mexc_pastpositions');

        $this->assertSame(['acct' => 1], $this->streaks());
        $this->assertSame(['acct' => 2], $this->streaks('LTCUSDT', 10, 'mexc'));
    }

    public function test_no_history_is_an_empty_object_never_a_list(): void
    {
        $body = $this->getJson('/api/engine/binance/loss-streaks?symbol=LTCUSDT', $this->engineHeaders())
            ->assertOk()->getContent();

        $this->assertStringContainsString('"streaks":{}', $body);
    }

    public function test_the_streak_read_needs_the_engine_secret(): void
    {
        $this->getJson('/api/engine/binance/loss-streaks?symbol=LTCUSDT')->assertStatus(401);
    }

    // ---- the ladder -------------------------------------------------------

    public function test_the_tab_saves_a_ladder_and_refreshes_the_engine(): void
    {
        $asset = $this->makeAsset();

        $this->withHeaders($this->adminHeaders())
            ->putJson("/api/admin/assets/{$asset->asset_id}/loss-sizing", [
                'loss_sizing_enabled' => true,
                'loss_sizes' => [['losses' => 3, 'size' => 25], ['losses' => 1, 'size' => 40]],
            ])
            ->assertOk()
            ->assertJsonPath('asset.loss_sizing_enabled', true)
            ->assertJsonPath('asset.loss_sizes', [['losses' => 1, 'size' => 40], ['losses' => 3, 'size' => 25]]);

        $this->assertSame(1, $this->pings());
    }

    public function test_saving_replaces_the_whole_ladder(): void
    {
        $asset = $this->makeAsset();
        AssetLossSize::create(['asset_id' => $asset->asset_id, 'losses' => 2, 'size' => 30]);

        $this->withHeaders($this->adminHeaders())
            ->putJson("/api/admin/assets/{$asset->asset_id}/loss-sizing", [
                'loss_sizing_enabled' => false,
                'loss_sizes' => [],
            ])
            ->assertOk()
            ->assertJsonPath('asset.loss_sizes', []);

        $this->assertSame(0, AssetLossSize::count());
        $this->assertFalse(Asset::find($asset->asset_id)->loss_sizing_enabled);
    }

    public function test_invalid_ladders_are_refused(): void
    {
        $asset = $this->makeAsset();
        $headers = $this->adminHeaders();
        $put = fn (array $sizes) => $this->withHeaders($headers)
            ->putJson("/api/admin/assets/{$asset->asset_id}/loss-sizing", [
                'loss_sizing_enabled' => true, 'loss_sizes' => $sizes,
            ]);

        $put([['losses' => 0, 'size' => 10]])->assertStatus(422);
        $put([['losses' => 11, 'size' => 10]])->assertStatus(422);
        $put([['losses' => 1, 'size' => 0]])->assertStatus(422);
        $put([['losses' => 1, 'size' => 10], ['losses' => 1, 'size' => 20]])->assertStatus(422);

        $this->assertSame(0, AssetLossSize::count());
    }

    public function test_the_asset_form_carries_the_ladder(): void
    {
        $created = $this->withHeaders($this->adminHeaders())->postJson('/api/admin/assets', [
            'ticker' => 'RENDERUSDT', 'broker' => 'Binance', 'side' => 'ALL',
            'base_size' => 10, 'max_increments' => 30, 'enabled' => true,
            'loss_sizing_enabled' => true,
            'loss_sizes' => [['losses' => 1, 'size' => 8]],
        ])->assertStatus(201);

        $created->assertJsonPath('asset.loss_sizes', [['losses' => 1, 'size' => 8]]);
        $this->assertSame(1, $this->pings());
    }

    /** The card's on/off toggle re-posts the asset fields only. */
    public function test_an_asset_update_without_ladder_fields_keeps_the_ladder(): void
    {
        $asset = $this->makeAsset(['loss_sizing_enabled' => true]);
        AssetLossSize::create(['asset_id' => $asset->asset_id, 'losses' => 1, 'size' => 40]);

        $this->withHeaders($this->adminHeaders())
            ->putJson("/api/admin/assets/{$asset->asset_id}", [
                'ticker' => 'LTCUSDT', 'broker' => 'Binance', 'side' => 'ALL',
                'base_size' => 50, 'max_increments' => 150, 'enabled' => false,
            ])
            ->assertOk()
            ->assertJsonPath('asset.loss_sizing_enabled', true)
            ->assertJsonPath('asset.loss_sizes', [['losses' => 1, 'size' => 40]]);
    }

    public function test_deleting_an_asset_deletes_its_ladder(): void
    {
        $asset = $this->makeAsset();
        AssetLossSize::create(['asset_id' => $asset->asset_id, 'losses' => 1, 'size' => 40]);

        $this->withHeaders($this->adminHeaders())->deleteJson("/api/admin/assets/{$asset->asset_id}")->assertOk();

        $this->assertSame(0, AssetLossSize::count());
    }

    public function test_the_engine_asset_list_carries_the_ladder(): void
    {
        $asset = $this->makeAsset(['loss_sizing_enabled' => true]);
        AssetLossSize::create(['asset_id' => $asset->asset_id, 'losses' => 3, 'size' => 25]);
        AssetLossSize::create(['asset_id' => $asset->asset_id, 'losses' => 1, 'size' => 40]);
        $this->makeAsset(['ticker' => 'BTCUSDT', 'base_size' => 0.004, 'max_increments' => 0.012]);

        $assets = collect($this->getJson('/api/engine/binance/assets?broker=Binance', $this->engineHeaders())
            ->assertOk()->json('assets'))->keyBy('ticker');

        $this->assertTrue($assets['LTCUSDT']['loss_sizing_enabled']);
        $this->assertSame([['losses' => 1, 'size' => 40], ['losses' => 3, 'size' => 25]], $assets['LTCUSDT']['loss_sizes']);
        $this->assertFalse($assets['BTCUSDT']['loss_sizing_enabled']);
        $this->assertSame([], $assets['BTCUSDT']['loss_sizes']);
    }

    public function test_the_trader_catalog_never_shows_the_ladder(): void
    {
        $asset = $this->makeAsset(['loss_sizing_enabled' => true]);
        AssetLossSize::create(['asset_id' => $asset->asset_id, 'losses' => 1, 'size' => 40]);

        $body = $this->withHeaders($this->userHeaders($this->makeUser()))
            ->getJson('/api/assets')->assertOk()->getContent();

        $this->assertStringNotContainsString('loss_siz', $body);
    }

    public function test_the_ladder_is_admin_only(): void
    {
        $asset = $this->makeAsset();
        $trader = $this->userHeaders($this->makeUser());

        $this->withHeaders($trader)
            ->putJson("/api/admin/assets/{$asset->asset_id}/loss-sizing", ['loss_sizing_enabled' => true])
            ->assertStatus(403);
        $this->withHeaders($trader)
            ->getJson("/api/admin/assets/{$asset->asset_id}/loss-streaks")
            ->assertStatus(403);
    }

    // ---- "right now" ------------------------------------------------------

    public function test_right_now_buckets_every_traded_account_by_streak(): void
    {
        $asset = $this->makeAsset(['loss_sizing_enabled' => true]);
        AssetLossSize::create(['asset_id' => $asset->asset_id, 'losses' => 1, 'size' => 40]);
        AssetLossSize::create(['asset_id' => $asset->asset_id, 'losses' => 3, 'size' => 25]);

        $user = $this->makeUser();
        $this->makeAccount($user, ['api_key' => 'fresh']);       // no history → normal
        $this->makeAccount($this->makeUser(), ['api_key' => 'one']);
        $this->makeAccount($this->makeUser(), ['api_key' => 'four']);
        $this->makeAccount($this->makeUser(), ['api_key' => 'off', 'enabled' => 0]);   // not traded
        $this->close('one', -1, 10);
        foreach (range(1, 4) as $i) {
            $this->close('four', -1, $i * 10);
        }
        $this->close('off', -1, 10);

        $this->withHeaders($this->adminHeaders())
            ->getJson("/api/admin/assets/{$asset->asset_id}/loss-streaks")
            ->assertOk()
            ->assertJsonPath('exchange', 'binance')
            ->assertJsonPath('depth', 3)
            ->assertJsonPath('accounts', 3)
            ->assertJsonPath('counts', [
                ['streak' => 0, 'accounts' => 1],
                ['streak' => 1, 'accounts' => 1],
                ['streak' => 2, 'accounts' => 0],
                ['streak' => 3, 'accounts' => 1],   // 4 losses → "3 or more"
            ]);
    }
}
