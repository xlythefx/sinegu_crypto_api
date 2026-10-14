<?php

namespace App\Http\Controllers;

use App\Models\TradeLog;
use App\Services\Admin\SignalHealth;
use App\Services\Exchanges\ExchangeSchema;
use App\Services\Positions\CloseSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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

    /** A retry belongs to the signal it re-runs when it lands this soon after it. */
    private const RETRY_FOLD_MINUTES = 60;

    /** How far an exit's closed-trade row may sit from the exit signal (retries included). */
    private const CLOSE_MATCH_MINUTES = 15;

    /**
     * GET /api/admin/trade-logs — filtered, paginated signal log.
     *
     * One timeline of what happened to positions, newest first:
     * - `kind: signal` — one engine run of a TradingView signal (or an admin's
     *   close, which goes through the same exit path). Its RETRIES are folded
     *   under it (`retries`), never listed as rows of their own: one MEXC exit
     *   retried every minute used to fill the page with five copies.
     * - `kind: outside_close` — a position closed on the exchange WITHOUT the
     *   bot (CloseSource: the customer by hand, their own TP/SL, a liquidation).
     *   It is not a signal, so it never counts in the signal summary.
     * Exit rows carry each account's closed trade (`close`: entry → exit, P&L
     * after fees), matched from {x}_pastpositions.
     *
     * Signal filters (exchange, side, type, action, ticker, category, strategy,
     * result, from/to) run in SQL. ACCOUNT filters look inside each signal's
     * fan-out and run in PHP over at most SignalHealth::SCAN_LIMIT signals:
     * - outcome = filled | skipped | failed | problem;
     * - reason  = a SignalHealth reason key;
     * - account = text matched against the account name and its owner's name.
     * `source` = all (default) | signals | outside.
     */
    public function index(Request $request): JsonResponse
    {
        $query = $this->baseQuery($request);
        $limit = min(max((int) $request->query('limit', 50), 1), self::MAX_LIMIT);
        $offset = max((int) $request->query('offset', 0), 0);

        $outcome = (string) $request->query('outcome', '');
        $reason = (string) $request->query('reason', '');
        $account = trim((string) $request->query('account', ''));
        $source = (string) $request->query('source', 'all');
        $accountFilter = $outcome !== '' || $reason !== '' || $account !== '';

        // The window every reason count, fold and account filter reads.
        $window = (clone $query)->orderByDesc('ts')->orderByDesc('id')->limit(SignalHealth::SCAN_LIMIT)->get();
        $names = $this->userNames($window);
        $reasonCounts = $this->reasonCounts($window);
        [$signals, $retries] = $this->foldRetries($window);

        $matcher = $accountFilter
            ? fn (array $d, TradeLog $log) => $this->detailMatches($d, $log, $outcome, $reason, $account, $names)
            : null;
        if ($source === 'outside') {
            $signals = collect();
        } elseif ($matcher) {
            $signals = $signals->filter(function (TradeLog $log) use ($matcher, $retries) {
                foreach ([$log, ...($retries[$log->id] ?? [])] as $run) {
                    foreach ((array) $run->details as $d) {
                        if (is_array($d) && $matcher($d, $run)) {
                            return true;
                        }
                    }
                }

                return false;
            })->values();
        }

        $outside = $this->wantsOutsideCloses($request, $source, $outcome, $reason)
            ? $this->outsideCloses($request, $account)
            : collect();

        $items = $signals->map(fn (TradeLog $log) => ['ts' => $log->ts?->getTimestamp() ?? 0, 'id' => $log->id, 'log' => $log])
            ->concat($outside->map(fn (array $row) => ['ts' => $row['_ts'], 'id' => 0, 'outside' => $row]))
            ->sort(fn ($a, $b) => [$b['ts'], $b['id']] <=> [$a['ts'], $a['id']])
            ->values();
        $page = $items->slice($offset, $limit)->values();

        $pageLogs = $page->pluck('log')->filter();
        $pageRuns = $pageLogs->concat($pageLogs->flatMap(fn (TradeLog $l) => $retries[$l->id] ?? []));
        $closes = $this->closesFor($pageRuns);
        $names += $this->userNames($pageRuns);

        $logs = $page->map(fn (array $item) => isset($item['log'])
            ? $this->mapLog($item['log'], $names, $matcher, $retries[$item['log']->id] ?? [], $closes)
            : $this->mapOutside($item['outside']));

        // Counted from the folded rows the list shows whenever the window holds
        // every matching signal; past the scan limit, SQL over the whole table.
        $complete = $window->count() < SignalHealth::SCAN_LIMIT;
        $summary = $this->summary($request, $signals, $retries, $complete || $accountFilter || $source === 'outside') + [
            'outside_closes' => $outside->count(),
        ];

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
                'total' => $items->count(),
            ],
            // Drives the frontend filter chips without a second round trip.
            'filters' => [
                'tickers' => TradeLog::distinct()->orderBy('ticker')->pluck('ticker'),
                'exchanges' => TradeLog::distinct()->orderBy('exchange')->pluck('exchange'),
                'strategies' => TradeLog::whereNotNull('strategy')
                    ->distinct()->orderBy('strategy')->pluck('strategy'),
                'reasons' => array_values($reasonCounts),
            ],
        ]);
    }

    /**
     * The signal summary. Retries are folded, so `signals` counts signals and
     * `skipped`/`failed` count their first run; `filled` adds the fills a
     * retry placed — each of those is an order. Without account filters it
     * runs in SQL over the whole filtered table, not just the scanned window.
     *
     * @param  array<int, list<TradeLog>>  $retries
     * @return array{signals: int, clean_signals: int, filled: int, failed: int, skipped: int}
     */
    private function summary(Request $request, Collection $signals, array $retries, bool $fromWindow): array
    {
        if ($fromWindow) {
            return [
                'signals' => $signals->count(),
                'clean_signals' => $signals->where('success', true)->count(),
                'filled' => (int) $signals->sum(fn (TradeLog $l) => $l->filled + collect($retries[$l->id] ?? [])->sum('filled')),
                'failed' => (int) $signals->sum('failed'),
                'skipped' => (int) $signals->sum('skipped'),
            ];
        }
        $firstRuns = $this->baseQuery($request)->where(fn ($q) => $q->whereNull('category')->orWhere('category', '!=', 'retry'));
        $row = (clone $firstRuns)
            ->selectRaw('COUNT(*) AS signals')
            ->selectRaw('COALESCE(SUM(failed), 0) AS failed')
            ->selectRaw('COALESCE(SUM(skipped), 0) AS skipped')
            ->selectRaw('COALESCE(SUM(success = 1), 0) AS clean_signals')
            ->first();

        return [
            'signals' => (int) ($row->signals ?? 0),
            'clean_signals' => (int) ($row->clean_signals ?? 0),
            'filled' => (int) $this->baseQuery($request)->sum('filled'),
            'failed' => (int) ($row->failed ?? 0),
            'skipped' => (int) ($row->skipped ?? 0),
        ];
    }

    /**
     * Split a window into signals and their retries. A retry row (category
     * `retry`) is folded under the latest earlier signal with the same
     * exchange, action and ticker within RETRY_FOLD_MINUTES; one with no such
     * signal in the window stays a row of its own.
     *
     * @return array{0: Collection<int, TradeLog>, 1: array<int, list<TradeLog>>}
     */
    private function foldRetries(Collection $window): array
    {
        $signals = [];
        $retries = [];
        $last = [];
        foreach ($window->sortBy(fn (TradeLog $l) => [$l->ts?->getTimestamp() ?? 0, $l->id]) as $log) {
            $key = "{$log->exchange}|{$log->action}|{$log->ticker}";
            $parent = $last[$key] ?? null;
            if ($log->category === 'retry' && $parent
                && ($log->ts?->getTimestamp() ?? 0) - ($parent->ts?->getTimestamp() ?? 0) <= self::RETRY_FOLD_MINUTES * 60) {
                $retries[$parent->id][] = $log;

                continue;
            }
            $signals[] = $log;
            $last[$key] = $log;
        }

        return [
            collect(array_reverse($signals)),
            $retries,
        ];
    }

    /** Closes made outside the bot can only answer exit-side, account-level questions. */
    private function wantsOutsideCloses(Request $request, string $source, string $outcome, string $reason): bool
    {
        if ($source === 'signals' || $outcome !== '' || $reason !== '') {
            return false;
        }
        if ($request->query('type') === 'entry' || $request->query('category') || $request->query('strategy') || $request->query('result')) {
            return false;
        }
        $action = strtoupper((string) $request->query('action', ''));

        return $action === '' || str_starts_with($action, 'EXIT_');
    }

    /**
     * Closes made outside the bot, every exchange (or the filtered one), real
     * rows only (no sandbox), under the same exchange / side / ticker / date /
     * account filters as the signals. Newest SignalHealth::SCAN_LIMIT.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function outsideCloses(Request $request, string $account): Collection
    {
        $exchanges = ExchangeSchema::supported();
        if ($ex = $request->query('exchange')) {
            $exchanges = in_array($ex, $exchanges, true) ? [$ex] : [];
        }
        $side = $this->sideFilter($request);
        $needle = strtolower($account);

        $rows = collect();
        foreach ($exchanges as $exchange) {
            $schema = ExchangeSchema::for($exchange);
            $q = DB::table("{$schema->pastPositions} as t")
                ->leftJoin("{$schema->accountsTable} as a", 't.api_key', '=', 'a.api_key')
                ->leftJoin('user_credentials as u', 'u.uni_id', '=', 't.uni_id')
                ->where('t.close_source', CloseSource::OUTSIDE)
                ->where('t.is_sandbox', 0);
            if ($ticker = $request->query('ticker')) {
                $q->where('t.symbol', strtoupper($ticker));
            }
            if ($side !== null) {
                $q->where('t.position_side', $side);
            }
            if ($from = $request->query('from')) {
                $q->where('t.closed_at', '>=', $from.' 00:00:00');
            }
            if ($to = $request->query('to')) {
                $q->where('t.closed_at', '<=', $to.' 23:59:59');
            }
            if ($needle !== '') {
                $q->where(fn ($w) => $w->whereRaw('LOWER(a.name) LIKE ?', ["%{$needle}%"])
                    ->orWhereRaw('LOWER(u.name) LIKE ?', ["%{$needle}%"]));
            }
            $rows = $rows->concat(
                $q->orderByDesc('t.closed_at')->limit(SignalHealth::SCAN_LIMIT)->get([
                    't.id', 't.uni_id', 't.symbol', 't.position_side', 't.position_amt', 't.entry_price',
                    't.exit_price', 't.realized_pnl', 't.exchange_fee', 't.closed_at',
                    'a.name as account_name', 'a.demo', 'u.name as user_name',
                ])->map(fn ($r) => ['exchange' => $exchange, '_ts' => Carbon::parse($r->closed_at, 'UTC')->getTimestamp(), 'row' => $r])
            );
        }

        return $rows;
    }

    /** `side=long|short` → the position side it names, or null. */
    private function sideFilter(Request $request): ?string
    {
        return match (strtolower((string) $request->query('side', ''))) {
            'long' => 'LONG',
            'short' => 'SHORT',
            default => null,
        };
    }

    /**
     * The closed-trade row behind each account an exit filled: same exchange,
     * owner and coin, closed within CLOSE_MATCH_MINUTES of the run, and not a
     * close made outside the bot. Keyed "{log id}|{uni_id}".
     *
     * @return array<string, array<string, mixed>>
     */
    private function closesFor(Collection $runs): array
    {
        $exits = $runs->filter(fn (TradeLog $l) => str_starts_with((string) $l->action, 'EXIT_'));
        $out = [];
        foreach ($exits->groupBy('exchange') as $exchange => $logs) {
            if (! ExchangeSchema::isSupported((string) $exchange)) {
                continue;
            }
            $uniIds = [];
            foreach ($logs as $log) {
                foreach ((array) $log->details as $d) {
                    if (is_array($d) && ($d['status'] ?? null) === 'filled' && ! empty($d['uni_id'])) {
                        $uniIds[$d['uni_id']] = true;
                    }
                }
            }
            if ($uniIds === []) {
                continue;
            }
            $times = $logs->map(fn (TradeLog $l) => $l->ts?->getTimestamp() ?? 0);
            $window = self::CLOSE_MATCH_MINUTES * 60;
            $rows = DB::table(ExchangeSchema::for((string) $exchange)->pastPositions)
                ->whereIn('uni_id', array_keys($uniIds))
                ->whereIn('symbol', $logs->pluck('ticker')->unique()->values())
                ->where('is_sandbox', 0)
                ->where(fn ($q) => $q->whereNull('close_source')->orWhere('close_source', '!=', CloseSource::OUTSIDE))
                ->whereBetween('closed_at', [
                    Carbon::createFromTimestamp($times->min() - $window, 'UTC')->toDateTimeString(),
                    Carbon::createFromTimestamp($times->max() + $window, 'UTC')->toDateTimeString(),
                ])
                ->get(['uni_id', 'symbol', 'position_amt', 'entry_price', 'exit_price', 'realized_pnl', 'exchange_fee', 'closed_at']);

            foreach ($logs as $log) {
                $at = $log->ts?->getTimestamp() ?? 0;
                foreach ((array) $log->details as $d) {
                    if (! is_array($d) || ($d['status'] ?? null) !== 'filled' || empty($d['uni_id'])) {
                        continue;
                    }
                    $best = null;
                    $bestGap = $window + 1;
                    foreach ($rows as $r) {
                        if ($r->uni_id !== $d['uni_id'] || $r->symbol !== $log->ticker) {
                            continue;
                        }
                        $gap = abs(Carbon::parse($r->closed_at, 'UTC')->getTimestamp() - $at);
                        if ($gap < $bestGap) {
                            $best = $r;
                            $bestGap = $gap;
                        }
                    }
                    if ($best) {
                        $out["{$log->id}|{$d['uni_id']}"] = [
                            'quantity' => abs((float) $best->position_amt),
                            'entry_price' => $best->entry_price !== null ? (float) $best->entry_price : null,
                            'exit_price' => $best->exit_price !== null ? (float) $best->exit_price : null,
                            'realized_pnl' => $best->realized_pnl !== null ? round((float) $best->realized_pnl, 2) : null,
                            'exchange_fee' => $best->exchange_fee !== null ? round((float) $best->exchange_fee, 2) : null,
                            'closed_at' => Carbon::parse($best->closed_at, 'UTC')->toIso8601String(),
                        ];
                    }
                }
            }
        }

        return $out;
    }

    /** One close made outside the bot, as a timeline row. */
    private function mapOutside(array $item): array
    {
        $r = $item['row'];
        $amount = (float) $r->position_amt;
        $side = in_array($r->position_side, ['LONG', 'SHORT'], true) ? $r->position_side : ($amount < 0 ? 'SHORT' : 'LONG');

        return [
            'kind' => 'outside_close',
            'id' => "outside-{$item['exchange']}-{$r->id}",
            'exchange' => $item['exchange'],
            'ticker' => $r->symbol,
            'side' => $side,
            'type' => 'exit',
            'account' => $r->account_name,
            'uni_id' => $r->uni_id,
            'user_name' => $r->user_name,
            'demo' => (bool) $r->demo,
            'quantity' => abs($amount),
            'entry_price' => $r->entry_price !== null ? (float) $r->entry_price : null,
            'exit_price' => $r->exit_price !== null ? (float) $r->exit_price : null,
            'realized_pnl' => $r->realized_pnl !== null ? round((float) $r->realized_pnl, 2) : null,
            'exchange_fee' => $r->exchange_fee !== null ? round((float) $r->exchange_fee, 2) : null,
            'ts' => Carbon::parse($r->closed_at, 'UTC')->toIso8601String(),
        ];
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
        // side = long | short, type = entry | exit — the plain-word facets
        // over the engine's four actions (BUY / SELL / EXIT_LONG / EXIT_SHORT).
        $side = strtolower((string) $request->query('side', ''));
        if ($side === 'long') {
            $query->whereIn('action', ['BUY', 'EXIT_LONG']);
        } elseif ($side === 'short') {
            $query->whereIn('action', ['SELL', 'EXIT_SHORT']);
        }
        $type = strtolower((string) $request->query('type', ''));
        if ($type === 'entry') {
            $query->whereIn('action', ['BUY', 'SELL']);
        } elseif ($type === 'exit') {
            $query->whereIn('action', ['EXIT_LONG', 'EXIT_SHORT']);
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
            'log' => $this->mapLog($log, $this->userNames(collect([$log])), null, [], $this->closesFor(collect([$log]))),
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

    /**
     * @param  list<TradeLog>  $retries  this signal's folded retry runs
     * @param  array<string, array<string, mixed>>  $closes  closesFor()
     */
    private function mapLog(TradeLog $log, array $names, ?callable $matcher = null, array $retries = [], array $closes = []): array
    {
        $details = is_array($log->details) ? $log->details : [];
        $problems = 0;
        $normal = 0;
        foreach ($details as $d) {
            if (is_array($d) && ($c = SignalHealth::classify($d, $log->category))) {
                $c['severity'] === SignalHealth::PROBLEM ? $problems++ : $normal++;
            }
        }

        $action = (string) $log->action;

        return [
            'kind' => 'signal',
            'id' => $log->id,
            'exchange' => $log->exchange,
            'action' => $log->action,
            // The plain words: which side of the market, and opening or closing.
            'side' => in_array($action, ['SELL', 'EXIT_SHORT'], true) ? 'SHORT' : 'LONG',
            'type' => str_starts_with($action, 'EXIT_') ? 'exit' : 'entry',
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
                fn ($row) => $this->mapDetail($row, $names, $log, $matcher, $closes),
                $details
            ),
            // Re-runs of this signal for the accounts that failed it, oldest
            // first — folded here instead of listed as rows of their own.
            'retries' => array_map(fn (TradeLog $r) => [
                'id' => $r->id,
                'success' => (bool) $r->success,
                'filled' => (int) $r->filled,
                'failed' => (int) $r->failed,
                'skipped' => (int) $r->skipped,
                'details' => array_map(
                    fn ($row) => $this->mapDetail($row, $names, $r, $matcher, $closes),
                    is_array($r->details) ? $r->details : []
                ),
                'ts' => optional($r->ts)->toIso8601String(),
            ], $retries),
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
    private function mapDetail($row, array $names, ?TradeLog $log = null, ?callable $matcher = null, array $closes = []): array
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
            // Exits: the closed trade this account's close produced — entry →
            // exit price and P&L after fees (null until it has been synced).
            'close' => $log && isset($row['uni_id']) ? ($closes["{$log->id}|{$row['uni_id']}"] ?? null) : null,
            // Stack depth after an entry / increments taken off by an exit.
            'increment' => isset($row['increment']) ? (int) $row['increment'] : null,
            'max_increments' => isset($row['max_increments']) ? (int) $row['max_increments'] : null,
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
