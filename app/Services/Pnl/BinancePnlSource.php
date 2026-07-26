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

        $realized = (float) DB::table('binance_pastpositions')
            ->where('api_key', $apiKey)
            ->whereBetween('closed_at', [$monthStart, $monthEnd])
            ->sum('realized_pnl');

        $tx = DB::table('binance_transactions')->where('api_key', $apiKey)->get();
        $depAll = (float) $tx->where('type', 'DEPOSIT')->sum('amount');
        $wdAll = (float) $tx->where('type', 'WITHDRAWAL')->sum('amount');

        $inMonth = $tx->filter(function ($t) use ($monthStart, $monthEnd) {
            $at = Carbon::parse($t->created_at);
            return $at->betweenIncluded($monthStart, $monthEnd);
        });
        $capitalFlow = (float) $inMonth->where('type', 'DEPOSIT')->sum('amount')
            - (float) $inMonth->where('type', 'WITHDRAWAL')->sum('amount');

        return [
            'realized' => $realized,
            'unrealized' => (float) $account->unrealized_pnl,
            'balance' => (float) $account->balance,
            'capitalFlow' => $capitalFlow,
            'adjustedDeposit' => (float) $account->initial_deposit + ($depAll - $wdAll),
        ];
    }
}
