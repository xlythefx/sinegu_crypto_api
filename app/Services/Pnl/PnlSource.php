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
     * @return array{realized: float, realizedGross?: float, unrealized: float, balance: float, capitalFlow: float, flowBefore: float, flowAfter: float, adjustedDeposit: float}
     *   realized        — closed P&L within [monthStart, monthEnd]
     *   realizedGross   — the same closes BEFORE exchange fees (optional: a
     *                     source that cannot tell leaves the invoice's fee
     *                     split unrecorded, never zero)
     *   unrealized      — current open P&L snapshot
     *   balance         — current account balance
     *   capitalFlow     — net deposits − withdrawals within the month
     *   flowBefore      — net deposits − withdrawals BEFORE monthStart
     *   flowAfter       — net deposits − withdrawals AFTER monthEnd (the
     *                     balance is read now, on the 1st or later, so it
     *                     already holds them)
     *   adjustedDeposit — initial deposit + all-time net transfers
     */
    public function periodSnapshot(object $account, Carbon $monthStart, Carbon $monthEnd): array;
}
