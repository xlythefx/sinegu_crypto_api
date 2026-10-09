<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetStreakSize;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Streak sizing: the ladder an admin types per asset (steps after N losses OR
 * N wins in a row), and the run the engine reads per account. The run rules
 * are the owner's (2026-10-09) — own trades, one coin, long and short
 * together, a stacked close is one trade, any profit after fees is a win —
 * and every case below is one of them.
 */
class StreakSizingTest extends PaymentTestCase
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

    private function step(Asset $asset, string $kind, int $streak, float $size): void
    {
        AssetStreakSize::create(['asset_id' => $asset->asset_id, 'kind' => $kind, 'streak' => $streak, 'size' => $size]);
    }

    /** One closed trade, `$minutesAgo` before now — the run reads newest first. */
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

    private function runs(string $symbol = 'LTCUSDT', int $depth = 10, string $exchange = 'binance'): array
    {
        return $this->getJson("/api/engine/{$exchange}/streaks?symbol={$symbol}&depth={$depth}", $this->engineHeaders())
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

    // ---- the run -----------------------------------------------------------

    public function test_a_losing_run_is_negative_and_stops_at_the_first_win(): void
    {
        $this->close('acct', -5, 50);   // behind the win — never counted
        $this->close('acct', 12, 40);
        $this->close('acct', -3, 30);
        $this->close('acct', -1, 20);
        $this->close('acct', -2, 10);

        $this->assertSame(['acct' => -3], $this->runs());
    }

    public function test_a_winning_run_is_positive_and_stops_at_the_first_loss(): void
    {
        $this->close('acct', 8, 40);
        $this->close('acct', -1, 30);   // ends the run
        $this->close('acct', 2, 20);
        $this->close('acct', 0.01, 10); // a tiny win is still a win

        $this->assertSame(['acct' => 2], $this->runs());
    }

    public function test_one_win_after_losses_starts_a_winning_run(): void
    {
        $this->close('acct', -3, 30);
        $this->close('acct', -1, 20);
        $this->close('acct', 4, 10);

        $this->assertSame(['acct' => 1], $this->runs());
    }

    public function test_zero_pnl_is_a_loss(): void
    {
        $this->close('acct', 0, 10);

        $this->assertSame(['acct' => -1], $this->runs());
    }

    public function test_a_stacked_close_is_one_trade(): void
    {
        $this->close('acct', -30, 10, ['position_amt' => 42, 'increments_closed' => 3]);

        $this->assertSame(['acct' => -1], $this->runs());
    }

    public function test_long_and_short_share_one_run_per_coin(): void
    {
        $this->close('acct', 3, 20, ['position_side' => 'SHORT', 'side' => 'BUY']);
        $this->close('acct', 1, 10, ['position_side' => 'LONG']);

        $this->assertSame(['acct' => 2], $this->runs());
    }

    public function test_other_coins_and_other_accounts_never_count(): void
    {
        $this->close('acct', -1, 30);
        $this->close('acct', 9, 20, ['symbol' => 'RENDERUSDT']);
        $this->close('other', 5, 10);

        $this->assertSame(['acct' => -1, 'other' => 1], $this->runs());
        $this->assertSame(['acct' => 1], $this->runs('RENDERUSDT'));
    }

    public function test_a_close_without_pnl_yet_is_skipped_not_guessed(): void
    {
        $this->close('acct', 4, 30);
        $this->close('acct', -1, 20);
        $this->close('acct', null, 10);

        $this->assertSame(['acct' => -1], $this->runs());
    }

    public function test_sandbox_rows_never_count(): void
    {
        $this->close('acct', -1, 20, ['is_sandbox' => 1]);

        $this->assertSame([], $this->runs());
    }

    public function test_the_run_is_capped_at_the_requested_depth(): void
    {
        foreach (range(1, 6) as $i) {
            $this->close('acct', 2, $i * 10);
        }

        $this->assertSame(['acct' => 3], $this->runs('LTCUSDT', 3));
        $this->assertSame(['acct' => 6], $this->runs('LTCUSDT', 10));
    }

    public function test_each_exchange_reads_its_own_table(): void
    {
        $this->close('acct', -1, 10);
        $this->close('acct', 1, 10, [], 'mexc_pastpositions');
        $this->close('acct', 1, 5, [], 'mexc_pastpositions');

        $this->assertSame(['acct' => -1], $this->runs());
        $this->assertSame(['acct' => 2], $this->runs('LTCUSDT', 10, 'mexc'));
    }

    public function test_no_history_is_an_empty_object_never_a_list(): void
    {
        $body = $this->getJson('/api/engine/binance/streaks?symbol=LTCUSDT', $this->engineHeaders())
            ->assertOk()->getContent();

        $this->assertStringContainsString('"streaks":{}', $body);
    }

    public function test_the_run_read_needs_the_engine_secret(): void
    {
        $this->getJson('/api/engine/binance/streaks?symbol=LTCUSDT')->assertStatus(401);
    }

    // ---- the ladder --------------------------------------------------------

    public function test_the_tab_saves_loss_and_win_steps_and_refreshes_the_engine(): void
    {
        $asset = $this->makeAsset();

        $this->withHeaders($this->adminHeaders())
            ->putJson("/api/admin/assets/{$asset->asset_id}/streak-sizing", [
                'streak_sizing_enabled' => true,
                'streak_sizes' => [
                    ['kind' => 'win', 'streak' => 3, 'size' => 70],
                    ['kind' => 'loss', 'streak' => 5, 'size' => 20],
                    ['kind' => 'loss', 'streak' => 2, 'size' => 30],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('asset.streak_sizing_enabled', true)
            ->assertJsonPath('asset.streak_sizes', [
                ['kind' => 'loss', 'streak' => 2, 'size' => 30],
                ['kind' => 'loss', 'streak' => 5, 'size' => 20],
                ['kind' => 'win', 'streak' => 3, 'size' => 70],
            ]);

        $this->assertSame(1, $this->pings());
    }

    public function test_the_same_count_may_exist_once_per_kind(): void
    {
        $asset = $this->makeAsset();

        $this->withHeaders($this->adminHeaders())
            ->putJson("/api/admin/assets/{$asset->asset_id}/streak-sizing", [
                'streak_sizing_enabled' => true,
                'streak_sizes' => [['kind' => 'loss', 'streak' => 2, 'size' => 30], ['kind' => 'win', 'streak' => 2, 'size' => 60]],
            ])
            ->assertOk();

        $this->assertSame(2, AssetStreakSize::count());
    }

    public function test_saving_replaces_the_whole_ladder(): void
    {
        $asset = $this->makeAsset();
        $this->step($asset, 'loss', 2, 30);

        $this->withHeaders($this->adminHeaders())
            ->putJson("/api/admin/assets/{$asset->asset_id}/streak-sizing", [
                'streak_sizing_enabled' => false,
                'streak_sizes' => [],
            ])
            ->assertOk()
            ->assertJsonPath('asset.streak_sizes', []);

        $this->assertSame(0, AssetStreakSize::count());
        $this->assertFalse(Asset::find($asset->asset_id)->streak_sizing_enabled);
    }

    public function test_invalid_ladders_are_refused(): void
    {
        $asset = $this->makeAsset();
        $headers = $this->adminHeaders();
        $put = fn (array $sizes) => $this->withHeaders($headers)
            ->putJson("/api/admin/assets/{$asset->asset_id}/streak-sizing", [
                'streak_sizing_enabled' => true, 'streak_sizes' => $sizes,
            ]);

        $put([['kind' => 'loss', 'streak' => 0, 'size' => 10]])->assertStatus(422);
        $put([['kind' => 'loss', 'streak' => 11, 'size' => 10]])->assertStatus(422);
        $put([['kind' => 'loss', 'streak' => 1, 'size' => 0]])->assertStatus(422);
        $put([['kind' => 'draw', 'streak' => 1, 'size' => 10]])->assertStatus(422);
        $put([['kind' => 'win', 'streak' => 1, 'size' => 10], ['kind' => 'win', 'streak' => 1, 'size' => 20]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('streak_sizes.1.streak');

        $this->assertSame(0, AssetStreakSize::count());
    }

    public function test_the_asset_form_carries_the_ladder(): void
    {
        $this->withHeaders($this->adminHeaders())->postJson('/api/admin/assets', [
            'ticker' => 'RENDERUSDT', 'broker' => 'Binance', 'side' => 'ALL',
            'base_size' => 10, 'max_increments' => 30, 'enabled' => true,
            'streak_sizing_enabled' => true,
            'streak_sizes' => [['kind' => 'loss', 'streak' => 1, 'size' => 8], ['kind' => 'win', 'streak' => 2, 'size' => 12]],
        ])
            ->assertStatus(201)
            ->assertJsonPath('asset.streak_sizes', [
                ['kind' => 'loss', 'streak' => 1, 'size' => 8],
                ['kind' => 'win', 'streak' => 2, 'size' => 12],
            ]);

        $this->assertSame(1, $this->pings());
    }

    public function test_a_duplicate_step_on_the_form_creates_nothing(): void
    {
        $this->withHeaders($this->adminHeaders())->postJson('/api/admin/assets', [
            'ticker' => 'RENDERUSDT', 'broker' => 'Binance', 'side' => 'ALL',
            'base_size' => 10, 'max_increments' => 30, 'enabled' => true,
            'streak_sizing_enabled' => true,
            'streak_sizes' => [['kind' => 'loss', 'streak' => 1, 'size' => 8], ['kind' => 'loss', 'streak' => 1, 'size' => 6]],
        ])->assertStatus(422);

        $this->assertSame(0, Asset::count());
    }

    /** The card's on/off toggle re-posts the asset fields only. */
    public function test_an_asset_update_without_ladder_fields_keeps_the_ladder(): void
    {
        $asset = $this->makeAsset(['streak_sizing_enabled' => true]);
        $this->step($asset, 'loss', 1, 40);

        $this->withHeaders($this->adminHeaders())
            ->putJson("/api/admin/assets/{$asset->asset_id}", [
                'ticker' => 'LTCUSDT', 'broker' => 'Binance', 'side' => 'ALL',
                'base_size' => 50, 'max_increments' => 150, 'enabled' => false,
            ])
            ->assertOk()
            ->assertJsonPath('asset.streak_sizing_enabled', true)
            ->assertJsonPath('asset.streak_sizes', [['kind' => 'loss', 'streak' => 1, 'size' => 40]]);
    }

    public function test_deleting_an_asset_deletes_its_ladder(): void
    {
        $asset = $this->makeAsset();
        $this->step($asset, 'win', 1, 60);

        $this->withHeaders($this->adminHeaders())->deleteJson("/api/admin/assets/{$asset->asset_id}")->assertOk();

        $this->assertSame(0, AssetStreakSize::count());
    }

    public function test_the_engine_asset_list_carries_the_ladder(): void
    {
        $asset = $this->makeAsset(['streak_sizing_enabled' => true]);
        $this->step($asset, 'win', 3, 70);
        $this->step($asset, 'loss', 1, 40);
        $this->makeAsset(['ticker' => 'BTCUSDT', 'base_size' => 0.004, 'max_increments' => 0.012]);

        $assets = collect($this->getJson('/api/engine/binance/assets?broker=Binance', $this->engineHeaders())
            ->assertOk()->json('assets'))->keyBy('ticker');

        $this->assertTrue($assets['LTCUSDT']['streak_sizing_enabled']);
        $this->assertSame([
            ['kind' => 'loss', 'streak' => 1, 'size' => 40],
            ['kind' => 'win', 'streak' => 3, 'size' => 70],
        ], $assets['LTCUSDT']['streak_sizes']);
        $this->assertFalse($assets['BTCUSDT']['streak_sizing_enabled']);
        $this->assertSame([], $assets['BTCUSDT']['streak_sizes']);
    }

    public function test_the_trader_catalog_never_shows_the_ladder(): void
    {
        $asset = $this->makeAsset(['streak_sizing_enabled' => true]);
        $this->step($asset, 'loss', 1, 40);

        $body = $this->withHeaders($this->userHeaders($this->makeUser()))
            ->getJson('/api/assets')->assertOk()->getContent();

        $this->assertStringNotContainsString('streak', $body);
    }

    public function test_the_ladder_is_admin_only(): void
    {
        $asset = $this->makeAsset();
        $trader = $this->userHeaders($this->makeUser());

        $this->withHeaders($trader)
            ->putJson("/api/admin/assets/{$asset->asset_id}/streak-sizing", ['streak_sizing_enabled' => true])
            ->assertStatus(403);
        $this->withHeaders($trader)
            ->getJson("/api/admin/assets/{$asset->asset_id}/streaks")
            ->assertStatus(403);
    }

    // ---- "right now" -------------------------------------------------------

    public function test_right_now_buckets_every_traded_account_by_signed_run(): void
    {
        $asset = $this->makeAsset(['streak_sizing_enabled' => true]);
        $this->step($asset, 'loss', 3, 25);
        $this->step($asset, 'win', 2, 70);

        $this->makeAccount($this->makeUser(), ['api_key' => 'fresh']);     // no history → 0
        $this->makeAccount($this->makeUser(), ['api_key' => 'won']);
        $this->makeAccount($this->makeUser(), ['api_key' => 'lost4']);
        $this->makeAccount($this->makeUser(), ['api_key' => 'off', 'enabled' => 0]);   // not traded
        $this->close('won', 3, 10);
        foreach (range(1, 4) as $i) {
            $this->close('lost4', -1, $i * 10);
        }
        $this->close('off', -1, 10);

        $this->withHeaders($this->adminHeaders())
            ->getJson("/api/admin/assets/{$asset->asset_id}/streaks")
            ->assertOk()
            ->assertJsonPath('exchange', 'binance')
            ->assertJsonPath('depth', 3)
            ->assertJsonPath('accounts', 3)
            ->assertJsonPath('runs', [
                ['run' => -3, 'accounts' => 1],   // 4 losses → "3 or more"
                ['run' => 0, 'accounts' => 1],
                ['run' => 1, 'accounts' => 1],
            ]);
    }
}
