<?php

namespace App\Services\Admin;

use App\Models\UserCredential;
use App\Services\Positions\TradeSide;
use App\Services\UserStatsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Admin → Customers vs master (`GET /admin/insights/vs-master`, 2026-11-23):
 * every customer's result beside the master's over the same days, ranked.
 *
 * - **Return is the same figure the Customer profit card and the P&L
 *   calendar print**: each day's P&L after fees over the capital that day
 *   started with (`UserStatsService::dailyReturns`), the days added up. Never
 *   P&L over today's balance — a withdrawal would rewrite the past.
 * - **"Gap" is percentage points**: the customer's return minus the master's.
 *   Dollars cannot be compared (sizes scale with balance); returns can.
 * - **Followed / missed** pairs each master close with the customer's close
 *   of the same coin + side on the same exchange within 15 minutes, one to
 *   one. A master close only counts against a customer who had an account on
 *   that exchange at the time — nobody "missed" a trade made before they
 *   connected. A customer close with no master partner is "extra" (closed by
 *   hand, their own stop, or a trade the master never took).
 * - **Why the gap** (`gap_parts`): each close's share of its owner's return
 *   is its P&L after fees over the capital its day started with — the same
 *   division as the day's %, so the parts add up to the gap (to the cent of
 *   a percent the daily rounding leaves). same = the customer's share minus
 *   the master's on the trades both took; missed = minus the master's share
 *   on trades the customer could have taken and did not; extra = the
 *   customer's own unmatched closes; before = minus the master's share on
 *   trades made while the customer had no account there.
 * - Days are UTC days, like every per-user P&L screen. Customers only
 *   (`type = 'user'`), real money only, disconnected accounts included —
 *   their history is still theirs.
 */
class MasterComparison
{
    /** A customer's close this close to the master's is the same signal. */
    public const MATCH_SECONDS = 900;

    private const TRADE_COLUMNS = [...UserStatsService::DAY_TRADE_COLUMNS, 'is_sandbox'];

    public function __construct(
        private AdminInsights $insights,
        private UserStatsService $stats,
    ) {}

    /** @return array<string, mixed> */
    public function compare(?string $exchange, string $from, string $to): array
    {
        $masterAccounts = $this->scoped($this->insights->masterAccounts(), $exchange);
        $customerAccounts = $this->scoped($this->insights->customerAccounts(), $exchange);

        $master = $masterAccounts->isEmpty() ? null : $this->measure($masterAccounts, $from, $to);
        $masterCloses = $master ? $master['closes'] : collect();

        $names = UserCredential::whereIn('uni_id', $customerAccounts->pluck('uni_id')->unique()->values())
            ->pluck('name', 'uni_id');

        $rows = [];
        foreach ($customerAccounts->groupBy('uni_id') as $uni => $accounts) {
            $accounts = $accounts->values();
            $m = $this->measure($accounts, $from, $to);
            [$eligible, $before] = $masterCloses->partition(
                fn ($c) => $this->hadAccount($accounts, $c['exchange'], $c['at'])
            );
            $eligible = $eligible->values();
            $pairs = self::pair($eligible, $m['closes']);

            // Live (not disconnected) accounts only: a closed account's
            // balance is the last one we saw, not money still at work.
            $live = $accounts->whereNull('deleted_at');
            if ($m['summary']['trades'] === 0 && $eligible->isEmpty() && $live->isEmpty()) {
                continue;
            }

            $days = $this->dayDuel($master['days'] ?? [], $m['days'], $accounts, $from, $to);
            $pct = $m['summary']['pct'];
            $masterPct = $master['summary']['pct'] ?? null;
            $matched = count($pairs);

            $rows[] = [
                'uni_id' => $uni,
                'name' => $names[$uni] ?? $uni,
                'exchanges' => $accounts->pluck('exchange')->unique()->values()->all(),
                'connected' => $live->isNotEmpty(),
                'balance' => $live->isNotEmpty() ? round((float) $live->sum('balance'), 2) : null,
                'gap' => $pct !== null && $masterPct !== null ? round($pct - $masterPct, 2) : null,
                'gap_parts' => $pct !== null && $masterPct !== null
                    ? self::gapParts($eligible, $before->values(), $m['closes'], $pairs)
                    : null,
                'followed' => $matched,
                'missed' => $eligible->count() - $matched,
                'master_closes' => $eligible->count(),
                'extra' => $m['closes']->count() - $matched,
                'days_ahead' => $days['ahead'],
                'days_behind' => $days['behind'],
            ] + $m['summary'];
        }

        usort($rows, fn ($a, $b) => [$b['pct'] !== null, $b['pct'] ?? 0, $b['pnl']]
            <=> [$a['pct'] !== null, $a['pct'] ?? 0, $a['pnl']]);
        foreach ($rows as $i => &$row) {
            $row['rank'] = $i + 1;
        }
        unset($row);

        return [
            'from' => $from,
            'to' => $to,
            'exchange' => $exchange,
            'master' => $master ? $master['summary'] + [
                'balance' => round((float) $masterAccounts->whereNull('deleted_at')->sum('balance'), 2),
            ] : null,
            'customers' => $rows,
        ];
    }

