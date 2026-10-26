<?php

namespace App\Services\Admin;

use App\Models\TradeLog;
use App\Services\Exchanges\ExchangeSchema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The same signal from both feeds, side by side — the Signal Log's
 * "TradingView vs QuantLab" view.
 *
 * TradingView posts to the main webhooks (master + customers) and QuantLab to
 * the test webhooks (tester accounts only). When both run the same strategy
 * on a coin, every signal arrives twice; this pairs the two arrivals and says
 * which came first, by how much, and what price the master and the tester got.
 *
 * Rules:
 * - A pair is the same exchange + action + coin, the two arrivals at most
 *   MATCH_SECONDS apart. Closest arrivals pair first and each signal pairs
 *   once, so two quick signals on one coin cannot both claim the same partner.
 * - Only coins BOTH feeds sent in the window are compared. QuantLab runs a
 *   few coins and TradingView every coin; listing TradingView's BTC signals as
 *   "QuantLab missed it" would bury the real misses. Those signals are
 *   counted in `not_compared`, never listed.
 * - Arrival = `received_at` (the engine's own clock for both feeds, so the
 *   gap needs no clock agreement). Retries and rows without an arrival time
 *   never reach this class — the controller leaves them out.
 * - Prices are compared only when both sides trade real money: a testnet fill
 *   is a different market, so the summary counts it as `demo` and skips it.
 */
class FeedComparison
{
    /** The longest gap at which two arrivals still count as one signal (owner, 2026-10-26). */
    public const MATCH_SECONDS = 120;

    /** Arrivals closer than this are a tie — neither feed meaningfully "won". */
    public const TIE_MS = 100;

    /** Actions that BUY on the exchange: a lower price is the better fill. */
    private const BUYING = ['BUY', 'EXIT_SHORT'];

    /**
     * @param  Collection<int, TradeLog>  $logs  first runs of both feeds, each with a received_at
     * @return array{summary: array<string, mixed>, rows: list<array<string, mixed>>}
     */
    public function compare(Collection $logs): array
    {
        $main = $logs->filter(fn (TradeLog $l) => ($l->feed ?? 'main') === 'main')->values();
        $test = $logs->filter(fn (TradeLog $l) => $l->feed === 'test')->values();

        $coin = fn (TradeLog $l) => "{$l->exchange}|{$l->ticker}";
        $shared = array_flip(array_intersect(
            $main->map($coin)->unique()->all(),
            $test->map($coin)->unique()->all(),
        ));
        $inScope = fn (TradeLog $l) => isset($shared[$coin($l)]);
        $notCompared = [
            'main' => $main->reject($inScope)->count(),
            'test' => $test->reject($inScope)->count(),
        ];
        $main = $main->filter($inScope)->values();
        $test = $test->filter($inScope)->values();

        [$pairs, $mainOnly, $testOnly] = $this->pair($main, $test);
        $demo = $this->demoFlags($main->concat($test));

        $rows = [];
        foreach ($pairs as [$m, $t]) {
            $rows[] = $this->row($m, $t, $demo);
        }
        foreach ($mainOnly as $m) {
            $rows[] = $this->row($m, null, $demo);
        }
        foreach ($testOnly as $t) {
            $rows[] = $this->row(null, $t, $demo);
        }
        usort($rows, fn ($a, $b) => [$b['_at'], $b['_id']] <=> [$a['_at'], $a['_id']]);

        return [
            'summary' => $this->summary($rows, $notCompared),
            'rows' => array_map(fn ($r) => array_diff_key($r, ['_at' => 0, '_id' => 0]), $rows),
        ];
    }

    /**
     * Closest-first matching inside each exchange + action + coin.
     *
     * @return array{0: list<array{0: TradeLog, 1: TradeLog}>, 1: list<TradeLog>, 2: list<TradeLog>}
     */
    private function pair(Collection $main, Collection $test): array
    {
        $key = fn (TradeLog $l) => "{$l->exchange}|{$l->action}|{$l->ticker}";
        $testByKey = $test->groupBy($key);
        $window = self::MATCH_SECONDS * 1000;

        $candidates = [];
        foreach ($main as $m) {
            foreach ($testByKey[$key($m)] ?? [] as $t) {
                $gap = abs(self::ms($t) - self::ms($m));
                if ($gap <= $window) {
                    $candidates[] = [$gap, $m, $t];
                }
            }
        }
        usort($candidates, fn ($a, $b) => [$a[0], self::ms($a[1]), $a[1]->id] <=> [$b[0], self::ms($b[1]), $b[1]->id]);

        $pairs = [];
        $usedMain = [];
        $usedTest = [];
        foreach ($candidates as [, $m, $t]) {
            if (isset($usedMain[$m->id]) || isset($usedTest[$t->id])) {
                continue;
            }
            $usedMain[$m->id] = $usedTest[$t->id] = true;
            $pairs[] = [$m, $t];
        }

        return [
            $pairs,
            $main->reject(fn (TradeLog $l) => isset($usedMain[$l->id]))->values()->all(),
            $test->reject(fn (TradeLog $l) => isset($usedTest[$l->id]))->values()->all(),
        ];
    }

