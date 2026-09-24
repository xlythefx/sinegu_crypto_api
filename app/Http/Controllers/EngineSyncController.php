<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\GuardsEngineExchange;
use App\Models\ExchangeAccount;
use App\Services\EngineCache;
use App\Services\Pnl\FeeRebase;
use App\Services\Pnl\TradingFee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Bookkeeping writes from the Python trading engine's pollers + webhook
 * write-through: positions, past positions, balances, transactions.
 * Behind the `engine` middleware (X-Engine-Secret); exchange-scoped routes.
 *
 * Every write lands in the route exchange's OWN tables (ExchangeSchema): a
 * MEXC poller posting to /engine/mexc/positions/sync fills mexc_positions and
 * can never touch a Binance row. The payload shapes are identical across
 * exchanges by design — the engine's adapters convert units (MEXC contracts →
 * coins, BTC_USDT → BTCUSDT) before anything reaches here.
 */
class EngineSyncController extends Controller
{
    use GuardsEngineExchange;

    /**
     * POST /api/engine/{exchange}/positions/sync — full-replace open positions.
     *
     * Payload: {accounts: [{api_key, uni_id, positions: [{symbol, position_side,
     * position_amt, entry_price, mark_price, unrealized_profit, notional,
     * initial_margin, maint_margin, isolated_margin, isolated_wallet, update_time}]}]}
     * Per account: delete all rows for the api_key, re-insert the snapshot.
     */
    public function syncPositions(string $exchange, Request $request): JsonResponse
    {
        if ($guard = $this->guardExchange($exchange)) {
            return $guard;
        }

        // Every optional field needs a rule: validate() returns ONLY validated
        // keys, so an unlisted field would be stripped and stored as NULL.
        $data = $request->validate([
            'accounts' => ['required', 'array'],
            'accounts.*.api_key' => ['required', 'string', 'max:128'],
            'accounts.*.uni_id' => ['required', 'string', 'max:36'],
            'accounts.*.positions' => ['present', 'array'],
            'accounts.*.positions.*.symbol' => ['required', 'string', 'max:32'],
            'accounts.*.positions.*.position_side' => ['required', 'string', 'max:16'],
            'accounts.*.positions.*.position_amt' => ['required', 'numeric'],
            'accounts.*.positions.*.entry_price' => ['nullable', 'numeric'],
            'accounts.*.positions.*.mark_price' => ['nullable', 'numeric'],
            'accounts.*.positions.*.unrealized_profit' => ['nullable', 'numeric'],
            'accounts.*.positions.*.notional' => ['nullable', 'numeric'],
            'accounts.*.positions.*.initial_margin' => ['nullable', 'numeric'],
            'accounts.*.positions.*.maint_margin' => ['nullable', 'numeric'],
            'accounts.*.positions.*.isolated_margin' => ['nullable', 'numeric'],
            'accounts.*.positions.*.isolated_wallet' => ['nullable', 'numeric'],
            'accounts.*.positions.*.update_time' => ['nullable', 'integer'],
        ]);

        $numeric = [
            'entry_price', 'mark_price', 'unrealized_profit', 'notional',
            'initial_margin', 'maint_margin', 'isolated_margin', 'isolated_wallet',
        ];

        $table = $this->schema($exchange)->positions;
        $deleted = 0;
        $inserted = 0;

        foreach ($data['accounts'] as $account) {
            $rows = [];
            foreach ($account['positions'] as $pos) {
                $row = [
                    'api_key' => $account['api_key'],
                    'uni_id' => $account['uni_id'],
                    'symbol' => $pos['symbol'],
                    'position_side' => $pos['position_side'],
                    'position_amt' => $pos['position_amt'],
                    'update_time' => $pos['update_time'] ?? null,
                    'created_at' => now(),
                ];
                foreach ($numeric as $col) {
                    $row[$col] = isset($pos[$col]) && is_numeric($pos[$col]) ? $pos[$col] : null;
                }
                $rows[] = $row;
            }

            DB::transaction(function () use ($table, $account, $rows, &$deleted, &$inserted) {
                $deleted += DB::table($table)
                    ->where('api_key', $account['api_key'])
                    ->delete();
                if ($rows) {
                    DB::table($table)->insert($rows);
                    $inserted += count($rows);
                }
            });
        }

        return response()->json(['success' => true, 'deleted' => $deleted, 'inserted' => $inserted]);
    }

