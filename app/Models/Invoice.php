<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A monthly billing period for one exchange account. One row per
 * (exchange, account, month). HWM is carried on the row itself
 * (hwm_before/hwm_after) so it stays uniform across every exchange.
 */
class Invoice extends Model
{
    protected $table = 'invoices';

    protected $fillable = [
        'user_id', 'account_id', 'exchange', 'api_key', 'month_year',
        'equity_start', 'equity_end', 'realized_pnl', 'unrealized_pnl',
        'deposit_amount', 'adjusted_equity', 'capital_flow', 'performance_equity',
        'hwm_before', 'hwm_after', 'new_realized_profit', 'new_unrealized_profit',
        'fee_realized', 'fee_unrealized', 'total_fee', 'status', 'due_date',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'invoice_sent_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function scopeForExchange($query, string $exchange)
    {
        return $query->where('exchange', $exchange);
    }

    public function scopeForUser($query, string $uniId)
    {
        return $query->where('user_id', $uniId);
    }

    /** Owning exchange account (may be soft-deleted). */
    public function account()
    {
        return $this->belongsTo(BinanceAccount::class, 'account_id')->withTrashed();
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    /**
     * Normalized shape the frontend maps into its `Invoice` type. `$isFirst`
     * (no earlier invoice for this account) is passed in so list endpoints can
     * compute it once for the whole set instead of a query per row.
     */
    public function toApiArray(?string $accountName = null, bool $isFirst = false): array
    {
        $f = fn ($v) => (float) $v;
        $monthYear = (string) $this->month_year;
        $period = Carbon::createFromFormat('Y-m', $monthYear)->startOfMonth();
        // Invoiced on the 1st of the month after the billing period.
        $invoiceDate = $period->copy()->addMonthNoOverflow()->startOfMonth();

        $hwmBefore = $f($this->hwm_before);
        $deposit = $f($this->deposit_amount);
        $realized = $f($this->realized_pnl);
        $unrealized = $f($this->unrealized_pnl);

        $referenceValue = $isFirst ? $deposit : $hwmBefore;
        $referenceLabel = $isFirst ? 'Initial Deposit' : 'Previous HWM';
        $performanceGain = $referenceValue > 0
            ? round(($realized + $unrealized) / $referenceValue * 100, 2)
            : 0.0;

        $paid = $this->status === 'paid';

        return [
            'id' => (string) $this->id,
            'user_id' => $this->user_id,
            'exchange' => $this->exchange,
            'formatted_id' => \sprintf('INV-%s-%04d', $period->format('Y'), $this->id),
            'account_name' => $accountName ?? $this->account?->name ?? 'Account',
            'month_year' => $monthYear,
            'month_label' => $period->format('F Y'),
            'invoice_date' => $invoiceDate->toDateString(),
            'due_date' => $this->due_date?->toDateString()
                ?? $invoiceDate->copy()->addDays(7)->toDateString(),
            'paid_date' => $paid ? $this->updated_at?->toDateString() : null,
            'status' => $paid ? 'paid' : 'pending',
            'is_overdue' => ! $paid
                && $this->due_date
                && $this->due_date->isPast()
                && $f($this->total_fee) > 0,
            'total_fee' => round($f($this->total_fee), 2),
            'currency' => 'USD',
            'performance_gain' => $performanceGain,
            'current_balance' => round($f($this->equity_end), 2),
            'hwm_before' => round($hwmBefore, 2),
            'hwm_after' => round($f($this->hwm_after), 2),
            'realized_pnl' => round($realized, 2),
            'unrealized_pnl' => round($unrealized, 2),
            'fee_realized' => round($f($this->fee_realized), 2),
            'fee_unrealized' => round($f($this->fee_unrealized), 2),
            'deposit_amount' => round($deposit, 2),
            'is_first_invoice' => $isFirst,
            'reference_label' => $referenceLabel,
            'reference_value' => round($referenceValue, 2),
        ];
    }
}
