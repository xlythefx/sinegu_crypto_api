<?php

namespace App\Services\Assets;

use App\Models\ExchangeAccount;
use App\Services\Exchanges\ExchangeSchema;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The asset form's "Binance check" (GET /api/admin/assets/binance-check): what Binance
 * and our smallest trading account say about a coin's size, so the form can show it in
 * dollars before the asset is saved. The dollar maths itself (minimum order, full-stack
 * margin, the suggested base size) is the form's (src/lib/binanceSizingCheck.ts), so
 * changing the base size or the 1×/2×/3× multiple never costs another request.
 *
 * - rules: /fapi/v1/exchangeInfo (weight 1, ~1.2 MB) cut down to the four figures per
 *   symbol, cached an hour, and the last good copy is served when Binance fails;
 * - price: /fapi/v1/premiumIndex?symbol= (weight 1), the mark price, cached 30 s;
 * - accounts: the ones the engine would open a position on — the same filter as
 *   EngineController::accounts (enabled, not sandbox, owner not suspended), minus
 *   blocked keys, with total_deposit (initial + net transfers) at least the engine's
 *   MIN_DEPOSIT and a balance. Live accounts, or the demo ones when no live account
 *   qualifies (`demo_only`);
 * - leverage: leverage is the SIGNAL's on this engine, not the asset's, so the figure
 *   is the latest one a main-feed Binance signal carried for this coin, else for any
 *   coin, else any feed's.
 */
class BinanceSizingCheck
{
    private const RULES_KEY = 'binance-check.rules';

    private const RULES_TTL = 3600;

    private const LAST_GOOD_TTL = 7 * 86400;

    private const PRICE_TTL = 30;

    /** @return array<string, mixed> */
    public function check(string $ticker): array
    {
        $symbol = strtoupper(trim($ticker));
        $rules = $this->rules();
        $binance = null;
        $error = null;

        if ($rules === null) {
            $error = 'Could not reach Binance to read the coin’s order rules.';
        } elseif (! isset($rules[$symbol])) {
            $binance = ['listed' => false, 'status' => null, 'price' => null,
                'step_size' => null, 'min_qty' => null, 'min_notional' => null];
        } else {
            $price = $this->price($symbol);
            if ($price === null) {
                $error = 'Could not read the coin’s price from Binance.';
            }
            $binance = ['listed' => true, 'price' => $price] + $rules[$symbol];
        }

        return [
            'ticker' => $symbol,
            'symbol' => $symbol,
            'binance' => $binance,
            'binance_error' => $error,
            'accounts' => $this->smallestAccount(),
            'leverage' => $this->signalLeverage($symbol),
            'min_deposit' => (float) config('services.engine.min_deposit', 1000),
            'checked_at' => now('UTC')->toIso8601ZuluString(),
        ];
    }

