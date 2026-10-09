<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\GuardsEngineExchange;
use App\Models\Asset;
use App\Models\ExchangeAccount;
use App\Models\OpenStrategy;
use App\Models\TradeLog;
use App\Services\Assets\StreakSizing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Machine-to-machine reads + logging for the Python trading engine.
 * All routes live under /api/engine/{exchange}/* behind the `engine`
 * middleware (X-Engine-Secret) — no user token involved.
 */
class EngineController extends Controller
{
    use GuardsEngineExchange;

    /**
     * GET /api/engine/{exchange}/accounts — every account the engine may trade.
     *
     * Filters: enabled, not soft-deleted, not sandbox, owner not suspended
     * (the owner join closes the mother project's gap where suspending a user
     * did not stop their bot trading). Deliberately includes secret_key — the
     * engine needs it to sign exchange requests; ExchangeAccount::$hidden still
     * protects every user-facing endpoint. Which accounts table answers is
     * decided by the route's {exchange} through ExchangeSchema.
     *
     * An account whose key the exchange refuses is deliberately still LISTED,
     * carrying `key_blocked: true`. The engine skips it in the trade fan-out
     * but keeps polling it, and that poll is the only thing that can discover
     * the key works again. Filtering it out here instead would freeze it as
     * broken forever and then disconnect it at the 3-day deadline even though
     * the user had already fixed their whitelist.
     */
    public function accounts(string $exchange): JsonResponse
    {
        if ($guard = $this->guardExchange($exchange)) {
            return $guard;
        }

        $schema = $this->schema($exchange);
        $t = $schema->accountsTable;

        $accounts = $schema->accountQuery()
            ->join('user_credentials', 'user_credentials.uni_id', '=', "{$t}.uni_id")
            ->where("{$t}.enabled", 1)
            ->where("{$t}.is_sandbox", 0)
            ->where('user_credentials.status', '!=', 'suspended')
            ->orderBy("{$t}.created_at")
            ->get([
                "{$t}.api_key",
                "{$t}.secret_key",
                "{$t}.name",
                "{$t}.uni_id",
                "{$t}.balance",
                "{$t}.initial_deposit",
                "{$t}.currency_type",
                "{$t}.demo",
                "{$t}.enabled",
                "{$t}.key_status",
                // Which row the PUBLIC channel speaks for. The published track
                // record is the master's alone (PublicStatsController scopes to
                // `type = 'master'`), so a close percentage blended across every
                // filled account could never reconcile with the daily recap.
                // Aliased: `type` would collide with nothing today, but the
                // account tables are the ones that grow columns.
                'user_credentials.type as owner_type',
            ]);

        $netFlow = $this->netTransferFlow($accounts->pluck('api_key')->all(), $schema->transactions);

        return response()->json([
            'success' => true,
            'accounts' => $accounts->map(function (ExchangeAccount $a) use ($netFlow) {
                $initial = $a->initial_deposit !== null ? (float) $a->initial_deposit : null;

                return [
                    'api_key' => $a->api_key,
                    'secret_key' => $a->secret_key,
                    'name' => $a->name,
                    'uni_id' => $a->uni_id,
                    'balance' => $a->balance !== null ? (float) $a->balance : null,
                    'initial_deposit' => $initial,
                    // Capital the account has actually been funded with, net of
                    // withdrawals — the engine's minimum-deposit gate reads this,
                    // not initial_deposit (which is a one-shot snapshot that never
                    // grows, so top-ups would otherwise never count). Same figure
                    // invoicing bills against (BinancePnlSource::adjustedDeposit).
                    // null when unknown: the engine fails closed on it.
                    'total_deposit' => $initial === null
                        ? null
                        : round($initial + ($netFlow[$a->api_key] ?? 0.0), 8),
                    'currency_type' => $a->currency_type,
                    'demo' => (bool) $a->demo,
                    'enabled' => (bool) $a->enabled,
                    // Skip in the fan-out, keep polling — see the docblock.
                    'key_blocked' => $a->keyIsBlocked(),
                    // The account the public channel reports on; see the select.
                    'is_master' => $a->owner_type === 'master',
                ];
            })->values(),
        ]);
    }

    /**
     * api_key => (deposits - withdrawals) across the exchange's transactions
     * table. One grouped query for the whole account set, not one per account.
     *
     * @param  list<string>  $apiKeys
     * @return array<string, float>
     */
    private function netTransferFlow(array $apiKeys, string $transactionsTable): array
    {
        if (! $apiKeys) {
            return [];
        }

        return DB::table($transactionsTable)
            ->whereIn('api_key', $apiKeys)
            ->groupBy('api_key')
            ->selectRaw('api_key')
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN type = 'DEPOSIT' THEN amount ".
                "WHEN type = 'WITHDRAWAL' THEN -amount ELSE 0 END), 0) AS net"
            )
            ->pluck('net', 'api_key')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    /** GET /api/engine/{exchange}/assets?broker=Binance — enabled tradeable assets. */
    public function assets(string $exchange, Request $request): JsonResponse
    {
        if ($guard = $this->guardExchange($exchange)) {
            return $guard;
        }

        $query = Asset::with('streakSizes')->where('enabled', 1);
        if ($broker = $request->query('broker')) {
            $query->where('broker', $broker);
        }

        return response()->json([
            'success' => true,
            'assets' => $query->orderBy('ticker')->get()->map(fn (Asset $a) => [
                'ticker' => $a->ticker,
                'broker' => $a->broker,
                'side' => $a->side,
                'base_size' => (float) $a->base_size,
                'max_increments' => (float) $a->max_increments,
                'enabled' => (bool) $a->enabled,
                // Streak sizing (StreakSizing): [{kind, streak, size}]. A list,
                // not a map: PHP encodes an empty map as [] and the engine
                // would have to guess which shape it got.
                'streak_sizing_enabled' => (bool) $a->streak_sizing_enabled,
                'streak_sizes' => StreakSizing::ladder($a),
            ])->values(),
        ]);
    }

