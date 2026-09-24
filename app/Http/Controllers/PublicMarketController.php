<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * GET /api/public/market-ticker — the landing page's quote strip.
 *
 * What this publishes is BINANCE'S OWN market data for the USDⓈ-M perpetuals
 * the product trades, not anything of ours, so the percentages-and-counts-only
 * rule that governs {@see PublicStatsController} has nothing to bite on here.
 *
 * Two rules it does follow:
 *
 * - **Proxied, not called from the browser.** Binance geo-blocks some regions
 *   and ad-blockers eat the request, so a direct call would leave the strip
 *   blank for a slice of visitors and we would never hear about it. One cached
 *   call per window also means traffic cannot turn into upstream load.
 * - **Empty is not unavailable.** A quote we could not read is dropped, never
 *   published as 0.00 — the same rule the pollers follow. A fetch that failed
 *   outright serves the LAST GOOD payload instead of an empty strip; its
 *   `updated_at` is how the client can tell how old it is.
 *
 * Weight: one `ticker/24hr` per symbol plus one `premiumIndex`, all weight 1,
 * so ~22/min for ten symbols at a 30s cache — against the 2400/min per-IP
 * ceiling this box shares with the engine's pollers, where past-positions alone
 * is ~95%. The no-symbol form of `ticker/24hr` costs 40 whatever the strip
 * carries, so per-symbol stays the cheaper read well past {@see MAX_SYMBOLS}.
 */
class PublicMarketController extends Controller
{
    /** How long one fetched payload is served before the next upstream read. */
    private const CACHE_TTL_SECONDS = 30;

    /** How long a good payload stays available as the fallback for a failed read. */
    private const STALE_TTL_SECONDS = 3600;

    private const CACHE_KEY = 'public.market-ticker';

    private const LAST_GOOD_KEY = 'public.market-ticker.last-good';

    /** Most symbols one strip may carry — the config is ours, but so is the weight. */
    private const MAX_SYMBOLS = 16;

    /**
     * Always 200. `available: false` with an empty `quotes` list means every
     * upstream read failed and nothing good is cached — the strip renders
     * nothing rather than zeros.
     */
    public function ticker(): JsonResponse
    {
        return response()->json($this->payload());
    }

    private function payload(): array
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $fresh = $this->fetch();

        if ($fresh['available']) {
            Cache::put(self::LAST_GOOD_KEY, $fresh, self::STALE_TTL_SECONDS);
            $result = $fresh;
        } else {
            // Serve the last good strip rather than an empty one. Its own
            // `updated_at` says how stale it is; we do not restamp it.
            $result = Cache::get(self::LAST_GOOD_KEY, $fresh);
        }

        // Cached either way: an upstream outage must not turn every page view
        // into an outbound call.
        Cache::put(self::CACHE_KEY, $result, self::CACHE_TTL_SECONDS);

