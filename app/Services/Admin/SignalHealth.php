<?php

namespace App\Services\Admin;

use App\Models\TradeLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Signal Log health — the ONE place that decides which skips and failures in
 * `trade_logs.details` are normal and which need a human.
 *
 * Until 2026-10-09 the log showed every skip and failure the same way, and
 * most of the red was a couple of customers' blocked API keys repeating on
 * every signal, sitting beside skips that are simply how the strategy works
 * ("nothing open to close", "stack already full"). Each account outcome is
 * classified into a stable reason key, a plain label and a severity:
 * - `normal`  — expected; the account did exactly what it should.
 * - `problem` — someone has to act (a key, a deposit, an order refused).
 * A rejected SIGNAL (category `rejected`: no venue could run it) is a problem
 * at signal level.
 *
 * The raw engine text is kept beside the key — the classification is for
 * filtering and grouping, never a replacement for what the engine said.
 */
final class SignalHealth
{
    public const NORMAL = 'normal';

    public const PROBLEM = 'problem';

    /** key => [label, severity, fix label|null, fix href|null] */
    public const REASONS = [
        'key_blocked' => ['API key refused by the exchange', self::PROBLEM, 'Fix in API Keys', '/admin/api-keys'],
        'deposit_unknown' => ['Deposit not synced yet', self::PROBLEM, 'Check in API Keys', '/admin/api-keys'],
        'size_too_small' => ['Size too small to trade', self::PROBLEM, 'Check the asset', '/admin/assets'],
        'stack_unknown' => ['Open size could not be read', self::PROBLEM, 'Check open positions', '/admin?tab=positions'],
        'insufficient_margin' => ['Not enough margin on the account', self::PROBLEM, null, null],
        'no_response' => ['Exchange did not answer', self::PROBLEM, 'Check the bot engine', '/admin/engine'],
        'order_failed' => ['Order refused by the exchange', self::PROBLEM, 'Check the bot engine', '/admin/engine'],
        'engine_error' => ['Engine error', self::PROBLEM, 'Check the bot engine', '/admin/engine'],
        'rejected' => ['Signal rejected', self::PROBLEM, 'Check the asset', '/admin/assets'],
        'other_skip' => ['Skipped for another reason', self::PROBLEM, null, null],
        'nothing_to_close' => ['Nothing open to close', self::NORMAL, null, null],
        'maxed' => ['Stack already full', self::NORMAL, null, null],
        'deposit_low' => ['Deposit below the minimum', self::NORMAL, null, null],
        'untradeable' => ['Account cannot trade on this venue', self::NORMAL, null, null],
    ];

    /** Most logs any one request classifies — the log is a few signals a day. */
    public const SCAN_LIMIT = 3000;

    /**
     * The reason an account outcome carries, or null for a fill.
     *
     * @param  array<string, mixed>  $detail  one raw `details` row
     * @return array{key: string, label: string, severity: string}|null
     */
    public static function classify(array $detail, ?string $category = null): ?array
    {
        if ($category === 'rejected') {
            return self::reason('rejected');
        }
        $status = $detail['status'] ?? null;
        if ($status === 'filled') {
            return null;
        }
        $text = strtolower(trim((string) ($detail['reason'] ?? $detail['error'] ?? '')));

        if ($status === 'skipped') {
            return self::reason(match (true) {
                $text === 'api key blocked' => 'key_blocked',
                str_starts_with($text, 'no ') && str_contains($text, 'to close') => 'nothing_to_close',
                $text === 'maxed sizing' => 'maxed',
                $text === 'deposit below minimum' => 'deposit_low',
                $text === 'deposit unknown' => 'deposit_unknown',
                $text === 'size too small' => 'size_too_small',
                $text === 'stack depth unknown' => 'stack_unknown',
                str_contains($text, 'demo') || str_contains($text, 'not available') => 'untradeable',
                default => 'other_skip',
            });
        }

        if ($status === 'failed') {
            return self::reason(match (true) {
                str_contains($text, 'api-key') || str_contains($text, 'api key')
                    || str_contains($text, 'permission') || str_contains($text, '-2015') => 'key_blocked',
                str_contains($text, 'margin') || str_contains($text, 'insufficient') || str_contains($text, 'balance') => 'insufficient_margin',
                str_contains($text, 'no response') || str_contains($text, 'timeout') || str_contains($text, 'timed out')
                    || str_contains($text, '408') || str_contains($text, '-1007') => 'no_response',
                str_starts_with($text, 'crash') => 'engine_error',
                default => 'order_failed',
            });
        }

        return null;
    }

    /** @return array{key: string, label: string, severity: string} */
    public static function reason(string $key): array
    {
        $r = self::REASONS[$key] ?? self::REASONS['other_skip'];

        return ['key' => $key, 'label' => $r[0], 'severity' => $r[1]];
    }

    /**
     * How the master did on this signal: filled | skipped | failed | absent,
     * or null when no row carries the master flag (an engine older than it).
     */
    public static function masterOutcome(TradeLog $log): ?string
    {
        $details = is_array($log->details) ? $log->details : [];
        $flagged = false;
        foreach ($details as $d) {
            if (! is_array($d) || ! array_key_exists('is_master', $d)) {
                continue;
            }
            $flagged = true;
            if ($d['is_master']) {
                return (string) ($d['status'] ?? 'absent');
            }
        }

        return $flagged ? 'absent' : null;
    }

