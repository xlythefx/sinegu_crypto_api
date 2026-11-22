<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\DevBotAction;
use App\Models\RiskAlert;
use App\Services\Alerts\RiskAlerts;
use App\Services\DevBot\CoinSuggestion;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * The developer error bot (2026-11-22): trading problems DM'd to the owner,
 * size buttons for a coin that is not set up, and the tap that adds it.
 */
class DevBotTest extends EngineTestCase
{
    private const OWNER = '555000111';

    private const SECRET = 'webhook-secret-for-tests-0123';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.dev_bot.token' => 'test-token',
            'services.dev_bot.chat_id' => self::OWNER,
            'services.dev_bot.webhook_secret' => self::SECRET,
            'services.dev_bot.base_url' => 'https://tg.test',
            'services.market.base_url' => 'https://fapi.test',
            'services.engine.admin_secret' => 'engine-admin-test',
            'services.engine.targets.local' => 'http://engine.test',
        ]);
        $this->fakeAll();
    }

    private function fakeAll(?int $maxLeverage = 10): void
    {
        Http::fake([
            'tg.test/bottest-token/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 77]]),
            'tg.test/*' => Http::response(['ok' => true, 'result' => true]),
            'fapi.test/fapi/v1/exchangeInfo' => Http::response(['symbols' => [[
                'symbol' => 'ASRUSDT', 'status' => 'TRADING',
                'filters' => [
                    ['filterType' => 'LOT_SIZE', 'stepSize' => '1', 'minQty' => '1'],
                    ['filterType' => 'MARKET_LOT_SIZE', 'stepSize' => '1', 'minQty' => '1'],
                    ['filterType' => 'MIN_NOTIONAL', 'notional' => '5'],
                ],
            ]]]),
            'fapi.test/fapi/v1/premiumIndex*' => Http::response(['symbol' => 'ASRUSDT', 'markPrice' => '0.628']),
            'engine.test/admin/size-check' => Http::response([
                'success' => true, 'max_leverage' => $maxLeverage, 'mark_price' => 0.628,
                'accounts' => [
                    ['name' => 'master', 'is_master' => true, 'balance' => 12000, 'entry_qty' => 12.0],
                    ['name' => 'dima-submaster', 'is_master' => false, 'balance' => 1080, 'entry_qty' => 1.0],
                ],
            ]),
            'engine.test/*' => Http::response(['ok' => true]),
        ]);
    }

    private function signal(array $overrides = [])
    {
        return $this->postJson('/api/engine/binance/trade-logs', array_merge([
            'action' => 'BUY', 'ticker' => 'ASRUSDT', 'success' => false, 'category' => 'rejected',
            'leverage' => 25, 'feed' => 'submaster', 'target_count' => 0, 'filled' => 0, 'failed' => 0,
            'skipped' => 0, 'details' => [['reason' => 'asset_not_added', 'exchange' => 'binance']],
            'ts' => '2026-11-22 04:30:00',
        ], $overrides), $this->engineHeaders())->assertStatus(201);
    }

    private function tap(string $data, string $from = self::OWNER, string $secret = self::SECRET)
    {
        return $this->postJson('/api/devbot/telegram', ['update_id' => 1, 'callback_query' => [
            'id' => 'cb-1', 'data' => $data, 'from' => ['id' => (int) $from, 'first_name' => 'Christian'],
        ]], ['X-Telegram-Bot-Api-Secret-Token' => $secret]);
    }

    /** @return list<HttpRequest> */
    private function sent(string $method): array
    {
        return Http::recorded(fn (HttpRequest $r) => str_ends_with($r->url(), "/bottest-token/{$method}"))
            ->map(fn ($pair) => $pair[0])->values()->all();
    }

    // ---- problems --------------------------------------------------------------

    public function test_a_problem_outcome_is_dmd_once_an_hour_and_normal_outcomes_never(): void
    {
        $failed = ['category' => 'signal', 'success' => false, 'ticker' => 'LTCUSDT', 'failed' => 1, 'details' => [
            ['account' => 'dima-submaster', 'uni_id' => 'u1', 'status' => 'failed', 'error' => 'Order failed: -2027 Exceeded the maximum allowable position'],
        ]];
        $this->signal($failed);
        $this->signal($failed); // same coin + reason within the hour: muted
        $this->signal(['category' => 'signal', 'ticker' => 'LTCUSDT', 'details' => [
            ['account' => 'master', 'uni_id' => 'u2', 'status' => 'skipped', 'reason' => 'maxed sizing'],
        ]]);

        $sends = $this->sent('sendMessage');
        $this->assertCount(1, $sends);
        $this->assertSame(self::OWNER, (string) $sends[0]['chat_id']);
        $this->assertStringContainsString('Order refused by the exchange', $sends[0]['text']);
        $this->assertStringContainsString('dima-submaster', $sends[0]['text']);
        $this->assertStringContainsString('-2027', $sends[0]['text']);
    }

    public function test_nothing_is_sent_when_the_bot_is_not_configured(): void
    {
        config(['services.dev_bot.token' => '']);
        $this->signal(['category' => 'signal', 'ticker' => 'LTCUSDT', 'details' => [
            ['account' => 'x', 'status' => 'failed', 'error' => 'boom'],
        ]]);
        $this->signal();

        $this->assertSame([], $this->sent('sendMessage'));
        $this->assertSame(0, DevBotAction::count());
    }

    public function test_a_new_risk_alert_is_dmd_and_a_repeat_is_not(): void
    {
        $alerts = app(RiskAlerts::class);
        $alerts->raise(RiskAlert::KILL_SWITCH, 'asset:1', 'LTCUSDT switched off after 3 master losses', ['Turn it back on in Trading Assets.'], email: false);
        $alerts->raise(RiskAlert::KILL_SWITCH, 'asset:1', 'LTCUSDT switched off after 3 master losses', [], email: false);

        $sends = $this->sent('sendMessage');
        $this->assertCount(1, $sends);
        $this->assertStringContainsString('Kill switch tripped', $sends[0]['text']);
    }

    // ---- coin buttons ----------------------------------------------------------

    public function test_a_missing_coin_gets_size_buttons_at_the_coins_own_leverage_cap(): void
    {
        $this->signal();
        $this->signal(); // the next refusal only counts

        $sends = $this->sent('sendMessage');
        $this->assertCount(1, $sends);
        $keyboard = $sends[0]['reply_markup']['inline_keyboard'];
        // $5 / $10 / $15 margin at 10× (Binance's max here) ÷ 0.628, floored to whole coins.
        $this->assertSame('$5 → 79 ASR (~$50 position)', $keyboard[0][0]['text']);
        $this->assertSame('$10 → 159 ASR (~$100 position)', $keyboard[1][0]['text']);
        $this->assertSame('$15 → 238 ASR (~$149 position)', $keyboard[2][0]['text']);
        $this->assertSame('Skip', $keyboard[3][0]['text']);
        $this->assertStringContainsString('Leverage 10×', $sends[0]['text']);
        $this->assertStringContainsString('Side LONG', $sends[0]['text']);
        $this->assertStringContainsString('trades 12× these sizes', $sends[0]['text']);

        $action = DevBotAction::sole();
        $this->assertSame(2, $action->occurrences);
        $this->assertSame(77, $action->message_id);
        $this->assertSame('da:'.$action->id.':1', $keyboard[1][0]['callback_data']);
    }

    public function test_an_alert_above_binances_max_is_called_out_with_the_json_fix(): void
    {
        $this->signal(); // the alert sends 25×, ASR allows 10×
        $text = $this->sent('sendMessage')[0]['text'];
        $this->assertStringContainsString('Your alert sends 25×, but Binance allows at most 10× on ASR', $text);
        $this->assertStringContainsString('"leverage": 10', $text);
    }

    public function test_an_alert_within_the_max_keeps_its_own_leverage_and_says_nothing(): void
    {
        $this->signal(['leverage' => 5]);
        $text = $this->sent('sendMessage')[0]['text'];
        $this->assertStringContainsString('Leverage 5×', $text);
        $this->assertStringNotContainsString('Your alert sends', $text);
        $this->assertSame(5, DevBotAction::sole()->payload['leverage']);
    }

    public function test_a_refused_leverage_names_binances_max_and_where_to_change_it(): void
    {
        $failed = fn (string $ticker) => ['category' => 'signal', 'success' => false, 'ticker' => $ticker, 'failed' => 1, 'details' => [[
            'account' => 'dima-submaster', 'status' => 'failed', 'leverage' => 25,
            'leverage_refused' => '-4028 Leverage 25 is not valid',
            'error' => 'Order failed: -2027 — 25x leverage refused (-4028 Leverage 25 is not valid)',
        ]]];

        // No leverage on the coin: the 25× is the alert's.
        Asset::create(['ticker' => 'ASRUSDT', 'type' => 'Cryptocurrency', 'broker' => 'Binance', 'side' => 'LONG',
            'base_size' => 16, 'max_increments' => 48, 'enabled' => true]);
        $this->signal($failed('ASRUSDT'));
        $text = $this->sent('sendMessage')[0]['text'];
        $this->assertStringContainsString('25× leverage refused', $text);
        $this->assertStringContainsString('Binance allows at most 10× on ASR', $text);
        $this->assertStringContainsString('"leverage": 10', $text);

        // The coin's own leverage is what was asked: point at Trading Assets.
        Asset::create(['ticker' => 'DEXEUSDT', 'type' => 'Cryptocurrency', 'broker' => 'Binance', 'side' => 'SHORT',
            'base_size' => 10, 'max_increments' => 30, 'leverage' => 25, 'enabled' => true]);
        $this->signal($failed('DEXEUSDT'));
        $this->assertStringContainsString("coin's own leverage in Trading Assets", $this->sent('sendMessage')[1]['text']);
    }

    public function test_a_sell_suggests_a_short_and_an_exit_gets_no_buttons(): void
    {
        $this->signal(['action' => 'SELL']);
        $this->assertSame('SHORT', DevBotAction::sole()->side);

        $this->signal(['action' => 'EXIT_LONG', 'ticker' => 'DEXEUSDT']);
        $this->assertSame(1, DevBotAction::count());
        $last = collect($this->sent('sendMessage'))->last();
        $this->assertStringContainsString('Coin not added yet', $last['text']);
        $this->assertArrayNotHasKey('reply_markup', $last->data());
    }

    public function test_the_owners_tap_adds_the_coin_once_through_the_normal_asset_path(): void
    {
        $this->signal();
        $action = DevBotAction::sole();

        $this->tap("da:{$action->id}:1")->assertOk();
        $this->tap("da:{$action->id}:2")->assertOk(); // second tap: already handled

        $asset = Asset::where('ticker', 'ASRUSDT')->sole();
        $this->assertSame('Binance', $asset->broker);
        $this->assertSame('LONG', $asset->side);
        $this->assertEquals(159, $asset->base_size);
        $this->assertEquals(477, $asset->max_increments);
        $this->assertSame(10, $asset->leverage);
        $this->assertTrue($asset->enabled);

        $history = DB::table('asset_changes')->where('asset_id', $asset->asset_id)->sole();
        $this->assertSame('created', $history->event);
        $this->assertSame('Christian (Telegram)', $history->actor_name);

        $action->refresh();
        $this->assertSame(DevBotAction::APPLIED, $action->status);
        $this->assertSame('Christian', $action->resolved_by);
        $edit = $this->sent('editMessageText')[0];
        $this->assertStringContainsString('✅ Added by Christian', $edit['text']);
        $this->assertSame([], $edit['reply_markup']['inline_keyboard']);
        $this->assertStringContainsString('Already handled', $this->sent('answerCallbackQuery')[1]['text']);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'engine.test/admin/refresh-assets'));
    }

    public function test_someone_else_or_a_forged_call_changes_nothing(): void
    {
        $this->signal();
        $action = DevBotAction::sole();

        $this->tap("da:{$action->id}:1", from: '999')->assertOk();
        $this->tap("da:{$action->id}:1", secret: 'wrong-secret-xxxxxxxxxx')->assertForbidden();
        config(['services.dev_bot.webhook_secret' => '']);
        $this->tap("da:{$action->id}:1", secret: '')->assertForbidden();

        $this->assertSame(0, Asset::count());
        $this->assertSame(DevBotAction::OPEN, $action->fresh()->status);
        $this->assertStringContainsString('private', $this->sent('answerCallbackQuery')[0]['text']);
    }

    public function test_skip_closes_the_suggestion_and_a_coin_added_meanwhile_is_not_added_twice(): void
    {
        $this->signal();
        $first = DevBotAction::sole();
        $this->tap("da:{$first->id}:skip")->assertOk();
        $this->assertSame(DevBotAction::SKIPPED, $first->fresh()->status);
        $this->assertSame(0, Asset::count());

        // A new refusal opens a new suggestion; the coin is then added by hand.
        $this->signal();
        $second = DevBotAction::where('status', DevBotAction::OPEN)->sole();
        Asset::create(['ticker' => 'ASRUSDT', 'type' => 'Cryptocurrency', 'broker' => 'Binance', 'side' => 'LONG',
            'base_size' => 16, 'max_increments' => 48, 'enabled' => true]);
        $this->tap("da:{$second->id}:0")->assertOk();

        $this->assertSame(1, Asset::count());
        $this->assertEquals(16, Asset::sole()->base_size);
        $this->assertSame(DevBotAction::ALREADY, $second->fresh()->status);
    }

    public function test_an_unreadable_leverage_limit_still_offers_25x_and_says_so(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory); // stubs stack; start clean
        $this->fakeAll(null);
        $this->signal();
        $text = $this->sent('sendMessage')[0]['text'];
        $this->assertStringContainsString('Leverage 25×', $text);
        $this->assertStringContainsString('could not be read', $text);
    }

    public function test_the_owners_start_message_is_answered(): void
    {
        $this->postJson('/api/devbot/telegram', ['message' => ['chat' => ['id' => (int) self::OWNER], 'text' => '/start']],
            ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
        $this->assertStringContainsString('Connected', $this->sent('sendMessage')[0]['text']);
    }

    // ---- the button maths ------------------------------------------------------

    public function test_options_floor_to_the_step_and_drop_what_binance_would_refuse(): void
    {
        // BTC at 98,000, step 0.001, min order $100: $5×25 = $125 → 0.001 BTC ($98) is under the minimum.
        $btc = CoinSuggestion::options(98000, '0.001', 0.001, 100, 25);
        $this->assertSame([10, 15], array_column($btc, 'cost'));
        $this->assertSame([0.002, 0.003], array_column($btc, 'qty'));

        // Whole coins at 200: $5 is under one coin; $10 and $15 both floor to 1 → offered once.
        $coarse = CoinSuggestion::options(200, '1', 1, 5, 25);
        $this->assertSame([10], array_column($coarse, 'cost'));
        $this->assertSame([1.0], array_column($coarse, 'qty'));

        $this->assertSame(3, CoinSuggestion::decimals('0.00100'));
        $this->assertSame(0, CoinSuggestion::decimals('1'));
    }
}