    /**
     * POST /api/engine/{exchange}/positions/upsert — write-through after a fill.
     *
     * Locks the (api_key, symbol, position_side) row: amt <= 0 deletes it,
     * otherwise updates in place or inserts when absent.
     */
    public function upsertPosition(string $exchange, Request $request): JsonResponse
    {
        if ($guard = $this->guardExchange($exchange)) {
            return $guard;
        }

        $data = $request->validate([
            'api_key' => ['required', 'string', 'max:128'],
            'uni_id' => ['required', 'string', 'max:36'],
            'symbol' => ['required', 'string', 'max:32'],
            'position_side' => ['required', 'string', 'max:16'],
            'position_amt' => ['required', 'numeric'],
            'entry_price' => ['nullable', 'numeric'],
        ]);

        $table = $this->schema($exchange)->positions;

        $result = DB::transaction(function () use ($table, $data) {
            $query = DB::table($table)
                ->where('api_key', $data['api_key'])
                ->where('symbol', $data['symbol'])
                ->where('position_side', $data['position_side']);

            $existing = (clone $query)->lockForUpdate()->first();

            if ((float) $data['position_amt'] <= 0.0) {
                $query->delete();

                return 'deleted';
            }

            if ($existing) {
                $update = ['position_amt' => $data['position_amt']];
                if (isset($data['entry_price'])) {
                    $update['entry_price'] = $data['entry_price'];
                }
                $query->update($update);

                return 'updated';
            }

            DB::table($table)->insert([
                'api_key' => $data['api_key'],
                'uni_id' => $data['uni_id'],
                'symbol' => $data['symbol'],
                'position_side' => $data['position_side'],
                'position_amt' => $data['position_amt'],
                'entry_price' => $data['entry_price'] ?? null,
                'created_at' => now(),
            ]);

            return 'inserted';
        });

        return response()->json(['success' => true, 'result' => $result]);
    }

    /**
     * GET /api/engine/{exchange}/positions/check?symbol=&position_side= — batched
     * stack-cap read: every account's open amount for one symbol in one call.
     */
    public function checkPositions(string $exchange, Request $request): JsonResponse
    {
        if ($guard = $this->guardExchange($exchange)) {
            return $guard;
        }

        $data = $request->validate([
            'symbol' => ['required', 'string', 'max:32'],
            'position_side' => ['nullable', 'string', 'max:16'],
        ]);

        $query = DB::table($this->schema($exchange)->positions)
            ->where('symbol', $data['symbol'])
            ->whereRaw('ABS(position_amt) > 0.00000001');
        if (! empty($data['position_side'])) {
            $query->where('position_side', $data['position_side']);
        }

        return response()->json([
            'success' => true,
            'positions' => $query
                ->get(['api_key', 'position_side', 'position_amt', 'entry_price'])
                ->map(fn ($p) => [
                    'api_key' => $p->api_key,
                    'position_side' => $p->position_side,
                    'position_amt' => (float) $p->position_amt,
                    'entry_price' => $p->entry_price !== null ? (float) $p->entry_price : null,
                ])->values(),
        ]);
    }

