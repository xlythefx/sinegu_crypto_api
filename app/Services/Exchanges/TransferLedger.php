<?php

namespace App\Services\Exchanges;

use App\Models\ExchangeAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Makes an account's stored capital agree with the exchange's own ledger:
 * every transfer the exchange still reports is stored, and `initial_deposit`
 * becomes ONLY the money the account held before the oldest of them.
 *
 * The ledger is read by the engine (`binance_abcd/ledger.py`) and reconciles
 * to the wallet to the cent: opening balance = wallet − Σ every income row.
 * Binance keeps about six months of it; for an account younger than that the
 * opening balance is 0 and every dollar is a real, dated transfer.
 *
 * Why it exists — two faults in the old model, both seen on prod 2026-09-25:
 *  - The transfers poller reads the last 3 days only, so anything older than
 *    an account's connection was never stored (the master's Mar 25 / Apr 20 /
 *    May 8 transfers), and `initial_deposit` had to stand in for it.
 *  - `initial_deposit` was the WALLET at the first poll, which already held
 *    the deposit that funded it — and the poller then stored that deposit
 *    too. Two customers were counted at twice their capital.
 *
 * Two callers, one rule:
 *  - the engine's first balance poll of a NEW account ({@see seedNew()}),
 *    which only ever fills an unset figure;
 *  - Admin → API Keys → "Transfer history" ({@see plan()} then
 *    {@see apply()}), which corrects an existing account after showing the
 *    admin the exact before → after.
 *
 * What moves when it is applied: `initial_deposit` + the transfers table are
 * the capital base for the deposit gate (`total_deposit`), invoicing
 * (`BinancePnlSource::adjustedDeposit`), Performance Analytics and the public
 * track record. Issued invoices keep their own snapshot and are not touched.
 */
class TransferLedger
{
    /** Below this the opening balance is rounding, not money. */
    private const TOLERANCE = 0.01;

    /**
     * What applying this ledger would do, without doing it.
     *
     * @param  array<string, mixed>  $ledger  the engine's payload
     * @return array<string, mixed>
     */
    public function plan(ExchangeAccount $account, string $exchange, array $ledger): array
    {
        $schema = ExchangeSchema::for($exchange);
        $problems = [];

        if (! empty($ledger['truncated'])) {
            $problems[] = 'The exchange history was too long to read completely.';
        }
        if (! empty($ledger['non_usdt_rows']) || ! empty($ledger['other_wallets'])) {
            $problems[] = 'The account holds or moved money in a currency other than USDT, which cannot be added into one USDT balance.';
        }

        $ledgerStart = isset($ledger['ledger_start']) ? (int) $ledger['ledger_start'] : null;
        $opening = (float) ($ledger['opening_balance'] ?? 0);

        // Binance's transfers, keyed by its own id.
        $binance = [];
        foreach ($ledger['transfers'] ?? [] as $t) {
            $binance[(string) $t['tran_id']] = $t;
        }

        $stored = DB::table($schema->transactions)
            ->where('api_key', $account->api_key)
            ->get(['id', 'type', 'amount', 'tran_id', 'transaction_time', 'created_at']);

        $storedIds = [];
        $beforeWindow = 0.0;        // stored transfers older than anything Binance still shows
        $unknownInWindow = [];      // stored, inside Binance's window, but not in its ledger
        foreach ($stored as $row) {
            $storedIds[(string) $row->tran_id] = true;
            $at = $row->transaction_time !== null
                ? (int) $row->transaction_time
                : Carbon::parse($row->created_at, 'UTC')->getTimestampMs();
            if (isset($binance[(string) $row->tran_id])) {
                continue;
            }
            if ($ledgerStart !== null && $at < $ledgerStart) {
                $beforeWindow += $this->signed($row->type, (float) $row->amount);
            } else {
                $unknownInWindow[] = [
                    'tran_id' => (string) $row->tran_id,
                    'type' => $row->type,
                    'amount' => round((float) $row->amount, 8),
                    'at' => $this->iso($at),
                ];
            }
        }
        if ($unknownInWindow !== []) {
            // Binance's ledger reconciles to the wallet; a stored transfer it
            // does not know about would be counted on top of it.
            $problems[] = count($unknownInWindow).' stored transfer(s) are not in the exchange\'s history. Check them before changing anything.';
        }

        // The money older than every stored transfer — the only thing
        // `initial_deposit` may still stand for. Stored transfers from before
        // the exchange's window are part of the opening balance already, so
        // they come off it rather than being counted twice.
        $initialAfter = $opening - $beforeWindow;
        if (abs($initialAfter) < self::TOLERANCE) {
            $initialAfter = 0.0;
        }
        if ($initialAfter < 0) {
            $problems[] = 'The exchange history does not add up to the current balance (it would leave a negative starting balance of '
                .number_format($initialAfter, 2).').';
        }

        $transfers = [];
        foreach ($binance as $id => $t) {
            $transfers[] = [
                'tran_id' => (string) $id,
                'type' => $t['type'],
                'amount' => round((float) $t['amount'], 8),
                'at' => $this->iso((int) $t['transaction_time']),
                'stored' => isset($storedIds[$id]),
            ];
        }
        usort($transfers, fn ($a, $b) => strcmp($a['at'], $b['at']));
        $missing = array_values(array_filter($transfers, fn ($t) => ! $t['stored']));

        $storedNet = $stored->sum(fn ($r) => $this->signed($r->type, (float) $r->amount));
        $missingNet = array_sum(array_map(fn ($t) => $this->signed($t['type'], $t['amount']), $missing));
        $initialBefore = $account->initial_deposit !== null ? (float) $account->initial_deposit : null;

        return [
            'exchange' => $exchange,
            'account_id' => $account->id,
            'wallet_balance' => round((float) ($ledger['wallet_balance'] ?? 0), 2),
            'ledger_start' => $ledgerStart !== null ? $this->iso($ledgerStart) : null,
            'ledger_rows' => (int) ($ledger['rows'] ?? 0),
            'sums' => $ledger['sums'] ?? [],
            'unclassified_types' => $ledger['unclassified_types'] ?? [],
            'transfers' => $transfers,
            'missing_count' => count($missing),
            'unknown_stored' => $unknownInWindow,
            'initial_deposit' => [
                'before' => $initialBefore === null ? null : round($initialBefore, 2),
                'after' => round($initialAfter, 2),
            ],
            // The capital base everything bills and gates on.
            'total_deposit' => [
                'before' => round(($initialBefore ?? 0) + $storedNet, 2),
                'after' => round($initialAfter + $storedNet + $missingNet, 2),
            ],
            'invoices' => DB::table('invoices')
                ->where('exchange', $exchange)
                ->where('account_id', $account->id)
                ->count(),
            'problems' => $problems,
            'changes' => $missing !== [] || $initialBefore === null
                || abs($initialBefore - $initialAfter) >= self::TOLERANCE,
            // Internal: what apply() writes. Stripped before a plan is shown.
            '_missing' => array_map(fn ($t) => $binance[$t['tran_id']], $missing),
            '_initial' => $initialAfter,
        ];
    }

