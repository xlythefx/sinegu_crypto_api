<?php

namespace App\Services\Admin;

use App\Models\Invoice;
use App\Services\Billing\PaymentMethod;
use App\Services\Exchanges\ExchangeSchema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What the boss asks about a customer, answered in one place (2026-11-11,
 * the admin audit): is the bot trading for them — and if not, WHY — do they
 * owe us, how did they last pay, and what have they made this month.
 *
 * Read by User Management (every row) and the customer's profile (each
 * account card), so the two can never disagree. DB reads only, batched: a
 * handful of queries for the whole list, never one per user, never an
 * exchange call.
 *
 * The trading verdict mirrors the engine's own gates in order — the engine
 * reaches an account only when it is enabled, not sandbox and its owner is
 * not suspended (EngineController::accounts), then skips ENTRIES while the
 * key is blocked or the deposit is below the minimum (webhook._deposit_gate).
 * "Paused · unpaid invoice" is a switched-off account with an unpaid invoice
 * past its due date, which is exactly what OverdueEnforcer switches off.
 */
class CustomerStatus
{
    /** Why an account is not trading, most actionable first (the user's row shows the first). */
    private const ORDER = ['trading', 'paused_unpaid', 'key_blocked', 'below_min', 'deposit_unknown', 'off'];

    /**
     * @param  list<string>  $uniIds
     * @return array<string, array{
     *   trading: array{state: string, label: string, exchange: ?string, invoice_id: ?int, since: ?string},
     *   key_problem: bool,
     *   owes: array{amount: float, count: int, overdue: bool},
     *   last_payment: ?array{invoice_id: int, amount: float, paid_at: ?string, kind: string, label: string},
     *   pnl_month: float,
     *   accounts: array<string, array{state: string, label: string, invoice_id: ?int, since: ?string}>
     * }>
     */
    public function forUsers(array $uniIds): array
    {
        $uniIds = array_values(array_unique(array_filter($uniIds)));
        if ($uniIds === []) {
            return [];
        }

        $owners = DB::table('user_credentials')->whereIn('uni_id', $uniIds)
            ->get(['uni_id', 'status', 'type'])->keyBy('uni_id');
        $accounts = $this->accounts($uniIds);
        $overdue = $this->overdueByAccount($uniIds);
        $invoices = $this->invoices($uniIds);
        $pnl = $this->pnlThisMonth($uniIds);
        $min = (float) config('services.engine.min_deposit', 1000);

        $out = [];
        foreach ($uniIds as $uniId) {
            $owner = $owners->get($uniId);
            $mine = $accounts->get($uniId, collect());

            $verdicts = [];
            foreach ($mine as $a) {
                if ($a->deleted_at !== null) {
                    continue;
                }
                $verdicts[$a->exchange.':'.$a->id] = $this->accountVerdict($a, $owner, $overdue, $min) + ['exchange' => $a->exchange, 'real' => ! $a->demo && ! $a->is_sandbox];
            }

            $real = array_filter($verdicts, fn ($v) => $v['real']);
            $out[$uniId] = [
                'trading' => $this->userVerdict($owner, $mine, $real, $verdicts),
                'key_problem' => (bool) array_filter($real, fn ($v) => $v['state'] === 'key_blocked'),
                'owes' => $invoices['owes'][$uniId] ?? ['amount' => 0.0, 'count' => 0, 'overdue' => false],
                'last_payment' => $invoices['last'][$uniId] ?? null,
                'pnl_month' => round($pnl[$uniId] ?? 0.0, 2),
                'accounts' => array_map(fn ($v) => array_intersect_key($v, array_flip(['state', 'label', 'invoice_id', 'since'])), $verdicts),
            ];
        }

        return $out;
    }

    /** One customer — the profile's account cards. */
    public function forUser(string $uniId): array
    {
        return $this->forUsers([$uniId])[$uniId] ?? [];
    }