    /**
     * GET /api/engine/{exchange}/streaks?symbol=&depth= — every account's
     * current run on one coin, in one read, for the fan-out (the same batching
     * as positions/check). `streaks` maps api_key → SIGNED run: -3 = three
     * losses in a row, +2 = two wins, length capped at depth. An account absent
     * from it has no known close on the coin and trades base size. Always a
     * JSON object, never [].
     */
    public function streaks(string $exchange, Request $request, StreakSizing $streakSizing): JsonResponse
    {
        if ($guard = $this->guardExchange($exchange)) {
            return $guard;
        }

        $data = $request->validate([
            'symbol' => ['required', 'string', 'max:32'],
            'depth' => ['nullable', 'integer', 'between:1,'.StreakSizing::MAX_STEPS],
        ]);
        $depth = (int) ($data['depth'] ?? StreakSizing::MAX_STEPS);

        return response()->json([
            'success' => true,
            'symbol' => $data['symbol'],
            'depth' => $depth,
            'streaks' => (object) $streakSizing->runs($exchange, $data['symbol'], $depth),
        ]);
    }

    /** POST /api/engine/{exchange}/trade-logs — one row per processed signal. */
    public function storeTradeLog(string $exchange, Request $request): JsonResponse
    {
        if ($guard = $this->guardExchange($exchange)) {
            return $guard;
        }

        $data = $request->validate([
            'action' => ['required', 'string', 'max:16'],
            'ticker' => ['required', 'string', 'max:20'],
            'success' => ['required', 'boolean'],
            'price' => ['nullable', 'numeric'],
            'strategy' => ['nullable', 'string', 'max:100'],
            'leverage' => ['nullable', 'integer', 'between:1,125'],
            'category' => ['nullable', 'string', 'max:32'],
            'target_count' => ['nullable', 'integer', 'min:0'],
            'filled' => ['nullable', 'integer', 'min:0'],
            'failed' => ['nullable', 'integer', 'min:0'],
            'skipped' => ['nullable', 'integer', 'min:0'],
            'details' => ['nullable', 'array'],
            'ts' => ['required', 'date'],
        ]);

        $log = TradeLog::create($data + ['exchange' => $exchange]);

        return response()->json(['success' => true, 'trade_log_id' => $log->id], 201);
    }

    /** GET /api/engine/{exchange}/open-strategies?api_key=&symbol= — entry tags for open positions. */
    public function openStrategies(string $exchange, Request $request): JsonResponse
    {
        if ($guard = $this->guardExchange($exchange)) {
            return $guard;
        }

        $query = OpenStrategy::where('exchange', $exchange);
        if ($apiKey = $request->query('api_key')) {
            $query->where('api_key', $apiKey);
        }
        if ($symbol = $request->query('symbol')) {
            $query->where('symbol', $symbol);
        }

        return response()->json([
            'success' => true,
            'open_strategies' => $query->get(['api_key', 'symbol', 'position_side', 'strategy'])->values(),
        ]);
    }

    /** POST /api/engine/{exchange}/open-strategies — remember an entry's strategy tag. */
    public function storeOpenStrategy(string $exchange, Request $request): JsonResponse
    {
        if ($guard = $this->guardExchange($exchange)) {
            return $guard;
        }

        $data = $request->validate([
            'api_key' => ['required', 'string', 'max:128'],
            'symbol' => ['required', 'string', 'max:32'],
            'position_side' => ['required', 'string', 'max:8'],
            'strategy' => ['required', 'string', 'max:100'],
        ]);

        OpenStrategy::updateOrCreate(
            [
                'exchange' => $exchange,
                'api_key' => $data['api_key'],
                'symbol' => $data['symbol'],
                'position_side' => $data['position_side'],
            ],
            ['strategy' => $data['strategy']]
        );

        return response()->json(['success' => true]);
    }

    /** DELETE /api/engine/{exchange}/open-strategies — consume a tag after the position closes. */
    public function destroyOpenStrategy(string $exchange, Request $request): JsonResponse
    {
        if ($guard = $this->guardExchange($exchange)) {
            return $guard;
        }

        $data = $request->validate([
            'api_key' => ['required', 'string', 'max:128'],
            'symbol' => ['required', 'string', 'max:32'],
            'position_side' => ['required', 'string', 'max:8'],
        ]);

        $deleted = OpenStrategy::where('exchange', $exchange)
            ->where('api_key', $data['api_key'])
            ->where('symbol', $data['symbol'])
            ->where('position_side', $data['position_side'])
            ->delete();

        return response()->json(['success' => true, 'deleted' => $deleted]);
    }
}