    /**
     * POST /api/engine/{exchange}/past-positions/sync — idempotent closed-trade
     * sync on the table's (api_key, symbol, order_id) unique key. New rows
     * insert; existing rows only fill columns that are still null.
     *
     * The engine posts Binance's raw `realizedPnl`, which is GROSS of
     * commission; the column stores NET. This is the one place that conversion
     * happens — TradingFee is applied on the way in rather than at read time, so
     * the dozen readers of `realized_pnl` (dashboard, analytics, calendar,
     * referrals, invoicing, the public track record) cannot disagree about
     * whether the number they hold includes fees. The engine stays unaware of
     * it: it reports what the exchange said, and the API decides what that means.
     *
     * Netting cannot double-apply. An insert happens once, and the null-fill
     * branch writes `realized_pnl` only while it is still null — so a row that
     * already carries a net figure is never reduced a second time. That same
     * guard is what keeps the restored-gross history (closes before
     * TradingFee::NET_SINCE) intact: those rows carry a P&L, so the poller's
     * lookback re-sync never rewrites them. A close dated before the cutoff
     * that arrives fresh is stored gross too — applyTo() reads `closed_at`.
     */
    public function syncPastPositions(string $exchange, Request $request): JsonResponse
    {
        if ($guard = $this->guardExchange($exchange)) {
            return $guard;
        }

        $data = $request->validate([
            'rows' => ['required', 'array'],
            'rows.*.api_key' => ['required', 'string', 'max:128'],
            'rows.*.uni_id' => ['required', 'string', 'max:36'],
            'rows.*.symbol' => ['required', 'string', 'max:32'],
            'rows.*.position_side' => ['required', 'string', 'max:16'],
            'rows.*.position_amt' => ['required', 'numeric'],
            // Entry-sized increments this close took off (the engine's
            // `Increments Closed (n/cap)` figure). Only the webhook close path
            // knows it; the poller's reconciliation rows leave it null.
            'rows.*.increments_closed' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'rows.*.entry_price' => ['nullable', 'numeric'],
            'rows.*.exit_price' => ['nullable', 'numeric'],
            'rows.*.realized_pnl' => ['nullable', 'numeric'],
            'rows.*.side' => ['required', 'string', 'max:8'],
            'rows.*.order_id' => ['required', 'integer'],
            'rows.*.closed_at' => ['required', 'date'],
            'rows.*.strategy' => ['nullable', 'string', 'max:100'],
        ]);

        $table = $this->schema($exchange)->pastPositions;
        $inserted = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($data['rows'] as $row) {
            $existing = DB::table($table)
                ->where('api_key', $row['api_key'])
                ->where('symbol', $row['symbol'])
                ->where('order_id', $row['order_id'])
                ->first();

            [$netPnl, $fee] = TradingFee::applyTo(
                isset($row['realized_pnl']) ? (float) $row['realized_pnl'] : null,
                (float) $row['position_amt'],
                isset($row['exit_price']) ? (float) $row['exit_price'] : null,
                $row['closed_at'],
                $exchange,
            );

            if (! $existing) {
                DB::table($table)->insert([
                    'api_key' => $row['api_key'],
                    'uni_id' => $row['uni_id'],
                    'symbol' => $row['symbol'],
                    'position_side' => $row['position_side'],
                    'position_amt' => $row['position_amt'],
                    'increments_closed' => $row['increments_closed'] ?? null,
                    'entry_price' => $row['entry_price'] ?? null,
                    'exit_price' => $row['exit_price'] ?? null,
                    'realized_pnl' => $netPnl,
                    'exchange_fee' => $fee,
                    'fee_source' => $fee === null ? null : TradingFee::SOURCE_ESTIMATED,
                    'side' => $row['side'],
                    'order_id' => $row['order_id'],
                    'closed_at' => $row['closed_at'],
                    'strategy' => $row['strategy'] ?? null,
                    'created_at' => now(),
                ]);
                $inserted++;

                continue;
            }

            // Fill only the gaps — never overwrite what an earlier sync wrote.
            $fill = [];
            foreach (['entry_price', 'exit_price', 'strategy', 'increments_closed'] as $col) {
                if ($existing->$col === null && isset($row[$col])) {
                    $fill[$col] = $row[$col];
                }
            }

            // The P&L and the fee taken out of it are one fact, so they are
            // filled together or not at all: a row carrying a net figure with no
            // fee beside it could never be turned back into what Binance said.
            if ($existing->realized_pnl === null && $netPnl !== null) {
                $fill['realized_pnl'] = $netPnl;
                $fill['exchange_fee'] = $fee;
                $fill['fee_source'] = $fee === null ? null : TradingFee::SOURCE_ESTIMATED;
            }

            if ($fill) {
                DB::table($table)->where('id', $existing->id)->update($fill);
                $updated++;
            } else {
                $skipped++;
            }
        }

        return response()->json([
            'success' => true,
            'inserted' => $inserted,
            'updated' => $updated,
            'skipped' => $skipped,
        ]);
    }

