<?php

namespace App\Http\Controllers;

use App\Models\TradeLog;
use App\Services\Admin\SignalHealth;
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

    /**
     * GET /api/admin/trade-logs — filtered, paginated signal log.
     *
     * Signal filters (exchange, action, ticker, category, strategy, result,
     * from/to) run in SQL. ACCOUNT filters look inside each signal's fan-out
     * and run in PHP over at most SignalHealth::SCAN_LIMIT signals — the log is
     * a few signals a day, and `details` is JSON:
     * - outcome = filled | skipped | failed | problem (any account outcome
     *   SignalHealth calls a problem);
     * - reason  = a SignalHealth reason key;
     * - account = text matched against the account name and its owner's name.
     * A signal is listed when at least one account matches; every account row
     * then carries `match` so the page can show just those.
     */
    public function index(Request $request): JsonResponse
    {
        $query = $this->baseQuery($request);
        $limit = min(max((int) $request->query('limit', 50), 1), self::MAX_LIMIT);
        $offset = max((int) $request->query('offset', 0), 0);

        $outcome = (string) $request->query('outcome', '');
        $reason = (string) $request->query('reason', '');
        $account = trim((string) $request->query('account', ''));
        $accountFilter = $outcome !== '' || $reason !== '' || $account !== '';

        // The window every reason count and account filter reads.
        $window = (clone $query)->orderByDesc('ts')->orderByDesc('id')->limit(SignalHealth::SCAN_LIMIT)->get();
        $names = $this->userNames($window);
        $reasonCounts = $this->reasonCounts($window);

        if ($accountFilter) {
            $matcher = fn (array $d, TradeLog $log) => $this->detailMatches($d, $log, $outcome, $reason, $account, $names);
            $matched = $window->filter(function (TradeLog $log) use ($matcher) {
                foreach ((array) $log->details as $d) {
                    if (is_array($d) && $matcher($d, $log)) {
                        return true;
                    }
                }

                return false;
            })->values();
            $page = $matched->slice($offset, $limit)->values();
            $summary = [
                'signals' => $matched->count(),
                'clean_signals' => $matched->where('success', true)->count(),
                'filled' => (int) $matched->sum('filled'),
                'failed' => (int) $matched->sum('failed'),
                'skipped' => (int) $matched->sum('skipped'),
            ];
            $logs = $page->map(fn (TradeLog $log) => $this->mapLog($log, $names, $matcher));
        } else {
            // Summary reflects the filters but NOT the page window.
            $row = (clone $query)
                ->selectRaw('COUNT(*) AS signals')
                ->selectRaw('COALESCE(SUM(filled), 0) AS filled')
                ->selectRaw('COALESCE(SUM(failed), 0) AS failed')
                ->selectRaw('COALESCE(SUM(skipped), 0) AS skipped')
                ->selectRaw('COALESCE(SUM(success = 1), 0) AS clean_signals')
                ->first();
            $summary = [
                'signals' => (int) ($row->signals ?? 0),
                'clean_signals' => (int) ($row->clean_signals ?? 0),
                'filled' => (int) ($row->filled ?? 0),
                'failed' => (int) ($row->failed ?? 0),
                'skipped' => (int) ($row->skipped ?? 0),
            ];
            $page = (clone $query)->orderByDesc('ts')->orderByDesc('id')->offset($offset)->limit($limit)->get();
            $logs = $page->map(fn (TradeLog $log) => $this->mapLog($log, $this->userNames($page) + $names));
        }

        $problemDetails = 0;
        $normalDetails = 0;
        foreach ($reasonCounts as $r) {
            $r['severity'] === SignalHealth::PROBLEM ? $problemDetails += $r['count'] : $normalDetails += $r['count'];
        }

        return response()->json([
            'success' => true,
            'logs' => $logs->values(),
            'summary' => $summary + [
                // Account outcomes in the window, split the SignalHealth way.
                'problem_accounts' => $problemDetails,
                'normal_skips' => $normalDetails,
            ],
            'pagination' => [
                'limit' => $limit,
                'offset' => $offset,
                'total' => $summary['signals'],
            ],
            // Drives the frontend filter chips without a second round trip.
            'filters' => [
                'tickers' => TradeLog::distinct()->orderBy('ticker')->pluck('ticker'),
                'strategies' => TradeLog::whereNotNull('strategy')
                    ->distinct()->orderBy('strategy')->pluck('strategy'),
                'reasons' => array_values($reasonCounts),
            ],
        ]);
    }

    /**
     * GET /api/admin/trade-logs/health?hours=24 — the banner: how the last
     * day of signals went (healthy | normal | attention | quiet) and the top
     * problems grouped by cause. Ignores the page's filters on purpose: it
     * answers "is anything wrong right now", whatever the admin is browsing.
     */
    public function health(Request $request, SignalHealth $health): JsonResponse
    {
        $hours = min(max((int) $request->query('hours', 24), 1), 24 * 30);

        return response()->json(['success' => true, 'health' => $health->health($hours)]);
    }

    /**
     * GET /api/admin/trade-logs/issues — every problem in the filtered window
     * grouped by cause (one row per reason + account), most recent first.
     * Takes the same signal filters as the list; with no dates it reads the
     * last 7 days.
     */
    public function issues(Request $request, SignalHealth $health): JsonResponse
    {
        $query = $this->baseQuery($request);
        if (! $request->query('from') && ! $request->query('to')) {
            $query->where('ts', '>=', now()->subDays(7));
        }
        $logs = $query->orderByDesc('ts')->limit(SignalHealth::SCAN_LIMIT)->get();

        return response()->json([
            'success' => true,
            'signals' => $logs->count(),
            'issues' => $health->issues($logs),
            'normal' => array_values(array_filter(
                $this->reasonCounts($logs),
                fn ($r) => $r['severity'] === SignalHealth::NORMAL,
            )),
        ]);
    }

    /** The SQL half of every list: the signal-level filters. */
    private function baseQuery(Request $request)
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

        return $query;
    }

    /**
     * Account outcomes per reason across these signals, for the reason filter.
     *
     * @return array<string, array{key: string, label: string, severity: string, count: int}>
     */
    private function reasonCounts($logs): array
    {
        $counts = [];
        foreach ($logs as $log) {
            foreach ((array) $log->details as $d) {
                if (! is_array($d) || ! ($c = SignalHealth::classify($d, $log->category))) {
                    continue;
                }
                $counts[$c['key']] ??= $c + ['count' => 0];
                $counts[$c['key']]['count']++;
            }
        }
        uasort($counts, fn ($a, $b) => [$a['severity'] === SignalHealth::NORMAL, -$a['count']] <=> [$b['severity'] === SignalHealth::NORMAL, -$b['count']]);

        return $counts;
    }

    /** Does one account outcome pass the account filters? */
    private function detailMatches(array $d, TradeLog $log, string $outcome, string $reason, string $account, array $names): bool
    {
        $c = SignalHealth::classify($d, $log->category);
        $status = $log->category === 'rejected' ? 'rejected' : ($d['status'] ?? null);

        if ($outcome === 'problem') {
            if (! $c || $c['severity'] !== SignalHealth::PROBLEM) {
                return false;
            }
        } elseif ($outcome !== '' && $status !== $outcome) {
            return false;
        }
        if ($reason !== '' && ($c['key'] ?? null) !== $reason) {
            return false;
        }
        if ($account !== '') {
            $hay = strtolower(($d['account'] ?? '').' '.(isset($d['uni_id']) ? ($names[$d['uni_id']] ?? '') : ''));
            if (! str_contains($hay, strtolower($account))) {
                return false;
            }
        }

        return true;
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

    private function mapLog(TradeLog $log, array $names, ?callable $matcher = null): array
    {
        $details = is_array($log->details) ? $log->details : [];
        $problems = 0;
        $normal = 0;
        foreach ($details as $d) {
            if (is_array($d) && ($c = SignalHealth::classify($d, $log->category))) {
                $c['severity'] === SignalHealth::PROBLEM ? $problems++ : $normal++;
            }
        }

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
                fn ($row) => $this->mapDetail($row, $names, $log, $matcher),
                $details
            ),
            // How the master did (filled | skipped | failed | absent), or null
            // when the engine sent no master flag — and the account outcomes
            // split into problems and normal skips, for the row's chips.
            'master' => SignalHealth::masterOutcome($log),
            'problems' => $problems,
            'normal_skips' => $normal,
            'ts' => optional($log->ts)->toIso8601String(),
        ];
    }

    /**
     * One account's outcome. `sizing` is the engine's balance-proportional
     * decision — present on entries only (exits close whatever is open, and
     * rejected signals never reach an account).
     */
    private function mapDetail($row, array $names, ?TradeLog $log = null, ?callable $matcher = null): array
    {
        if (! is_array($row)) {
            return ['status' => 'unknown'];
        }

        $sizing = is_array($row['sizing'] ?? null) ? $row['sizing'] : null;
        $class = SignalHealth::classify($row, $log?->category);

        return [
            // SignalHealth's verdict on this outcome (null for a fill) and,
            // when account filters are on, whether this row is one they found.
            'reason_key' => $class['key'] ?? null,
            'reason_label' => $class['label'] ?? null,
            'severity' => $class['severity'] ?? null,
            'is_master' => array_key_exists('is_master', $row) ? (bool) $row['is_master'] : null,
            'match' => $matcher && $log ? (bool) $matcher($row, $log) : true,
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
                // Streak sizing — written only for an asset with a ladder, so
                // null on every other row. `streak_run` is signed (-3 = three
                // losses in a row, +2 = two wins); `streak_kind`/`streak_step`
                // name the step applied (step 0 = base). `streak_size` is the
                // size the multiple was applied to; base_size stays the asset's.
                'streak_run' => isset($sizing['streak_run']) ? (int) $sizing['streak_run'] : null,
                'streak_known' => isset($sizing['streak_known']) ? (bool) $sizing['streak_known'] : null,
                'streak_kind' => isset($sizing['streak_kind']) ? (string) $sizing['streak_kind'] : null,
                'streak_step' => isset($sizing['streak_step']) ? (int) $sizing['streak_step'] : null,
                'streak_size' => isset($sizing['streak_size']) ? (float) $sizing['streak_size'] : null,
            ],
        ];
    }
}
