<?php

namespace App\Services\Ai\Tools;

use App\Http\Controllers\AdminOpenPositionsController;
use App\Models\Asset;
use App\Services\Exchanges\ExchangeSchema;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Positions and closed trades on every venue, and the per-coin result
 * ("which assets are up"). Real money only unless asked: demo accounts,
 * sandbox rows and invoice-scenario scratch accounts (`SBXINV-`) are never
 * business, the same rule every admin insight follows.
 */
final class TradeTools implements Toolset
{
    /** A window this wide is refused rather than read into memory. */
    private const MAX_ROWS = 50000;

    public function definitions(): array
    {
        return [
            'open_positions' => [
                'title' => 'Open positions',
                'description' => 'Every position open right now on every exchange: owner, account, coin, LONG/SHORT, size, '
                    .'entry and mark price, unrealized P&L. Refreshed from the exchanges every few minutes. Use it for '
                    .'"what is open now", "is the master in LTC", "who holds BTC".',
                'schema' => Props::object([
                    'exchange' => Props::exchange(),
                    'coin' => Props::coin(),
                    'owner' => Props::owner(),
                ]),
            ],
            'closed_trades' => [
                'title' => 'Closed trades and P&L',
                'description' => 'Closed trades (realized P&L) on every exchange, with totals: trades, wins, losses, win rate, '
                    .'P&L after fees, fees, P&L before fees. Use group_by to total per day, coin, person, account or '
                    .'exchange — e.g. "best day this month" (group_by=day), "who made the most this week" (group_by=owner). '
                    .'Without group_by it lists the trades newest first. Real-money accounts only unless include_demo.',
                'schema' => Props::object([
                    'from' => Props::from('30 days ago'),
                    'to' => Props::to(),
                    'exchange' => Props::exchange(),
                    'coin' => Props::coin(),
                    'owner' => Props::owner(),
                    'who' => Props::who('all'),
                    'closed_by' => ['type' => 'string', 'enum' => ['bot', 'outside', 'any'], 'description' => 'bot = closed by our bot; outside = closed on the exchange without the bot (by hand, own stop-loss, liquidation). Default: any.'],
                    'group_by' => ['type' => 'string', 'enum' => ['none', 'day', 'coin', 'owner', 'account', 'exchange'], 'description' => 'Default: none (list the trades).'],
                    'include_demo' => ['type' => 'boolean', 'description' => 'Also count demo (play-money) accounts. Default: false.'],
                    'limit' => Props::limit(50, 200),
                    'offset' => Props::offset(),
                ]),
            ],
            'asset_performance' => [
                'title' => 'Which assets are up',
                'description' => 'P&L per coin over a period — answers "what assets are up / down". Each coin shows the '
                    .'MASTER account\'s result (the strategy itself), the CUSTOMERS\' combined result and everyone together: '
                    .'trades, wins, losses, win rate, P&L after fees, fees. Also says whether the coin is switched on for '
                    .'trading right now on each exchange (and if the kill switch turned it off). Sorted best first.',
                'schema' => Props::object([
                    'from' => Props::from('30 days ago (pass e.g. 2020-01-01 for all time)'),
                    'to' => Props::to(),
                    'exchange' => Props::exchange(),
                ]),
            ],
        ];
    }

    public function call(string $tool, array $arguments): array
    {
        $args = new Args($arguments);

        return match ($tool) {
            'open_positions' => $this->open($args),
            'closed_trades' => $this->closed($args),
            'asset_performance' => $this->performance($args),
        };
    }

