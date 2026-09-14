<?php

namespace App\Services\Pnl;

/**
 * Turn one (api_key, symbol)'s fee RECEIPTS into a fee per CLOSING ORDER.
 *
 * Pure: no DB, no clock, no config. Given every `exchange_fee_receipts` row for
 * one account and symbol in charge order, it replays the position: entry fills
 * pile into an open bucket, funding charged while the bucket is open attaches
 * to it, and a closing order takes the bucket's accumulated cost with it. The
 * output is what `binance_pastpositions.exchange_fee` should hold for each
 * close — exit commission + the entry commission and funding that belong to
 * the position it closed — and whether that figure can be trusted.
 *
 * Why a replay and not a join: a closed-trade row references only its closing
 * order. The entry commission sits on the entry order(s) — several, when the
 * strategy stacks increments — and funding is charged to the SYMBOL every 8
 * hours with no order at all. Nothing on the row says which fills opened it.
 * Position side + chronology do: a position is whatever was bought and not yet
 * sold, and everything charged in between belongs to it.
 *
 * Hedge mode is the engine's setting, so fills carry a real `position_side`
 * and the direction of an order is explicit: LONG+BUY opens, LONG+SELL closes.
 * One-way (`BOTH`) is handled as a fallback by the sign of the net position,
 * with the order's own realized P&L breaking the tie at zero — a fill that
 * realized something while we hold nothing closed a position we never saw open.
 *
 * Three answers are refused rather than guessed, each with a reason the
 * reconciler can print:
 *  - entry_missing    the close found no entry fills (position opened before
 *                     the ledger existed, or a webhook row Binance gave no
 *                     order id).
 *  - entry_qty_short  the close is larger than what the ledger saw enter,
 *                     beyond rounding — part of the position predates the
 *                     ledger, so the entry fee would be understated.
 *  - non_usdt         a receipt on this position is in another asset (BNB fee
 *                     discount). Summing BNB and USDT is not a fee.
 * An unconfirmable close still reports what it could see, so a dry run shows
 * the pieces; only `confirmable` decides whether the row moves.
 *
 * A partial close takes its share of the bucket pro-rata by quantity and
 * leaves the rest for the next close. The engine always closes in full, so
 * that branch is for correctness, not for the strategy.
 */
class FeeAttribution
{
    /** Assets a trade's fee may be summed in. Anything else refuses the trade. */
    public const SETTLEMENT_ASSETS = ['USDT'];

    public const REASON_ENTRY_MISSING = 'entry_missing';
    public const REASON_ENTRY_QTY_SHORT = 'entry_qty_short';
    public const REASON_NON_USDT = 'non_usdt';

    /** A close may exceed the ledger's entry quantity by this much and still count as whole. */
    private const QTY_TOLERANCE_ABS = 1e-8;
    private const QTY_TOLERANCE_REL = 1e-4;

    /**
     * @param  iterable<object|array>  $receipts  every receipt of ONE api_key + symbol,
     *                                            ascending by charged_at then id
     * @return array<int, array{
     *     order_id:int, position_side:string, close_qty:float, closed_at_ms:int,
     *     exit_commission:float, entry_commission:float, funding:float, total:float,
     *     confirmable:bool, reason:?string, assets:list<string>
     * }>  keyed by closing order_id
     */
    public static function attribute(iterable $receipts): array
    {
        $events = self::events($receipts);
        $buckets = ['LONG' => self::emptyBucket(), 'SHORT' => self::emptyBucket()];
        $results = [];

        foreach ($events as $event) {
            if ($event['kind'] === 'funding') {
                self::applyFunding($buckets, $event);

                continue;
            }

            [$dir, $opens, $orphanClose] = self::direction($event, $buckets);

            if ($orphanClose) {
                $results[$event['order_id']] = self::result($event, $dir, 0.0, 0.0, $event['assets'], false, self::REASON_ENTRY_MISSING);

                continue;
            }

            if ($opens) {
                self::open($buckets[$dir], $event);

                continue;
            }

            $results[$event['order_id']] = self::close($buckets[$dir], $event, $dir);
        }

        return $results;
    }

    // --- replay -------------------------------------------------------------

