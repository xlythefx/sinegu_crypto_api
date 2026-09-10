<?php

namespace App\Http\Controllers;

use App\Models\BinanceAccount;
use App\Models\Invoice;
use App\Models\UserCredential;
use App\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Invoice scenario runner for the admin sandbox (auth:sanctum + admin).
 *
 * Each scenario is a full round trip: reset a throwaway account to a known
 * state, seed real `binance_pastpositions` / `binance_transactions` rows for
 * the billing month, run the SAME `InvoiceService::generateForAccount` the
 * product uses, then assert the resulting invoice against hard-coded expected
 * figures. That makes the billing rules — high-water mark, loss months,
 * deposits, idempotency — regression-testable from the browser.
 *
 * Safety: every write lands on a dedicated sandbox account (api_key prefix
 * `SBXINV-`) created under the selected user. Real exchange accounts, their
 * trade history and their invoices are never touched by a run.
 */
class SandboxInvoiceController extends Controller
{
    public function __construct(private InvoiceService $invoices) {}

    /** Scenarios run at the standard shares so expected figures stay literal. */
    private const RATES = ['realized' => 20.0, 'unrealized' => 6.0];

    private const EXCHANGE = 'binance';

    /** api_key prefix that marks an account as scenario-runner scratch space. */
    private const ACCOUNT_PREFIX = 'SBXINV-';

    /** Tolerance for money comparisons (half a cent). */
    private const EPSILON = 0.005;

    /** Human labels for the asserted invoice columns. */
    private const LABELS = [
        'hwm_before' => 'HWM before',
        'hwm_after' => 'HWM after',
        'realized_pnl' => 'Realized P&L',
        'unrealized_pnl' => 'Unrealized P&L',
        'equity_end' => 'Equity at month end',
        'deposit_amount' => 'Adjusted deposit',
        'capital_flow' => 'Capital flow',
        'fee_realized' => 'Fee on realized',
        'fee_unrealized' => 'Fee on unrealized',
        'total_fee' => 'Total fee',
        'status' => 'Status',
        'paid_amount' => 'Amount collected',
        'invoice_count' => 'Invoice rows for the month',
    ];