    private function open(Args $args): array
    {
        $exchange = $args->enum('exchange', ['binance', 'bybit', 'mexc']);
        $coin = $args->str('coin');
        $owner = $args->str('owner');
        $data = app(AdminOpenPositionsController::class)->index()->getData(true);

        $rows = array_values(array_filter($data['positions'] ?? [], function (array $p) use ($exchange, $coin, $owner) {
            if ($exchange && ($p['exchange'] ?? null) !== $exchange) {
                return false;
            }
            if ($coin !== null && Coins::of((string) ($p['symbol'] ?? '')) !== Coins::of($coin)) {
                return false;
            }
            if ($owner !== null && ! $this->nameMatches($owner, $p['owner_name'] ?? null, $p['account_name'] ?? null)) {
                return false;
            }

            return true;
        }));

        return [
            'count' => count($rows),
            'unrealized_pnl_total' => round(array_sum(array_map(fn ($p) => (float) ($p['unrealized_pnl'] ?? 0), $rows)), 2),
            'exchanges_with_a_master_account' => $data['master_exchanges'] ?? [],
            'positions' => array_map(fn (array $p) => [
                'exchange' => $p['exchange'] ?? null,
                'owner' => $p['owner_name'] ?? null,
                'owner_type' => self::ownerType($p['owner_type'] ?? null),
                'account' => $p['account_name'] ?? null,
                'demo' => $p['demo'] ?? null,
                'feed' => $p['feed'] ?? null,
                'coin' => Coins::of((string) ($p['symbol'] ?? '')),
                'side' => $p['side'] ?? null,
                'size' => $p['size'] ?? null,
                'entry_price' => $p['entry_price'] ?? null,
                'mark_price' => $p['mark_price'] ?? null,
                'unrealized_pnl' => $p['unrealized_pnl'] ?? null,
                'updated_at' => $p['updated_at'] ?? null,
                'note' => $p['blocked_reason'] ?? null,
            ], $rows),
        ];
    }

    private function closed(Args $args): array
    {
        [$from, $to] = $args->range(30);
        $groupBy = $args->enum('group_by', ['none', 'day', 'coin', 'owner', 'account', 'exchange'], 'none');
        $limit = $args->int('limit', 50, 1, 200);
        $offset = $args->int('offset', 0, 0, self::MAX_ROWS);

        $rows = $this->trades($from, $to, [
            'exchange' => $args->enum('exchange', ['binance', 'bybit', 'mexc']),
            'coin' => $args->str('coin'),
            'owner' => $args->str('owner'),
            'who' => $args->enum('who', ['all', 'master', 'customers', 'staff'], 'all'),
            'closed_by' => $args->enum('closed_by', ['bot', 'outside', 'any'], 'any'),
            'include_demo' => $args->bool('include_demo'),
        ]);

        $out = [
            'window' => ['from' => $from, 'to' => $to],
            'totals' => $this->totals($rows),
        ];
        if ($groupBy !== 'none') {
            $groups = [];
            foreach ($rows as $r) {
                $key = match ($groupBy) {
                    'day' => substr($r['closed_at'], 0, 10),
                    'coin' => $r['coin'],
                    'owner' => ($r['owner'] ?? 'Unknown').' ('.$r['owner_type'].')',
                    'account' => ($r['account'] ?? 'Unknown').' · '.$r['exchange'].' · '.($r['owner'] ?? 'Unknown'),
                    'exchange' => $r['exchange'],
                };
                $groups[$key][] = $r;
            }
            $list = array_map(fn ($k, $g) => [$groupBy => $k] + $this->totals($g), array_keys($groups), array_values($groups));
            usort($list, $groupBy === 'day'
                ? fn ($a, $b) => strcmp($a['day'], $b['day'])
                : fn ($a, $b) => $b['pnl_after_fees'] <=> $a['pnl_after_fees']);
            $out['grouped_by'] = $groupBy;
            $out['groups'] = $list;

            return $out;
        }

        $out['total_rows'] = count($rows);
        $out['trades'] = array_slice($rows, $offset, $limit);

        return $out;
    }

    private function performance(Args $args): array
    {
        [$from, $to] = $args->range(30);
        $exchange = $args->enum('exchange', ['binance', 'bybit', 'mexc']);
        $rows = $this->trades($from, $to, ['exchange' => $exchange, 'who' => 'all', 'closed_by' => 'any', 'include_demo' => false]);

        $byCoin = [];
        foreach ($rows as $r) {
            $byCoin[$r['coin']][] = $r;
        }

        $status = $this->assetStatus();
        $coins = [];
        foreach ($byCoin as $coin => $trades) {
            $coins[] = [
                'coin' => $coin,
                'everyone' => $this->totals($trades),
                'master' => $this->totals(array_filter($trades, fn ($t) => $t['owner_type'] === 'master')),
                'customers' => $this->totals(array_filter($trades, fn ($t) => $t['owner_type'] === 'customer')),
                'trading_now' => $status[$coin] ?? [],
            ];
        }
        usort($coins, fn ($a, $b) => $b['everyone']['pnl_after_fees'] <=> $a['everyone']['pnl_after_fees']);

        // Coins switched on with no closed trade in the window still belong in
        // "which assets are up": the answer for them is "nothing closed".
        $quiet = array_values(array_diff(array_keys($status), array_keys($byCoin)));
        sort($quiet);

        return [
            'window' => ['from' => $from, 'to' => $to, 'exchange' => $exchange ?? 'all'],
            'up' => array_values(array_map(fn ($c) => $c['coin'], array_filter($coins, fn ($c) => $c['everyone']['pnl_after_fees'] > 0))),
            'down' => array_values(array_map(fn ($c) => $c['coin'], array_filter($coins, fn ($c) => $c['everyone']['pnl_after_fees'] < 0))),
            'coins' => $coins,
            'no_closed_trades_in_window' => array_map(fn ($c) => ['coin' => $c, 'trading_now' => $status[$c]], $quiet),
        ];
    }

