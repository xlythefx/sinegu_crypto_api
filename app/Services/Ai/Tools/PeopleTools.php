<?php

namespace App\Services\Ai\Tools;

use App\Services\Exchanges\ExchangeSchema;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * People and their exchange accounts. Selects named columns only — never an
 * account's api_key / secret_key, never a user's email, password or codes.
 * (api_key is read for joining and dropped before the row leaves.)
 */
final class PeopleTools implements Toolset
{
    public function definitions(): array
    {
        return [
            'accounts' => [
                'title' => 'Exchange accounts',
                'description' => 'Every connected exchange account with its owner: balance, unrealized P&L, money deposited '
                    .'(net of withdrawals), whether the bot trades it (enabled) or it is paused, whether its API key works, '
                    .'demo or real money, when it connected, and its P&L (all time and last 30 days). Use it for "how much '
                    .'does Juan have", "whose keys are broken", "how many accounts are paused".',
                'schema' => Props::object([
                    'exchange' => Props::exchange(),
                    'owner' => Props::owner(),
                    'who' => Props::who('all'),
                    'status' => ['type' => 'string', 'enum' => ['connected', 'disconnected', 'all'], 'description' => 'Default: connected.'],
                    'include_demo' => ['type' => 'boolean', 'description' => 'Include demo (play-money) accounts. Default: true (they are flagged demo).'],
                ]),
            ],
            'users' => [
                'title' => 'Users',
                'description' => 'People registered on Pixel Alpha: name, role (customer, master, staff), status (pending '
                    .'approval, active, suspended), email verified, when they joined, last active, their fee rates, how many '
                    .'exchange accounts they have connected and how many invoices they owe. Use it for "how many sign-ups '
                    .'this week", "who is waiting for approval".',
                'schema' => Props::object([
                    'search' => Props::owner(),
                    'status' => ['type' => 'string', 'enum' => ['pending', 'active', 'suspended', 'all'], 'description' => 'Default: all.'],
                    'who' => Props::who('all'),
                    'joined_from' => ['type' => 'string', 'description' => 'Joined on or after, YYYY-MM-DD.'],
                    'joined_to' => ['type' => 'string', 'description' => 'Joined on or before, YYYY-MM-DD.'],
                    'limit' => Props::limit(100, 500),
                ]),
            ],
        ];
    }

    public function call(string $tool, array $arguments): array
    {
        $args = new Args($arguments);

        return match ($tool) {
            'accounts' => $this->accounts($args),
            'users' => $this->users($args),
        };
    }

