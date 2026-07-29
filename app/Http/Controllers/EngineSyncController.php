<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\GuardsEngineExchange;
use App\Models\BinanceAccount;
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

        $data = $request->validate([
            'accounts' => ['required', 'array'],
            'accounts.*.api_key' => ['required', 'string', 'max:128'],
            'accounts.*.uni_id' => ['required', 'string', 'max:36'],
            'accounts.*.positions' => ['present', 'array'],
            'accounts.*.positions.*.symbol' => ['required', 'string', 'max:32'],
            'accounts.*.positions.*.position_side' => ['required', 'string', 'max:16'],
            'accounts.*.positions.*.position_amt' => ['required', 'numeric'],
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

            if (! $existing) {
                DB::table('binance_pastpositions')->insert([
                    'api_key' => $row['api_key'],
                    'uni_id' => $row['uni_id'],
                    'symbol' => $row['symbol'],
                    'position_side' => $row['position_side'],
                    'position_amt' => $row['position_amt'],
                    'entry_price' => $row['entry_price'] ?? null,
                    'exit_price' => $row['exit_price'] ?? null,
                    'realized_pnl' => $row['realized_pnl'] ?? null,
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
            foreach (['entry_price', 'exit_price', 'realized_pnl', 'strategy'] as $col) {
                if ($existing->$col === null && isset($row[$col])) {
                    $fill[$col] = $row[$col];
                }
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