    /**
     * Closed trades across every venue, real money unless include_demo,
     * newest first.
     *
     * @param  array<string, mixed>  $f
     * @return list<array<string, mixed>>
     */
    private function trades(string $from, string $to, array $f): array
    {
        $exchanges = ! empty($f['exchange']) ? [$f['exchange']] : ExchangeSchema::supported();
        $rows = [];
        foreach ($exchanges as $exchange) {
            $s = ExchangeSchema::for($exchange);
            $q = DB::table("{$s->pastPositions} as p")
                // The raw table, so a disconnected (soft-deleted) account's trades keep their name.
                ->leftJoin("{$s->accountsTable} as a", 'a.api_key', '=', 'p.api_key')
                ->leftJoin('user_credentials as u', 'u.uni_id', '=', 'p.uni_id')
                ->where('p.is_sandbox', 0)
                ->where('p.api_key', 'not like', 'SBXINV-%')
                ->where('p.closed_at', '>=', $from.' 00:00:00')
                ->where('p.closed_at', '<=', $to.' 23:59:59');
            if (empty($f['include_demo'])) {
                $q->where(fn ($w) => $w->whereNull('a.demo')->orWhere('a.demo', 0));
            }
            if (! empty($f['coin'])) {
                $q->whereIn('p.symbol', Coins::symbols($f['coin']));
            }
            match ($f['who'] ?? 'all') {
                'master' => $q->where('u.type', 'master'),
                'customers' => $q->where('u.type', 'user'),
                'staff' => $q->whereNotIn('u.type', ['user', 'master']),
                default => null,
            };
            match ($f['closed_by'] ?? 'any') {
                'bot' => $q->where('p.close_source', 'bot'),
                'outside' => $q->where('p.close_source', 'outside'),
                default => null,
            };
            if (! empty($f['owner'])) {
                // Not escaped: a stray % or _ only widens a read-only name search.
                $like = '%'.mb_strtolower($f['owner']).'%';
                $q->where(fn ($w) => $w->whereRaw('LOWER(u.name) LIKE ?', [$like])->orWhereRaw('LOWER(a.name) LIKE ?', [$like]));
            }

            $found = $q->orderByDesc('p.closed_at')->limit(self::MAX_ROWS + 1)->get([
                'p.id', 'p.symbol', 'p.position_side', 'p.position_amt', 'p.increments_closed', 'p.entry_price',
                'p.exit_price', 'p.realized_pnl', 'p.exchange_fee', 'p.fee_source', 'p.closed_at', 'p.close_source',
                'p.strategy', 'p.streak_kind', 'p.streak_run', 'a.name as account_name', 'a.demo',
                'u.name as owner_name', 'u.type as owner_type',
            ]);
            if ($found->count() > self::MAX_ROWS) {
                throw new ToolError('Too many trades in that window — narrow the dates, the coin or the exchange.');
            }
            foreach ($found as $r) {
                $rows[] = $this->tradeRow($r, $exchange);
            }
        }
        usort($rows, fn ($a, $b) => strcmp($b['closed_at'], $a['closed_at']));

        return $rows;
    }

