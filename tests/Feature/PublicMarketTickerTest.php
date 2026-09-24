<?php

namespace Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Covers GET /api/public/market-ticker — the landing page's quote strip.
 *
 * The load-bearing assertions are the two failure ones: a quote we could not
 * read must never be published as 0.00, and a fetch that failed must fall back
 * to the last good strip rather than emptying the marketing page.
 */
class PublicMarketTickerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();  // the endpoint caches for 30 seconds
        config([
            'services.market.symbols' => ['BTCUSDT', 'ETHUSDT', 'LTCUSDT'],
            'services.market.funding_symbol' => 'LTCUSDT',
            'services.market.base_url' => 'https://fapi.binance.com',
        ]);
    }

    /** A 24hr ticker body with only the two fields the controller reads. */
    private function ticker(string $price, string $changePct): array
    {
        return ['lastPrice' => $price, 'priceChangePercent' => $changePct];
    }

    private function fakeAll(): void
    {
        Http::fake([
            '*ticker/24hr?symbol=BTCUSDT*' => Http::response($this->ticker('68412.50', '1.840')),
            '*ticker/24hr?symbol=ETHUSDT*' => Http::response($this->ticker('3571.20', '-0.420')),
            '*ticker/24hr?symbol=LTCUSDT*' => Http::response($this->ticker('118.44', '2.100')),
            '*premiumIndex*' => Http::response([
                'lastFundingRate' => '0.000093',
                'nextFundingTime' => 1790265600000,  // 2026-09-24T16:00:00Z
            ]),
        ]);
    }

    public function test_it_publishes_every_configured_symbol_with_a_usdt_label(): void
    {
        $this->fakeAll();

        $res = $this->getJson('/api/public/market-ticker');

        $res->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonCount(3, 'quotes')
            // The order is the configured order — the strip reads left to right.
            ->assertJsonPath('quotes.0.symbol', 'BTCUSDT')
            ->assertJsonPath('quotes.0.label', 'BTC/USDT')
            ->assertJsonPath('quotes.0.price', 68412.50)
            ->assertJsonPath('quotes.0.change_pct', 1.84)
            ->assertJsonPath('quotes.1.label', 'ETH/USDT')
            ->assertJsonPath('quotes.2.label', 'LTC/USDT')
            ->assertJsonPath('quotes.2.change_pct', 2.1);
    }

    public function test_it_publishes_the_traded_pairs_funding_rate_as_a_percent(): void
    {
        $this->fakeAll();

        $this->getJson('/api/public/market-ticker')
            ->assertOk()
            // Binance reports the rate as a fraction; the strip prints a percent.
            ->assertJsonPath('funding.rate_pct', 0.0093)
            ->assertJsonPath('funding.label', 'LTC/USDT')
            ->assertJsonPath('funding.next_at', '2026-09-24T16:00:00Z');
    }

    /**
     * A malformed or failed read is dropped, never published as a price of
     * zero — the same empty-vs-unavailable rule the pollers follow. One dead
     * symbol must not take the other two down with it.
     */
    public function test_a_failed_quote_is_dropped_rather_than_published_as_zero(): void
    {
        Http::fake([
            '*ticker/24hr?symbol=BTCUSDT*' => Http::response($this->ticker('68412.50', '1.840')),
            '*ticker/24hr?symbol=ETHUSDT*' => Http::response([], 500),
            // A zero price is a malformed answer, not a price.
            '*ticker/24hr?symbol=LTCUSDT*' => Http::response($this->ticker('0', '0')),
            '*premiumIndex*' => Http::response([], 500),
        ]);

        $res = $this->getJson('/api/public/market-ticker');

        $res->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonCount(1, 'quotes')
            ->assertJsonPath('quotes.0.symbol', 'BTCUSDT')
            // Funding we could not read is absent, not 0.0000%.
            ->assertJsonPath('funding', null);
    }

    /**
     * When every read fails the endpoint serves the LAST GOOD strip. An empty
     * marketing page is the one outcome worse than a slightly stale price, and
     * `updated_at` is how the client tells the difference.
     */
    public function test_a_failed_fetch_falls_back_to_the_last_good_payload(): void
    {
        $this->fakeAll();
        $good = $this->getJson('/api/public/market-ticker')->json();

        // Past the 30s window, with the upstream now refusing to answer at all.
        Cache::forget('public.market-ticker');
        Http::fake(fn () => throw new ConnectionException('upstream down'));

        $res = $this->getJson('/api/public/market-ticker');

        $res->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonCount(3, 'quotes')
            // Not restamped: the client must be able to see how old this is.
            ->assertJsonPath('updated_at', $good['updated_at']);
    }

    /** With nothing good cached either, the strip renders nothing at all. */
    public function test_it_reports_unavailable_when_nothing_can_be_read(): void
    {
        Http::fake(fn () => throw new ConnectionException('upstream down'));

        $this->getJson('/api/public/market-ticker')
            ->assertOk()
            ->assertJsonPath('available', false)
            ->assertJsonCount(0, 'quotes')
            ->assertJsonPath('funding', null);
    }

    /**
     * The payload is cached, so an upstream outage cannot turn page views into
     * outbound calls — and neither can traffic.
     */
    public function test_it_reads_upstream_once_per_cache_window(): void
    {
        $this->fakeAll();

        $this->getJson('/api/public/market-ticker')->assertOk();
        $this->getJson('/api/public/market-ticker')->assertOk();
        $this->getJson('/api/public/market-ticker')->assertOk();

        // 3 quotes + 1 funding, for three page views.
        Http::assertSentCount(4);
    }
}
