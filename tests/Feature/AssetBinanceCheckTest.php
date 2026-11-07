<?php

namespace Tests\Feature;

use App\Models\TradeLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * The asset form's Binance check (GET /api/admin/assets/binance-check): the coin's
 * order rules and mark price, the smallest account the engine would trade it on, and
 * the leverage the signals carry.
 */
class AssetBinanceCheckTest extends PaymentTestCase
{
    private string $adminUniId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.engine.min_deposit' => 1000, 'services.market.base_url' => 'https://fapi.test']);
        $this->adminUniId = $this->makeUser(['type' => 'admin']);
    }

    private function fakeBinance(bool $up = true): void
    {
        if (! $up) {
            Http::fake(['fapi.test/*' => Http::response('down', 503)]);

            return;
        }
        Http::fake([
            'fapi.test/fapi/v1/exchangeInfo' => Http::response(['symbols' => [[
                'symbol' => 'LTCUSDT', 'status' => 'TRADING',
                'filters' => [
                    ['filterType' => 'LOT_SIZE', 'stepSize' => '0.001', 'minQty' => '0.001'],
                    ['filterType' => 'MARKET_LOT_SIZE', 'stepSize' => '0.001', 'minQty' => '0.002'],
                    ['filterType' => 'MIN_NOTIONAL', 'notional' => '20'],
                ],
            ]]]),
            'fapi.test/fapi/v1/premiumIndex*' => Http::response(['symbol' => 'LTCUSDT', 'markPrice' => '52.5']),
        ]);
    }

    private function check(string $ticker = 'LTCUSDT'): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders($this->userHeaders($this->adminUniId))
            ->getJson('/api/admin/assets/binance-check?ticker='.$ticker);
    }

    private function account(array $overrides = [], array $owner = []): void
    {
        $this->makeAccount($this->makeUser($owner), $overrides);
    }

    public function test_reports_binance_rules_and_the_mark_price(): void
    {
        $this->fakeBinance();

        $this->check()->assertOk()->assertJson([
            'success' => true,
            'symbol' => 'LTCUSDT',
            'binance' => [
                'listed' => true, 'status' => 'TRADING', 'price' => 52.5,
                // The market-order minimum is the stricter of the two.
                'step_size' => '0.001', 'min_qty' => 0.002, 'min_notional' => 20,
            ],
            'binance_error' => null,
        ]);
    }

    public function test_a_coin_binance_does_not_list_is_reported_as_not_listed(): void
    {
        $this->fakeBinance();

        $this->check('NOPEUSDT')->assertOk()->assertJsonPath('binance.listed', false);
    }

    public function test_smallest_account_is_the_smallest_one_the_engine_would_trade(): void
    {
        $this->fakeBinance();
        $this->account(['name' => 'Big', 'balance' => 17000]);
        $this->account(['name' => 'Small', 'balance' => 600]);
        // None of these would open a position, so none of them is the smallest:
        $this->account(['name' => 'Under deposit', 'balance' => 300, 'initial_deposit' => 500]);
        $this->account(['name' => 'Blocked key', 'balance' => 200, 'key_status' => 'blocked']);
        $this->account(['name' => 'Sandbox', 'balance' => 100, 'is_sandbox' => 1]);
        $this->account(['name' => 'Disabled', 'balance' => 90, 'enabled' => 0]);
        $this->account(['name' => 'Suspended', 'balance' => 80], ['status' => 'suspended']);
        $this->account(['name' => 'Demo', 'balance' => 50, 'demo' => 1]);

        $this->check()->assertOk()->assertJson(['accounts' => [
            'count' => 2, 'smallest_balance' => 600, 'smallest_name' => 'Small', 'demo_only' => false,
        ]]);
    }

    public function test_top_ups_count_towards_the_deposit_minimum(): void
    {
        $this->fakeBinance();
        $this->account(['name' => 'Big', 'balance' => 5000]);
        $this->account(['api_key' => 'topped-up', 'name' => 'Topped up', 'balance' => 400, 'initial_deposit' => 500]);
        DB::table('binance_transactions')->insert([
            'api_key' => 'topped-up', 'uni_id' => 'x', 'type' => 'DEPOSIT', 'amount' => 600, 'tran_id' => 1, 'created_at' => now(),
        ]);

        $this->check()->assertOk()->assertJsonPath('accounts.smallest_name', 'Topped up');
    }

    public function test_demo_accounts_count_only_when_no_live_account_qualifies(): void
    {
        $this->fakeBinance();
        $this->account(['name' => 'Demo', 'balance' => 50, 'demo' => 1]);

        $this->check()->assertOk()->assertJson(['accounts' => [
            'count' => 1, 'smallest_name' => 'Demo', 'demo_only' => true,
        ]]);
    }

    public function test_leverage_is_the_latest_main_feed_signal_for_the_coin_then_any_coin(): void
    {
        $this->fakeBinance();
        $log = fn (array $o) => TradeLog::create(array_merge([
            'exchange' => 'binance', 'feed' => 'main', 'action' => 'BUY', 'ticker' => 'BTCUSDT',
            'success' => true, 'category' => 'signal', 'ts' => now(),
        ], $o));

        $this->check()->assertJson(['leverage' => ['value' => null, 'source' => null]]);

        $log(['leverage' => 10, 'feed' => 'test']);
        $this->check()->assertJson(['leverage' => ['value' => 10, 'source' => 'any_feed']]);

        $log(['leverage' => 20]);
        $this->check()->assertJson(['leverage' => ['value' => 20, 'source' => 'exchange']]);

        $log(['leverage' => 25, 'ticker' => 'LTCUSDT']);
        $log(['leverage' => 50]); // a newer signal on another coin does not win
        $this->check()->assertJson(['leverage' => ['value' => 25, 'source' => 'coin']]);
    }

    public function test_binance_down_still_reports_the_accounts(): void
    {
        $this->fakeBinance(up: false);
        $this->account(['name' => 'Small', 'balance' => 600]);

        $this->check()->assertOk()->assertJson([
            'binance' => null,
            'accounts' => ['smallest_name' => 'Small'],
        ])->assertJsonPath('binance_error', fn ($e) => is_string($e) && $e !== '');
    }

    public function test_only_admins_may_run_it(): void
    {
        $this->fakeBinance();
        $trader = $this->userHeaders($this->makeUser());

        $this->withHeaders($trader)->getJson('/api/admin/assets/binance-check?ticker=LTCUSDT')->assertForbidden();
    }

    public function test_the_ticker_is_validated(): void
    {
        $this->fakeBinance();

        $this->check('LTC%2FUSDT')->assertStatus(422);
    }
}