    private function accounts(Args $args): array
    {
        $exchange = $args->enum('exchange', ['binance', 'bybit', 'mexc']);
        $owner = $args->str('owner');
        $who = $args->enum('who', ['all', 'master', 'customers', 'staff'], 'all');
        $status = $args->enum('status', ['connected', 'disconnected', 'all'], 'connected');
        $includeDemo = $args->bool('include_demo', true);
        $since30 = Carbon::now('UTC')->subDays(30)->toDateTimeString();

        $rows = [];
        foreach ($exchange ? [$exchange] : ExchangeSchema::supported() as $ex) {
            $s = ExchangeSchema::for($ex);
            $q = DB::table("{$s->accountsTable} as a")
                ->leftJoin('user_credentials as u', 'u.uni_id', '=', 'a.uni_id')
                ->where('a.is_sandbox', 0)
                ->where('a.api_key', 'not like', 'SBXINV-%');
            match ($status) {
                'connected' => $q->whereNull('a.deleted_at'),
                'disconnected' => $q->whereNotNull('a.deleted_at'),
                default => null,
            };
            if (! $includeDemo) {
                $q->where('a.demo', 0);
            }
            match ($who) {
                'master' => $q->where('u.type', 'master'),
                'customers' => $q->where('u.type', 'user'),
                'staff' => $q->whereNotIn('u.type', ['user', 'master']),
                default => null,
            };
            if ($owner !== null) {
                $like = '%'.mb_strtolower($owner).'%';
                $q->where(fn ($w) => $w->whereRaw('LOWER(u.name) LIKE ?', [$like])->orWhereRaw('LOWER(a.name) LIKE ?', [$like]));
            }
            $accounts = $q->get([
                'a.id', 'a.api_key', 'a.name', 'a.balance', 'a.unrealized_pnl', 'a.initial_deposit', 'a.demo', 'a.enabled',
                'a.key_status', 'a.key_error_reason', 'a.key_error_message', 'a.key_blocked_at', 'a.created_at', 'a.deleted_at',
                'u.name as owner_name', 'u.type as owner_type', 'u.status as owner_status',
            ]);
            if ($accounts->isEmpty()) {
                continue;
            }
            $keys = $accounts->pluck('api_key')->all();

            $flows = DB::table($s->transactions)->whereIn('api_key', $keys)
                ->groupBy('api_key')
                ->select('api_key')
                ->selectRaw("COALESCE(SUM(CASE WHEN type = 'DEPOSIT' THEN amount ELSE 0 END), 0) AS deposits")
                ->selectRaw("COALESCE(SUM(CASE WHEN type = 'WITHDRAWAL' THEN amount ELSE 0 END), 0) AS withdrawals")
                ->get()->keyBy('api_key');
            $pnl = DB::table($s->pastPositions)->whereIn('api_key', $keys)->where('is_sandbox', 0)
                ->groupBy('api_key')
                ->select('api_key')
                ->selectRaw('COUNT(*) AS trades')
                ->selectRaw('COALESCE(SUM(realized_pnl), 0) AS pnl')
                ->selectRaw('COALESCE(SUM(CASE WHEN closed_at >= ? THEN realized_pnl ELSE 0 END), 0) AS pnl_30d', [$since30])
                ->selectRaw('MAX(closed_at) AS last_close')
                ->get()->keyBy('api_key');

            foreach ($accounts as $a) {
                $f = $flows->get($a->api_key);
                $p = $pnl->get($a->api_key);
                $deposits = (float) ($f->deposits ?? 0);
                $withdrawals = (float) ($f->withdrawals ?? 0);
                $initial = $a->initial_deposit !== null ? (float) $a->initial_deposit : null;
                $rows[] = [
                    'exchange' => $ex,
                    'account_id' => (int) $a->id,
                    'account' => $a->name,
                    'owner' => $a->owner_name,
                    'owner_type' => TradeTools::ownerType($a->owner_type),
                    'owner_status' => $a->owner_status,
                    'demo' => (bool) $a->demo,
                    'bot_trades_it' => (bool) $a->enabled && $a->deleted_at === null,
                    'enabled' => (bool) $a->enabled,
                    'connected' => $a->deleted_at === null,
                    'balance' => $a->balance !== null ? round((float) $a->balance, 2) : null,
                    'unrealized_pnl' => $a->unrealized_pnl !== null ? round((float) $a->unrealized_pnl, 2) : null,
                    'opening_balance' => $initial === null ? null : round($initial, 2),
                    'deposits' => round($deposits, 2),
                    'withdrawals' => round($withdrawals, 2),
                    // The deposit gate's figure: opening balance + deposits − withdrawals.
                    'total_deposited' => $initial === null ? null : round($initial + $deposits - $withdrawals, 2),
                    'key_ok' => $a->key_status !== 'blocked',
                    'key_problem' => $a->key_status === 'blocked' ? ($a->key_error_message ?: $a->key_error_reason) : null,
                    'key_blocked_since' => $a->key_blocked_at ? Carbon::parse($a->key_blocked_at, 'UTC')->toIso8601String() : null,
                    'trades_all_time' => (int) ($p->trades ?? 0),
                    'pnl_all_time' => round((float) ($p->pnl ?? 0), 2),
                    'pnl_last_30_days' => round((float) ($p->pnl_30d ?? 0), 2),
                    'last_trade_closed_at' => ! empty($p->last_close) ? Carbon::parse($p->last_close, 'UTC')->toIso8601String() : null,
                    'connected_at' => $a->created_at ? Carbon::parse($a->created_at, 'UTC')->toIso8601String() : null,
                    'disconnected_at' => $a->deleted_at ? Carbon::parse($a->deleted_at, 'UTC')->toIso8601String() : null,
                ];
            }
        }
        usort($rows, fn ($a, $b) => [$a['owner'] ?? '', $a['exchange']] <=> [$b['owner'] ?? '', $b['exchange']]);

        $real = array_filter($rows, fn ($r) => ! $r['demo'] && $r['connected']);

        return [
            'count' => count($rows),
            'real_money_connected' => count($real),
            'real_money_balance_total' => round(array_sum(array_map(fn ($r) => (float) ($r['balance'] ?? 0), $real)), 2),
            'paused_or_disabled' => count(array_filter($rows, fn ($r) => $r['connected'] && ! $r['enabled'])),
            'broken_keys' => count(array_filter($rows, fn ($r) => $r['connected'] && ! $r['key_ok'])),
            'accounts' => $rows,
        ];
    }