    /**
     * The health banner: the last `$hours` of signals, plus every problem in
     * them grouped by cause.
     */
    public function health(int $hours = 24): array
    {
        $since = now()->subHours($hours);
        $logs = TradeLog::where('ts', '>=', $since)->orderByDesc('ts')->limit(self::SCAN_LIMIT)->get();

        $issues = $this->issues($logs);
        $normal = 0;
        $filled = 0;
        $masterMissed = 0;
        foreach ($logs as $log) {
            $filled += (int) $log->filled;
            foreach ((array) $log->details as $d) {
                if (is_array($d) && ($c = self::classify($d, $log->category)) && $c['severity'] === self::NORMAL) {
                    $normal++;
                }
            }
            // The master missed it only when it WAS in the fan-out and its
            // outcome is a problem. Absent is not a miss: a manual close
            // narrowed to customers, or a venue the master has no account on.
            $masterDetail = collect((array) $log->details)->first(fn ($d) => is_array($d) && ! empty($d['is_master']));
            if ($masterDetail && $log->category !== 'rejected') {
                $c = self::classify($masterDetail, $log->category);
                if ($c !== null && $c['severity'] === self::PROBLEM) {
                    $masterMissed++;
                }
            }
        }

        $problems = array_sum(array_column($issues, 'count'));
        $status = match (true) {
            $logs->isEmpty() => 'quiet',
            $problems > 0 || $masterMissed > 0 => 'attention',
            $normal > 0 => 'normal',
            default => 'healthy',
        };

        return [
            'status' => $status,
            'hours' => $hours,
            'signals' => $logs->count(),
            'filled' => $filled,
            'normal_skips' => $normal,
            'problems' => $problems,
            'master_missed' => $masterMissed,
            'last_signal_at' => optional($logs->first()?->ts)->toIso8601String(),
            'issues' => array_slice($issues, 0, 5),
            'issues_total' => count($issues),
        ];
    }

    /**
     * Problems grouped by cause: one entry per (reason, account) — or per
     * (ticker, rejection) for a rejected signal — most recent first. Normal
     * skips are not issues and never appear here.
     *
     * @param  Collection<int, TradeLog>  $logs
     * @return list<array<string, mixed>>
     */
    public function issues(Collection $logs): array
    {
        $groups = [];
        $uniIds = [];

        foreach ($logs as $log) {
            $details = is_array($log->details) ? $log->details : [];
            $at = $log->ts instanceof Carbon ? $log->ts : null;

            foreach ($details as $d) {
                if (! is_array($d)) {
                    continue;
                }
                $c = self::classify($d, $log->category);
                if ($c === null || $c['severity'] !== self::PROBLEM) {
                    continue;
                }
                $raw = (string) ($d['reason'] ?? $d['error'] ?? '');
                $exchange = (string) ($d['exchange'] ?? $log->exchange ?? '');
                $key = $log->category === 'rejected'
                    ? "rejected|{$exchange}|{$log->ticker}|".strtolower($raw)
                    : "{$c['key']}|{$exchange}|".($d['uni_id'] ?? '').'|'.($d['account'] ?? '');

                if (! isset($groups[$key])) {
                    $fix = self::REASONS[$c['key']] ?? null;
                    $groups[$key] = [
                        'key' => md5($key),
                        'reason_key' => $c['key'],
                        'label' => $c['label'],
                        'severity' => $c['severity'],
                        'account' => $log->category === 'rejected' ? null : ($d['account'] ?? null),
                        'uni_id' => $log->category === 'rejected' ? null : ($d['uni_id'] ?? null),
                        'user_name' => null,
                        'is_master' => ! empty($d['is_master']),
                        'exchange' => $exchange !== '' ? $exchange : null,
                        'tickers' => [],
                        'count' => 0,
                        'first_at' => null,
                        'last_at' => null,
                        'last_message' => $raw !== '' ? $raw : null,
                        'fix' => $fix && $fix[2] ? ['label' => $fix[2], 'href' => $fix[3]] : null,
                    ];
                }
                $g = &$groups[$key];
                $g['count']++;
                $g['tickers'][$log->ticker] = true;
                if ($at) {
                    $iso = $at->toIso8601String();
                    if ($g['last_at'] === null || $iso > $g['last_at']) {
                        $g['last_at'] = $iso;
                        $g['last_message'] = $raw !== '' ? $raw : $g['last_message'];
                    }
                    if ($g['first_at'] === null || $iso < $g['first_at']) {
                        $g['first_at'] = $iso;
                    }
                }
                unset($g);
                if (! empty($d['uni_id'])) {
                    $uniIds[$d['uni_id']] = true;
                }
            }
        }

        $names = $uniIds
            ? \Illuminate\Support\Facades\DB::table('user_credentials')->whereIn('uni_id', array_keys($uniIds))->pluck('name', 'uni_id')->all()
            : [];

        $out = [];
        foreach ($groups as $g) {
            $g['tickers'] = array_keys($g['tickers']);
            $g['user_name'] = $g['uni_id'] ? ($names[$g['uni_id']] ?? null) : null;
            $out[] = $g;
        }
        usort($out, fn ($a, $b) => strcmp((string) $b['last_at'], (string) $a['last_at']) ?: $b['count'] <=> $a['count']);

        return $out;
    }
}
