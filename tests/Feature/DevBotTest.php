<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\DevBotAction;
// (DevBotJob, DevBotWatch, SystemStatus are referenced fully qualified below.)
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
            'services.dev_bot.check_disk' => false, // the test machine's own disk is not the server's
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
        // No size buttons for an exit — only the Investigate one every problem carries.
        $buttons = collect($last['reply_markup']['inline_keyboard'])->flatten(1)->pluck('text')->all();
        $this->assertSame(['🤖 Investigate on my PC'], $buttons);
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
        $this->assertStringContainsString('Pixel Alpha error bot', $this->sent('sendMessage')[0]['text']);
    }

    // ---- status, watchdog, server errors ----------------------------------------

    private function fakeEngineHealth(array $health): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake([
            'tg.test/bottest-token/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 77]]),
            'tg.test/*' => Http::response(['ok' => true, 'result' => true]),
            'engine.test/health' => Http::response($health),
            'engine.test/*' => Http::response(['ok' => true]),
        ]);
    }

    private function healthyEngine(): array
    {
        return ['status' => 'ok', 'exchanges' => ['binance' => [], 'mexc' => [], 'bybit' => []],
            'pollers' => ['balances', 'positions', 'transfers', 'past-positions', 'mexc-history', 'bybit-history'],
            'retry_queue_depth' => 0, 'rate_limited_until' => null,
            'monthly_invoices' => ['enabled' => true, 'next' => '2026-12-01T16:00:00+07:00']];
    }

    public function test_status_reports_every_service_from_the_server(): void
    {
        $this->fakeEngineHealth($this->healthyEngine());
        \Illuminate\Support\Facades\Cache::put(\App\Services\DevBot\SystemStatus::HEARTBEAT_KEY, now()->timestamp, 600);
        \App\Services\Payments\TronGateway::markScanned(app(\App\Services\Payments\PaymentEnvironment::class)->tronNetworkFor(false));

        $this->say('/status');
        $text = $this->sent('sendMessage')[0]['text'];
        $this->assertStringContainsString('All services up', $text);
        $this->assertStringContainsString('6/6 data pollers', $text);
        $this->assertStringContainsString('Crypto payment listener', $text);
        $this->assertStringContainsString('last scan', $text);
        $this->assertStringContainsString('next monthly run 1 Dec', $text);
        $this->assertStringContainsString('Scheduled jobs</b> — running', $text);
    }

    public function test_a_status_question_is_answered_now_with_the_agent_one_tap_away(): void
    {
        $this->fakeEngineHealth($this->healthyEngine());
        $this->say('is the crypto listener up?');

        $sent = $this->sent('sendMessage')[0];
        $this->assertStringContainsString('Crypto payment listener', $sent['text']);
        $this->assertSame(0, \App\Models\DevBotJob::count());
        $button = $sent['reply_markup']['inline_keyboard'][0][0];
        $this->assertSame('🤖 Ask my PC agent instead', $button['text']);

        $this->tap($button['callback_data'])->assertOk();
        $this->assertSame('is the crypto listener up?', \App\Models\DevBotJob::sole()->prompt);
    }

    public function test_the_watchdog_tells_a_stopped_service_once_and_its_recovery_once(): void
    {
        $this->fakeEngineHealth($this->healthyEngine());
        \Illuminate\Support\Facades\Cache::put(\App\Services\DevBot\SystemStatus::HEARTBEAT_KEY, now()->timestamp, 600);
        $watch = app(\App\Services\DevBot\DevBotWatch::class);

        $watch->run(); // the crypto listener has never scanned: a problem on first sight
        $watch->run(); // still down: nothing new
        $down = collect($this->sent('sendMessage'))->pluck('text')->filter(fn ($t) => str_contains($t, 'Crypto payment listener'));
        $this->assertCount(1, $down);
        $this->assertStringContainsString('has a problem', $down->first());

        \App\Services\Payments\TronGateway::markScanned(app(\App\Services\Payments\PaymentEnvironment::class)->tronNetworkFor(false));
        $watch->run();
        $this->assertStringContainsString('recovered', collect($this->sent('sendMessage'))->last()['text']);
        $this->assertCount(2, $this->sent('sendMessage')); // healthy checks never spoke
    }

    public function test_a_server_error_is_dmd_once_an_hour_and_can_be_investigated(): void
    {
        $handler = app(\Illuminate\Contracts\Debug\ExceptionHandler::class);
        foreach ([1, 2] as $_) { // the same place twice: told once
            $handler->report(new \RuntimeException('Division by zero in the fee maths'));
        }

        $sends = $this->sent('sendMessage');
        $this->assertCount(1, $sends);
        $this->assertStringContainsString('Server error', $sends[0]['text']);
        $this->assertStringContainsString('Division by zero', $sends[0]['text']);
        $this->assertStringContainsString('tests/Feature/DevBotTest.php', $sends[0]['text']);

        $this->tap($sends[0]['reply_markup']['inline_keyboard'][0][0]['callback_data'])->assertOk();
        $job = \App\Models\DevBotJob::sole();
        $this->assertSame('exception', $job->context['kind']);
        $this->assertStringContainsString('Division by zero', $job->prompt);
    }

    public function test_an_engine_error_is_dmd_once_an_hour_and_can_be_switched_off(): void
    {
        $alert = ['context' => "poller 'past-positions' failing", 'detail' => 'ReadTimeout: fapi.binance.com'];
        $this->postJson('/api/engine/binance/dev-alert', $alert, $this->engineHeaders())->assertOk();
        $this->postJson('/api/engine/binance/dev-alert', $alert, $this->engineHeaders())->assertOk();
        $this->postJson('/api/engine/binance/dev-alert', $alert)->assertUnauthorized(); // no engine secret

        $sends = $this->sent('sendMessage');
        $this->assertCount(1, $sends);
        $this->assertStringContainsString('Engine error', $sends[0]['text']);
        $this->assertStringContainsString('past-positions', $sends[0]['text']);
        $this->tap($sends[0]['reply_markup']['inline_keyboard'][0][0]['callback_data'])->assertOk();
        $this->assertStringContainsString('ReadTimeout', \App\Models\DevBotJob::sole()->prompt);

        \App\Models\DevBotSetting::current()->forceFill(['disabled_kinds' => ['engine']])->save();
        $this->postJson('/api/engine/binance/dev-alert', ['context' => 'Binance rate limit / ban', 'detail' => 'x'], $this->engineHeaders())->assertOk();
        $this->assertCount(2, $this->sent('sendMessage')); // only the job's "queued" line was added
    }

    public function test_a_failed_scheduled_job_is_named(): void
    {
        app(\App\Services\DevBot\DevBotReporter::class)->scheduledFailure("'/usr/bin/php8.3' 'artisan' payments:watch-tron", 1);
        $this->assertStringContainsString('payments:watch-tron</code> ended with code 1', $this->sent('sendMessage')[0]['text']);
    }

    // ---- deploy after a merge ----------------------------------------------------

    public function test_a_merged_pull_request_offers_a_deploy_that_needs_a_second_tap(): void
    {
        config(['services.dev_bot.agent_key' => self::AGENT_KEY]);
        $this->agent('POST', '/merged', [
            'repo' => 'web', 'number' => 12, 'url' => 'https://github.com/xlythefx/sinegu_crypto/pull/12',
            'title' => 'Fix the skip reason label', 'branch' => 'devbot/job-3', 'job_id' => 3,
            'files' => ['src/lib/insightLabels.ts', 'trading-flask/binance_abcd/notify.py', 'CLAUDE.md', 'trading-flask/tests/test_x.py'],
        ])->assertOk()->assertJsonPath('targets', ['website', 'engine']);

        $sent = $this->sent('sendMessage')[0];
        $this->assertStringContainsString('the website and the trading engine', $sent['text']);
        $action = DevBotAction::where('kind', DevBotAction::DEPLOY)->sole();
        $this->assertSame("dd:{$action->id}:ask", $sent['reply_markup']['inline_keyboard'][0][0]['callback_data']);

        $this->tap("dd:{$action->id}:ask")->assertOk();
        $this->assertSame(0, \App\Models\DevBotJob::count()); // asking is not deploying
        $confirm = $this->sent('editMessageText')[0];
        $this->assertStringContainsString('This updates the live site', $confirm['text']);

        $this->tap("dd:{$action->id}:go")->assertOk();
        $this->tap("dd:{$action->id}:go")->assertOk(); // a second confirm deploys nothing more
        $job = \App\Models\DevBotJob::sole();
        $this->assertSame('deploy', $job->source);
        $this->assertSame(['kind' => 'deploy', 'targets' => ['website', 'engine'], 'repo' => 'web',
            'pr' => ['number' => 12, 'url' => 'https://github.com/xlythefx/sinegu_crypto/pull/12', 'title' => 'Fix the skip reason label']], $job->context);
    }

    public function test_docs_only_merges_offer_nothing_and_foreign_links_are_refused(): void
    {
        config(['services.dev_bot.agent_key' => self::AGENT_KEY]);
        $this->agent('POST', '/merged', ['repo' => 'api', 'number' => 4, 'url' => 'https://github.com/xlythefx/sinegu_crypto_api/pull/4',
            'title' => 'Docs', 'files' => ['README.md', 'tests/Feature/XTest.php']])->assertOk()->assertJsonPath('targets', []);
        $this->assertArrayNotHasKey('reply_markup', $this->sent('sendMessage')[0]->data());

        $this->agent('POST', '/merged', ['repo' => 'web', 'number' => 5, 'url' => 'https://evil.test/pull/5',
            'title' => 'x', 'files' => ['src/a.ts']])->assertStatus(422);
    }

    public function test_typing_deploy_offers_the_current_main_with_the_same_confirm_step(): void
    {
        $this->say('deploy');
        $sent = $this->sent('sendMessage')[0];
        $this->assertStringContainsString('Deploy the current main', $sent['text']);
        $this->assertStringContainsString('the website and the API', $sent['text']);
        $this->assertSame('🚀 Deploy', $sent['reply_markup']['inline_keyboard'][0][0]['text']);
    }

    public function test_deploy_targets_come_from_the_changed_paths(): void
    {
        $t = fn (string $repo, array $f) => \App\Services\DevBot\DevBotJobs::deployTargets($repo, $f);
        $this->assertSame(['api'], $t('api', ['app/Services/X.php']));
        $this->assertSame([], $t('api', ['tests/Feature/XTest.php', 'CLAUDE.md']));
        $this->assertSame(['website', 'telegram'], $t('web', ['pixel-telegram/pixel_telegram/x.py', 'package.json']));
        $this->assertSame([], $t('web', ['dev-agent/agent.py', 'docs/x.md']));
    }

    // ---- the PC agent's jobs ---------------------------------------------------

    private const AGENT_KEY = 'agent-key-for-tests-0123456789abcdef';

    private function agent(string $method, string $path, array $body = [], string $key = self::AGENT_KEY)
    {
        return $this->json($method, '/api/devbot/agent'.$path, $body, ['X-Devbot-Agent-Key' => $key]);
    }

    private function say(string $text, string $from = self::OWNER)
    {
        return $this->postJson('/api/devbot/telegram', ['message' => [
            'chat' => ['id' => (int) $from], 'from' => ['id' => (int) $from], 'text' => $text,
        ]], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    public function test_the_owners_text_becomes_a_job_the_agent_claims_and_reports_on(): void
    {
        config(['services.dev_bot.agent_key' => self::AGENT_KEY]);
        $this->say('why did the LTC buy at 14:15 skip?');
        $this->say('/start');                         // answered, not a job
        $this->say('please fix it', from: '999');     // a stranger: refused, not a job

        $job = \App\Models\DevBotJob::sole();
        $this->assertSame('queued', $job->status);
        $this->assertSame('message', $job->source);
        $texts = array_map(fn ($r) => $r['text'], $this->sent('sendMessage'));
        $this->assertStringContainsString('Job #'.$job->id.' queued', $texts[0]);
        $this->assertStringContainsString('has not checked in', $texts[0]); // the agent never polled

        $this->agent('POST', '/claim')->assertOk()->assertJsonPath('job.id', $job->id)
            ->assertJsonPath('job.prompt', 'why did the LTC buy at 14:15 skip?');
        $this->agent('POST', '/claim')->assertOk()->assertJsonPath('job', null);
        $this->agent('POST', "/jobs/{$job->id}/finish", [
            'ok' => true, 'summary' => 'The master was already full on LTC; no bug.',
            'links' => [['label' => 'Pull request', 'url' => 'https://github.com/xlythefx/sinegu_crypto/pull/7'],
                ['label' => 'Bad', 'url' => 'https://evil.test/x']],
        ])->assertOk();

        $this->assertSame('done', $job->fresh()->status);
        $last = collect($this->sent('sendMessage'))->last()['text'];
        $this->assertStringContainsString('Job #'.$job->id.' done', $last);
        $this->assertStringContainsString('pull/7', $last);
        $this->assertStringNotContainsString('evil.test', $last); // only GitHub links are relayed
        $this->agent('POST', "/jobs/{$job->id}/finish", ['ok' => true, 'summary' => 'again'])->assertNotFound();
    }

    public function test_investigate_button_queues_one_job_with_the_signal_as_context(): void
    {
        $this->signal(['category' => 'signal', 'ticker' => 'LTCUSDT', 'details' => [
            ['account' => 'x', 'status' => 'failed', 'error' => 'boom'],
        ]]);
        $button = $this->sent('sendMessage')[0]['reply_markup']['inline_keyboard'][0][0];
        $this->assertSame('🤖 Investigate on my PC', $button['text']);

        $this->tap($button['callback_data'])->assertOk();
        $this->tap($button['callback_data'])->assertOk(); // a second tap queues nothing

        $job = \App\Models\DevBotJob::sole();
        $this->assertSame('button', $job->source);
        $this->assertSame('order_failed', $job->context['reason']);
        $this->assertStringContainsString('Signal Log row #'.$job->context['trade_log_id'], $job->prompt);
    }

    public function test_the_agent_api_is_shut_without_the_right_key_and_lists_private_strings(): void
    {
        $this->agent('POST', '/claim')->assertForbidden(); // no key configured
        config(['services.dev_bot.agent_key' => self::AGENT_KEY]);
        $this->agent('POST', '/claim', [], 'wrong-key-wrong-key-wrong-key')->assertForbidden();

        $uni = $this->makeUser(['name' => 'Alexandre Guyomard', 'type' => 'user']);
        $this->makeAccount($uni, ['name' => 'alex-main-binance']);
        $this->makeUser(['name' => 'Admin', 'type' => 'admin']);
        $strings = $this->agent('GET', '/private-strings')->assertOk()->json('strings');
        $this->assertContains('Alexandre Guyomard', $strings);
        $this->assertContains('alex-main-binance', $strings);
        $this->assertNotContains('Admin', $strings); // too generic to block code with
    }

    // ---- Admin → Error Bot ------------------------------------------------------

    private function as(string $type): array
    {
        $uni = $this->makeUser(['type' => $type, 'name' => 'Admin '.$type]);
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.\App\Models\UserCredential::where('uni_id', $uni)->firstOrFail()->createToken('t')->plainTextToken];
    }

    public function test_the_admin_page_shows_status_jobs_and_never_a_secret(): void
    {
        config(['services.dev_bot.agent_key' => self::AGENT_KEY]);
        $this->fakeEngineHealth($this->healthyEngine());
        $this->say('why did LTC skip?');

        $body = $this->getJson('/api/admin/devbot', $this->as('admin'))->assertOk()
            ->assertJsonPath('bot.configured', true)
            ->assertJsonPath('bot.agent_key_set', true)
            ->assertJsonPath('settings.agent_paused', false)
            ->assertJsonPath('jobs.0.prompt', 'why did LTC skip?')
            ->assertJsonCount(count(\App\Models\DevBotSetting::KINDS), 'kinds')
            ->getContent();
        foreach (['test-token', self::SECRET, self::AGENT_KEY] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
        $this->assertStringContainsString('Crypto payment listener', $body);

        $this->getJson('/api/admin/devbot', $this->as('collaborator'))->assertForbidden();
        $this->getJson('/api/admin/devbot', $this->as('user'))->assertForbidden();
    }

    public function test_switching_a_kind_off_or_muting_silences_the_bot_but_never_replies(): void
    {
        $admin = $this->as('admin');
        $failed = ['category' => 'signal', 'ticker' => 'LTCUSDT', 'details' => [['account' => 'x', 'status' => 'failed', 'error' => 'boom']]];

        $this->putJson('/api/admin/devbot/settings', ['disabled_kinds' => ['signals']], $admin)->assertOk()
            ->assertJsonPath('settings.disabled_kinds', ['signals']);
        $this->signal($failed);
        $this->assertSame([], $this->sent('sendMessage'));

        $this->putJson('/api/admin/devbot/settings', ['disabled_kinds' => [], 'mute_minutes' => 60], $admin)->assertOk();
        $this->assertNotNull(\App\Models\DevBotSetting::current()->muted_until);
        app(\Illuminate\Contracts\Debug\ExceptionHandler::class)->report(new \RuntimeException('muted'));
        $this->assertSame([], $this->sent('sendMessage'));

        $this->say('/status'); // a reply to the owner is never muted
        $this->assertCount(1, $this->sent('sendMessage'));

        $this->putJson('/api/admin/devbot/settings', ['mute_minutes' => 0], $admin)->assertOk()
            ->assertJsonPath('settings.muted_until', null);
        $this->putJson('/api/admin/devbot/settings', ['disabled_kinds' => ['nonsense']], $admin)->assertStatus(422);
    }

    public function test_a_paused_agent_gets_no_work_until_resumed(): void
    {
        config(['services.dev_bot.agent_key' => self::AGENT_KEY]);
        $admin = $this->as('admin');
        $this->putJson('/api/admin/devbot/settings', ['agent_paused' => true], $admin)->assertOk();
        $this->say('look into the RENDER skip');
        $this->assertStringContainsString('paused on Admin', $this->sent('sendMessage')[0]['text']);

        $this->agent('POST', '/claim')->assertOk()->assertJsonPath('job', null);
        $this->putJson('/api/admin/devbot/settings', ['agent_paused' => false], $admin)->assertOk();
        $this->agent('POST', '/claim')->assertOk()->assertJsonPath('job.prompt', 'look into the RENDER skip');
    }

    public function test_cancel_a_queued_job_and_retry_a_finished_one(): void
    {
        $admin = $this->as('admin');
        $this->say('first question');
        $job = \App\Models\DevBotJob::sole();

        $this->postJson("/api/admin/devbot/jobs/{$job->id}/retry", [], $admin)->assertStatus(409); // not finished
        $this->postJson("/api/admin/devbot/jobs/{$job->id}/cancel", [], $admin)->assertOk();
        $this->assertSame('cancelled', $job->fresh()->status);
        $this->postJson("/api/admin/devbot/jobs/{$job->id}/cancel", [], $admin)->assertStatus(409);

        $newId = $this->postJson("/api/admin/devbot/jobs/{$job->id}/retry", [], $admin)->assertOk()->json('job_id');
        $this->assertSame('first question', \App\Models\DevBotJob::find($newId)->prompt);
        $this->assertSame('queued', \App\Models\DevBotJob::find($newId)->status);

        $this->postJson('/api/admin/devbot/test', [], $admin)->assertOk();
        $this->assertStringContainsString('Test message', collect($this->sent('sendMessage'))->last()['text']);
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
