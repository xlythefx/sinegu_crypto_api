<?php

namespace App\Services;

use App\Models\BinanceAccount;
use App\Models\Invoice;
use App\Services\Pnl\BinancePnlSource;
use App\Services\Pnl\PnlSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The one place invoices are generated and settled. `generateForAccount` is the
 * reusable core the admin sandbox calls now and the monthly scheduler will call
 * later; `settle` is the single settlement path that manual admin action calls
 * now and the Stripe/Coinsbuy webhooks + auto-charge will call later.
 */
class InvoiceService
{
    public function __construct(
        private BinancePnlSource $binance,
        private EngineCache $engineCache,
    ) {}

    private function sourceFor(string $exchange): PnlSource
    {
        return match ($exchange) {
            'binance' => $this->binance,
            // 'bybit' => $this->bybit,  'mexc' => $this->mexc,  (adapters land with those tables)
            default => throw new \InvalidArgumentException("No P&L source for exchange '{$exchange}' yet."),
        };
    }

    /**
     * Compute and upsert one invoice for an account + month. Idempotent per
     * (exchange, account, month): regenerating updates the existing row.
     *
     * @param  array{realized: float, unrealized: float}  $rates  fee percentages (e.g. 20, 6)
     */
    public function generateForAccount(
        BinanceAccount $account,
        string $monthYear,
        array $rates,
        string $exchange = 'binance'
    ): Invoice {
        // '!Y-m', never 'Y-m': without the '!' PHP fills the missing day from
        // TODAY, so parsing '2026-06' on the 31st overflows to 2026-07-01 and
        // the invoice would bill the wrong month's trades.
        $period = Carbon::createFromFormat('!Y-m', $monthYear);
        $monthStart = $period->copy()->startOfMonth();
        $monthEnd = $period->copy()->endOfMonth();

        $snap = $this->sourceFor($exchange)->periodSnapshot($account, $monthStart, $monthEnd);
        $equityEnd = $snap['balance'] + $snap['unrealized'];

        $prior = Invoice::forExchange($exchange)
            ->where('account_id', $account->id)
            ->where('month_year', '<', $monthYear)
            ->orderByDesc('month_year')
            ->first();
        $isFirst = ! $prior;

        $hwmBefore = $isFirst
            ? ((float) $account->initial_deposit ?: $snap['adjustedDeposit'])
            : (float) $prior->hwm_after;

        $realizedRate = (float) ($rates['realized'] ?? 20) / 100;
        $unrealizedRate = (float) ($rates['unrealized'] ?? 6) / 100;

        // Fee-eligible profit for the period.
        $newRealizedProfit = max(0, $snap['realized']);
        $newUnrealizedProfit = max(0, $snap['unrealized']);

        // Charge only when equity is above the prior high-water mark.
        $chargeable = $equityEnd > $hwmBefore;
        $feeRealized = $chargeable ? $newRealizedProfit * $realizedRate : 0.0;
        $feeUnrealized = $chargeable ? $newUnrealizedProfit * $unrealizedRate : 0.0;
        $totalFee = round($feeRealized + $feeUnrealized, 8);

        $hwmAfter = $chargeable ? max($hwmBefore, $equityEnd) : $hwmBefore;
        $dueDate = $period->copy()->addMonthNoOverflow()->startOfMonth()->addDays(7);

        $key = ['exchange' => $exchange, 'account_id' => $account->id, 'month_year' => $monthYear];
        $existing = Invoice::where($key)->first();

        $attributes = [
            'user_id' => $account->uni_id,
            'api_key' => $account->api_key,
            'equity_start' => $hwmBefore,
            'equity_end' => $equityEnd,
            'realized_pnl' => $snap['realized'],
            'unrealized_pnl' => $snap['unrealized'],
            'deposit_amount' => $snap['adjustedDeposit'],
            'adjusted_equity' => $equityEnd,
            'capital_flow' => $snap['capitalFlow'],
            'performance_equity' => $equityEnd,
            'hwm_before' => $hwmBefore,
            'hwm_after' => $hwmAfter,
            'new_realized_profit' => $newRealizedProfit,
            'new_unrealized_profit' => $newUnrealizedProfit,
            'fee_realized' => $feeRealized,
            'fee_unrealized' => $feeUnrealized,
            'total_fee' => $totalFee,
            'due_date' => $dueDate->toDateString(),
        ];

        // Regenerating a month refreshes the figures but must never wipe a real
        // payment — a paid row keeps its status and its provenance.
        if (! $existing || $existing->status !== 'paid') {
            $attributes['status'] = $totalFee > 0 ? 'pending' : 'paid';
        }

        return Invoice::updateOrCreate($key, $attributes);
    }