    /**
     * The catalogue. `setup` is the world we build before invoicing; `expect`
     * is what the invoice must come out as. Every number below was derived by
     * hand from the billing rules, NOT from the code under test.
     *
     * @return array<int, array<string, mixed>>
     */
    private function scenarios(): array
    {
        return [
            [
                'key' => 'first_profit',
                'title' => 'First invoice — realized + unrealized profit',
                'summary' => 'No earlier invoice, so the high-water mark starts at the initial deposit. Both profit kinds are billed.',
                'note' => null,
                'setup' => [
                    'initial_deposit' => 10000, 'balance' => 11000, 'unrealized' => 500,
                    'trades' => [600, 400], 'deposits' => [], 'prior_hwm' => null,
                ],
                'expect' => [
                    'hwm_before' => 10000, 'realized_pnl' => 1000, 'unrealized_pnl' => 500,
                    'equity_end' => 11500, 'fee_realized' => 200, 'fee_unrealized' => 30,
                    'total_fee' => 230, 'hwm_after' => 11500, 'status' => 'pending',
                ],
            ],
            [
                'key' => 'realized_only',
                'title' => 'Closed trades only — nothing open',
                'summary' => 'A month with no open positions bills the 20% share and nothing else.',
                'note' => null,
                'setup' => [
                    'initial_deposit' => 10000, 'balance' => 11000, 'unrealized' => 0,
                    'trades' => [1000], 'deposits' => [], 'prior_hwm' => null,
                ],
                'expect' => [
                    'realized_pnl' => 1000, 'unrealized_pnl' => 0,
                    'fee_realized' => 200, 'fee_unrealized' => 0,
                    'total_fee' => 200, 'hwm_after' => 11000, 'status' => 'pending',
                ],
            ],
            [
                'key' => 'unrealized_only',
                'title' => 'Open profit only — no trade closed',
                'summary' => 'Paper profit is billed at the lower 6% share even when nothing was closed all month.',
                'note' => null,
                'setup' => [
                    'initial_deposit' => 10000, 'balance' => 10000, 'unrealized' => 2000,
                    'trades' => [], 'deposits' => [], 'prior_hwm' => null,
                ],
                'expect' => [
                    'realized_pnl' => 0, 'unrealized_pnl' => 2000,
                    'fee_realized' => 0, 'fee_unrealized' => 120,
                    'total_fee' => 120, 'hwm_after' => 12000, 'status' => 'pending',
                ],
            ],
            [
                'key' => 'loss_month',
                'title' => 'Losing month — nothing to bill',
                'summary' => 'Equity ends below the mark, so the fee is zero and the invoice closes itself.',
                'note' => 'A zero-fee invoice is written as already paid, so it never shows up as outstanding.',
                'setup' => [
                    'initial_deposit' => 10000, 'balance' => 8500, 'unrealized' => 0,
                    'trades' => [-1500], 'deposits' => [], 'prior_hwm' => null,
                ],
                'expect' => [
                    'realized_pnl' => -1500, 'equity_end' => 8500,
                    'fee_realized' => 0, 'fee_unrealized' => 0, 'total_fee' => 0,
                    'hwm_before' => 10000, 'hwm_after' => 10000, 'status' => 'paid',
                ],
            ],
            [
                'key' => 'below_hwm',
                'title' => 'Profitable, but still under the old peak',
                'summary' => 'The account made 1,000 this month yet sits below its 15,000 peak — the whole point of the high-water mark.',
                'note' => 'Profit earned while recovering is free, and the peak does not move.',
                'setup' => [
                    'initial_deposit' => 10000, 'balance' => 12000, 'unrealized' => 0,
                    'trades' => [1000], 'deposits' => [], 'prior_hwm' => 15000,
                ],
                'expect' => [
                    'hwm_before' => 15000, 'realized_pnl' => 1000, 'equity_end' => 12000,
                    'fee_realized' => 0, 'fee_unrealized' => 0, 'total_fee' => 0,
                    'hwm_after' => 15000, 'status' => 'paid',
                ],
            ],
            [
                'key' => 'recovery_above_hwm',
                'title' => 'Climbs back past the peak',
                'summary' => 'Equity finally clears the 15,000 mark, so billing resumes and the peak is reset.',
                'note' => 'The fee is 20% of the FULL 6,000 realized, not of the 1,000 that sits above the old peak. Worth confirming that is the intended policy.',
                'setup' => [
                    'initial_deposit' => 10000, 'balance' => 16000, 'unrealized' => 0,
                    'trades' => [6000], 'deposits' => [], 'prior_hwm' => 15000,
                ],
                'expect' => [
                    'hwm_before' => 15000, 'realized_pnl' => 6000, 'equity_end' => 16000,
                    'fee_realized' => 1200, 'total_fee' => 1200,
                    'hwm_after' => 16000, 'status' => 'pending',
                ],
            ],
            [
                'key' => 'month_chaining',
                'title' => 'The peak carries from last month, not the deposit',
                'summary' => 'With a prior invoice on file the mark comes from its closing HWM (12,000), not the 10,000 initial deposit.',
                'note' => null,
                'setup' => [
                    'initial_deposit' => 10000, 'balance' => 12300, 'unrealized' => 0,
                    'trades' => [500], 'deposits' => [], 'prior_hwm' => 12000,
                ],
                'expect' => [
                    'hwm_before' => 12000, 'realized_pnl' => 500,
                    'fee_realized' => 100, 'total_fee' => 100,
                    'hwm_after' => 12300, 'status' => 'pending',
                ],
            ],
            [
                'key' => 'deposit_mid_month',
                'title' => 'Deposit mid-month — new capital is not profit',
                'summary' => 'The user wires in 5,000 and also trades 1,000 in profit. Only the trading profit may be billed.',
                'note' => 'The deposit is excluded from the fee but it does lift the high-water mark to 16,000 — the next month has to clear the higher bar.',
                'setup' => [
                    'initial_deposit' => 10000, 'balance' => 16000, 'unrealized' => 0,
                    'trades' => [1000], 'deposits' => [5000], 'prior_hwm' => null,
                ],
                'expect' => [
                    'hwm_before' => 10000, 'realized_pnl' => 1000, 'capital_flow' => 5000,
                    'deposit_amount' => 15000, 'equity_end' => 16000,
                    'fee_realized' => 200, 'total_fee' => 200,
                    'hwm_after' => 16000, 'status' => 'pending',
                ],
            ],
            [
                'key' => 'regenerate_idempotent',
                'title' => 'Generating twice does not double-bill',
                'summary' => 'The same month is generated twice; the second run must update the existing row instead of adding a second invoice.',
                'note' => null,
                'after' => 'regenerate',
                'setup' => [
                    'initial_deposit' => 10000, 'balance' => 11000, 'unrealized' => 500,
                    'trades' => [600, 400], 'deposits' => [], 'prior_hwm' => null,
                ],
                'expect' => [
                    'invoice_count' => 1, 'total_fee' => 230,
                    'hwm_after' => 11500, 'status' => 'pending',
                ],
            ],
            [
                'key' => 'paid_protected',
                'title' => 'Regenerating a paid month keeps it paid',
                'summary' => 'The invoice is settled, then more trades land and the month is regenerated. The payment must survive.',
                'note' => 'The status and the collected amount are protected, but the figures are refreshed — the row now reads a 430 fee against 230 actually collected. Decide whether a settled month should be frozen instead.',
                'after' => 'settle_then_regenerate',
                'mutate' => ['trades' => [1000], 'balance' => 12000],
                'setup' => [
                    'initial_deposit' => 10000, 'balance' => 11000, 'unrealized' => 500,
                    'trades' => [600, 400], 'deposits' => [], 'prior_hwm' => null,
                ],
                'expect' => [
                    'invoice_count' => 1, 'status' => 'paid', 'paid_amount' => 230,
                    'realized_pnl' => 2000, 'total_fee' => 430, 'hwm_after' => 12500,
                ],
            ],
        ];
    }

