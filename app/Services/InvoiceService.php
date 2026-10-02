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
     * Why this account must never be invoiced, or null when it may be.
     *
     * The MASTER account is the house's own trading (2026-09-25, owner's
     * decision): it publishes the track record, it pays nobody a fee. Checked
     * here, in the one place invoices are made, so the admin screen today and
     * the monthly scheduler later cannot disagree about it. Invoice-scenario
     * scratch accounts (`SBXINV-`) are exempt — the runner may be pointed at
     * any user, the master included, and never bills anyone real.
     *
     * `$sandbox` (2026-09-30, owner's request) lifts the master rule for Admin →
     * Sandbox → Invoice Testing ONLY, so a real payment can be rehearsed on the
     * house's own account. It is an explicit opt-in per request, never a
     * default: the monthly run and every other caller still refuse the master.
     * The other half of making that safe is engine:mark-overdue, which never
     * disables a master's account — a test invoice left unpaid must not stop
     * the house trading.
     */
    public static function notInvoiceableReason(BinanceAccount $account, bool $sandbox = false): ?string
    {
        if ($sandbox || str_starts_with((string) $account->api_key, 'SBXINV-')) {
            return null;
        }
        $type = DB::table('user_credentials')->where('uni_id', $account->uni_id)->value('type');

        return $type === 'master' ? 'The master account is never invoiced.' : null;
    }

    /**
     * Compute and upsert one invoice for an account + month. Idempotent per
     * (exchange, account, month): regenerating updates the existing row.
     *
     * @param  array{realized: float, unrealized: float}  $rates  fee percentages (e.g. 20, 6)
     *
     * @throws \DomainException for an account that is never invoiced
     */
    public function generateForAccount(
        BinanceAccount $account,
        string $monthYear,
        array $rates,
        string $exchange = 'binance',
        bool $sandbox = false
    ): Invoice {
        if ($reason = self::notInvoiceableReason($account, $sandbox)) {
            throw new \DomainException($reason);
        }

        $attributes = $this->computeForAccount($account, $monthYear, $rates, $exchange);
        // Regenerating from P&L replaces a manual fee on an unpaid row, so the
        // row must stop claiming it was typed by hand.
        $attributes['fee_source'] = 'pnl';

        $key = ['exchange' => $exchange, 'account_id' => $account->id, 'month_year' => $monthYear];
        $existing = Invoice::where($key)->first();

        // Regenerating a month refreshes the figures but must never wipe a real
        // payment — a paid row keeps its status and its provenance.
        if (! $existing || $existing->status !== 'paid') {
            $attributes['status'] = $attributes['total_fee'] > 0 ? 'pending' : 'paid';
        }

        return Invoice::updateOrCreate($key, $attributes);
    }

    /**
     * Create (or replace an unpaid) invoice for an account + month whose fee
     * an admin TYPED rather than one computed from P&L — a one-off charge, or
     * a real payment test at a chosen amount.
     *
     * It is still the month's invoice: the performance figures and the HWM
     * are computed exactly as generateForAccount would, so next month's
     * invoice chains from the same watermark either way. Only the fee differs
     * — total_fee is the typed amount, the realized/unrealized split is zero
     * (a split that does not add up to the total would be a second, divergent
     * statement of what is owed), and fee_source says so.
     *
     * Due on the 4th of the month after the billing month — the same due
     * date as the monthly run (owner, 2026-10-02). When that 4th has already
     * passed (an invoice issued late), it is due 3 days from TODAY instead:
     * a past due date would be born overdue, and engine:mark-overdue would
     * disable the account the same night.
     *
     * @throws \DomainException for an account that is never invoiced, or when
     *                          that month's invoice is already paid
     */
    public function generateManual(
        BinanceAccount $account,
        string $monthYear,
        float $amount,
        string $exchange = 'binance',
        bool $sandbox = false
    ): Invoice {
        if ($reason = self::notInvoiceableReason($account, $sandbox)) {
            throw new \DomainException($reason);
        }

        $key = ['exchange' => $exchange, 'account_id' => $account->id, 'month_year' => $monthYear];
        $existing = Invoice::where($key)->first();
        // A zero-fee month is stored 'paid' by generateForAccount with nothing
        // collected, so only a paid row that actually charged something is final.
        if ($existing && $existing->isPaid() && $existing->feeCents() > 0) {
            throw new \DomainException('That month\'s invoice for this account is already paid.');
        }

        $attributes = $this->computeForAccount($account, $monthYear, ['realized' => 0, 'unrealized' => 0], $exchange);

        return Invoice::updateOrCreate($key, array_merge($attributes, [
            'fee_realized' => 0,
            'fee_unrealized' => 0,
            'total_fee' => round($amount, 2),
            'fee_source' => 'manual',
            'status' => 'pending',
            'due_date' => self::manualDueDate($monthYear)->toDateString(),
        ]));
    }

    /** See generateManual: the billing 4th, or today + 3 once it has passed. */
    public static function manualDueDate(string $monthYear): Carbon
    {
        $fourth = Carbon::createFromFormat('!Y-m', $monthYear)->addMonthNoOverflow()->startOfMonth()->addDays(3);

        return $fourth->lt(today()) ? today()->addDays(3) : $fourth;
    }

    /**
     * The invoice figures for an account + month, WITHOUT writing anything —
     * the one place the fee math lives. `generateForAccount` persists it; the
     * admin Money tab's "Future invoice" forecast (AdminInsights::forecast)
     * reads it for the running month, so a forecast can never drift from the
     * invoice the customer is eventually sent.
     *
     * @param  array{realized: float, unrealized: float}  $rates
     * @return array<string, mixed>  the invoice row's attributes (no key, no status)
     */
    public function computeForAccount(
        object $account,
        string $monthYear,
        array $rates,
        string $exchange = 'binance'
    ): array {
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
        // Due the 4th of the following month (owner, 2026-10-01): invoiced the
        // 1st, reminded the 2nd and 3rd, paused on the 4th if still unpaid.
        $dueDate = $period->copy()->addMonthNoOverflow()->startOfMonth()->addDays(3);

        return [
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
    }

    /** Whether a P&L source exists for this exchange yet — keep in step with sourceFor(). */
    public static function canInvoice(string $exchange): bool
    {
        return $exchange === 'binance';
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