    private function users(Args $args): array
    {
        $status = $args->enum('status', ['pending', 'active', 'suspended', 'all'], 'all');
        $who = $args->enum('who', ['all', 'master', 'customers', 'staff'], 'all');
        $search = $args->str('search');
        $from = $args->date('joined_from');
        $to = $args->date('joined_to');
        $limit = $args->int('limit', 100, 1, 500);

        $q = DB::table('user_credentials')->where(fn ($w) => $w->whereNull('is_sandbox')->orWhere('is_sandbox', 0));
        if ($status !== 'all') {
            $q->where('status', $status);
        }
        match ($who) {
            'master' => $q->where('type', 'master'),
            'customers' => $q->where('type', 'user'),
            'staff' => $q->whereNotIn('type', ['user', 'master']),
            default => null,
        };
        if ($search !== null) {
            $q->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%']);
        }
        if ($from) {
            $q->where('created_at', '>=', $from.' 00:00:00');
        }
        if ($to) {
            $q->where('created_at', '<=', $to.' 23:59:59');
        }

        $total = (clone $q)->count();
        $byStatus = (clone $q)->groupBy('status')->select('status')->selectRaw('COUNT(*) AS n')->pluck('n', 'status');
        $users = $q->orderByDesc('created_at')->limit($limit)->get([
            'uni_id', 'name', 'type', 'status', 'email_verified', 'created_at', 'last_activity',
            'realized_percentage', 'unrealized_percentage',
        ]);
        $ids = $users->pluck('uni_id')->all();

        $connected = [];
        foreach (ExchangeSchema::supported() as $ex) {
            $s = ExchangeSchema::for($ex);
            DB::table($s->accountsTable)->whereIn('uni_id', $ids)->whereNull('deleted_at')->where('is_sandbox', 0)
                ->get(['uni_id', 'demo'])
                ->each(function ($a) use (&$connected, $ex) {
                    $connected[$a->uni_id][] = $ex.($a->demo ? ' (demo)' : '');
                });
        }
        $owing = DB::table('invoices')->whereIn('user_id', $ids)->where('status', '!=', 'paid')->where('total_fee', '>', 0)
            ->where(fn ($w) => $w->whereNull('api_key')->orWhere('api_key', 'not like', 'SBXINV-%'))
            ->groupBy('user_id')->select('user_id')->selectRaw('COUNT(*) AS n')->pluck('n', 'user_id');

        return [
            'total' => $total,
            'by_status' => $byStatus,
            'returned' => $users->count(),
            'users' => $users->map(fn ($u) => [
                'user_id' => $u->uni_id,
                'name' => $u->name,
                'role' => TradeTools::ownerType($u->type),
                'status' => $u->status,
                'email_verified' => (bool) $u->email_verified,
                'joined' => $u->created_at ? Carbon::parse($u->created_at, 'UTC')->toIso8601String() : null,
                'last_active' => $u->last_activity ? Carbon::parse($u->last_activity, 'UTC')->toIso8601String() : null,
                'fee_on_realized_profit_pct' => $u->realized_percentage !== null ? (float) $u->realized_percentage : null,
                'fee_on_unrealized_profit_pct' => $u->unrealized_percentage !== null ? (float) $u->unrealized_percentage : null,
                'exchanges_connected' => $connected[$u->uni_id] ?? [],
                'unpaid_invoices' => (int) ($owing[$u->uni_id] ?? 0),
            ])->values(),
        ];
    }
}
