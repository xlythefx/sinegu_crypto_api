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

    /** The book moves; a strip-length cache would render it dead on arrival. */
    private const BOOK_CACHE_KEY = 'public.order-book';

    /** The book's 24h volume line, which changes far slower than the book. */
    private const BOOK_TICKER_KEY = 'public.order-book.ticker';

    private const BOOK_TICKER_TTL_SECONDS = 30;

    /** Most symbols one strip may carry — the config is ours, but so is the weight. */
    private const MAX_SYMBOLS = 16;

    /**
     * Always 200. `available: false` with an empty `quotes` list means every
     * upstream read failed and nothing good is cached — the strip renders
     * nothing rather than zeros.
     */
    public function ticker(): JsonResponse
    {
        return response()->json($this->cached(
            self::CACHE_KEY,
            self::CACHE_TTL_SECONDS,
            fn () => $this->fetch(),
        ));
    }

    /**
     * GET /api/public/order-book — the landing hero's live book.
     *
     * Raw levels, not rows: the client buckets them, because how a book is
     * GROUPED is a display choice (every exchange UI has a selector for it)
     * and five consecutive levels of BTCUSDT span about forty cents — true,
     * and unreadable as a ladder.
     */
    public function orderBook(): JsonResponse
    {
        return response()->json($this->cached(
            self::BOOK_CACHE_KEY,
            $this->bookTtl(),
            fn () => $this->fetchBook(),
        ));
    }

    /**
     * Serve `$key` from cache, else fetch — and on a failed fetch fall back to
     * the last payload that worked rather than publishing an empty one. The
     * verdict is cached either way, so an upstream outage cannot turn page
     * views into outbound calls.
     *
     * @param  callable(): array  $fetch
     */
    private function cached(string $key, int $ttl, callable $fetch): array
    {
        $cachedValue = Cache::get($key);
        if (is_array($cachedValue)) {
            return $cachedValue;
        }

        $fresh = $fetch();
        $lastGoodKey = $key.'.last-good';

        if ($fresh['available']) {
            Cache::put($lastGoodKey, $fresh, self::STALE_TTL_SECONDS);
            $result = $fresh;
        } else {
            // Its own `updated_at` says how stale it is; we do not restamp it.
            $result = Cache::get($lastGoodKey, $fresh);
        }

        Cache::put($key, $result, $ttl);

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
     * The hero book: one depth read plus the 24h line, which is cached far
     * longer because a day's volume does not move in five seconds.
     */
    private function fetchBook(): array
    {
        $symbol = $this->bookSymbol();
        $base = rtrim((string) config('services.market.base_url'), '/');
        $limit = $this->bookLimit();

        $depth = $this->body(Http::pool(fn (Pool $pool) => [
            $this->pooled($pool, 'depth')
                ->get($base.'/fapi/v1/depth', ['symbol' => $symbol, 'limit' => $limit]),
        ])['depth'] ?? null);

        $levels = $this->levels($depth['bids'] ?? null);
        $asks = $this->levels($depth['asks'] ?? null);

        if ($levels === [] || $asks === []) {
            Log::warning('public order book: depth could not be read', ['symbol' => $symbol]);

            return ['available' => false, 'symbol' => $symbol] + $this->emptyBook();
        }

        $line = $this->bookTicker($symbol, $base);

        return [
            'available' => true,
            'symbol' => $symbol,
            'label' => (string) config('services.market.order_book.label', $symbol),
            'bids' => $levels,
            'asks' => $asks,
            'price' => $line['price'],
            'change_pct' => $line['change_pct'],
            'quote_volume' => $line['quote_volume'],
            // The EXCHANGE's own stamp for this book, not our clock — it is
            // what the freshness dot should answer to.
            'book_at' => isset($depth['E']) && (int) $depth['E'] > 0
                ? Carbon::createFromTimestampMs((int) $depth['E'], 'UTC')->toIso8601ZuluString()
                : null,
            'updated_at' => Carbon::now('UTC')->toIso8601ZuluString(),
        ];
    }

    /** The shape an unavailable book still answers with, so clients need no branches. */
    private function emptyBook(): array
    {
        return [
            'label' => (string) config('services.market.order_book.label', ''),
            'bids' => [],
            'asks' => [],
            'price' => null,
            'change_pct' => null,
            'quote_volume' => null,
            'book_at' => null,
            'updated_at' => Carbon::now('UTC')->toIso8601ZuluString(),
        ];
    }

    /**
     * `[["84266.50","7.614"], …]` → `[[84266.5, 7.614], …]`, dropping anything
     * malformed. A zero-priced or zero-sized level is not a level.
     *
     * @return list<array{0: float, 1: float}>
     */
    private function levels(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $levels = [];
        foreach ($raw as $level) {
            if (! is_array($level) || count($level) < 2) {
                continue;
            }
            $price = (float) $level[0];
            $size = (float) $level[1];
            if ($price > 0 && $size > 0) {
                $levels[] = [$price, $size];
            }
        }

        return $levels;
    }

    /**
     * Last price, 24h move and 24h quote volume for the book's symbol, on their
     * own longer cache. Every field is null when the read failed — the card
     * drops those lines and still draws the book, which is the part that moves.
     */
    private function bookTicker(string $symbol, string $base): array
    {
        $cachedLine = Cache::get(self::BOOK_TICKER_KEY);
        if (is_array($cachedLine) && ($cachedLine['symbol'] ?? null) === $symbol) {
            return $cachedLine;
        }

        $body = $this->body(Http::pool(fn (Pool $pool) => [
            $this->pooled($pool, 't')->get($base.'/fapi/v1/ticker/24hr', ['symbol' => $symbol]),
        ])['t'] ?? null);

        $price = isset($body['lastPrice']) ? (float) $body['lastPrice'] : 0.0;
        $line = [
            'symbol' => $symbol,
            'price' => $price > 0 ? $price : null,
            'change_pct' => isset($body['priceChangePercent'])
                ? round((float) $body['priceChangePercent'], 2)
                : null,
            'quote_volume' => isset($body['quoteVolume']) ? (float) $body['quoteVolume'] : null,
        ];

        if ($line['price'] !== null) {
            Cache::put(self::BOOK_TICKER_KEY, $line, self::BOOK_TICKER_TTL_SECONDS);
        }

        return $line;
    }

    private function bookSymbol(): string
    {
        $symbol = strtoupper(trim((string) config('services.market.order_book.symbol', '')));

        return $symbol === '' ? 'BTCUSDT' : $symbol;
    }

    /**
     * Binance charges depth by size: 5–50 cost weight 2, 100 costs 5, 500 costs
     * 10, 1000 costs 20. 100 is the smallest that covers enough of the book to
     * bucket a readable ladder, so anything larger is clamped away.
     */
    private function bookLimit(): int
    {
        $limit = (int) config('services.market.order_book.limit', 100);

        return in_array($limit, [5, 10, 20, 50, 100, 500, 1000], true) ? $limit : 100;
    }

    private function bookTtl(): int
    {
        return max(1, (int) config('services.market.order_book.ttl', 5));
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