    /** One line of the view: a pair, or a signal only one feed sent. */
    private function row(?TradeLog $m, ?TradeLog $t, array $demo): array
    {
        $any = $m ?? $t;
        $action = (string) $any->action;
        $main = $m ? $this->side($m, 'main', $demo) : null;
        $test = $t ? $this->side($t, 'test', $demo) : null;

        // Signed: positive = QuantLab arrived later, i.e. TradingView was first.
        $gap = $m && $t ? self::ms($t) - self::ms($m) : null;
        $first = match (true) {
            $gap === null => null,
            abs($gap) < self::TIE_MS => 'tie',
            $gap > 0 => 'main',
            default => 'test',
        };
        [$diff, $better] = $this->priceVerdict($action, $main['fill_price'] ?? null, $test['fill_price'] ?? null);
        $earliest = $m && $t ? (self::ms($m) <= self::ms($t) ? $m : $t) : $any;

        return [
            'kind' => $m && $t ? 'pair' : ($m ? 'main_only' : 'test_only'),
            'exchange' => $any->exchange,
            'ticker' => $any->ticker,
            'action' => $action,
            'side' => in_array($action, ['SELL', 'EXIT_SHORT'], true) ? 'SHORT' : 'LONG',
            'type' => str_starts_with($action, 'EXIT_') ? 'exit' : 'entry',
            // The first arrival — when the signal reached us at all.
            'at' => SignalDelivery::iso($earliest->received_at),
            'gap_ms' => $gap,
            'first' => $first,
            // (QuantLab − TradingView) / TradingView, in percent.
            'price_diff_pct' => $diff,
            'better_price' => $better,
            // Both sides trade real money — only then is the price gap slippage.
            'prices_comparable' => $diff !== null && $main['demo'] === false && $test['demo'] === false,
            'main' => $main,
            'test' => $test,
            '_at' => self::ms($earliest),
            '_id' => $earliest->id,
        ];
    }

    /**
     * One feed's half of a row. The account shown is the MASTER on the main
     * feed (the strategy's own account) and the first tester that filled on
     * the test feed (testers are the only accounts it reaches).
     */
    private function side(TradeLog $log, string $feed, array $demo): array
    {
        $details = array_values(array_filter((array) $log->details, 'is_array'));
        $pick = $feed === 'main'
            ? collect($details)->first(fn ($d) => ($d['is_master'] ?? false) === true)
            : (collect($details)->first(fn ($d) => ($d['status'] ?? null) === 'filled') ?? ($details[0] ?? null));

        $outcome = match (true) {
            $log->category === 'rejected' => 'rejected',
            $pick === null => 'absent',
            default => (string) ($pick['status'] ?? 'unknown'),
        };
        $price = $outcome === 'filled' && isset($pick['fill_price']) && (float) $pick['fill_price'] > 0
            ? (float) $pick['fill_price']
            : null;

        return [
            'id' => $log->id,
            'received_at' => SignalDelivery::iso($log->received_at),
            'sent_at' => SignalDelivery::iso($log->sent_at),
            'transit_ms' => $log->transit_ms !== null ? (int) $log->transit_ms : null,
            'strategy' => $log->strategy,
            'account' => $pick['account'] ?? null,
            'outcome' => $outcome,
            'reason' => $pick['reason'] ?? null,
            'fill_price' => $price,
            // null = unknown (no account picked, or its row is gone).
            'demo' => $pick && isset($pick['account']) ? ($demo["{$log->exchange}|{$pick['account']}"] ?? null) : null,
        ];
    }

    /** @return array{0: ?float, 1: 'main'|'test'|'same'|null} */
    private function priceVerdict(string $action, ?float $main, ?float $test): array
    {
        if ($main === null || $test === null) {
            return [null, null];
        }
        $diff = round(($test - $main) / $main * 100, 4);
        if (round($main, 8) === round($test, 8)) {
            return [$diff, 'same'];
        }
        $testCheaper = $test < $main;

        return [$diff, in_array($action, self::BUYING, true) === $testCheaper ? 'test' : 'main'];
    }

