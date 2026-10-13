<?php

namespace App\Services\Positions;

use App\Services\Exchanges\ExchangeSchema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Who closed a trade (owner, 2026-10-14): the bot, or someone OUTSIDE it.
 *
 * On 12 Oct a customer closed their RENDER short by hand on Binance. The bot
 * saw an empty account at the next signal and opened a fresh first entry
 * while everyone else was full — which read as "the bot reopened it". This
 * marks such closes so the admin screens can say so.
 *
 * A close is `bot` when the engine closed it — its own close path writes
 * `increments_closed`, and every engine exit (TradingView, a retry, an admin's
 * manual close) leaves a trade_logs row naming the account as filled. Anything
 * else is `outside`: the customer by hand, their own take-profit / stop-loss,
 * a liquidation. A close is judged only once the signal log has had time to
 * land (SETTLE_MINUTES) and only after the log began — before that there is
 * nothing to judge it against, so it stays NULL.
 *
 * Rows are judged once and never re-judged. Sandbox and invoice-scenario rows
 * are never judged.
 */
final class CloseSource
{
    public const BOT = 'bot';

    public const OUTSIDE = 'outside';

    /** An engine exit's log row is written within seconds of the fill; give it this long. */
    public const SETTLE_MINUTES = 3;

    /** How far a bot exit's log row may sit from the close it made (retries included). */
    public const MATCH_WINDOW_MINUTES = 15;

    public const EXIT_ACTIONS = ['EXIT_LONG', 'EXIT_SHORT'];

    /**
     * Judge every unjudged close on the exchange since $since. A dry run
     * writes nothing and lists the closes it would mark outside the bot, so a
     * backfill can be read before it is applied.
     *
     * @return array{bot: int, outside: int, outside_ids: list<int>}
     */
    public function classify(string $exchange, Carbon $since, bool $dryRun = false): array
    {
        $result = ['bot' => 0, 'outside' => 0, 'outside_ids' => []];
        $logStart = DB::table('trade_logs')->where('exchange', $exchange)->min('ts');
        if ($logStart === null) {
            return $result;
        }
        $from = $since->max(Carbon::parse($logStart, 'UTC'));
        $settled = now()->subMinutes(self::SETTLE_MINUTES);
        if ($from->greaterThan($settled)) {
            return $result;
        }

        $table = ExchangeSchema::for($exchange)->pastPositions;
        $rows = DB::table($table)
            ->whereNull('close_source')
            ->where('is_sandbox', 0)
            ->where('api_key', 'not like', 'SBXINV-%')
            ->where('closed_at', '>=', $from->toDateTimeString())
            ->where('closed_at', '<=', $settled->toDateTimeString())
            ->orderBy('closed_at')
            ->limit(5000)
            ->get(['id', 'uni_id', 'symbol', 'closed_at', 'increments_closed']);
        if ($rows->isEmpty()) {
            return $result;
        }

        $exits = $this->exitsBetween(
            $exchange,
            Carbon::parse($rows->first()->closed_at, 'UTC')->subMinutes(self::MATCH_WINDOW_MINUTES),
            Carbon::parse($rows->last()->closed_at, 'UTC')->addMinutes(self::MATCH_WINDOW_MINUTES),
        );

        $byBot = [];
        $outside = [];
        foreach ($rows as $row) {
            if ($row->increments_closed !== null || $this->matchesExit($row, $exits)) {
                $byBot[] = $row->id;
            } else {
                $outside[] = $row->id;
            }
        }

        if ($dryRun) {
            return ['bot' => count($byBot), 'outside' => count($outside), 'outside_ids' => $outside];
        }

        foreach (array_chunk($byBot, 500) as $ids) {
            $result['bot'] += DB::table($table)->whereIn('id', $ids)->whereNull('close_source')->update(['close_source' => self::BOT]);
        }
        foreach (array_chunk($outside, 500) as $ids) {
            $result['outside'] += DB::table($table)->whereIn('id', $ids)->whereNull('close_source')->update(['close_source' => self::OUTSIDE]);
        }

        return $result;
    }

    /**
     * Engine exits in the window, per ticker: [timestamp, uni_ids that filled].
     *
     * @return array<string, list<array{0: int, 1: array<string, true>}>>
     */
    private function exitsBetween(string $exchange, Carbon $from, Carbon $to): array
    {
        $logs = DB::table('trade_logs')
            ->where('exchange', $exchange)
            ->whereIn('action', self::EXIT_ACTIONS)
            ->where('ts', '>=', $from->toDateTimeString())
            ->where('ts', '<=', $to->toDateTimeString())
            ->get(['ticker', 'ts', 'details']);

        $exits = [];
        foreach ($logs as $log) {
            $details = is_string($log->details) ? json_decode($log->details, true) : $log->details;
            $filled = [];
            foreach (is_array($details) ? $details : [] as $detail) {
                if (! is_array($detail) || ($detail['status'] ?? null) !== 'filled') {
                    continue;
                }
                if (isset($detail['exchange']) && $detail['exchange'] !== $exchange) {
                    continue;
                }
                if (! empty($detail['uni_id'])) {
                    $filled[(string) $detail['uni_id']] = true;
                }
            }
            if ($filled !== []) {
                $exits[$log->ticker][] = [Carbon::parse($log->ts, 'UTC')->getTimestamp(), $filled];
            }
        }

        return $exits;
    }

    /** @param  array<string, list<array{0: int, 1: array<string, true>}>>  $exits */
    private function matchesExit(object $row, array $exits): bool
    {
        $at = Carbon::parse($row->closed_at, 'UTC')->getTimestamp();
        $window = self::MATCH_WINDOW_MINUTES * 60;
        foreach ($exits[$row->symbol] ?? [] as [$ts, $filled]) {
            if (abs($ts - $at) <= $window && isset($filled[(string) $row->uni_id])) {
                return true;
            }
        }

        return false;
    }
}
