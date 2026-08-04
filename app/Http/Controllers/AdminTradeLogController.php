<?php

namespace App\Http\Controllers;

use App\Models\TradeLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Read-only view over `trade_logs` — one append-only row per signal the trading
 * engine processed, with the per-account fan-out (and its sizing decision) in
 * the `details` JSON.
 *
 * Writes come from the engine alone (`POST /api/engine/{exchange}/trade-logs`,
 * EngineController::storeTradeLog). Nothing here mutates; the table is the
 * audit trail for "why did this account get this size".
 */
class AdminTradeLogController extends Controller
{
    /** Hard ceiling so a bad `limit` can never table-scan the whole log. */
    private const MAX_LIMIT = 200;

    /** GET /api/admin/trade-logs — filtered, paginated signal log. */
    public function index(Request $request): JsonResponse
    {
        $query = TradeLog::query();

        if ($exchange = $request->query('exchange')) {
            $query->where('exchange', $exchange);
        }
        if ($action = $request->query('action')) {
            $query->where('action', strtoupper($action));
        }
        if ($ticker = $request->query('ticker')) {
            $query->where('ticker', strtoupper($ticker));
        }
        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }
        if ($strategy = $request->query('strategy')) {
            $query->where('strategy', $strategy);
        }
        // result=success -> clean signals only; result=problem -> anything that
        // failed outright or had at least one account fail.
        $result = $request->query('result');
        if ($result === 'success') {
            $query->where('success', true);
        } elseif ($result === 'problem') {
            $query->where('success', false);
        }
        if ($from = $request->query('from')) {
            $query->where('ts', '>=', $from.' 00:00:00');
        }
        if ($to = $request->query('to')) {
            $query->where('ts', '<=', $to.' 23:59:59');
        }

        // Summary reflects the filters but NOT the page window.
        $summary = (clone $query)
            ->selectRaw('COUNT(*) AS signals')
            ->selectRaw('COALESCE(SUM(filled), 0) AS filled')
            ->selectRaw('COALESCE(SUM(failed), 0) AS failed')
            ->selectRaw('COALESCE(SUM(skipped), 0) AS skipped')
            ->selectRaw('COALESCE(SUM(success = 1), 0) AS clean_signals')
            ->first();

        $limit = min(max((int) $request->query('limit', 50), 1), self::MAX_LIMIT);
        $offset = max((int) $request->query('offset', 0), 0);

        $logs = $query->orderByDesc('ts')->orderByDesc('id')
            ->offset($offset)->limit($limit)->get();

        $names = $this->userNames($logs);

        return response()->json([
            'success' => true,
            'logs' => $logs->map(fn (TradeLog $log) => $this->mapLog($log, $names))->values(),
            'summary' => [
                'signals' => (int) ($summary->signals ?? 0),
                'clean_signals' => (int) ($summary->clean_signals ?? 0),
                'filled' => (int) ($summary->filled ?? 0),
                'failed' => (int) ($summary->failed ?? 0),
                'skipped' => (int) ($summary->skipped ?? 0),
            ],
            'pagination' => [
                'limit' => $limit,
                'offset' => $offset,
                'total' => (int) ($summary->signals ?? 0),
            ],
            // Drives the frontend filter chips without a second round trip.
            'filters' => [
                'tickers' => TradeLog::distinct()->orderBy('ticker')->pluck('ticker'),
                'strategies' => TradeLog::whereNotNull('strategy')
                    ->distinct()->orderBy('strategy')->pluck('strategy'),
            ],
        ]);
    }

    /** GET /api/admin/trade-logs/{id} — one signal with its full fan-out. */
    public function show(string $id): JsonResponse
    {
        $log = TradeLog::find($id);
        if (! $log) {
            return response()->json(['success' => false, 'message' => 'Trade log not found'], 404);
        }

        return response()->json([
            'success' => true,
            'log' => $this->mapLog($log, $this->userNames(collect([$log]))),
        ]);
    }

    /** uni_id -> display name for every account mentioned across these logs. */
    private function userNames($logs): array
    {
        $uniIds = [];
        foreach ($logs as $log) {
            foreach ($log->details ?? [] as $row) {
                if (is_array($row) && ! empty($row['uni_id'])) {
                    $uniIds[$row['uni_id']] = true;
                }
            }
        }
        if (! $uniIds) {
            return [];
        }

        return DB::table('user_credentials')
            ->whereIn('uni_id', array_keys($uniIds))
            ->pluck('name', 'uni_id')
            ->all();
    }

    private function mapLog(TradeLog $log, array $names): array
    {
        return [
            'id' => $log->id,
            'exchange' => $log->exchange,
            'action' => $log->action,
            'ticker' => $log->ticker,
            'success' => (bool) $log->success,
            'price' => $log->price !== null ? (float) $log->price : null,
            'strategy' => $log->strategy,
            'leverage' => $log->leverage !== null ? (int) $log->leverage : null,
            'category' => $log->category,
            'target_count' => (int) $log->target_count,
            'filled' => (int) $log->filled,
            'failed' => (int) $log->failed,
            'skipped' => (int) $log->skipped,
            'details' => array_map(
                fn ($row) => $this->mapDetail($row, $names),
                is_array($log->details) ? $log->details : []
            ),
            'ts' => optional($log->ts)->toIso8601String(),
        ];
    }

    /**
     * One account's outcome. `sizing` is the engine's balance-proportional
     * decision — present on entries only (exits close whatever is open, and
     * rejected signals never reach an account).
     */
    private function mapDetail($row, array $names): array
    {
        if (! is_array($row)) {
            return ['status' => 'unknown'];
        }

        $sizing = is_array($row['sizing'] ?? null) ? $row['sizing'] : null;

        return [
            'account' => $row['account'] ?? null,
            'uni_id' => $row['uni_id'] ?? null,
            'user_name' => isset($row['uni_id']) ? ($names[$row['uni_id']] ?? null) : null,
            'status' => $row['status'] ?? null,
            'reason' => $row['reason'] ?? null,
            'error' => $row['error'] ?? null,
            'retryable' => isset($row['retryable']) ? (bool) $row['retryable'] : null,
            'quantity' => isset($row['quantity']) ? (float) $row['quantity'] : null,
            'fill_price' => isset($row['fill_price']) ? (float) $row['fill_price'] : null,
            'closed_quantity' => isset($row['closed_quantity']) ? (float) $row['closed_quantity'] : null,
            'sizing' => $sizing === null ? null : [
                'balance' => isset($sizing['balance']) ? (float) $sizing['balance'] : null,
                // Deposit gate. A gate-blocked skip writes only these three
                // keys — everything below stays null, so the reader must not
                // assume a full sizing block is present.
                'total_deposit' => isset($sizing['total_deposit']) ? (float) $sizing['total_deposit'] : null,
                'min_deposit' => isset($sizing['min_deposit']) ? (float) $sizing['min_deposit'] : null,
                'base_size' => isset($sizing['base_size']) ? (float) $sizing['base_size'] : null,
                'reference_balance' => isset($sizing['reference_balance']) ? (float) $sizing['reference_balance'] : null,
                'coarse_step' => (bool) ($sizing['coarse_step'] ?? false),
                'quantity' => isset($sizing['quantity']) ? (float) $sizing['quantity'] : null,
                'size_multiple' => isset($sizing['size_multiple']) ? (float) $sizing['size_multiple'] : null,
                'stacks_now' => isset($sizing['stacks_now']) ? (float) $sizing['stacks_now'] : null,
                'max_increments' => isset($sizing['max_increments']) ? (float) $sizing['max_increments'] : null,
            ],
        ];
    }
}