    /**
     * exchange|account name → demo, for every account these logs name.
     * Names are UNIQUE per {x}_accounts table (soft-deleted rows included),
     * which is what lets a fan-out row find its account without an id.
     *
     * @return array<string, bool>
     */
    private function demoFlags(Collection $logs): array
    {
        $names = [];
        foreach ($logs as $log) {
            foreach ((array) $log->details as $d) {
                if (is_array($d) && ! empty($d['account'])) {
                    $names[$log->exchange][$d['account']] = true;
                }
            }
        }
        $out = [];
        foreach ($names as $exchange => $set) {
            if (! ExchangeSchema::isSupported((string) $exchange)) {
                continue;
            }
            DB::table(ExchangeSchema::for((string) $exchange)->accountsTable)
                ->whereIn('name', array_keys($set))
                ->get(['name', 'demo'])
                ->each(function ($r) use (&$out, $exchange) {
                    $out["{$exchange}|{$r->name}"] = (bool) $r->demo;
                });
        }

        return $out;
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function summary(array $rows, array $notCompared): array
    {
        $pairs = array_values(array_filter($rows, fn ($r) => $r['kind'] === 'pair'));
        $gaps = array_map(fn ($r) => $r['gap_ms'], $pairs);
        sort($gaps);
        $worst = null;
        foreach ($pairs as $p) {
            if ($worst === null || abs($p['gap_ms']) > abs($worst['gap_ms'])) {
                $worst = $p;
            }
        }
        $priced = array_filter($pairs, fn ($r) => $r['better_price'] !== null);
        $comparable = array_filter($priced, fn ($r) => $r['prices_comparable']);

        return [
            'pairs' => count($pairs),
            'main_first' => count(array_filter($pairs, fn ($r) => $r['first'] === 'main')),
            'test_first' => count(array_filter($pairs, fn ($r) => $r['first'] === 'test')),
            'ties' => count(array_filter($pairs, fn ($r) => $r['first'] === 'tie')),
            // Signed like gap_ms: positive = TradingView arrives first on average.
            'avg_gap_ms' => $gaps ? (int) round(array_sum($gaps) / count($gaps)) : null,
            'median_gap_ms' => $gaps ? self::median($gaps) : null,
            'worst' => $worst ? [
                'gap_ms' => $worst['gap_ms'],
                'ticker' => $worst['ticker'],
                'action' => $worst['action'],
                'at' => $worst['at'],
            ] : null,
            'main_only' => count(array_filter($rows, fn ($r) => $r['kind'] === 'main_only')),
            'test_only' => count(array_filter($rows, fn ($r) => $r['kind'] === 'test_only')),
            'not_compared' => $notCompared,
            // How long each feed's signal was on the way (sent_at → received_at),
            // over the pairs whose provider sent a fire time.
            'avg_transit_ms' => [
                'main' => self::mean(array_map(fn ($r) => $r['main']['transit_ms'], $pairs)),
                'test' => self::mean(array_map(fn ($r) => $r['test']['transit_ms'], $pairs)),
            ],
            'prices' => [
                'compared' => count($comparable),
                'main_better' => count(array_filter($comparable, fn ($r) => $r['better_price'] === 'main')),
                'test_better' => count(array_filter($comparable, fn ($r) => $r['better_price'] === 'test')),
                'same' => count(array_filter($comparable, fn ($r) => $r['better_price'] === 'same')),
                // Both filled, but at least one side on a testnet — not compared.
                'demo' => count($priced) - count($comparable),
            ],
        ];
    }

    /** Arrival in epoch milliseconds. */
    private static function ms(TradeLog $log): int
    {
        return (int) $log->received_at->getPreciseTimestamp(3);
    }

    /** @param  list<int>  $sorted */
    private static function median(array $sorted): int
    {
        $n = count($sorted);
        $mid = intdiv($n, 2);

        return (int) round($n % 2 ? $sorted[$mid] : ($sorted[$mid - 1] + $sorted[$mid]) / 2);
    }

    /** @param  list<?int>  $values */
    private static function mean(array $values): ?int
    {
        $values = array_filter($values, fn ($v) => $v !== null);

        return $values ? (int) round(array_sum($values) / count($values)) : null;
    }
}