    /**
     * POST /api/engine/{exchange}/balances — poller balance refresh.
     * initial_deposit is only set when the account has none yet (null/0).
     */
    public function updateBalances(string $exchange, Request $request): JsonResponse
    {
        if ($guard = $this->guardExchange($exchange)) {
            return $guard;
        }

        $data = $request->validate([
            'rows' => ['required', 'array'],
            'rows.*.api_key' => ['required', 'string', 'max:128'],
            'rows.*.balance' => ['required', 'numeric'],
            'rows.*.unrealized_pnl' => ['nullable', 'numeric'],
            'rows.*.initial_deposit' => ['nullable', 'numeric'],
        ]);

        $schema = $this->schema($exchange);
        $updatedCount = 0;

        foreach ($data['rows'] as $row) {
            $account = $schema->accountQuery()->where('api_key', $row['api_key'])->first();
            if (! $account) {
                continue;
            }

            $account->balance = $row['balance'];
            if (array_key_exists('unrealized_pnl', $row) && $row['unrealized_pnl'] !== null) {
                $account->unrealized_pnl = $row['unrealized_pnl'];
            }
            if (
                ! ((float) $account->initial_deposit > 0)
                && isset($row['initial_deposit']) && (float) $row['initial_deposit'] > 0
            ) {
                $account->initial_deposit = $row['initial_deposit'];
            }
            $account->save();
            $updatedCount++;
        }

        return response()->json(['success' => true, 'updated' => $updatedCount]);
    }

    /**
     * POST /api/engine/{exchange}/key-status
     * Body: {api_key, status: ok|blocked, code?, reason?, message?}
     *
     * The engine reports here when the exchange REFUSES an account's
     * credentials from our server (Binance -2015: key invalid, IP not
     * allow-listed, or permission missing) and again when they start working.
     * Until this existed the failure was invisible: the account looked
     * connected, its balance silently froze, and it received no trades.
     *
     * `key_blocked_at` is set only on the transition into blocked, never
     * refreshed by a repeat report — otherwise a poller finding the same fault
     * every five minutes would push the 3-day disconnect deadline forward
     * forever and the account would never age out.
     */
    public function keyStatus(string $exchange, Request $request, EngineCache $engineCache): JsonResponse
    {
        if ($guard = $this->guardExchange($exchange)) {
            return $guard;
        }

        $data = $request->validate([
            'api_key' => ['required', 'string', 'max:128'],
            'status' => ['required', 'in:ok,blocked'],
            'code' => ['nullable', 'string', 'max:16'],
            'reason' => ['nullable', 'string', 'max:32'],
            'message' => ['nullable', 'string', 'max:255'],
        ]);

        $account = $this->schema($exchange)->accountQuery()->where('api_key', $data['api_key'])->first();
        if (! $account) {
            return response()->json(['success' => true, 'updated' => false]);
        }

        $blocked = $data['status'] === ExchangeAccount::KEY_BLOCKED;
        $wasBlocked = $account->keyIsBlocked();

        $account->key_status = $blocked ? ExchangeAccount::KEY_BLOCKED : ExchangeAccount::KEY_OK;
        $account->key_checked_at = now();

        if ($blocked) {
            $account->key_error_code = $data['code'] ?? null;
            $account->key_error_reason = $data['reason'] ?? null;
            $account->key_error_message = $data['message'] ?? null;
            if (! $wasBlocked || $account->key_blocked_at === null) {
                $account->key_blocked_at = now();
            }
        } else {
            $account->key_error_code = null;
            $account->key_error_reason = null;
            $account->key_error_message = null;
            $account->key_blocked_at = null;
        }

        $account->save();

        // The verdict changed what the fan-out may do with this account
        // (`key_blocked` rides on the engine's cached account list), so the
        // engine must drop that list now rather than at its 90s TTL. Seen live
        // on 2026-09-17: a recheck cleared the flag and the very next signal
        // was still skipped as "api key blocked" from the stale cache.
        // Transitions only — the pollers re-report the same verdict every tick.
        if ($wasBlocked !== $blocked) {
            $engineCache->refreshAccounts();
        }

        return response()->json([
            'success' => true,
            'updated' => true,
            'status' => $account->key_status,
            'grace_ends_at' => $account->keyGraceEndsAt()?->toIso8601String(),
        ]);
    }

