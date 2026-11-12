<?php

namespace App\Services\Admin;

use App\Models\TradeLog;
use App\Services\Exchanges\ExchangeSchema;
use App\Services\Notifications\TradeAlerts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The hourly failed-trade summary's content (2026-11-12): what went wrong on
 * the MAIN feed's signals in one window, read from `trade_logs` only — the
 * engine's own record — so the email and the Signal Log cannot disagree.
 *
 * Per signal, every account outcome that needs a person:
 * - `failed`   an order the exchange refused or never confirmed. `resolved`
 *              when a later retry of that signal filled the same account
 *              (folded like the Signal Log does: same exchange + action +
 *              ticker within RETRY_FOLD_MINUTES);
 * - `shrunk`   an entry cut down to fit the exchange's position limit;
 * - `limit`    an entry skipped because the position was already at it;
 * - `rejected` a whole signal refused for a PROBLEM reason (SignalHealth).
 * Other problem skips (blocked keys, deposits not synced) are only COUNTED:
 * they repeat on every signal and have their own screens.
 *
 * Test-feed signals are left out (testers only, like the health banner).
 */
class TradeFailureDigest
{
    public const RETRY_FOLD_MINUTES = 60;

    /**
     * @return array{
     *   from: string, to: string,
     *   signals: list<array<string, mixed>>,
     *   counts: array{failed: int, resolved: int, shrunk: int, limit: int, rejected: int, other_problems: int},
     * }
     */
    public function build(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $signals = TradeLog::query()
            ->where('feed', 'main')
            ->where(fn ($q) => $q->whereNull('category')->orWhere('category', '!=', 'retry'))
            ->where('ts', '>=', $from)->where('ts', '<', $to)
            ->orderBy('ts')->orderBy('id')
            ->get();

        $counts = ['failed' => 0, 'resolved' => 0, 'shrunk' => 0, 'limit' => 0, 'rejected' => 0, 'other_problems' => 0];
        if ($signals->isEmpty()) {
            return ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String(), 'signals' => [], 'counts' => $counts];
        }

        $retries = TradeLog::query()
            ->where('feed', 'main')->where('category', 'retry')
            ->where('ts', '>=', $from)
            ->where('ts', '<', $to->addMinutes(self::RETRY_FOLD_MINUTES))
            ->get();

        $out = [];
        $wantNames = [];
        foreach ($signals as $log) {
            $items = $this->items($log, $this->retriesOf($log, $retries), $counts);
            if ($items === []) {
                continue;
            }
            foreach ($items as $it) {
                if ($it['uni_id']) {
                    $wantNames[$it['exchange']][] = $it['uni_id'];
                }
            }
            $out[] = [
                'id' => $log->id,
                'at' => $log->ts?->toIso8601String(),
                'exchange' => $log->exchange,
                'broker' => ExchangeSchema::isSupported((string) $log->exchange)
                    ? ExchangeSchema::for((string) $log->exchange)->brokerLabel : (string) $log->exchange,
                'action' => $log->action,
                'ticker' => $log->ticker,
                'side' => in_array($log->action, ['BUY', 'EXIT_LONG'], true) ? 'LONG' : 'SHORT',
                'type' => str_starts_with((string) $log->action, 'EXIT') ? 'exit' : 'entry',
                'items' => $items,
            ];
        }

        $names = [];
        foreach ($wantNames as $exchange => $ids) {
            $names[$exchange] = TradeAlerts::names((string) $exchange, $ids);
        }
        foreach ($out as &$signal) {
            foreach ($signal['items'] as &$it) {
                $n = $names[$it['exchange']][$it['uni_id'] ?? ''] ?? null;
                $it['owner'] = $n['owner'] ?? null;
                $it['account'] = $it['account'] ?: ($n['account'] ?? null);
            }
            unset($it);
        }
        unset($signal);

        return ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String(), 'signals' => $out, 'counts' => $counts];
    }

    /** True when the summary has something a person should read. */
    public static function hasNews(array $digest): bool
    {
        $c = $digest['counts'];

        return ($c['failed'] + $c['shrunk'] + $c['limit'] + $c['rejected']) > 0;
    }

    /** @return list<TradeLog> */
    private function retriesOf(TradeLog $log, Collection $retries): array
    {
        $start = $log->ts?->getTimestamp() ?? 0;

        return $retries->filter(fn (TradeLog $r) => $r->exchange === $log->exchange
            && $r->action === $log->action && $r->ticker === $log->ticker
            && ($r->ts?->getTimestamp() ?? 0) >= $start
            && ($r->ts?->getTimestamp() ?? 0) - $start <= self::RETRY_FOLD_MINUTES * 60)->values()->all();
    }

    /**
     * @param  list<TradeLog>  $retries
     * @return list<array<string, mixed>>
     */
    private function items(TradeLog $log, array $retries, array &$counts): array
    {
        $filledLater = [];
        foreach ($retries as $r) {
            foreach (is_array($r->details) ? $r->details : [] as $d) {
                if (is_array($d) && ($d['status'] ?? null) === 'filled') {
                    $filledLater[($d['exchange'] ?? $r->exchange).'|'.($d['uni_id'] ?? '')] = true;
                }
            }
        }

        $items = [];
        foreach (is_array($log->details) ? $log->details : [] as $d) {
            if (! is_array($d)) {
                continue;
            }
            $exchange = (string) ($d['exchange'] ?? $log->exchange);
            $base = [
                'exchange' => $exchange,
                'uni_id' => isset($d['uni_id']) ? (string) $d['uni_id'] : null,
                'account' => isset($d['account']) ? (string) $d['account'] : null,
                'owner' => null,
                'is_master' => ! empty($d['is_master']),
            ];

            if ($log->category === 'rejected') {
                $c = SignalHealth::classify($d, 'rejected');
                if ($c && $c['severity'] === SignalHealth::PROBLEM) {
                    $counts['rejected']++;
                    $items[] = $base + ['kind' => 'rejected', 'label' => $c['label'],
                        'message' => (string) ($d['reason'] ?? $d['error'] ?? ''), 'resolved' => false];
                }

                continue;
            }

            $status = $d['status'] ?? null;
            if ($status === 'filled') {
                if (! empty($d['shrunk_by_limit'])) {
                    $counts['shrunk']++;
                    $items[] = $base + ['kind' => 'shrunk', 'label' => 'Shrunk to fit Binance’s position limit',
                        'message' => null, 'resolved' => true, 'limit' => $d['sizing']['limit'] ?? null,
                        'quantity' => $d['quantity'] ?? null];
                }

                continue;
            }

            $c = SignalHealth::classify($d, $log->category);
            if ($c === null || $c['severity'] !== SignalHealth::PROBLEM) {
                continue;
            }
            if ($status === 'skipped') {
                if ($c['key'] === 'limit_reached') {
                    $counts['limit']++;
                    $items[] = $base + ['kind' => 'limit', 'label' => $c['label'], 'message' => null,
                        'resolved' => false, 'limit' => $d['sizing']['limit'] ?? null];
                } else {
                    $counts['other_problems']++;
                }

                continue;
            }

            // failed
            $resolved = isset($filledLater[$exchange.'|'.($d['uni_id'] ?? '')]);
            $counts[$resolved ? 'resolved' : 'failed']++;
            $items[] = $base + ['kind' => 'failed', 'label' => $c['label'],
                'message' => (string) ($d['error'] ?? $d['reason'] ?? ''), 'resolved' => $resolved,
                'retryable' => (bool) ($d['retryable'] ?? false)];
        }

        return $items;
    }
}
