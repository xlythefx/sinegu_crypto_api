<?php

namespace App\Services\Ai\Tools;

use App\Http\Controllers\AdminTradeLogController;
use App\Services\Admin\SignalHealth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The Signal Log: every TradingView signal the bot received and what each
 * account did with it. Search and the single-signal view REUSE the admin
 * Signal Log's own code (AdminTradeLogController), so the AI and the admin
 * page can never disagree about what a signal did.
 */
final class SignalTools implements Toolset
{
    public function definitions(): array
    {
        return [
            'search_signals' => [
                'title' => 'Search trading signals',
                'description' => 'List the trading signals the bot received (newest first), with how many accounts filled, '
                    .'skipped or failed each one, and how the master account did. Use it for "show me yesterday\'s LTC signals", '
                    .'"which signals had problems", "did Juan get the BTC entry". Set include_accounts=true to see each '
                    .'account\'s result and reason on every signal (bigger answer). For COUNTS use signal_counts instead. '
                    .'For one signal in full detail use get_signal with its id.',
                'schema' => Props::object([
                    'from' => Props::from('30 days ago'),
                    'to' => Props::to(),
                    'exchange' => Props::exchange(),
                    'coin' => Props::coin(),
                    'action' => ['type' => 'string', 'enum' => ['BUY', 'SELL', 'EXIT_LONG', 'EXIT_SHORT'], 'description' => 'BUY opens a long, SELL opens a short, EXIT_LONG / EXIT_SHORT close them.'],
                    'type' => ['type' => 'string', 'enum' => ['entry', 'exit'], 'description' => 'entry = BUY or SELL, exit = EXIT_LONG or EXIT_SHORT.'],
                    'feed' => ['type' => 'string', 'enum' => ['main', 'test', 'all'], 'description' => 'main = TradingView, the real signals every customer follows; test = the testers-only feed (QuantLab). Default: main.'],
                    'outcome' => ['type' => 'string', 'enum' => ['filled', 'skipped', 'failed', 'problem'], 'description' => 'Only signals where at least one account had this result. problem = anything that needs attention.'],
                    'account' => Props::owner(),
                    'only_problems' => ['type' => 'boolean', 'description' => 'Only signals that failed outright or where an account failed.'],
                    'include_accounts' => ['type' => 'boolean', 'description' => 'Add every account\'s result to each signal. Default: false.'],
                    'limit' => Props::limit(25, 100),
                    'offset' => Props::offset(),
                ]),
            ],
            'get_signal' => [
                'title' => 'One signal in full',
                'description' => 'Everything about one signal by its id (from search_signals): each account\'s result and the '
                    .'plain-English reason, the size it traded and how that size was worked out (balance, deposit, base size, '
                    .'stack depth), the closed trade and P&L for exits, retries, and delivery timing from TradingView.',
                'schema' => Props::object([
                    'id' => ['type' => 'integer', 'description' => 'The signal id.'],
                ], ['id']),
            ],
            'signal_counts' => [
                'title' => 'Count signals',
                'description' => 'How many signals were received, grouped by coin, day, exchange or action. Use it for '
                    .'"how many LTC trades came in this month", "signals per day last week", "how many exits on MEXC". '
                    .'Each group gives signals (first runs, retries not counted), entries / exits, buys / sells, '
                    .'rejected signals, and how many account orders filled, were skipped or failed.',
                'schema' => Props::object([
                    'from' => Props::from('30 days ago'),
                    'to' => Props::to(),
                    'exchange' => Props::exchange(),
                    'coin' => Props::coin(),
                    'feed' => ['type' => 'string', 'enum' => ['main', 'test', 'all'], 'description' => 'Default: main (TradingView).'],
                    'group_by' => ['type' => 'string', 'enum' => ['coin', 'day', 'exchange', 'action', 'none'], 'description' => 'Default: coin.'],
                ]),
            ],
            'signal_health' => [
                'title' => 'Signal health and problems',
                'description' => 'Is the bot healthy right now: a verdict for the last N hours (healthy, normal, attention, '
                    .'quiet), plus every PROBLEM in the last N days grouped by cause and account (e.g. "key refused" for one '
                    .'customer 12 times), and the normal skips (stack full, deposit below minimum) counted separately.',
                'schema' => Props::object([
                    'hours' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 720, 'description' => 'Window for the verdict. Default: 24.'],
                    'days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 90, 'description' => 'Window for the problem list. Default: 7.'],
                ]),
            ],
        ];
    }

    public function call(string $tool, array $arguments): array
    {
        $args = new Args($arguments);

        return match ($tool) {
            'search_signals' => $this->search($args),
            'get_signal' => $this->show($args),
            'signal_counts' => $this->counts($args),
            'signal_health' => $this->health($args),
        };
    }

    private function search(Args $args): array
    {
        [$from, $to] = $args->range(30);
        $feed = $args->enum('feed', ['main', 'test', 'all'], 'main');
        $limit = $args->int('limit', 25, 1, 100);
        $includeAccounts = $args->bool('include_accounts');
        $coin = $args->str('coin');

        $query = array_filter([
            'from' => $from,
            'to' => $to,
            'exchange' => $args->enum('exchange', ['binance', 'bybit', 'mexc']),
            'feed' => $feed === 'all' ? null : $feed,
            'action' => $args->enum('action', ['BUY', 'SELL', 'EXIT_LONG', 'EXIT_SHORT']),
            'type' => $args->enum('type', ['entry', 'exit']),
            'ticker' => $coin === null ? null : $this->tickerFor($coin),
            'outcome' => $args->enum('outcome', ['filled', 'skipped', 'failed', 'problem']),
            'account' => $args->str('account'),
            'result' => $args->bool('only_problems') ? 'problem' : null,
            // A hand close on the exchange is not a signal; closed_trades covers those.
            'source' => 'signals',
            'limit' => $limit,
            'offset' => $args->int('offset', 0, 0, 100000),
        ], fn ($v) => $v !== null && $v !== '');

        $data = app(AdminTradeLogController::class)->index(Request::create('/', 'GET', $query))->getData(true);

        return [
            'window' => ['from' => $from, 'to' => $to, 'feed' => $feed],
            'summary' => $data['summary'] ?? null,
            'total' => $data['pagination']['total'] ?? null,
            'returned' => count($data['logs'] ?? []),
            'signals' => array_map(fn (array $log) => $this->compact($log, $includeAccounts), $data['logs'] ?? []),
        ];
    }

    private function show(Args $args): array
    {
        $id = $args->int('id', 0, 0, PHP_INT_MAX);
        if ($id < 1) {
            throw new ToolError('id is required (a signal id from search_signals).');
        }
        $response = app(AdminTradeLogController::class)->show((string) $id);
        if ($response->getStatusCode() === 404) {
            throw new ToolError("No signal has id {$id}.");
        }
        $log = $response->getData(true)['log'];
        $strip = function (array $d) {
            unset($d['match']);

            return $d;
        };
        $log['details'] = array_map($strip, $log['details'] ?? []);
        foreach ($log['retries'] ?? [] as $i => $r) {
            $log['retries'][$i]['details'] = array_map($strip, $r['details'] ?? []);
        }
        $log['coin'] = Coins::of((string) ($log['ticker'] ?? ''));

        return ['signal' => $log];
    }

    /** A list row: the signal's facts and counts; accounts only on request. */
    private function compact(array $log, bool $includeAccounts): array
    {
        $row = [
            'id' => $log['id'] ?? null,
            'time' => $log['ts'] ?? null,
            'exchange' => $log['exchange'] ?? null,
            'feed' => $log['feed'] ?? null,
            'action' => $log['action'] ?? null,
            'side' => $log['side'] ?? null,
            'type' => $log['type'] ?? null,
            'coin' => Coins::of((string) ($log['ticker'] ?? '')),
            'ticker' => $log['ticker'] ?? null,
            'price' => $log['price'] ?? null,
            'strategy' => $log['strategy'] ?? null,
            'category' => $log['category'] ?? null,
            'accounts_targeted' => $log['target_count'] ?? null,
            'filled' => $log['filled'] ?? null,
            'skipped' => $log['skipped'] ?? null,
            'failed' => $log['failed'] ?? null,
            'problems' => $log['problems'] ?? null,
            'normal_skips' => $log['normal_skips'] ?? null,
            'master' => $log['master'] ?? null,
            'retries' => count($log['retries'] ?? []),
            'delivery' => $log['delivery'] ?? null,
        ];
        if ($includeAccounts) {
            $row['accounts'] = array_map(fn (array $d) => array_filter([
                'account' => $d['account'] ?? null,
                'owner' => $d['user_name'] ?? null,
                'is_master' => $d['is_master'] ?? null,
                'status' => $d['status'] ?? null,
                'reason' => $d['reason_label'] ?? $d['reason'] ?? null,
                'severity' => $d['severity'] ?? null,
                'error' => $d['error'] ?? null,
                'quantity' => $d['quantity'] ?? null,
                'fill_price' => $d['fill_price'] ?? null,
                'increment' => $d['increment'] ?? null,
                'max_increments' => $d['max_increments'] ?? null,
                'close' => $d['close'] ?? null,
            ], fn ($v) => $v !== null), $log['details'] ?? []);
        }

        return $row;
    }

    private function counts(Args $args): array
    {
        [$from, $to] = $args->range(30);
        $feed = $args->enum('feed', ['main', 'test', 'all'], 'main');
        $groupBy = $args->enum('group_by', ['coin', 'day', 'exchange', 'action', 'none'], 'coin');
        $exchange = $args->enum('exchange', ['binance', 'bybit', 'mexc']);
        $coin = $args->str('coin');

        $q = DB::table('trade_logs')
            ->where('ts', '>=', $from.' 00:00:00')
            ->where('ts', '<=', $to.' 23:59:59');
        if ($feed !== 'all') {
            $q->where('feed', $feed);
        }
        if ($exchange) {
            $q->where('exchange', $exchange);
        }
        if ($coin !== null) {
            $q->whereIn('ticker', Coins::symbols($coin));
        }

        $first = "(category IS NULL OR category <> 'retry')";
        $rows = $q->groupBy('ticker', 'exchange', 'action', DB::raw('DATE(ts)'))
            ->select('ticker', 'exchange', 'action', DB::raw('DATE(ts) AS day'))
            ->selectRaw("SUM(CASE WHEN {$first} THEN 1 ELSE 0 END) AS signals")
            ->selectRaw("SUM(CASE WHEN category = 'rejected' THEN 1 ELSE 0 END) AS rejected")
            ->selectRaw("SUM(CASE WHEN category = 'retry' THEN 1 ELSE 0 END) AS retries")
            ->selectRaw('COALESCE(SUM(filled), 0) AS filled')
            ->selectRaw("COALESCE(SUM(CASE WHEN {$first} THEN skipped ELSE 0 END), 0) AS skipped")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$first} THEN failed ELSE 0 END), 0) AS failed")
            ->get();

        $groups = [];
        $totals = $this->emptyCount();
        foreach ($rows as $r) {
            $key = match ($groupBy) {
                'coin' => Coins::of((string) $r->ticker),
                'day' => (string) $r->day,
                'exchange' => (string) $r->exchange,
                'action' => (string) $r->action,
                'none' => 'all',
            };
            $groups[$key] ??= $this->emptyCount();
            $this->addCount($groups[$key], $r);
            $this->addCount($totals, $r);
        }
        $groupBy === 'day' ? ksort($groups) : uasort($groups, fn ($a, $b) => $b['signals'] <=> $a['signals']);

        return [
            'window' => ['from' => $from, 'to' => $to, 'feed' => $feed, 'exchange' => $exchange ?? 'all', 'coin' => $coin === null ? 'all' : Coins::of($coin)],
            'grouped_by' => $groupBy,
            'totals' => $totals,
            'groups' => $groupBy === 'none' ? [] : array_map(
                fn ($k, $v) => [$groupBy => $k] + $v,
                array_keys($groups),
                array_values($groups),
            ),
        ];
    }

    private function emptyCount(): array
    {
        return ['signals' => 0, 'entries' => 0, 'exits' => 0, 'buys' => 0, 'sells' => 0, 'exit_longs' => 0,
            'exit_shorts' => 0, 'rejected' => 0, 'retries' => 0, 'account_fills' => 0, 'account_skips' => 0, 'account_failures' => 0];
    }

    private function addCount(array &$c, object $r): void
    {
        $n = (int) $r->signals;
        $c['signals'] += $n;
        $action = (string) $r->action;
        if ($action === 'BUY' || $action === 'SELL') {
            $c['entries'] += $n;
            $c[$action === 'BUY' ? 'buys' : 'sells'] += $n;
        } else {
            $c['exits'] += $n;
            $c[$action === 'EXIT_LONG' ? 'exit_longs' : 'exit_shorts'] += $n;
        }
        $c['rejected'] += (int) $r->rejected;
        $c['retries'] += (int) $r->retries;
        $c['account_fills'] += (int) $r->filled;
        $c['account_skips'] += (int) $r->skipped;
        $c['account_failures'] += (int) $r->failed;
    }

    private function health(Args $args): array
    {
        $hours = $args->int('hours', 24, 1, 720);
        $days = $args->int('days', 7, 1, 90);
        $issues = app(AdminTradeLogController::class)->issues(
            Request::create('/', 'GET', ['from' => now('UTC')->subDays($days - 1)->toDateString(), 'to' => now('UTC')->toDateString()]),
            app(SignalHealth::class),
        )->getData(true);

        return [
            'verdict_window_hours' => $hours,
            'health' => app(SignalHealth::class)->health($hours),
            'problems_window_days' => $days,
            'signals_in_window' => $issues['signals'] ?? null,
            'problems' => $issues['issues'] ?? [],
            'normal_skips' => $issues['normal'] ?? [],
        ];
    }

    /** The stored ticker for a coin — the Signal Log filters on one exact ticker. */
    private function tickerFor(string $coin): string
    {
        $symbols = Coins::symbols($coin);
        $seen = DB::table('trade_logs')->whereIn('ticker', $symbols)->distinct()->pluck('ticker')->all();

        return $seen[0] ?? $symbols[0];
    }
}
