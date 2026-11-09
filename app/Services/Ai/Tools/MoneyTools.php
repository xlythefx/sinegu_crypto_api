<?php

namespace App\Services\Ai\Tools;

use App\Models\Invoice;
use App\Services\Admin\AdminInsights;
use App\Services\Exchanges\ExchangeSchema;
use App\Services\InvoiceService;
use Illuminate\Support\Facades\DB;

/**
 * Billing and the platform's totals. Invoices are presented exactly as the
 * admin Invoice History presents them (Invoice::toApiArray via
 * InvoiceService::mapList); totals come from AdminInsights, the service behind
 * the Admin Dashboard tabs — one definition of every figure.
 */
final class MoneyTools implements Toolset
{
    public function __construct(private InvoiceService $invoices, private AdminInsights $insights) {}

    public function definitions(): array
    {
        return [
            'invoices' => [
                'title' => 'Invoices: who paid, who owes, who is overdue',
                'description' => 'Monthly performance-fee invoices. Each shows the person, account, exchange, month, fee owed, '
                    .'status (paid / unpaid), whether it is OVERDUE (past its due date, normally the 4th of the next month), '
                    .'days overdue, whether the account was paused for it, how it was paid, and the profit it was billed on. '
                    .'The summary splits paid / unpaid-not-yet-due / overdue with counts and amounts. Use it for "who '
                    .'hasn\'t paid", "who is overdue", "how much did we collect in October".',
                'schema' => Props::object([
                    'status' => ['type' => 'string', 'enum' => ['unpaid', 'overdue', 'paid', 'all'], 'description' => 'unpaid includes overdue. Default: unpaid.'],
                    'month' => ['type' => 'string', 'description' => 'Billing month, YYYY-MM (the month the profit was made). Default: every month.'],
                    'owner' => Props::owner(),
                    'exchange' => Props::exchange(),
                    'limit' => Props::limit(100, 500),
                ]),
            ],
            'platform_summary' => [
                'title' => 'Platform overview',
                'description' => 'The Admin Dashboard\'s overview: money under management, P&L today / last 7 days / this '
                    .'month / all time (after fees, with before-fees and fees), win rate, live accounts, plus what needs '
                    .'attention (pending sign-ups, broken API keys, overdue and unpaid invoices, unmatched payments, coins '
                    .'switched off by the kill switch, today\'s signal problems, positions closed outside the bot).',
                'schema' => Props::object([
                    'scope' => ['type' => 'string', 'enum' => ['all', 'customers', 'master'], 'description' => 'Whose money the P&L and balances cover. Default: all.'],
                ]),
            ],
            'business_stats' => [
                'title' => 'Customers and revenue',
                'description' => 'Customer and revenue statistics: sign-up funnel (registered → approved → connected → funded '
                    .'→ trading), active customers, sign-ups per week, customers per exchange, biggest accounts, best P&L '
                    .'last 30 days, most missed trades; and money: fees invoiced / collected / outstanding per month, average '
                    .'days to pay, overdue list, deposits and withdrawals, this month\'s fee forecast.',
                'schema' => Props::object([]),
            ],
        ];
    }

    public function call(string $tool, array $arguments): array
    {
        $args = new Args($arguments);

        return match ($tool) {
            'invoices' => $this->list($args),
            'platform_summary' => $this->platform($args),
            'business_stats' => [
                'customers' => $this->insights->customers(),
                'money' => $this->insights->money(),
            ],
        };
    }

