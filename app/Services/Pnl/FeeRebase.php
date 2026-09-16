<?php

namespace App\Services\Pnl;

use App\Services\Exchanges\ExchangeSchema;
use Illuminate\Support\Facades\DB;

/**
 * Move a closed trade from its ESTIMATED fee to the ACTUAL one, from receipts.
 *
 * The one writer that changes `{exchange}_pastpositions.realized_pnl` after
 * the ingest, so its rules are the whole story of when a stored P&L may move:
 *
 *  - Only closes at/after TradingFee::NET_SINCE. History before it is gross
 *    by decision (see TradingFee) and stays gross even when receipts exist.
 *  - Only rows that hold a fee already (`exchange_fee` NOT NULL): the gross
 *    figure is recovered as `realized_pnl + exchange_fee` and the actual fee is
 *    taken from that, so the invariant `gross = pnl + fee` survives the swap.
 *    A row still awaiting the poller's backfill has no fee to swap.
 *  - Only `fee_source` estimated or actual. `manual` is an admin's typed
 *    figure and NULL is a gross row; both are left alone.
 *  - Never sandbox rows (`is_sandbox`, `SBXINV-…`): no receipts exist for
 *    them, and a scenario runner asserting hand-derived figures must not find
 *    them moved.
 *  - Never `order_id = 0`: the webhook writes that when Binance returned no
 *    order id, and a row that cannot name its closing order cannot be matched.
 *
 * Idempotent by construction. The ledger is append-only and the attribution
 * is a pure function of it, so re-running converges on the same figures; a
 * receipt that arrives late (funding indexed after the close) simply moves the
 * row once more, still on the invariant. An `actual` row is never downgraded:
 * if a re-run cannot confirm it, the figure it holds stands.
 *
 * The UPDATE is optimistic on the values read — an admin edit landing between
 * the read and the write (which stamps `manual`) wins, and the row is counted
 * as skipped rather than overwritten.
 *
 * Nothing here touches issued invoices (own snapshot) or the HWM.
 */
class FeeRebase
{
    /**
     * Attribute and rebase every qualifying close of one api_key + symbol.
     *
     * @return array{
     *     rows:int, rebased:int, unchanged:int, skipped:int,
     *     unconfirmed:list<array{order_id:int, reason:string}>,
     *     changes:list<array{id:int, order_id:int, closed_at:string, fee_before:float, fee_after:float, pnl_before:float, pnl_after:float, source_before:string}>
     * }
     */
    public function pair(string $apiKey, string $symbol, bool $dryRun = false, string $exchange = 'binance'): array
    {
        $schema = ExchangeSchema::for($exchange);

        // Receipts are filtered by exchange as well as by key: the ledger is one
        // table for every exchange, and a Binance receipt must never be replayed
        // against a MEXC close that happens to share api_key + symbol.
        $receipts = DB::table('exchange_fee_receipts')
            ->where('exchange', $exchange)
            ->where('api_key', $apiKey)
            ->where('symbol', $symbol)
            ->orderBy('charged_at')
            ->orderBy('id')
            ->get();

        $results = FeeAttribution::attribute($receipts);

        $rows = self::qualifying($exchange)
            ->where('api_key', $apiKey)
            ->where('symbol', $symbol)
            ->orderBy('closed_at')
            ->get();

        $report = ['rows' => $rows->count(), 'rebased' => 0, 'unchanged' => 0, 'skipped' => 0, 'unconfirmed' => [], 'changes' => []];

        foreach ($rows as $row) {
            $result = $results[(int) $row->order_id] ?? null;
            if ($result === null) {
                $report['skipped']++; // its receipts have not been ingested yet

                continue;
            }

            if (! $result['confirmable']) {
                $report['unconfirmed'][] = ['order_id' => (int) $row->order_id, 'reason' => $result['reason']];

                continue;
            }

            $pnlBefore = (float) $row->realized_pnl;
            $feeBefore = (float) $row->exchange_fee;
            $gross = TradingFee::gross($pnlBefore, $feeBefore);
            $feeAfter = round($result['total'], 8);
            $pnlAfter = round($gross - $feeAfter, 8);

            if (
                $row->fee_source === TradingFee::SOURCE_ACTUAL
                && abs($feeAfter - $feeBefore) < 1e-9
                && abs($pnlAfter - $pnlBefore) < 1e-9
            ) {
                $report['unchanged']++;

                continue;
            }

            $report['changes'][] = [
                'id' => (int) $row->id,
                'order_id' => (int) $row->order_id,
                'closed_at' => (string) $row->closed_at,
                'fee_before' => $feeBefore,
                'fee_after' => $feeAfter,
                'pnl_before' => $pnlBefore,
                'pnl_after' => $pnlAfter,
                'source_before' => (string) $row->fee_source,
            ];

            if ($dryRun) {
                $report['rebased']++;

                continue;
            }

            $affected = DB::table($schema->pastPositions)
                ->where('id', $row->id)
                ->where('fee_source', $row->fee_source)
                ->where('realized_pnl', $row->realized_pnl)
                ->update([
                    'realized_pnl' => $pnlAfter,
                    'exchange_fee' => $feeAfter,
                    'fee_source' => TradingFee::SOURCE_ACTUAL,
                ]);

            if ($affected === 1) {
                $report['rebased']++;
            } else {
                $report['skipped']++; // edited underneath us; the edit wins
            }
        }

        return $report;
    }

    /**
     * The rows this class is allowed to touch. Shared with the reconcile
     * command so "which pairs have work" and "which rows move" cannot drift.
     */
    public static function qualifying(string $exchange = 'binance'): \Illuminate\Database\Query\Builder
    {
        return DB::table(ExchangeSchema::for($exchange)->pastPositions)
            ->where('closed_at', '>=', TradingFee::NET_SINCE)
            ->whereNotNull('realized_pnl')
            ->whereNotNull('exchange_fee')
            ->where('is_sandbox', 0)
            ->where('api_key', 'not like', 'SBXINV-%')
            ->whereIn('fee_source', [TradingFee::SOURCE_ESTIMATED, TradingFee::SOURCE_ACTUAL])
            ->where('order_id', '>', 0);
    }
}