    /**
     * One customer against the master, day by day over the window: each
     * side's daily % (the calendar's), for the page's head-to-head chart.
     *
     * @return array{uni_id: string, name: string, from: string, to: string, master: list<array>, customer: list<array>}|null
     */
    public function headToHead(string $uniId, ?string $exchange, string $from, string $to): ?array
    {
        $accounts = $this->scoped($this->insights->customerAccounts(), $exchange)->where('uni_id', $uniId)->values();
        if ($accounts->isEmpty()) {
            return null;
        }
        $masterAccounts = $this->scoped($this->insights->masterAccounts(), $exchange);
        $series = function (array $days) use ($from, $to): array {
            $out = [];
            foreach ($days as $date => $d) {
                if ($date >= $from && $date <= $to) {
                    $out[] = ['date' => $date, 'pct' => $d['pct'], 'pnl' => $d['pnl'], 'trades' => $d['trades']];
                }
            }

            return $out;
        };

        return [
            'uni_id' => $uniId,
            'name' => UserCredential::where('uni_id', $uniId)->value('name') ?? $uniId,
            'from' => $from,
            'to' => $to,
            'master' => $masterAccounts->isEmpty() ? [] : $series($this->measure($masterAccounts, $from, $to)['days']),
            'customer' => $series($this->measure($accounts, $from, $to)['days']),
        ];
    }

    private function scoped(Collection $accounts, ?string $exchange): Collection
    {
        return ($exchange ? $accounts->where('exchange', $exchange) : $accounts)->values();
    }

