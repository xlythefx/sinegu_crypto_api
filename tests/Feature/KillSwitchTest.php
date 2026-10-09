<?php

namespace Tests\Feature;

use App\Mail\AssetKillSwitchNotice;
use App\Models\Asset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * The per-asset kill switch: the MASTER's losing run on a coin switches the
 * coin off for everyone, announces it (team email, admin Telegram via the
 * engine, Overview strip), and stays off until an admin turns it back on —
 * which re-arms it from that moment.
 */
class KillSwitchTest extends PaymentTestCase
{
    private const ENGINE = 'http://127.0.0.1:5010';

    private int $order = 0;

    private string $masterKey = 'master-key';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.engine.targets.local' => self::ENGINE,
            'services.engine.webhook_secrets.binance' => 'engine-hook-secret',
            'services.engine.admin_secret' => null,
            'mail.admin_address' => 'support@pixel-alpha.com, dmitri@feature-digital.com',
        ]);
        Http::fake([self::ENGINE.'/*' => Http::response(['success' => true], 200)]);
        Mail::fake();

        $master = $this->makeUser(['type' => 'master']);
        $this->makeAccount($master, ['api_key' => $this->masterKey]);
    }

    /** LTC with a 3-loss kill switch, armed a day ago so today's closes count. */
    private function ltc(array $overrides = []): Asset
    {
        $asset = Asset::create(array_merge([
            'ticker' => 'LTCUSDT', 'broker' => 'Binance', 'side' => 'ALL',
            'base_size' => 5, 'max_increments' => 15, 'enabled' => 1, 'kill_switch_losses' => 3,
        ], $overrides));
        DB::table('assets')->where('asset_id', $asset->asset_id)->update(['kill_switch_armed_at' => now()->subDay()]);

        return $asset->fresh();
    }

    /** POST closes through the real ingest endpoint, as the engine does. */
    private function sync(array $closes, string $apiKey = 'master-key'): array
    {
        $rows = [];
        foreach ($closes as [$pnl, $minutesAgo]) {
            $rows[] = [
                'api_key' => $apiKey, 'symbol' => 'LTCUSDT', 'position_side' => 'LONG',
                'position_amt' => 15, 'exit_price' => 60, 'realized_pnl' => $pnl,
                'side' => 'SELL', 'order_id' => ++$this->order,
                'closed_at' => now()->subMinutes($minutesAgo)->toDateTimeString(),
            ];
        }

        return $this->postJson('/api/engine/binance/past-positions/sync', ['rows' => $rows], $this->engineHeaders())
            ->assertOk()
            ->json();
    }

    private function engineCalls(string $path): array
    {
        $calls = [];
        Http::assertSent(function ($request) use ($path, &$calls) {
            if ($request->url() === self::ENGINE."/admin/{$path}") {
                $calls[] = $request->data();
            }

            return true;
        });

        return $calls;
    }

    private function adminHeaders(): array
    {
        return $this->userHeaders($this->makeUser(['type' => 'admin']));
    }

    public function test_three_master_losses_in_a_row_switch_the_coin_off_and_tell_everyone(): void
    {
        $asset = $this->ltc();

        $body = $this->sync([[-12, 30], [-4, 20], [-7, 10]]);

        $this->assertSame(['LTCUSDT'], $body['kill_switch']);
        $asset->refresh();
        $this->assertFalse($asset->enabled);
        $this->assertNotNull($asset->killed_at);
        $this->assertSame('The master lost 3 trades in a row on LTCUSDT.', $asset->killed_reason);

        $this->assertCount(1, $this->engineCalls('refresh-assets'));
        $this->assertSame([[
            'exchange' => 'binance', 'ticker' => 'LTCUSDT', 'losses' => 3,
            'reason' => 'The master lost 3 trades in a row on LTCUSDT.',
        ]], $this->engineCalls('kill-switch'));
        Mail::assertSent(AssetKillSwitchNotice::class, fn ($m) => $m->hasTo('support@pixel-alpha.com')
            && $m->hasTo('dmitri@feature-digital.com') && $m->ticker === 'LTCUSDT' && $m->losses === 3);
    }

    public function test_a_win_inside_the_run_resets_it(): void
    {
        $asset = $this->ltc();

        $this->sync([[-12, 40], [-4, 30], [9, 20], [-7, 10]]);

        $this->assertTrue($asset->fresh()->enabled);
        Mail::assertNothingSent();
    }

    public function test_losses_from_before_the_switch_was_armed_never_count(): void
    {
        $asset = $this->ltc();
        DB::table('assets')->where('asset_id', $asset->asset_id)->update(['kill_switch_armed_at' => now()->subMinutes(25)]);

        $this->sync([[-12, 40], [-4, 30], [-7, 10]]);   // only the last is after arming

        $this->assertTrue($asset->fresh()->enabled);
    }

    public function test_customer_losses_never_trip_it(): void
    {
        $asset = $this->ltc();
        $this->makeAccount($this->makeUser(), ['api_key' => 'customer-key']);

        $this->sync([[-12, 30], [-4, 20], [-7, 10]], 'customer-key');

        $this->assertTrue($asset->fresh()->enabled);
        Mail::assertNothingSent();
    }

    public function test_an_asset_without_a_kill_switch_is_never_switched_off(): void
    {
        $asset = $this->ltc(['kill_switch_losses' => null]);

        $this->sync([[-12, 30], [-4, 20], [-7, 10], [-1, 5]]);

        $this->assertTrue($asset->fresh()->enabled);
    }

    public function test_a_close_whose_pnl_arrives_later_still_trips_it(): void
    {
        $asset = $this->ltc();
        $this->sync([[-12, 30], [-4, 20]]);
        // The webhook close lands first with no P&L…
        $this->sync([[null, 10]]);
        $this->assertTrue($asset->fresh()->enabled);

        // …and the poller fills it in on the next tick.
        $this->postJson('/api/engine/binance/past-positions/sync', ['rows' => [[
            'api_key' => $this->masterKey, 'symbol' => 'LTCUSDT', 'position_side' => 'LONG',
            'position_amt' => 15, 'exit_price' => 60, 'realized_pnl' => -7, 'side' => 'SELL',
            'order_id' => $this->order, 'closed_at' => now()->subMinutes(10)->toDateTimeString(),
        ]]], $this->engineHeaders())->assertOk()->assertJsonPath('kill_switch', ['LTCUSDT']);

        $this->assertFalse($asset->fresh()->enabled);
    }

    public function test_it_trips_once_and_announces_once(): void
    {
        $asset = $this->ltc();
        $this->sync([[-12, 30], [-4, 20], [-7, 10]]);
        $this->sync([[-2, 5]]);

        $this->assertFalse($asset->fresh()->enabled);
        Mail::assertSentCount(1);
        $this->assertCount(1, $this->engineCalls('kill-switch'));
    }

    public function test_turning_the_coin_back_on_clears_the_trip_and_rearms_it(): void
    {
        $asset = $this->ltc();
        $this->sync([[-12, 30], [-4, 20], [-7, 10]]);

        $this->withHeaders($this->adminHeaders())
            ->putJson("/api/admin/assets/{$asset->asset_id}", [
                'ticker' => 'LTCUSDT', 'broker' => 'Binance', 'side' => 'ALL',
                'base_size' => 5, 'max_increments' => 15, 'enabled' => true,
            ])
            ->assertOk()
            ->assertJsonPath('asset.killed_at', null)
            ->assertJsonPath('asset.killed_reason', null)
            ->assertJsonPath('asset.kill_switch_losses', 3);

        $asset->refresh();
        $this->assertTrue($asset->enabled);
        $this->assertTrue($asset->kill_switch_armed_at->greaterThan(now()->subMinute()));

        // The run that tripped it is behind the new arming: one more loss is not three.
        $this->sync([[-3, 0]]);
        $this->assertTrue($asset->fresh()->enabled);
    }

    public function test_the_overview_shows_a_caution_strip_until_the_coin_is_back_on(): void
    {
        $asset = $this->ltc();
        $headers = $this->adminHeaders();
        $this->getJson('/api/admin/insights/overview', $headers)->assertOk()->assertJsonPath('attention.killed_assets', []);

        $this->sync([[-12, 30], [-4, 20], [-7, 10]]);

        $this->getJson('/api/admin/insights/overview', $headers)
            ->assertOk()
            ->assertJsonPath('attention.killed_assets.0.ticker', 'LTCUSDT')
            ->assertJsonPath('attention.killed_assets.0.losses', 3)
            ->assertJsonPath('attention.killed_assets.0.reason', 'The master lost 3 trades in a row on LTCUSDT.');

        $this->withHeaders($headers)->putJson("/api/admin/assets/{$asset->asset_id}", [
            'ticker' => 'LTCUSDT', 'broker' => 'Binance', 'side' => 'ALL',
            'base_size' => 5, 'max_increments' => 15, 'enabled' => true,
        ])->assertOk();

        $this->getJson('/api/admin/insights/overview', $headers)->assertOk()->assertJsonPath('attention.killed_assets', []);
    }

    public function test_the_tab_sets_and_clears_the_kill_switch(): void
    {
        $asset = $this->ltc(['kill_switch_losses' => null]);
        $headers = $this->adminHeaders();
        $put = fn (array $body) => $this->withHeaders($headers)
            ->putJson("/api/admin/assets/{$asset->asset_id}/streak-sizing", ['streak_sizing_enabled' => false] + $body);

        $put(['kill_switch_losses' => 4])->assertOk()->assertJsonPath('asset.kill_switch_losses', 4);
        $this->assertNotNull($asset->fresh()->kill_switch_armed_at);
        $put([])->assertOk()->assertJsonPath('asset.kill_switch_losses', 4);   // absent = unchanged
        $put(['kill_switch_losses' => null])->assertOk()->assertJsonPath('asset.kill_switch_losses', null);
        $put(['kill_switch_losses' => 11])->assertStatus(422);
        $put(['kill_switch_losses' => 0])->assertStatus(422);
    }

    public function test_the_asset_form_sets_it_and_the_card_toggle_leaves_it_alone(): void
    {
        $headers = $this->adminHeaders();
        $id = $this->withHeaders($headers)->postJson('/api/admin/assets', [
            'ticker' => 'RENDERUSDT', 'broker' => 'Binance', 'side' => 'ALL',
            'base_size' => 60, 'max_increments' => 180, 'enabled' => true, 'kill_switch_losses' => 2,
        ])->assertStatus(201)->assertJsonPath('asset.kill_switch_losses', 2)->json('asset.asset_id');

        $this->withHeaders($headers)->putJson("/api/admin/assets/{$id}", [
            'ticker' => 'RENDERUSDT', 'broker' => 'Binance', 'side' => 'ALL',
            'base_size' => 60, 'max_increments' => 180, 'enabled' => false,
        ])->assertOk()->assertJsonPath('asset.kill_switch_losses', 2);

        $this->withHeaders($headers)->putJson("/api/admin/assets/{$id}", [
            'ticker' => 'RENDERUSDT', 'broker' => 'Binance', 'side' => 'ALL',
            'base_size' => 60, 'max_increments' => 180, 'enabled' => false, 'kill_switch_losses' => '',
        ])->assertOk()->assertJsonPath('asset.kill_switch_losses', null);
    }

    public function test_the_trader_catalog_never_shows_the_kill_switch(): void
    {
        $this->ltc();

        $body = $this->withHeaders($this->userHeaders($this->makeUser()))
            ->getJson('/api/assets')->assertOk()->getContent();

        $this->assertStringNotContainsString('kill', $body);
    }

    public function test_the_kill_switch_email_is_in_the_catalogue(): void
    {
        $entry = \App\Services\Notifications\EmailCatalogue::find('team-kill-switch');

        $this->assertNotNull($entry);
        $this->assertTrue($entry['live']);
        $this->assertStringContainsString('LTCUSDT was switched off', $entry['mail']->render());
    }
}
