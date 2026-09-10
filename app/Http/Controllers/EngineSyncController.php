<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\GuardsEngineExchange;
use App\Models\BinanceAccount;
use App\Services\Pnl\TradingFee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Bookkeeping writes from the Python trading engine's pollers + webhook
 * write-through: positions, past positions, balances, transactions.
 * Behind the `engine` middleware (X-Engine-Secret); exchange-scoped routes.
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

            DB::transaction(function () use ($account, $rows, &$deleted, &$inserted) {
                $deleted += DB::table('binance_positions')
                    ->where('api_key', $account['api_key'])
                    ->delete();
                if ($rows) {
                    DB::table('binance_positions')->insert($rows);
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

        $result = DB::transaction(function () use ($data) {
            $query = DB::table('binance_positions')
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

            DB::table('binance_positions')->insert([
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

        $query = DB::table('binance_positions')
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
     * already carries a net figure is never reduced a second time.
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
            'rows.*.entry_price' => ['nullable', 'numeric'],
            'rows.*.exit_price' => ['nullable', 'numeric'],
            'rows.*.realized_pnl' => ['nullable', 'numeric'],
            'rows.*.side' => ['required', 'string', 'max:8'],
            'rows.*.order_id' => ['required', 'integer'],
            'rows.*.closed_at' => ['required', 'date'],
            'rows.*.strategy' => ['nullable', 'string', 'max:100'],
        ]);

        $inserted = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($data['rows'] as $row) {
            $existing = DB::table('binance_pastpositions')
                ->where('api_key', $row['api_key'])
                ->where('symbol', $row['symbol'])
                ->where('order_id', $row['order_id'])
                ->first();

            [$netPnl, $fee] = TradingFee::applyTo(
                isset($row['realized_pnl']) ? (float) $row['realized_pnl'] : null,
                (float) $row['position_amt'],
                isset($row['exit_price']) ? (float) $row['exit_price'] : null,
            );

            if (! $existing) {
                DB::table('binance_pastpositions')->insert([
                    'api_key' => $row['api_key'],
                    'uni_id' => $row['uni_id'],
                    'symbol' => $row['symbol'],
                    'position_side' => $row['position_side'],
                    'position_amt' => $row['position_amt'],
                    'entry_price' => $row['entry_price'] ?? null,
                    'exit_price' => $row['exit_price'] ?? null,
                    'realized_pnl' => $netPnl,
                    'exchange_fee' => $fee,
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
            foreach (['entry_price', 'exit_price', 'strategy'] as $col) {
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
            }

            if ($fill) {
                DB::table('binance_pastpositions')->where('id', $existing->id)->update($fill);
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

        $updatedCount = 0;

        foreach ($data['rows'] as $row) {
            $account = BinanceAccount::where('api_key', $row['api_key'])->first();
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
    public function keyStatus(string $exchange, Request $request): JsonResponse
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

        $account = BinanceAccount::where('api_key', $data['api_key'])->first();
        if (! $account) {
            return response()->json(['success' => true, 'updated' => false]);
        }

        $blocked = $data['status'] === BinanceAccount::KEY_BLOCKED;
        $wasBlocked = $account->keyIsBlocked();

        $account->key_status = $blocked ? BinanceAccount::KEY_BLOCKED : BinanceAccount::KEY_OK;
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

        $inserted = DB::table('binance_transactions')->insertOrIgnore($rows);

        return response()->json(['success' => true, 'inserted' => $inserted]);
    }
}