    /** GET /api/admin/sandbox/invoice-scenarios — the catalogue, no side effects. */
    public function catalogue(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'rates' => self::RATES,
            'scenarios' => array_map(fn ($s) => [
                'key' => $s['key'],
                'title' => $s['title'],
                'summary' => $s['summary'],
                'note' => $s['note'] ?? null,
                'setup' => $s['setup'],
            ], $this->scenarios()),
        ]);
    }

    /**
     * POST /api/admin/sandbox/invoice-scenarios/run
     * Body: { uni_id, keys?: string[], month_year?: 'YYYY-MM', cleanup?: bool }
     */
    public function run(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'uni_id' => ['required', 'string', 'exists:user_credentials,uni_id'],
            'keys' => ['nullable', 'array'],
            'keys.*' => ['string'],
            'month_year' => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
            'cleanup' => ['nullable', 'boolean'],
        ]);

        $monthYear = $validated['month_year'] ?? Carbon::now()->subMonthNoOverflow()->format('Y-m');
        $wanted = $validated['keys'] ?? [];

        $specs = $this->scenarios();
        if ($wanted) {
            $specs = array_values(array_filter($specs, fn ($s) => in_array($s['key'], $wanted, true)));
            if (! $specs) {
                return response()->json([
                    'success' => false,
                    'error_code' => 'UNKNOWN_SCENARIO',
                    'message' => 'None of the requested scenario keys exist.',
                ], 422);
            }
        }

        $account = $this->scenarioAccount($validated['uni_id']);

        $results = [];
        foreach ($specs as $spec) {
            $results[] = $this->runOne($spec, $account, $monthYear);
        }

        if ($validated['cleanup'] ?? false) {
            $this->wipeAccount($account);
            $account->forceDelete();
        }

        return response()->json([
            'success' => true,
            'month_year' => $monthYear,
            'rates' => self::RATES,
            'cleaned_up' => (bool) ($validated['cleanup'] ?? false),
            'account' => [
                'id' => $account->id,
                'name' => $account->name,
                'api_key' => $account->api_key,
            ],
            'passed' => count(array_filter($results, fn ($r) => $r['pass'])),
            'failed' => count(array_filter($results, fn ($r) => ! $r['pass'])),
            'results' => $results,
        ]);
    }

    /**
     * DELETE /api/admin/sandbox/users/{uniId}/invoices  (one user)
     * DELETE /api/admin/sandbox/invoices                (every user)
     *
     * Query: account_id (optional — one account instead of all of them),
     *        include_paid (default false — settled invoices are spared).
     *
     * The global form exists because clearing test invoices one user at a time
     * is how a stale row gets left behind; `$uniId` being null is what makes it
     * global, so the two modes cannot be confused for one another by a typo in
     * a query string. `include_paid` still defaults to FALSE in both: a settled
     * invoice is a payment record, and the wider the scope the more that
     * matters.
     */
    public function clearInvoices(Request $request, ?string $uniId = null): JsonResponse
    {
        if ($uniId !== null && ! UserCredential::find($uniId)) {
            return response()->json([
                'success' => false,
                'error_code' => 'USER_NOT_FOUND',
                'message' => 'User not found.',
            ], 404);
        }

        $includePaid = filter_var($request->query('include_paid', 'false'), FILTER_VALIDATE_BOOLEAN);
        $accountId = $request->query('account_id');

        $base = Invoice::query()
            ->when($uniId !== null, fn ($q) => $q->where('user_id', $uniId))
            ->when($accountId, fn ($q) => $q->where('account_id', (int) $accountId));

        $skippedPaid = $includePaid ? 0 : (clone $base)->where('status', 'paid')->count();

        $target = (clone $base)
            ->when(! $includePaid, fn ($q) => $q->where('status', '!=', 'paid'));

        // Counted before the delete — afterwards there is nothing left to count,
        // and "across N users" is the figure that tells an admin whether the
        // global wipe hit the scope they meant.
        $users = (clone $target)->distinct()->count('user_id');
        $deleted = $target->delete();

        return response()->json([
            'success' => true,
            'scope' => $uniId === null ? 'all' : 'user',
            'deleted' => $deleted,
            'skipped_paid' => $skippedPaid,
            'users' => $users,
        ]);
    }

    /**
     * DELETE /api/admin/sandbox/users/{uniId}/scenario-account
     * Remove the scratch account a run created, with everything it owns.
     */
    public function clearScenarioData(Request $request, string $uniId): JsonResponse
    {
        $account = BinanceAccount::withTrashed()
            ->where('uni_id', $uniId)
            ->where('api_key', 'like', self::ACCOUNT_PREFIX.'%')
            ->first();

        if (! $account) {
            return response()->json(['success' => true, 'deleted' => false]);
        }

        $counts = $this->wipeAccount($account);
        $account->forceDelete();

        return response()->json([
            'success' => true,
            'deleted' => true,
            'removed' => $counts,
        ]);
    }

    /* ============================ internals ============================ */

    /**
     * First instant of a 'YYYY-MM' period. The '!' matters: without it PHP
     * fills the missing day from today, so '2026-06' parsed on the 31st
     * overflows into July and every date derived from it is a month out.
     */
    private function monthStart(string $monthYear): Carbon
    {
        return Carbon::createFromFormat('!Y-m', $monthYear);
    }

    /** Resolve (or create) the user's scenario scratch account. */
    private function scenarioAccount(string $uniId): BinanceAccount
    {
        $account = BinanceAccount::withTrashed()
            ->where('uni_id', $uniId)
            ->where('api_key', 'like', self::ACCOUNT_PREFIX.'%')
            ->first();

        if ($account) {
            if ($account->trashed()) {
                $account->restore();
            }

            return $account;
        }

        $user = UserCredential::find($uniId);

        do {
            $apiKey = self::ACCOUNT_PREFIX.substr($uniId, 0, 8).'-'.Str::random(4);
        } while (BinanceAccount::withTrashed()->where('api_key', $apiKey)->exists());

        do {
            $name = 'Invoice Scenario '.($user->name ?: substr($uniId, 0, 6)).' '.Str::random(4);
        } while (BinanceAccount::withTrashed()->where('name', $name)->exists());

        return BinanceAccount::create([
            'api_key' => $apiKey,
            'uni_id' => $uniId,
            'secret_key' => 'sandbox',
            'name' => $name,
            'balance' => 0,
            'unrealized_pnl' => 0,
            'initial_deposit' => 0,
            'currency_type' => 'USDT',
            'demo' => 1,
            'enabled' => 1,
            'is_sandbox' => true,
            'created_at' => now(),
        ]);
    }

    /** Delete everything the scratch account owns. Returns per-table counts. */
    private function wipeAccount(BinanceAccount $account): array
    {
        return [
            'positions' => DB::table('binance_pastpositions')->where('api_key', $account->api_key)->delete(),
            'transactions' => DB::table('binance_transactions')->where('api_key', $account->api_key)->delete(),
            'invoices' => Invoice::where('account_id', $account->id)->delete(),
        ];
    }

    /** Build the world a scenario describes, then invoice it and assert. */
    private function runOne(array $spec, BinanceAccount $account, string $monthYear): array
    {
        $setup = $spec['setup'];
        $steps = [];

        $this->wipeAccount($account);
        $steps[] = 'Reset the account — cleared its positions, transfers and invoices.';

        $account->forceFill([
            'balance' => $setup['balance'],
            'unrealized_pnl' => $setup['unrealized'],
            'initial_deposit' => $setup['initial_deposit'],
            'enabled' => 1,
        ])->save();
        $steps[] = \sprintf(
            'Set deposit %s, balance %s, open P&L %s.',
            number_format((float) $setup['initial_deposit'], 2),
            number_format((float) $setup['balance'], 2),
            number_format((float) $setup['unrealized'], 2),
        );

        if ($setup['prior_hwm'] !== null) {
            $this->seedPriorInvoice($account, $monthYear, (float) $setup['prior_hwm']);
            $steps[] = \sprintf('Seeded last month\'s invoice with a %s high-water mark.', number_format((float) $setup['prior_hwm'], 2));
        }

        if ($setup['trades']) {
            $this->seedTrades($account, $monthYear, $setup['trades']);
            $steps[] = \sprintf('Inserted %d closed trade(s) into %s.', count($setup['trades']), $monthYear);
        }

        if ($setup['deposits']) {
            $this->seedTransfers($account, $monthYear, $setup['deposits']);
            $steps[] = \sprintf('Recorded %d deposit(s) inside the month.', count($setup['deposits']));
        }

        // The product path — exactly what the monthly job and the manual form call.
        $invoice = $this->invoices->generateForAccount($account->refresh(), $monthYear, self::RATES, self::EXCHANGE);
        $steps[] = 'Generated the invoice.';

        $after = $spec['after'] ?? null;

        if ($after === 'settle_then_regenerate') {
            $this->invoices->settle($invoice, 'manual');
            $steps[] = 'Marked it paid.';

            $mutate = $spec['mutate'] ?? [];
            if (! empty($mutate['trades'])) {
                $this->seedTrades($account, $monthYear, $mutate['trades'], 20);
                $steps[] = \sprintf('Added %d more closed trade(s) after payment.', count($mutate['trades']));
            }
            if (isset($mutate['balance'])) {
                $account->forceFill(['balance' => $mutate['balance']])->save();
                $steps[] = \sprintf('Balance moved to %s.', number_format((float) $mutate['balance'], 2));
            }
        }

        if ($after === 'regenerate' || $after === 'settle_then_regenerate') {
            $invoice = $this->invoices->generateForAccount($account->refresh(), $monthYear, self::RATES, self::EXCHANGE);
            $steps[] = 'Generated the same month a second time.';
        }

        $invoice->refresh();

        $invoiceCount = Invoice::where('exchange', self::EXCHANGE)
            ->where('account_id', $account->id)
            ->where('month_year', $monthYear)
            ->count();

        $checks = $this->assert($spec['expect'], $invoice, $invoiceCount);

        return [
            'key' => $spec['key'],
            'title' => $spec['title'],
            'summary' => $spec['summary'],
            'note' => $spec['note'] ?? null,
            'setup' => $setup,
            'steps' => $steps,
            'checks' => $checks,
            'pass' => ! in_array(false, array_column($checks, 'pass'), true),
            'invoice' => $invoice->toApiArray($account->name, $setup['prior_hwm'] === null),
        ];
    }

    /** Compare every expected column against what actually landed on the row. */
    private function assert(array $expect, Invoice $invoice, int $invoiceCount): array
    {
        $checks = [];

        foreach ($expect as $field => $want) {
            if ($field === 'invoice_count') {
                $got = $invoiceCount;
                $pass = $got === (int) $want;
            } elseif ($field === 'status') {
                $got = (string) $invoice->status;
                $pass = $got === (string) $want;
            } else {
                $got = round((float) $invoice->{$field}, 2);
                $want = round((float) $want, 2);
                $pass = abs($got - $want) <= self::EPSILON;
            }

            $checks[] = [
                'field' => $field,
                'label' => self::LABELS[$field] ?? $field,
                'expected' => $want,
                'actual' => $got,
                'pass' => $pass,
            ];
        }

        return $checks;
    }

    /** Write the previous month's invoice so the HWM has something to chain from. */
    private function seedPriorInvoice(BinanceAccount $account, string $monthYear, float $hwm): void
    {
        $prior = $this->monthStart($monthYear)->subMonthNoOverflow();

        Invoice::updateOrCreate(
            [
                'exchange' => self::EXCHANGE,
                'account_id' => $account->id,
                'month_year' => $prior->format('Y-m'),
            ],
            [
                'user_id' => $account->uni_id,
                'api_key' => $account->api_key,
                'equity_start' => $hwm,
                'equity_end' => $hwm,
                'realized_pnl' => 0,
                'unrealized_pnl' => 0,
                'deposit_amount' => $account->initial_deposit,
                'adjusted_equity' => $hwm,
                'capital_flow' => 0,
                'performance_equity' => $hwm,
                'hwm_before' => $hwm,
                'hwm_after' => $hwm,
                'new_realized_profit' => 0,
                'new_unrealized_profit' => 0,
                'fee_realized' => 0,
                'fee_unrealized' => 0,
                'total_fee' => 0,
                'status' => 'paid',
                'due_date' => $prior->copy()->addMonthNoOverflow()->startOfMonth()->addDays(7)->toDateString(),
            ]
        );
    }

    /**
     * Insert one closed position per P&L figure, spread across the billing
     * month. Entry/exit prices are back-solved so the row stays coherent.
     */
    private function seedTrades(BinanceAccount $account, string $monthYear, array $pnls, int $dayOffset = 0): void
    {
        $monthStart = $this->monthStart($monthYear);
        $daysInMonth = $monthStart->daysInMonth;
        $orderBase =(int) (now()->timestamp.str_pad((string) mt_rand(0, 999), 3, '0', STR_PAD_LEFT));

        $rows = [];
        foreach (array_values($pnls) as $i => $pnl) {
            $pnl = (float) $pnl;
            $side = $pnl >= 0 ? 'LONG' : 'SHORT';
            $amt = 1.0;
            $entry = 30000.0;
            $move = $pnl / $amt;
            $exit = round($side === 'LONG' ? $entry + $move : $entry - $move, 8);

            // Day 3, 8, 13 … clamped inside the month so `closed_at` always lands
            // in the period the invoice bills for.
            $day = min($daysInMonth, 3 + $dayOffset + ($i * 5));

            $rows[] = [
                'api_key' => $account->api_key,
                'uni_id' => $account->uni_id,
                'symbol' => 'BTCUSDT',
                'position_side' => $side,
                'position_amt' => $amt,
                'entry_price' => $entry,
                'exit_price' => $exit,
                'realized_pnl' => $pnl,
                'side' => $side === 'LONG' ? 'SELL' : 'BUY',
                'order_id' => $orderBase + $i,
                'closed_at' => $monthStart->copy()->setDay($day)->setTime(12, 0),
                'strategy' => 'Scenario',
                'is_sandbox' => true,
                'created_at' => now(),
            ];
        }

        DB::table('binance_pastpositions')->insert($rows);
    }

    /** Record wallet deposits inside the billing month. */
    private function seedTransfers(BinanceAccount $account, string $monthYear, array $amounts): void
    {
        $monthStart = $this->monthStart($monthYear);
        $daysInMonth = $monthStart->daysInMonth;
        $tranBase =(int) (now()->timestamp.str_pad((string) mt_rand(0, 999), 3, '0', STR_PAD_LEFT));

        $rows = [];
        foreach (array_values($amounts) as $i => $amount) {
            $at = $monthStart->copy()->setDay(min($daysInMonth, 10 + $i))->setTime(9, 0);

            $rows[] = [
                'api_key' => $account->api_key,
                'uni_id' => $account->uni_id,
                'type' => (float) $amount >= 0 ? 'DEPOSIT' : 'WITHDRAWAL',
                'amount' => abs((float) $amount),
                'balance_after' => null,
                'tran_id' => $tranBase + $i,
                'currency' => 'USDT',
                'transaction_time' => $at->timestamp * 1000,
                'info' => 'SCENARIO',
                'created_at' => $at,
            ];
        }

        DB::table('binance_transactions')->insert($rows);
    }
}