    /**
     * Shape a full set of invoices for the frontend. "First invoice" is the
     * earliest month per (exchange, account) within the set — so index endpoints
     * that load the complete relevant set compute it without a query per row.
     */
    public function mapList($invoices): array
    {
        $firstMonth = [];
        foreach ($invoices as $inv) {
            $k = $inv->exchange.'|'.$inv->account_id;
            if (! isset($firstMonth[$k]) || $inv->month_year < $firstMonth[$k]) {
                $firstMonth[$k] = $inv->month_year;
            }
        }

        return $invoices->map(function ($inv) use ($firstMonth) {
            $k = $inv->exchange.'|'.$inv->account_id;

            return $inv->toApiArray($inv->account?->name, $inv->month_year === $firstMonth[$k]);
        })->values()->all();
    }

    /** Whether an invoice is the account's earliest (single-row lookups). */
    public function isFirstInvoice(Invoice $invoice): bool
    {
        return ! Invoice::forExchange($invoice->exchange)
            ->where('account_id', $invoice->account_id)
            ->where('month_year', '<', $invoice->month_year)
            ->exists();
    }

    /**
     * Mark an invoice paid — idempotently. Locks the row, no-ops if already paid,
     * flips status, records who took the money, and re-enables the owning
     * account. HWM already lives on the row from generation, so nothing else
     * recomputes.
     *
     * Every parameter after the invoice is optional, so the admin manual path
     * (`settle($invoice, 'manual')`) is unchanged. The lock + already-paid
     * short-circuit still runs first, so a replayed webhook returns false and
     * cannot overwrite the provenance: the FIRST successful settlement owns it.
     *
     * @param  string       $method     'manual' | 'stripe' | 'coinsbuy'
     * @param  string|null  $reference  provider handle (cs_… / Coinsbuy deposit id)
     * @param  float|null   $paid       amount actually received; defaults to total_fee
     * @return bool  true if this call did the settling, false if already settled
     */
    public function settle(
        Invoice $invoice,
        string $method = 'manual',
        ?string $reference = null,
        ?float $paid = null
    ): bool {
        $settled = DB::transaction(function () use ($invoice, $method, $reference, $paid) {
            $locked = Invoice::whereKey($invoice->getKey())->lockForUpdate()->first();
            if (! $locked || $locked->status === 'paid') {
                return false;
            }

            $locked->status = 'paid';
            $locked->paid_at = now();
            $locked->payment_provider = $method;
            $locked->payment_reference = $reference;
            $locked->paid_amount = $paid ?? $locked->feeCents() / 100;
            $locked->save();

            if ($locked->account_id) {
                BinanceAccount::whereKey($locked->account_id)->update(['enabled' => 1]);
            }

            return true;
        });

        // AFTER the commit, never inside it: the engine reloads the moment it
        // is told to, and from inside the transaction it would read the
        // pre-commit rows and cache the account as still disabled — the exact
        // staleness this is meant to remove. Trading resumes on the next signal
        // rather than at the next TTL expiry.
        if ($settled) {
            $this->engineCache->refreshAccounts();
        }

        return $settled;
    }
}