    private function list(Args $args): array
    {
        $status = $args->enum('status', ['unpaid', 'overdue', 'paid', 'all'], 'unpaid');
        $month = $args->month('month');
        $owner = $args->str('owner');
        $exchange = $args->enum('exchange', ['binance', 'bybit', 'mexc']);
        $limit = $args->int('limit', 100, 1, 500);

        $q = Invoice::with('account')
            // Invoice-scenario scratch rows are nobody's real invoice.
            ->where(fn ($w) => $w->whereNull('api_key')->orWhere('api_key', 'not like', 'SBXINV-%'));
        if ($month) {
            $q->where('month_year', $month);
        }
        if ($exchange) {
            $q->where('exchange', $exchange);
        }
        $all = $q->orderByDesc('month_year')->orderByDesc('id')->get();

        $people = DB::table('user_credentials')
            ->whereIn('uni_id', $all->pluck('user_id')->unique()->all())
            ->get(['uni_id', 'name', 'type'])
            ->keyBy('uni_id');

        // Each invoice's account from ITS exchange's table: ids repeat across
        // {x}_accounts, and Invoice::account() only knows Binance.
        $accounts = [];
        foreach ($all->groupBy('exchange') as $ex => $invs) {
            if (! ExchangeSchema::isSupported((string) $ex)) {
                continue;
            }
            DB::table(ExchangeSchema::for((string) $ex)->accountsTable)
                ->whereIn('id', $invs->pluck('account_id')->unique()->all())
                ->get(['id', 'name', 'enabled'])
                ->each(function ($a) use (&$accounts, $ex) {
                    $accounts[$ex.'|'.$a->id] = $a;
                });
        }

        $rows = [];
        foreach ($this->invoices->mapList($all) as $i => $row) {
            $invoice = $all[$i];
            $account = $accounts[$invoice->exchange.'|'.$invoice->account_id] ?? null;
            if ($account) {
                $row['account_name'] = $account->name;
            }
            $person = $people->get($row['user_id']);
            $row['owner'] = $person->name ?? null;
            $row['owner_type'] = TradeTools::ownerType($person->type ?? null);
            if ($owner !== null && ! str_contains(mb_strtolower(($row['owner'] ?? '').' '.($row['account_name'] ?? '')), mb_strtolower($owner))) {
                continue;
            }
            $row['state'] = match (true) {
                $row['status'] === 'paid' => 'paid',
                $row['total_fee'] <= 0 => 'nothing owed',
                $row['is_overdue'] => 'overdue',
                default => 'unpaid, not yet due',
            };
            $row['days_overdue'] = $row['is_overdue'] ? (int) now()->startOfDay()->diffInDays($invoice->due_date, true) : 0;
            $row['account_paused'] = $account ? ! (bool) $account->enabled : null;
            unset($row['user_id'], $row['reference_label'], $row['reference_value']);
            $rows[] = $row;
        }

        $summary = ['paid' => ['count' => 0, 'amount' => 0.0], 'unpaid_not_yet_due' => ['count' => 0, 'amount' => 0.0],
            'overdue' => ['count' => 0, 'amount' => 0.0], 'nothing_owed' => ['count' => 0]];
        foreach ($rows as $r) {
            match ($r['state']) {
                'paid' => $this->bump($summary['paid'], $r['total_fee']),
                'overdue' => $this->bump($summary['overdue'], $r['total_fee']),
                'unpaid, not yet due' => $this->bump($summary['unpaid_not_yet_due'], $r['total_fee']),
                default => $summary['nothing_owed']['count']++,
            };
        }

        $wanted = array_values(array_filter($rows, fn ($r) => match ($status) {
            'paid' => $r['state'] === 'paid',
            'overdue' => $r['state'] === 'overdue',
            'unpaid' => in_array($r['state'], ['overdue', 'unpaid, not yet due'], true),
            'all' => true,
        }));

        return [
            'filters' => ['status' => $status, 'month' => $month ?? 'all', 'exchange' => $exchange ?? 'all', 'owner' => $owner],
            'summary' => $summary,
            'matching' => count($wanted),
            'invoices' => array_slice($wanted, 0, $limit),
        ];
    }

    private function bump(array &$bucket, float $amount): void
    {
        $bucket['count']++;
        $bucket['amount'] = round($bucket['amount'] + $amount, 2);
    }

    private function platform(Args $args): array
    {
        $scope = $args->enum('scope', ['all', 'customers', 'master'], 'all');
        $overview = $this->insights->overview();

        return [
            'scope' => $scope,
            'platform' => $this->insights->platform($scope),
            'headline' => $overview['headline'] ?? null,
            'needs_attention' => $overview['attention'] ?? null,
        ];
    }
}