    /**
     * One owner's window: the summary, its daily returns, and its closes
     * reduced to what pairing and the gap split need.
     *
     * @return array{summary: array<string, mixed>, days: array<string, array>, closes: Collection}
     */
    private function measure(Collection $accounts, string $from, string $to): array
    {
        $trades = UserStatsService::withFeeBasis(
            $this->stats->pastPositions($accounts, self::TRADE_COLUMNS)
                ->filter(fn ($t) => (int) $t->is_sandbox === 0)
                ->values()
        );
        $flows = UserStatsService::flowsByDay($this->stats->transactions($accounts, ['type', 'amount', 'created_at']));
        $days = UserStatsService::dailyReturns($trades, $flows, $accounts);

        $inWindow = $trades->filter(function ($t) use ($from, $to) {
            $d = substr((string) $t->closed_at, 0, 10);

            return $d >= $from && $d <= $to;
        })->values();

        $pnl = $gross = $pct = 0.0;
        $pctKnown = true;
        $best = $worst = null;
        foreach ($days as $date => $d) {
            if ($date < $from || $date > $to) {
                continue;
            }
            $pnl += $d['pnl'];
            $gross += $d['pnl_gross'];
            if ($d['pct'] === null) {
                $pctKnown = false;
            } else {
                $pct += $d['pct'];
                $best = $best === null ? $d['pct'] : max($best, $d['pct']);
                $worst = $worst === null ? $d['pct'] : min($worst, $d['pct']);
            }
        }
        $n = $inWindow->count();
        $wins = $inWindow->filter(fn ($t) => (float) ($t->pnl_net ?? 0) > 0)->count();

        return [
            'summary' => [
                'pnl' => round($pnl, 2),
                'pnl_gross' => round($gross, 2),
                'fees' => round($gross - $pnl, 2),
                // Null when a day in the window had no capital on record.
                'pct' => $n === 0 ? 0.0 : ($pctKnown ? round($pct, 2) : null),
                'trades' => $n,
                'wins' => $wins,
                'win_rate' => $n ? round($wins / $n * 100, 1) : null,
                'best_day' => $best,
                'worst_day' => $worst,
            ],
            'days' => $days,
            'closes' => $inWindow->map(function ($t) use ($days) {
                $base = $days[substr((string) $t->closed_at, 0, 10)]['start_balance'] ?? null;

                return [
                    'exchange' => $t->exchange,
                    'coin' => preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $t->symbol)),
                    'side' => TradeSide::closed($t->position_side ?? null, $t->side ?? null),
                    'at' => Carbon::parse($t->closed_at, 'UTC')->getTimestamp(),
                    // This close's share of its owner's return, in % points.
                    'share' => $base !== null && $base > 0 ? (float) ($t->pnl_net ?? 0) / $base * 100 : 0.0,
                ];
            })->values(),
        ];
    }

    /** True when the owner had an account on that exchange at that moment. */
    private function hadAccount(Collection $accounts, string $exchange, int $at): bool
    {
        return $accounts->contains(function ($a) use ($exchange, $at) {
            if ($a->exchange !== $exchange) {
                return false;
            }
            $opened = $a->created_at ? Carbon::parse($a->created_at, 'UTC')->getTimestamp() : 0;
            $closed = $a->deleted_at ? Carbon::parse($a->deleted_at, 'UTC')->getTimestamp() : PHP_INT_MAX;

            return $at >= $opened && $at <= $closed;
        });
    }

    /**
     * Pairs master closes with the customer's, one to one: same exchange,
     * coin and side, nearest in time within MATCH_SECONDS.
     *
     * @return list<array{0: int, 1: int}> [master index, customer index] pairs
     */
    public static function pair(Collection $master, Collection $customer): array
    {
        $pool = $customer->values()->all();
        $used = [];
        $pairs = [];
        foreach ($master->values()->sortBy('at') as $mKey => $m) {
            $bestKey = null;
            $bestGap = self::MATCH_SECONDS + 1;
            foreach ($pool as $k => $c) {
                if (isset($used[$k]) || $c['exchange'] !== $m['exchange'] || $c['coin'] !== $m['coin'] || $c['side'] !== $m['side']) {
                    continue;
                }
                $gap = abs($c['at'] - $m['at']);
                if ($gap < $bestGap) {
                    $bestGap = $gap;
                    $bestKey = $k;
                }
            }
            if ($bestKey !== null) {
                $used[$bestKey] = true;
                $pairs[] = [$mKey, $bestKey];
            }
        }

        return $pairs;
    }

    /**
     * Where a customer's gap to the master comes from, in % points (see the
     * class note). same + missed + extra + before = the gap, give or take the
     * daily rounding.
     *
     * @param  list<array{0: int, 1: int}>  $pairs
     * @return array{same: float, missed: float, extra: float, before: float}
     */
    public static function gapParts(Collection $eligible, Collection $before, Collection $customer, array $pairs): array
    {
        $eligible = $eligible->values();
        $customer = $customer->values();
        $matchedM = array_flip(array_column($pairs, 0));
        $matchedC = array_flip(array_column($pairs, 1));

        $same = 0.0;
        foreach ($pairs as [$m, $c]) {
            $same += $customer[$c]['share'] - $eligible[$m]['share'];
        }
        $missed = -$eligible->filter(fn ($_, $k) => ! isset($matchedM[$k]))->sum('share');
        $extra = $customer->filter(fn ($_, $k) => ! isset($matchedC[$k]))->sum('share');

        return [
            'same' => round($same, 2),
            'missed' => round($missed, 2),
            'extra' => round($extra, 2),
            'before' => round(-$before->sum('share'), 2),
        ];
    }

    /**
     * On each day the master traded (and the customer had an account), did
     * the customer do at least as well? A day they took no trade counts as 0%.
     *
     * @return array{ahead: int, behind: int}
     */
    private function dayDuel(array $masterDays, array $customerDays, Collection $accounts, string $from, string $to): array
    {
        $ahead = $behind = 0;
        foreach ($masterDays as $date => $m) {
            if ($date < $from || $date > $to || $m['pct'] === null) {
                continue;
            }
            $endOfDay = Carbon::parse($date.' 23:59:59', 'UTC')->getTimestamp();
            $startOfDay = Carbon::parse($date.' 00:00:00', 'UTC')->getTimestamp();
            $live = $accounts->contains(function ($a) use ($startOfDay, $endOfDay) {
                $opened = $a->created_at ? Carbon::parse($a->created_at, 'UTC')->getTimestamp() : 0;
                $closed = $a->deleted_at ? Carbon::parse($a->deleted_at, 'UTC')->getTimestamp() : PHP_INT_MAX;

                return $opened <= $endOfDay && $closed >= $startOfDay;
            });
            if (! $live) {
                continue;
            }
            $c = $customerDays[$date]['pct'] ?? 0.0;
            if ($c === null) {
                continue;
            }
            $c >= $m['pct'] ? $ahead++ : $behind++;
        }

        return ['ahead' => $ahead, 'behind' => $behind];
    }
}