    private function accountVerdict(object $a, ?object $owner, array $overdue, float $min): array
    {
        $v = fn (string $state, string $label, ?int $invoice = null, ?string $since = null) => [
            'state' => $state, 'label' => $label, 'invoice_id' => $invoice, 'since' => $since,
        ];

        if ($a->demo) {
            return $v('demo', 'Demo account');
        }
        if ($a->is_sandbox) {
            return $v('off', 'Sandbox account');
        }
        if (($owner->status ?? null) === 'suspended') {
            return $v('suspended', 'Customer suspended');
        }
        if (! $a->enabled) {
            $invoice = $overdue[$a->exchange.':'.$a->id] ?? null;

            return $invoice
                ? $v('paused_unpaid', "Paused · invoice #{$invoice} unpaid", $invoice)
                : $v('off', 'Switched off');
        }
        if (($a->key_status ?? null) === 'blocked') {
            return $v('key_blocked', 'Key blocked', null, $a->key_blocked_at ? Carbon::parse($a->key_blocked_at)->toIso8601String() : null);
        }
        if ($a->initial_deposit === null) {
            return $v('deposit_unknown', 'Waiting for the first balance read');
        }
        if ($min > 0 && (float) $a->total_deposit < $min) {
            return $v('below_min', 'Below the $'.number_format($min).' minimum deposit');
        }

        return $v('trading', 'Trading');
    }

    private function userVerdict(?object $owner, Collection $mine, array $real, array $all): array
    {
        $out = fn (string $state, string $label, ?array $from = null) => [
            'state' => $state,
            'label' => $label,
            'exchange' => $from['exchange'] ?? null,
            'invoice_id' => $from['invoice_id'] ?? null,
            'since' => $from['since'] ?? null,
        ];

        if (($owner->status ?? null) === 'suspended') {
            return $out('suspended', 'Suspended');
        }
        if ($real === []) {
            if ($all !== []) {
                return $out('demo_only', 'Demo only');
            }

            return $mine->isNotEmpty() ? $out('disconnected', 'Disconnected') : $out('no_exchange', 'No exchange');
        }
        foreach (self::ORDER as $state) {
            foreach ($real as $v) {
                if ($v['state'] === $state) {
                    return $out($state, $state === 'paused_unpaid' ? 'Paused · unpaid invoice' : $v['label'], $v);
                }
            }
        }

        return $out('off', 'Switched off');
    }

    /** Every account of these users on every venue (disconnected too), with its funded total. */
    private function accounts(array $uniIds): Collection
    {
        $rows = collect();
        foreach (ExchangeSchema::supported() as $exchange) {
            $schema = ExchangeSchema::for($exchange);
            $accounts = DB::table($schema->accountsTable)
                ->whereIn('uni_id', $uniIds)
                ->get(['id', 'uni_id', 'api_key', 'demo', 'enabled', 'is_sandbox', 'deleted_at', 'initial_deposit', 'key_status', 'key_blocked_at']);
            if ($accounts->isEmpty()) {
                continue;
            }
            // Same figure the engine's deposit gate reads: initial + net transfers.
            $net = DB::table($schema->transactions)
                ->whereIn('api_key', $accounts->pluck('api_key')->all())
                ->groupBy('api_key')
                ->selectRaw("api_key, COALESCE(SUM(CASE WHEN type = 'DEPOSIT' THEN amount WHEN type = 'WITHDRAWAL' THEN -amount ELSE 0 END), 0) AS net")
                ->pluck('net', 'api_key');
            foreach ($accounts as $a) {
                $a->exchange = $exchange;
                $a->demo = (bool) $a->demo;
                $a->enabled = (bool) $a->enabled;
                $a->is_sandbox = (bool) $a->is_sandbox;
                $a->total_deposit = $a->initial_deposit === null ? null : (float) $a->initial_deposit + (float) ($net[$a->api_key] ?? 0);
                $rows->push($a);
            }
        }

        return $rows->groupBy('uni_id');
    }

    /** "exchange:account_id" → the oldest unpaid-past-due invoice id on it. */
    private function overdueByAccount(array $uniIds): array
    {
        $out = [];
        Invoice::query()->whereIn('user_id', $uniIds)->unpaidPastDue()
            ->orderBy('due_date')->orderBy('id')
            ->get(['id', 'exchange', 'account_id'])
            ->each(function ($i) use (&$out) {
                $out[($i->exchange ?: 'binance').':'.$i->account_id] ??= (int) $i->id;
            });

        return $out;
    }