    /**
     * POST /api/engine/{exchange}/transactions — deposit/withdrawal sync,
     * idempotent on the table's (api_key, tran_id) unique key.
     */
    public function insertTransactions(string $exchange, Request $request): JsonResponse
    {
        if ($guard = $this->guardExchange($exchange)) {
            return $guard;
        }

        $data = $request->validate([
            'rows' => ['required', 'array'],
            'rows.*.api_key' => ['required', 'string', 'max:128'],
            'rows.*.uni_id' => ['required', 'string', 'max:36'],
            'rows.*.type' => ['required', 'string', 'max:16'],
            'rows.*.amount' => ['required', 'numeric'],
            'rows.*.balance_after' => ['nullable', 'numeric'],
            'rows.*.tran_id' => ['required', 'integer'],
            'rows.*.currency' => ['nullable', 'string', 'max:16'],
            'rows.*.transaction_time' => ['nullable', 'integer'],
            'rows.*.info' => ['nullable', 'string', 'max:64'],
        ]);

        $rows = array_map(fn (array $row) => [
            'api_key' => $row['api_key'],
            'uni_id' => $row['uni_id'],
            'type' => $row['type'],
            'amount' => $row['amount'],
            'balance_after' => $row['balance_after'] ?? null,
            'tran_id' => $row['tran_id'],
            'currency' => $row['currency'] ?? 'USDT',
            'transaction_time' => $row['transaction_time'] ?? null,
            'info' => $row['info'] ?? null,
            'created_at' => now(),
        ], $data['rows']);

        $inserted = DB::table($this->schema($exchange)->transactions)->insertOrIgnore($rows);

        return response()->json(['success' => true, 'inserted' => $inserted]);
    }

