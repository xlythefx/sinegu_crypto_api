<?php

namespace App\Services\Pnl;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Binance P&L source: realized from binance_pastpositions, unrealized/balance
 * from the account snapshot, capital flow from binance_transactions.
 */
class BinancePnlSource implements PnlSource
{
    public function periodSnapshot(object $account, Carbon $monthStart, Carbon $monthEnd): array
    {
        $apiKey = $account->api_key;

        $closes = DB::table('binance_pastpositions')
            ->where('api_key', $apiKey)
            ->whereBetween('closed_at', [$monthStart, $monthEnd]);
        $realized = (float) (clone $closes)->sum('realized_pnl');
        // Before exchange fees: a row's own fee added back. Pre-2026-09-11
        // rows are gross with no fee, so they count once either way.
        $realizedGross = (float) $closes->sum(DB::raw('realized_pnl + COALESCE(exchange_fee, 0)'));

        $tx = DB::table('binance_transactions')->where('api_key', $apiKey)->get();
        $depAll = (float) $tx->where('type', 'DEPOSIT')->sum('amount');
        $wdAll = (float) $tx->where('type', 'WITHDRAWAL')->sum('amount');

        // Bucketed by created_at = when the money moved (TransferLedger dates
        // a transfer by the exchange's own transaction_time).
        $net = fn ($rows) => (float) $rows->where('type', 'DEPOSIT')->sum('amount')
            - (float) $rows->where('type', 'WITHDRAWAL')->sum('amount');
        $at = fn ($t) => Carbon::parse($t->created_at);

        return [
            'realized' => $realized,
            'realizedGross' => $realizedGross,
            'unrealized' => (float) $account->unrealized_pnl,
            'balance' => (float) $account->balance,
            'capitalFlow' => $net($tx->filter(fn ($t) => $at($t)->betweenIncluded($monthStart, $monthEnd))),
            'flowBefore' => $net($tx->filter(fn ($t) => $at($t)->lt($monthStart))),
            'flowAfter' => $net($tx->filter(fn ($t) => $at($t)->gt($monthEnd))),
            'adjustedDeposit' => (float) $account->initial_deposit + ($depAll - $wdAll),
        ];
    }
}