    /** What each customer owes, and the last invoice they paid (fee > 0) and how. */
    private function invoices(array $uniIds): array
    {
        $rows = Invoice::query()->whereIn('user_id', $uniIds)
            ->where('total_fee', '>', 0)
            ->where(fn ($q) => $q->whereNull('api_key')->orWhere('api_key', 'not like', 'SBXINV-%'))
            ->get(['id', 'user_id', 'status', 'total_fee', 'due_date', 'paid_at', 'payment_provider', 'payment_reference', 'manual_method']);

        $owes = [];
        $last = [];
        $today = Carbon::today();
        foreach ($rows as $i) {
            $fee = $i->feeCents() / 100;
            if ($i->status !== 'paid') {
                $o = $owes[$i->user_id] ?? ['amount' => 0.0, 'count' => 0, 'overdue' => false];
                $o['amount'] = round($o['amount'] + $fee, 2);
                $o['count']++;
                $o['overdue'] = $o['overdue'] || in_array($i->status, ['overdue', 'failed'], true)
                    || ($i->due_date && $i->due_date->lt($today));
                $owes[$i->user_id] = $o;

                continue;
            }
            $prev = $last[$i->user_id] ?? null;
            if ($prev === null || (string) $i->paid_at > (string) $prev['paid_at_raw']) {
                $last[$i->user_id] = [
                    'invoice_id' => (int) $i->id,
                    'amount' => $fee,
                    'paid_at' => $i->paid_at?->toIso8601String(),
                    'paid_at_raw' => (string) $i->paid_at,
                    'kind' => self::kind($i->payment_provider),
                    'label' => self::shortLabel($i->payment_provider, $i->manual_method),
                ];
            }
        }

        return [
            'owes' => $owes,
            'last' => array_map(fn ($p) => array_diff_key($p, ['paid_at_raw' => 1]), $last),
        ];
    }

    /** card | crypto | manual | unknown — the badge, no DB lookup (the list has many rows). */
    public static function kind(?string $provider): string
    {
        return match ($provider) {
            'stripe' => 'card',
            'tron', 'coinsbuy' => 'crypto',
            'manual' => 'manual',
            default => 'unknown',
        };
    }

    private static function shortLabel(?string $provider, ?string $manualMethod): string
    {
        return match ($provider) {
            'stripe' => 'Stripe',
            'tron' => 'USDT',
            'coinsbuy' => 'Crypto',
            'manual' => $manualMethod ? PaymentMethod::manualLabel($manualMethod) : 'By hand',
            default => 'Not recorded',
        };
    }

    /**
     * Closed P&L after exchange fees this calendar month (UTC), real money
     * only: a demo or sandbox account never counts, nor a scenario row. Keyed
     * by the ACCOUNT's owner, disconnected accounts included (they traded).
     */
    private function pnlThisMonth(array $uniIds): array
    {
        $start = Carbon::now('UTC')->startOfMonth();
        $out = [];
        foreach (ExchangeSchema::supported() as $exchange) {
            $schema = ExchangeSchema::for($exchange);
            DB::table("{$schema->pastPositions} as p")
                ->join("{$schema->accountsTable} as a", 'a.api_key', '=', 'p.api_key')
                ->whereIn('a.uni_id', $uniIds)
                ->where('a.demo', 0)
                ->where('a.is_sandbox', 0)
                ->where('p.is_sandbox', 0)
                ->where('p.api_key', 'not like', 'SBXINV-%')
                ->where('p.closed_at', '>=', $start)
                ->groupBy('a.uni_id')
                ->selectRaw('a.uni_id, COALESCE(SUM(p.realized_pnl), 0) AS pnl')
                ->pluck('pnl', 'uni_id')
                ->each(function ($v, $uniId) use (&$out) {
                    $out[$uniId] = ($out[$uniId] ?? 0.0) + (float) $v;
                });
        }

        return $out;
    }
}