        return $result;
    }

    private function fetch(): array
    {
        $symbols = $this->symbols();
        $fundingSymbol = $this->fundingSymbol();
        $base = rtrim((string) config('services.market.base_url'), '/');

        $responses = Http::pool(function (Pool $pool) use ($symbols, $fundingSymbol, $base) {
            $requests = [];
            foreach ($symbols as $symbol) {
                $requests[] = $this->pooled($pool, 'q:'.$symbol)
                    ->get($base.'/fapi/v1/ticker/24hr', ['symbol' => $symbol]);
            }
            if ($fundingSymbol !== null) {
                $requests[] = $this->pooled($pool, 'funding')
                    ->get($base.'/fapi/v1/premiumIndex', ['symbol' => $fundingSymbol]);
            }

            return $requests;
        });

        $quotes = [];
        foreach ($symbols as $symbol) {
            $quote = $this->quote($symbol, $responses['q:'.$symbol] ?? null);
            if ($quote !== null) {
                $quotes[] = $quote;
            }
        }

        if ($quotes === []) {
            Log::warning('public market ticker: no quote could be read', ['symbols' => $symbols]);
        }

        return [
            'available' => $quotes !== [],
            'quotes' => $quotes,
            'funding' => $fundingSymbol === null
                ? null
                : $this->funding($fundingSymbol, $responses['funding'] ?? null),
            'updated_at' => Carbon::now('UTC')->toIso8601ZuluString(),
        ];
    }

    /**
     * One symbol's line, or null when we could not read it. A price of zero is
     * not a price — it is a malformed answer, and publishing it would put
     * "BTC/USDT 0.00" on the marketing page.
     */
    private function quote(string $symbol, mixed $response): ?array
    {
        $body = $this->body($response);
        if ($body === null) {
            return null;
        }

        $price = isset($body['lastPrice']) ? (float) $body['lastPrice'] : 0.0;
        if ($price <= 0) {
            return null;
        }

        return [
            'symbol' => $symbol,
            'label' => $this->label($symbol),
            'price' => $price,
            // A missing 24h change is not a flat one: the client prints nothing.
            'change_pct' => isset($body['priceChangePercent'])
                ? round((float) $body['priceChangePercent'], 2)
                : null,
        ];
    }

    /**
     * The traded pair's funding rate and when it is next charged. Null whenever
     * the read failed or the rate is absent — the strip then drops the item,
     * which is the honest answer; 0.0000% is a real rate Binance can report.
     */
    private function funding(string $symbol, mixed $response): ?array
    {
        $body = $this->body($response);
        if ($body === null || ! isset($body['lastFundingRate'])) {
            return null;
        }

        $nextMs = (int) ($body['nextFundingTime'] ?? 0);

        return [
            'symbol' => $symbol,
            'label' => $this->label($symbol),
            // Reported as a fraction (0.000093); the strip prints a percent.
            'rate_pct' => (float) $body['lastFundingRate'] * 100,
            'next_at' => $nextMs > 0
                ? Carbon::createFromTimestampMs($nextMs, 'UTC')->toIso8601ZuluString()
                : null,
        ];
    }

    /**
     * A pooled request's decoded body, or null when it never answered. A failed
     * pooled request comes back as the exception, not a Response, so the type
     * check is what separates "no answer" from "an answer we can read".
     */
    private function body(mixed $response): ?array
    {
        if (! $response instanceof Response || ! $response->successful()) {
            return null;
        }

        $body = $response->json();

        return is_array($body) ? $body : null;
    }

    private function pooled(Pool $pool, string $key): PendingRequest
    {
        $request = $pool->as($key)
            ->timeout((int) config('services.market.timeout', 4))
            ->connectTimeout((int) config('services.market.connect_timeout', 3))
            ->acceptJson();

        $ca = (string) config('services.market.ca_bundle', '');

        return $ca === '' ? $request : $request->withOptions(['verify' => $ca]);
    }

    /** @return list<string> */
    private function symbols(): array
    {
        $configured = (array) config('services.market.symbols', []);
        $symbols = [];
        foreach ($configured as $symbol) {
            $symbol = strtoupper(trim((string) $symbol));
            if ($symbol !== '' && ! in_array($symbol, $symbols, true)) {
                $symbols[] = $symbol;
            }
        }

        return array_slice($symbols, 0, self::MAX_SYMBOLS);
    }

    private function fundingSymbol(): ?string
    {
        $symbol = strtoupper(trim((string) config('services.market.funding_symbol', '')));

        return $symbol === '' ? null : $symbol;
    }

    /**
     * `LTCUSDT` → `LTC/USDT`. The quote asset is named, not assumed to be
     * dollars: what these prices quote IS the USDT perpetual, and labelling it
     * "LTC/USD" would be a small fiction on the one page selling the product.
     */
    private function label(string $symbol): string
    {
        foreach (['USDT', 'USDC', 'BUSD', 'USD'] as $quote) {
            if (str_ends_with($symbol, $quote) && strlen($symbol) > strlen($quote)) {
                return substr($symbol, 0, -strlen($quote)).'/'.$quote;
            }
        }

        return $symbol;
    }
}
