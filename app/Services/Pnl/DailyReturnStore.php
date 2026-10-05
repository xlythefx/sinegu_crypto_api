<?php

namespace App\Services\Pnl;

use App\Services\UserStatsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The saved daily P&L percentages (`daily_returns`) — what the Date Range
 * card's Period Return adds up.
 *
 * The figures are not calculated here: {@see UserStatsService::dailyReturns}
 * is the one function behind both the P&L calendar's cell and this row, so a
 * saved day is always the day the calendar shows. This class only keeps the
 * table in step with it — inserting new days, rewriting a day whose trades
 * changed (late close, fee receipt, admin edit) and dropping a day that no
 * longer has any — and reads it back.
 */
class DailyReturnStore
{
    public function __construct(private UserStatsService $stats) {}

    /**
     * Bring one user's rows for one scope in line with $days, writing only
     * what differs (an unchanged day is not touched, so a page load that
     * changes nothing writes nothing).
     *
     * @param  array<string, array{start_balance: ?float, pnl: float, pnl_gross: float, pct: ?float, pct_gross: ?float, trades: int}>  $days
     */
    public function sync(string $uniId, string $scope, array $days): void
    {
        $stored = $this->read($uniId, $scope);
        $now = Carbon::now();

        $changed = [];
        foreach ($days as $day => $row) {
            if (($stored[$day] ?? null) == $row) {
                continue;
            }
            $changed[] = ['uni_id' => $uniId, 'scope' => $scope, 'day' => $day]
                + $row
                + ['created_at' => $now, 'updated_at' => $now];
        }
        if ($changed !== []) {
            DB::table('daily_returns')->upsert(
                $changed,
                ['uni_id', 'scope', 'day'],
                ['start_balance', 'pnl', 'pnl_gross', 'pct', 'pct_gross', 'trades', 'updated_at'],
            );
        }

        $gone = array_diff(array_keys($stored), array_keys($days));
        if ($gone !== []) {
            DB::table('daily_returns')
                ->where('uni_id', $uniId)
                ->where('scope', $scope)
                ->whereIn('day', array_values($gone))
                ->delete();
        }
    }

    /**
     * The saved rows, keyed 'YYYY-MM-DD', ascending — the same shape
     * {@see UserStatsService::dailyReturns} returns, so the two compare
     * directly.
     *
     * @return array<string, array{start_balance: ?float, pnl: float, pnl_gross: float, pct: ?float, pct_gross: ?float, trades: int}>
     */
    public function read(string $uniId, string $scope): array
    {
        $num = fn ($v) => $v === null ? null : (float) $v;

        $out = [];
        foreach (
            DB::table('daily_returns')
                ->where('uni_id', $uniId)
                ->where('scope', $scope)
                ->orderBy('day')
                ->get() as $r
        ) {
            $out[substr((string) $r->day, 0, 10)] = [
                'start_balance' => $num($r->start_balance),
                'pnl' => (float) $r->pnl,
                'pnl_gross' => (float) $r->pnl_gross,
                'pct' => $num($r->pct),
                'pct_gross' => $num($r->pct_gross),
                'trades' => (int) $r->trades,
            ];
        }

        return $out;
    }

    /**
     * Recompute and save one user's days for 'all' and every exchange — the
     * scheduled pass, for users who have not opened the page since their last
     * trade. The analytics endpoint syncs its own scope on every read from
     * the data it has already loaded, so this is the background half.
     */
    public function refreshUser(string $uniId): void
    {
        foreach (array_merge(['all'], UserStatsService::exchanges()) as $scope) {
            $accounts = $this->stats->displayAccounts($uniId, $scope);
            $trades = UserStatsService::withFeeBasis(
                $this->stats->pastPositions($accounts, UserStatsService::DAY_TRADE_COLUMNS)
            );
            $flows = UserStatsService::flowsByDay(
                $this->stats->transactions($accounts, ['type', 'amount', 'created_at'])
            );

            $this->sync($uniId, $scope, UserStatsService::dailyReturns($trades, $flows, $accounts));
        }
    }
}