    /**
     * Receipts → events in time order. Fill receipts are grouped per order
     * (one market order can fill in several pieces) and dated at the LAST
     * fill, which is how the poller dates the close itself. Funding stays one
     * event per receipt. On an exact tie funding goes first: it was charged to
     * whoever held the position at that instant, and a close at the same
     * millisecond still held it.
     */
    private static function events(iterable $receipts): array
    {
        $orders = [];
        $funding = [];

        foreach ($receipts as $raw) {
            $r = self::normalize($raw);
            if ($r === null) {
                continue;
            }

            if ($r['kind'] === 'funding') {
                $funding[] = [
                    'kind' => 'funding',
                    'time' => $r['charged_at'],
                    'seq' => $r['id'],
                    'amount' => $r['amount'],
                    'asset' => $r['asset'],
                ];

                continue;
            }

            $oid = $r['order_id'];
            if ($oid === null) {
                continue; // a fill with no order cannot be placed in the replay
            }

            if (! isset($orders[$oid])) {
                $orders[$oid] = [
                    'kind' => 'order',
                    'order_id' => $oid,
                    'side' => $r['side'],
                    'position_side' => $r['position_side'],
                    'qty' => 0.0,
                    'amount' => 0.0,
                    'realized_pnl' => 0.0,
                    'first_time' => $r['charged_at'],
                    'time' => $r['charged_at'],
                    'seq' => $r['id'],
                    'assets' => [],
                ];
            }

            $o = &$orders[$oid];
            $o['qty'] += $r['qty'];
            $o['amount'] += $r['amount'];
            $o['realized_pnl'] += $r['realized_pnl'];
            $o['first_time'] = min($o['first_time'], $r['charged_at']);
            $o['time'] = max($o['time'], $r['charged_at']);
            $o['seq'] = max($o['seq'], $r['id']);
            if ($r['amount'] != 0.0) {
                $o['assets'][$r['asset']] = true;
            }
            unset($o);
        }

        $events = array_merge(array_values($orders), $funding);
        usort($events, function (array $a, array $b): int {
            if ($a['time'] !== $b['time']) {
                return $a['time'] <=> $b['time'];
            }
            if ($a['kind'] !== $b['kind']) {
                return $a['kind'] === 'funding' ? -1 : 1;
            }

            return $a['seq'] <=> $b['seq'];
        });

        return $events;
    }

    /** One receipt row (stdClass from the query builder, or an array) → typed array; null if unusable. */
    private static function normalize(object|array $raw): ?array
    {
        $get = fn (string $k) => is_array($raw) ? ($raw[$k] ?? null) : ($raw->$k ?? null);

        $kind = (string) $get('kind');
        if ($kind !== 'fill' && $kind !== 'funding') {
            return null;
        }

        $chargedAt = $get('charged_at');
        if ($chargedAt === null) {
            return null;
        }

        return [
            'id' => (int) ($get('id') ?? 0),
            'kind' => $kind,
            'order_id' => $get('order_id') === null ? null : (int) $get('order_id'),
            'side' => strtoupper((string) ($get('side') ?? '')),
            'position_side' => strtoupper((string) ($get('position_side') ?? 'BOTH')),
            'qty' => (float) ($get('qty') ?? 0),
            'realized_pnl' => (float) ($get('realized_pnl') ?? 0),
            'amount' => (float) ($get('amount') ?? 0),
            'asset' => strtoupper((string) ($get('asset') ?? 'USDT')),
            'charged_at' => (int) $chargedAt,
        ];
    }

    private static function emptyBucket(): array
    {
        return ['qty' => 0.0, 'entry_commission' => 0.0, 'funding' => 0.0, 'opened_at' => null, 'assets' => []];
    }

