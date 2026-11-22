<?php

namespace App\Http\Controllers;

use App\Services\Exchanges\ExchangeSchema;
use App\Models\RiskAlert;
use App\Services\Alerts\RiskAlerts;
use App\Services\DevBot\DevBotReporter;
use App\Services\Alerts\FeedAudit;
use Illuminate\Validation\Rule;
use App\Services\Engine\WebhookPaths;
use App\Http\Controllers\Concerns\GuardsEngineExchange;
use App\Models\Asset;
use App\Models\ExchangeAccount;
use App\Models\OpenStrategy;
use App\Models\TradeLog;
use App\Models\UserCredential;
use App\Services\Admin\SignalDelivery;
use App\Services\Assets\StreakSizing;
use App\Services\Notifications\TradeAlerts;
use App\Services\Positions\EntryStreak;
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
                    // Which signal feed this account follows. The engine fans a
                    // MAIN-feed signal (TradingView's webhooks) out to `main`
                    // rows only and a TEST-feed signal (WebhookPaths::TEST) to
                    // `test` rows only, so a tester's account never trades
                    // TradingView's signals and a customer never trades a test
                    // signal. The API decides the feed from the owner's role;
                    // the engine never reads a role.
                    'feed' => WebhookPaths::feedForRole($a->owner_type),
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

    /**
     * GET /api/engine/{exchange}/assets?broker=Binance — enabled tradeable
     * assets, plus `disabled`: the coins that EXIST but are switched off.
     *
     * `assets` stays enabled-only (what may be traded). `disabled` is there
     * only so a rejected entry can say WHY — "turned off" (or "switched off by
     * the kill switch") rather than the old `asset_not_configured`, which read
     * the same for a coin an admin paused and a coin nobody ever added. An
     * engine older than the key ignores it.
     */
    public function assets(string $exchange, Request $request): JsonResponse
    {
        if ($guard = $this->guardExchange($exchange)) {
            return $guard;
        }

        $broker = $request->query('broker');
        $query = Asset::with('streakSizes')->where('enabled', 1);
        $disabled = Asset::where('enabled', 0);
        if ($broker) {
            $query->where('broker', $broker);
            $disabled->where('broker', $broker);
        }

        return response()->json([
            'success' => true,
            'disabled' => $disabled->orderBy('ticker')
                ->get(['ticker', 'killed_at', 'killed_reason', 'enabled'])
                ->map(fn (Asset $a) => [
                    'ticker' => $a->ticker,
                    'killed' => $a->isKilled(),
                    'killed_reason' => $a->isKilled() ? $a->killed_reason : null,
                ])->values(),
            'assets' => $query->orderBy('ticker')->get()->map(fn (Asset $a) => [
                'ticker' => $a->ticker,
                'broker' => $a->broker,
                'side' => $a->side,
                'base_size' => (float) $a->base_size,
                'max_increments' => (float) $a->max_increments,
                // The coin's own leverage, which beats the alert's; null = the alert's.
                'leverage' => $a->leverage === null ? null : (int) $a->leverage,
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
     * GET /api/engine/{exchange}/streaks?symbol=&depth= — the run every account
     * SIZES on for one coin, in one read, for the fan-out (the same batching
     * as positions/check). Customers' and the master's entries carry the
     * master's run (StreakSizing::followedRuns). `streaks` maps api_key →
     * SIGNED run: -3 = three losses in a row, +2 = two wins, length capped at
     * depth. An account absent from it is on no run and trades base size.
     * Always a JSON object, never [].
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
            // Customers and the master size on the MASTER's run (2026-11-13,
            // StreakSizing::followedRuns); testers and other staff on their own.
            'streaks' => (object) $streakSizing->followedRuns($exchange, $data['symbol'], $depth),
            // The master's own run, or null with no real-money master here —
            // informational; the engine reads `streaks` only.
            'master_run' => $streakSizing->masterRun($exchange, $data['symbol'], $depth),
        ]);
    }

    /**
     * POST /api/engine/{exchange}/alerts/exit-stuck — a CLOSE the exchange
     * refused outright, or one the retry queue gave up on (the engine's red
     * "MANUAL ACTION" moment). Emailed at once to TRADE_ALERT_EMAILS
     * (TradeAlerts::exitStuck); everything else waits for the hourly summary.
     * Always 200 when valid — the email is best-effort and a refusal here
     * would only make the engine log noise; Telegram already carries it.
     */
    public function exitStuck(string $exchange, Request $request, TradeAlerts $alerts, RiskAlerts $risk): JsonResponse
    {
        if ($guard = $this->guardExchange($exchange)) {
            return $guard;
        }
        $data = $request->validate([
            'action' => ['required', 'string', 'in:EXIT_LONG,EXIT_SHORT'],
            'ticker' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9_]+$/'],
            'why' => ['required', 'string', 'in:rejected,abandoned'],
            'attempts' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'total' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'accounts' => ['required', 'array', 'min:1', 'max:50'],
            'accounts.*.uni_id' => ['nullable', 'string', 'max:64'],
            'accounts.*.name' => ['nullable', 'string', 'max:255'],
            'accounts.*.error' => ['nullable', 'string', 'max:300'],
        ]);

        $sent = $alerts->exitStuck(
            $exchange, $data['action'], $data['ticker'], $data['why'], $data['attempts'] ?? null,
            array_values($data['accounts']), (int) ($data['total'] ?? count($data['accounts'])),
        );

        // Also on the Overview's high-risk list until someone resolves it. Not
        // emailed twice: exitStuck() above already mailed the same list.
        $names = TradeAlerts::names($exchange, array_map(fn ($a) => (string) ($a['uni_id'] ?? ''), $data['accounts']));
        $side = str_ends_with($data['action'], 'SHORT') ? 'SHORT' : 'LONG';
        $broker = ExchangeSchema::for($exchange)->brokerLabel;
        $lines = [$data['why'] === 'abandoned'
            ? "The bot stopped retrying the close after ".($data['attempts'] ?? 'several').' attempts. These positions may still be open:'
            : "{$broker} refused the close outright. These positions may still be open:"];
        foreach ($data['accounts'] as $a) {
            $n = $names[(string) ($a['uni_id'] ?? '')] ?? ['owner' => null, 'account' => null];
            $lines[] = '• '.($n['owner'] ?? 'Unknown user').' · '.(($a['name'] ?? null) ?: ($n['account'] ?? '—'))
                .(! empty($a['error']) ? ' — '.$a['error'] : '');
        }
        $risk->raise(
            RiskAlert::EXIT_STUCK,
            "{$exchange}:{$data['ticker']}:{$side}",
            "Close failed: {$data['ticker']} {$side} may still be open on {$broker}",
            $lines,
            $exchange,
            email: false,
        );

        return response()->json(['success' => true, 'sent' => $sent]);
    }

    /** POST /api/engine/{exchange}/trade-logs — one row per processed signal. */
    public function storeTradeLog(string $exchange, Request $request, FeedAudit $feedAudit, RiskAlerts $alerts): JsonResponse
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
            // Which webhook the signal arrived on (WebhookPaths::FEEDS).
            'feed' => ['nullable', 'string', Rule::in(WebhookPaths::FEEDS)],
        ]);

        // An engine deployed before the test feed existed sends no feed, and
        // every signal it can receive is a main-feed one.
        $data['feed'] = $data['feed'] ?? 'main';

        // Delivery timing is read OUTSIDE validate(): a malformed value is
        // dropped, never a 422 that would lose the whole signal's row.
        $data += SignalDelivery::fromPayload(
            $request->input('sent_at'), $request->input('received_at'), $request->input('ack_ms'),
        );

        $log = TradeLog::create($data + ['exchange' => $exchange]);

        // High-risk checks on what just happened (best-effort, never a 500):
        // a fill outside the signal's feed, and a signal refused because the
        // engine could not load the coin list at all.
        $feedAudit->check($log);
        $this->alertIfAssetsUnavailable($log, $alerts);

        // The developer error bot reads the outcome AFTER the engine has its
        // answer: building coin buttons asks Binance and the engine itself,
        // and the engine must never wait on that. The flag stops a second
        // request in the same process (tests) re-running this one.
        $told = false;
        app()->terminating(function () use ($log, &$told) {
            if (! $told) {
                $told = true;
                app(DevBotReporter::class)->tradeLog($log);
            }
        });

        return response()->json(['success' => true, 'trade_log_id' => $log->id], 201);
    }

    /**
     * A rejected signal whose reason is `assets_unavailable`: the engine never
     * got the coin list, so EVERY entry on that venue is refused until it
     * does — a high-risk alert, once an hour per venue while it lasts.
     */
    private function alertIfAssetsUnavailable(TradeLog $log, RiskAlerts $alerts): void
    {
        if ($log->category !== 'rejected' || $log->feed === 'test') {
            return;
        }
        $reasons = collect((array) $log->details)->pluck('reason')->filter()->all();
        if (! in_array('assets_unavailable', $reasons, true)) {
            return;
        }
        $broker = ExchangeSchema::isSupported((string) $log->exchange)
            ? ExchangeSchema::for((string) $log->exchange)->brokerLabel : ucfirst((string) $log->exchange);
        $alerts->raise(
            RiskAlert::ASSETS_UNAVAILABLE,
            'venue:'.$log->exchange,
            "{$log->action} {$log->ticker} on {$broker} was refused: the bot could not load the coin list",
            [
                "The bot could not read the Trading Assets list from the API, so it refused this signal rather than trade blind. Every entry on {$broker} is refused the same way until the list loads again.",
                'Check that the API is up and answering the engine (Admin → Bot Engine). Signal Log row #'.$log->id.'.',
            ],
            (string) $log->exchange,
            $log->id,
        );
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
            'open_strategies' => $query
                ->get(['api_key', 'symbol', 'position_side', 'strategy', ...EntryStreak::COLUMNS])
                ->values(),
        ]);
    }

    /**
     * POST /api/engine/{exchange}/open-strategies — remember an entry's tag:
     * its strategy label and, on a coin with a streak ladder, the streak step
     * that sized it (EntryStreak). Both are copied onto the closed trade.
     *
     * Every entry of a stack shares one step, so the streak columns are always
     * the latest entry's (absent = normal size). The label is kept when an
     * entry arrives without one — an older entry's label is still the stack's.
     */
    public function storeOpenStrategy(string $exchange, Request $request): JsonResponse
    {
        if ($guard = $this->guardExchange($exchange)) {
            return $guard;
        }

        $data = $request->validate([
            'api_key' => ['required', 'string', 'max:128'],
            'symbol' => ['required', 'string', 'max:32'],
            'position_side' => ['required', 'string', 'max:8'],
            'strategy' => ['nullable', 'string', 'max:100'],
            ...EntryStreak::rules(),
        ]);

        $key = [
            'exchange' => $exchange,
            'api_key' => $data['api_key'],
            'symbol' => $data['symbol'],
            'position_side' => $data['position_side'],
        ];
        $values = EntryStreak::fromPayload($data);
        if (! empty($data['strategy'])) {
            $values['strategy'] = $data['strategy'];
        }

        // Neither a label nor a streak: nothing to start a tag for. An
        // existing tag is still updated, which clears a streak left by a
        // position closed outside the bot (never consumed).
        if (! isset($values['strategy']) && $values['streak_kind'] === null
            && ! OpenStrategy::where($key)->exists()) {
            return response()->json(['success' => true]);
        }

        OpenStrategy::updateOrCreate($key, $values);

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