    private function tradeRow(object $r, string $exchange): array
    {
        $pnl = $r->realized_pnl !== null ? (float) $r->realized_pnl : null;
        $fee = $r->exchange_fee !== null ? (float) $r->exchange_fee : null;
        $side = in_array($r->position_side, ['LONG', 'SHORT'], true)
            ? $r->position_side
            : ((float) $r->position_amt < 0 ? 'SHORT' : 'LONG');

        return [
            'id' => (int) $r->id,
            'exchange' => $exchange,
            'coin' => Coins::of((string) $r->symbol),
            'side' => $side,
            'size' => abs((float) $r->position_amt),
            'entries_closed' => $r->increments_closed !== null ? (int) $r->increments_closed : null,
            'entry_price' => $r->entry_price !== null ? (float) $r->entry_price : null,
            'exit_price' => $r->exit_price !== null ? (float) $r->exit_price : null,
            // The column as stored: after fees from 2026-09-11, the exchange's
            // gross figure before (fee null). See the guide.
            'pnl_after_fees' => $pnl === null ? null : round($pnl, 2),
            'fee' => $fee === null ? null : round($fee, 2),
            'pnl_before_fees' => $pnl === null ? null : round($pnl + ($fee ?? 0.0), 2),
            'fee_basis' => $r->fee_source ?? 'none recorded',
            'closed_at' => Carbon::parse($r->closed_at, 'UTC')->toIso8601String(),
            'closed_by' => $r->close_source ?? 'unknown',
            'strategy' => $r->strategy,
            'streak' => $r->streak_kind ? ['kind' => $r->streak_kind, 'run' => $r->streak_run !== null ? (int) $r->streak_run : null] : null,
            'owner' => $r->owner_name,
            'owner_type' => self::ownerType($r->owner_type),
            'account' => $r->account_name,
            'demo' => $r->demo === null ? null : (bool) $r->demo,
        ];
    }

    /** @param iterable<array<string, mixed>> $rows */
    private function totals(iterable $rows): array
    {
        $trades = 0;
        $entries = 0;
        $wins = 0;
        $losses = 0;
        $pnl = 0.0;
        $fees = 0.0;
        $before = 0.0;
        $best = null;
        $worst = null;
        foreach ($rows as $r) {
            $trades++;
            $entries += $r['entries_closed'] ?? 1;
            if ($r['pnl_after_fees'] === null) {
                continue;
            }
            $p = $r['pnl_after_fees'];
            $p > 0 ? $wins++ : $losses++;
            $pnl += $p;
            $fees += $r['fee'] ?? 0.0;
            $before += $r['pnl_before_fees'];
            $best = $best === null ? $p : max($best, $p);
            $worst = $worst === null ? $p : min($worst, $p);
        }

        return [
            'trades' => $trades,
            'entries' => $entries,
            'wins' => $wins,
            'losses' => $losses,
            'win_rate_pct' => $wins + $losses > 0 ? round($wins / ($wins + $losses) * 100, 1) : null,
            'pnl_after_fees' => round($pnl, 2),
            'fees' => round($fees, 2),
            'pnl_before_fees' => round($before, 2),
            'best_trade' => $best === null ? null : round($best, 2),
            'worst_trade' => $worst === null ? null : round($worst, 2),
        ];
    }

    /** coin => list of {exchange, enabled, killed_reason}. @return array<string, list<array<string, mixed>>> */
    private function assetStatus(): array
    {
        $out = [];
        foreach (Asset::query()->orderBy('ticker')->get(['ticker', 'broker', 'enabled', 'killed_at', 'killed_reason']) as $a) {
            $out[Coins::of((string) $a->ticker)][] = [
                'exchange' => strtolower((string) $a->broker),
                'switched_on' => (bool) $a->enabled,
                'kill_switch_tripped_at' => $a->killed_at ? Carbon::parse($a->killed_at, 'UTC')->toIso8601String() : null,
                'kill_switch_reason' => $a->killed_reason,
            ];
        }

        return $out;
    }

    private function nameMatches(string $needle, ?string ...$names): bool
    {
        $n = mb_strtolower($needle);
        foreach ($names as $name) {
            if ($name !== null && str_contains(mb_strtolower($name), $n)) {
                return true;
            }
        }

        return false;
    }

    /** The plain word for a user_credentials.type. */
    public static function ownerType(?string $type): string
    {
        return match ($type) {
            'user' => 'customer',
            'master' => 'master',
            null => 'unknown',
            default => 'staff:'.$type,
        };
    }
}