    /** @return array<string, array{status: ?string, step_size: ?string, min_qty: ?float, min_notional: ?float}>|null */
    private function rules(): ?array
    {
        $cached = Cache::get(self::RULES_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $fresh = $this->fetchRules();
        if ($fresh === null) {
            $lastGood = Cache::get(self::RULES_KEY.'.last-good');

            return is_array($lastGood) ? $lastGood : null;
        }
        Cache::put(self::RULES_KEY, $fresh, self::RULES_TTL);
        Cache::put(self::RULES_KEY.'.last-good', $fresh, self::LAST_GOOD_TTL);

        return $fresh;
    }

    private function fetchRules(): ?array
    {
        try {
            $response = $this->http()->get($this->base().'/fapi/v1/exchangeInfo');
        } catch (\Throwable $e) {
            Log::warning('binance-check: exchangeInfo failed', ['error' => $e->getMessage()]);

            return null;
        }
        $symbols = $response->successful() ? $response->json('symbols') : null;
        if (! is_array($symbols) || $symbols === []) {
            return null;
        }

        $out = [];
        foreach ($symbols as $s) {
            if (empty($s['symbol'])) {
                continue;
            }
            $step = null;
            $lotMin = 0.0;
            $marketMin = 0.0;
            $notional = null;
            foreach ($s['filters'] ?? [] as $f) {
                switch ($f['filterType'] ?? '') {
                    case 'LOT_SIZE':
                        $step = isset($f['stepSize']) ? (string) $f['stepSize'] : null;
                        $lotMin = (float) ($f['minQty'] ?? 0);
                        break;
                    case 'MARKET_LOT_SIZE':
                        $marketMin = (float) ($f['minQty'] ?? 0);
                        break;
                    case 'MIN_NOTIONAL':
                        $notional = isset($f['notional']) ? (float) $f['notional'] : null;
                        break;
                }
            }
            // The engine sends MARKET orders, so the stricter of the two minimums applies.
            $minQty = max($lotMin, $marketMin);
            $out[(string) $s['symbol']] = [
                'status' => $s['status'] ?? null,
                'step_size' => $step,
                'min_qty' => $minQty > 0 ? $minQty : null,
                'min_notional' => $notional,
            ];
        }

        return $out;
    }

    private function price(string $symbol): ?float
    {
        $key = 'binance-check.price.'.$symbol;
        $cached = Cache::get($key);
        if (is_float($cached)) {
            return $cached;
        }
        try {
            $response = $this->http()->get($this->base().'/fapi/v1/premiumIndex', ['symbol' => $symbol]);
        } catch (\Throwable $e) {
            return null;
        }
        $price = $response->successful() ? (float) $response->json('markPrice') : 0.0;
        if ($price <= 0) {
            return null;
        }
        Cache::put($key, $price, self::PRICE_TTL);

        return $price;
    }

    /** @return array{count: int, smallest_balance: ?float, smallest_name: ?string, demo_only: bool} */
    private function smallestAccount(): array
    {
        $schema = ExchangeSchema::for('binance');
        $t = $schema->accountsTable;
        $minDeposit = (float) config('services.engine.min_deposit', 1000);

        $accounts = $schema->accountQuery()
            ->join('user_credentials', 'user_credentials.uni_id', '=', "{$t}.uni_id")
            ->where("{$t}.enabled", 1)
            ->where("{$t}.is_sandbox", 0)
            ->where('user_credentials.status', '!=', 'suspended')
            ->where("{$t}.balance", '>', 0)
            ->get(["{$t}.api_key", "{$t}.name", "{$t}.balance", "{$t}.initial_deposit", "{$t}.demo", "{$t}.key_status"])
            ->reject(fn (ExchangeAccount $a) => $a->keyIsBlocked());

        $flows = $this->netFlows($accounts->pluck('api_key')->all(), $schema->transactions);
        $eligible = $accounts->filter(function (ExchangeAccount $a) use ($flows, $minDeposit) {
            if ($minDeposit <= 0) {
                return true;
            }
            if ($a->initial_deposit === null) {
                return false; // the engine fails closed on an unknown deposit
            }

            return (float) $a->initial_deposit + ($flows[$a->api_key] ?? 0.0) + 1e-9 >= $minDeposit;
        });

        $live = $eligible->filter(fn (ExchangeAccount $a) => ! $a->demo);
        $pool = ($live->isNotEmpty() ? $live : $eligible)->sortBy(fn (ExchangeAccount $a) => (float) $a->balance)->values();
        $smallest = $pool->first();

        return [
            'count' => $pool->count(),
            'smallest_balance' => $smallest ? round((float) $smallest->balance, 2) : null,
            'smallest_name' => $smallest?->name,
            'demo_only' => $pool->isNotEmpty() && $live->isEmpty(),
        ];
    }

    /**
     * api_key => deposits - withdrawals.
     *
     * @param  list<string>  $apiKeys
     * @return array<string, float>
     */
    private function netFlows(array $apiKeys, string $table): array
    {
        if ($apiKeys === []) {
            return [];
        }

        return DB::table($table)
            ->whereIn('api_key', $apiKeys)
            ->groupBy('api_key')
            ->selectRaw('api_key')
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'DEPOSIT' THEN amount WHEN type = 'WITHDRAWAL' THEN -amount ELSE 0 END), 0) AS net")
            ->pluck('net', 'api_key')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    /** @return array{value: ?int, source: ?string} source: coin | exchange | any_feed */
    private function signalLeverage(string $symbol): array
    {
        $base = fn () => DB::table('trade_logs')->where('exchange', 'binance')->whereNotNull('leverage')->where('leverage', '>', 0);

        foreach ([
            'coin' => $base()->where('feed', 'main')->where('ticker', $symbol),
            'exchange' => $base()->where('feed', 'main'),
            'any_feed' => $base(),
        ] as $source => $query) {
            $value = $query->orderByDesc('id')->value('leverage');
            if ($value !== null) {
                return ['value' => (int) $value, 'source' => $source];
            }
        }

        return ['value' => null, 'source' => null];
    }

    private function base(): string
    {
        return rtrim((string) config('services.market.base_url', 'https://fapi.binance.com'), '/');
    }

    private function http(): PendingRequest
    {
        $request = Http::timeout(8)
            ->connectTimeout((int) config('services.market.connect_timeout', 3))
            ->acceptJson();
        $ca = (string) config('services.market.ca_bundle', '');

        return $ca === '' ? $request : $request->withOptions(['verify' => $ca]);
    }
}