    /**
     * Which bucket an order acts on and whether it opens or closes it.
     *
     * @return array{0:string, 1:bool, 2:bool}  [direction, opens, orphan close]
     */
    private static function direction(array $order, array $buckets): array
    {
        $side = $order['side'];
        $ps = $order['position_side'];

        if ($ps === 'LONG' || $ps === 'SHORT') {
            $opens = ($ps === 'LONG') === ($side === 'BUY');

            return [$ps, $opens, ! $opens && $buckets[$ps]['qty'] <= 0.0];
        }

        // One-way: the sign of what we hold decides. At flat, a fill that
        // realized P&L closed something the ledger never saw open.
        $net = $buckets['LONG']['qty'] - $buckets['SHORT']['qty'];

        if ($side === 'BUY') {
            if ($net < 0.0) {
                return ['SHORT', false, false];
            }
            if ($net == 0.0 && $order['realized_pnl'] != 0.0) {
                return ['SHORT', false, true];
            }

            return ['LONG', true, false];
        }

        if ($net > 0.0) {
            return ['LONG', false, false];
        }
        if ($net == 0.0 && $order['realized_pnl'] != 0.0) {
            return ['LONG', false, true];
        }

        return ['SHORT', true, false];
    }

    private static function open(array &$bucket, array $order): void
    {
        $bucket['qty'] += $order['qty'];
        $bucket['entry_commission'] += $order['amount'];
        $bucket['opened_at'] ??= $order['first_time'];
        $bucket['assets'] += $order['assets'];
    }

    /** Funding belongs to whoever was open when it was charged; split by size if both sides were. */
    private static function applyFunding(array &$buckets, array $event): void
    {
        $open = array_filter(
            $buckets,
            fn (array $b) => $b['qty'] > 0.0 && $b['opened_at'] !== null && $b['opened_at'] <= $event['time'],
        );
        if (! $open) {
            return; // charged while flat — not ours to attribute
        }

        $totalQty = array_sum(array_column($open, 'qty'));
        foreach (array_keys($open) as $dir) {
            $share = $totalQty > 0.0 ? $buckets[$dir]['qty'] / $totalQty : 0.0;
            $buckets[$dir]['funding'] += $event['amount'] * $share;
            if ($event['amount'] != 0.0) {
                $buckets[$dir]['assets'][$event['asset']] = true;
            }
        }
    }

    private static function close(array &$bucket, array $order, string $dir): array
    {
        $closeQty = $order['qty'];
        $assets = $bucket['assets'] + $order['assets'];

        $tolerance = max(self::QTY_TOLERANCE_ABS, $closeQty * self::QTY_TOLERANCE_REL);
        $short = $closeQty - $bucket['qty'];

        if ($short > $tolerance) {
            // More left than the ledger saw enter: the rest predates it. Report
            // what was seen, refuse the figure, and start clean — the engine
            // never flips a position, so nothing carries over.
            $seenEntry = $bucket['entry_commission'];
            $seenFunding = $bucket['funding'];
            $bucket = self::emptyBucket();

            return self::result($order, $dir, $seenEntry, $seenFunding, $assets, false, self::REASON_ENTRY_QTY_SHORT);
        }

        $whole = ($bucket['qty'] - $closeQty) <= $tolerance;
        $share = $whole ? 1.0 : $closeQty / $bucket['qty'];

        $entry = $bucket['entry_commission'] * $share;
        $funding = $bucket['funding'] * $share;

        if ($whole) {
            $bucket = self::emptyBucket();
        } else {
            $bucket['qty'] -= $closeQty;
            $bucket['entry_commission'] -= $entry;
            $bucket['funding'] -= $funding;
        }

        $foreign = array_diff(array_keys($assets), self::SETTLEMENT_ASSETS);

        return self::result($order, $dir, $entry, $funding, $assets, $foreign === [], $foreign === [] ? null : self::REASON_NON_USDT);
    }

    private static function result(
        array $order,
        string $dir,
        float $entry,
        float $funding,
        array $assets,
        bool $confirmable,
        ?string $reason,
    ): array {
        $exit = $order['amount'];
        $assetList = array_keys($assets);
        sort($assetList);

        return [
            'order_id' => $order['order_id'],
            'position_side' => $dir,
            'close_qty' => round($order['qty'], 8),
            'closed_at_ms' => $order['time'],
            'exit_commission' => round($exit, 8),
            'entry_commission' => round($entry, 8),
            'funding' => round($funding, 8),
            'total' => round($exit + $entry + $funding, 8),
            'confirmable' => $confirmable,
            'reason' => $reason,
            'assets' => $assetList,
        ];
    }
}