    /**
     * POST /api/engine/{exchange}/fees — the exchange's own fee receipts:
     * one row per fill (its commission) and per funding payment, idempotent on
     * (exchange, api_key, symbol, kind, ref). Append-only; nothing here ever
     * updates a receipt.
     *
     * Ingest then ATTRIBUTES: every (api_key, symbol) pair in the payload is
     * handed to FeeRebase, which replays the pair's receipts and moves any
     * matched close from its estimated fee to the actual one. Synchronous
     * because it is bounded — a tick's payload names a handful of pairs and
     * each is one indexed read plus one small update — and because doing it
     * here is what lets a close flip to "actual" within the same poller tick
     * that delivered its receipts.
     *
     * A rebase failure must NOT fail the request. The engine advances its fee
     * watermark only on a success response, so a 500 here would re-send the
     * same receipts into the same exception every tick, forever. The receipts
     * are already stored; the failure is reported and the daily
     * `fees:reconcile` retries the pair.
     */
    public function insertFees(string $exchange, Request $request, FeeRebase $rebase): JsonResponse
    {
        if ($guard = $this->guardExchange($exchange)) {
            return $guard;
        }

        // `ref` is the exchange's own id for the charge, and its TYPE differs by
        // venue: Binance's fill id and MEXC's deal id arrive as JSON numbers,
        // Bybit's execId as a UUID string. Normalise to a string before
        // validating so one rule covers all three and the column (widened to
        // VARCHAR on 2026-09-24) always receives the same shape.
        $request->merge([
            'rows' => array_map(
                fn ($row) => is_array($row) && isset($row['ref']) && is_scalar($row['ref'])
                    ? ['ref' => (string) $row['ref']] + $row
                    : $row,
                (array) $request->input('rows', [])
            ),
        ]);

        $data = $request->validate([
            'rows' => ['required', 'array', 'max:2000'],
            'rows.*.api_key' => ['required', 'string', 'max:128'],
            'rows.*.uni_id' => ['required', 'string', 'max:36'],
            'rows.*.symbol' => ['required', 'string', 'max:32'],
            'rows.*.kind' => ['required', 'in:fill,funding'],
            'rows.*.ref' => ['required', 'string', 'max:64'],
            'rows.*.order_id' => ['nullable', 'integer'],
            'rows.*.side' => ['nullable', 'string', 'max:8'],
            'rows.*.position_side' => ['nullable', 'string', 'max:16'],
            'rows.*.qty' => ['nullable', 'numeric'],
            'rows.*.price' => ['nullable', 'numeric'],
            'rows.*.realized_pnl' => ['nullable', 'numeric'],
            'rows.*.amount' => ['required', 'numeric'],
            'rows.*.asset' => ['required', 'string', 'max:16'],
            'rows.*.charged_at' => ['required', 'integer'],
        ]);

        $startedAt = microtime(true);
        $now = now();

        $rows = array_map(fn (array $row) => [
            'exchange' => $exchange,
            'api_key' => $row['api_key'],
            'uni_id' => $row['uni_id'],
            'symbol' => strtoupper($row['symbol']),
            'kind' => $row['kind'],
            'ref' => $row['ref'],
            'order_id' => $row['order_id'] ?? null,
            'side' => isset($row['side']) ? strtoupper($row['side']) : null,
            'position_side' => isset($row['position_side']) ? strtoupper($row['position_side']) : null,
            'qty' => $row['qty'] ?? null,
            'price' => $row['price'] ?? null,
            'realized_pnl' => $row['realized_pnl'] ?? null,
            'amount' => $row['amount'],
            'asset' => strtoupper($row['asset']),
            'charged_at' => $row['charged_at'],
            'created_at' => $now,
        ], $data['rows']);

        $inserted = DB::table('exchange_fee_receipts')->insertOrIgnore($rows);

        $pairs = [];
        foreach ($rows as $row) {
            $pairs[$row['api_key'].'|'.$row['symbol']] = [$row['api_key'], $row['symbol']];
        }

        $rebased = 0;
        $unchanged = 0;
        $unconfirmed = 0;
        $errors = 0;

        foreach ($pairs as [$apiKey, $symbol]) {
            try {
                $report = $rebase->pair($apiKey, $symbol, false, $exchange);
                $rebased += $report['rebased'];
                $unchanged += $report['unchanged'];
                $unconfirmed += count($report['unconfirmed']);
            } catch (\Throwable $e) {
                report($e);
                $errors++;
            }
        }

        $elapsed = microtime(true) - $startedAt;
        if ($elapsed > 3.0) {
            logger()->warning(sprintf(
                'engine fees ingest took %.1fs for %d receipt(s) across %d pair(s)',
                $elapsed, count($rows), count($pairs),
            ));
        }

        return response()->json([
            'success' => true,
            'inserted' => $inserted,
            'pairs' => count($pairs),
            'rebased' => $rebased,
            'unchanged' => $unchanged,
            'unconfirmed' => $unconfirmed,
            'errors' => $errors,
        ]);
    }
}