    /**
     * Write a clean plan: store the missing transfers (dated when they
     * HAPPENED, not when we learned of them) and set `initial_deposit`.
     * One transaction, so a half-applied ledger cannot exist.
     *
     * @param  array<string, mixed>  $plan
     * @return int  transfers inserted
     */
    public function apply(ExchangeAccount $account, array $plan): int
    {
        $schema = ExchangeSchema::for($plan['exchange']);

        return DB::transaction(function () use ($account, $plan, $schema) {
            $rows = array_map(fn ($t) => [
                'api_key' => $account->api_key,
                'uni_id' => $account->uni_id,
                'type' => $t['type'],
                'amount' => $t['amount'],
                'balance_after' => null,
                'tran_id' => $t['tran_id'],
                'currency' => $t['currency'] ?? 'USDT',
                'transaction_time' => $t['transaction_time'],
                'info' => $t['info'] ?? null,
                'created_at' => Carbon::createFromTimestampMs((int) $t['transaction_time'], 'UTC'),
            ], $plan['_missing']);

            // insertOrIgnore on (api_key, tran_id): the poller may have stored
            // one of these between the plan and now.
            $inserted = $rows === [] ? 0 : DB::table($schema->transactions)->insertOrIgnore($rows);

            $account->forceFill(['initial_deposit' => $plan['_initial']])->save();

            return $inserted;
        });
    }

    /**
     * The engine's first balance poll of a new account. Fills an UNSET figure
     * only — an account that already has one is corrected by an admin, with
     * the before → after in front of them, never silently by a poller.
     *
     * @param  array<string, mixed>  $ledger
     * @return array{applied: bool, reason: ?string, inserted: int}
     */
    public function seedNew(ExchangeAccount $account, string $exchange, array $ledger): array
    {
        if ($account->initial_deposit !== null) {
            return ['applied' => false, 'reason' => 'ALREADY_SET', 'inserted' => 0];
        }

        $plan = $this->plan($account, $exchange, $ledger);
        if ($plan['problems'] !== []) {
            return ['applied' => false, 'reason' => implode(' ', $plan['problems']), 'inserted' => 0];
        }

        return ['applied' => true, 'reason' => null, 'inserted' => $this->apply($account, $plan)];
    }

    /** A plan without its internal write-set, for a response. */
    public static function public(array $plan): array
    {
        return array_diff_key($plan, ['_missing' => true, '_initial' => true]);
    }

    private function signed(string $type, float $amount): float
    {
        return strtoupper($type) === 'WITHDRAWAL' ? -abs($amount) : abs($amount);
    }

    private function iso(int $ms): string
    {
        return Carbon::createFromTimestampMs($ms, 'UTC')->toIso8601ZuluString();
    }
}
