<?php

namespace App\Services\Pnl;

use Illuminate\Support\Carbon;

/**
 * Exchange-agnostic source of the numbers invoice generation needs for one
 * account over one billing month. Each exchange (Binance now; Bybit/MEXC later)
 * ships one implementation — the InvoiceService stays untouched.
 */
interface PnlSource
{
    /**
     * @return array{realized: float, unrealized: float, balance: float, capitalFlow: float, adjustedDeposit: float}
     *   realized        — closed P&L within [monthStart, monthEnd]
     *   unrealized      — current open P&L snapshot
     *   balance         — current account balance
     *   capitalFlow     — net deposits − withdrawals within the month
     *   adjustedDeposit — initial deposit + all-time net transfers
     */
    public function periodSnapshot(object $account, Carbon $monthStart, Carbon $monthEnd): array;
}
