<?php

namespace App\Http\Controllers;

use App\Services\UserStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    /** Bucket label for closed trades that carry no strategy tag. */
    private const UNTAGGED = 'Untagged';

    public function __construct(private UserStatsService $stats) {}

    /**
     * GET /api/analytics
     * Aggregated performance analytics for the authenticated user,
     * computed from the binance_* tables (all connected accounts).
     * Per-exchange data is shaped as arrays so mexc / bybit tables
     * can append later without contract changes.
     *
     * Query filters — every one of them narrows the closed-trade set that
     * feeds EVERY metric below, so the page can never mix a filtered headline
     * with unfiltered detail:
     *   exchange       all | binance (anything else has no tables yet)
     *   from, to       inclusive 'YYYY-MM-DD' bounds on the close date
     *   symbols[]      + symbol_mode   = include | exclude (default exclude)
     *   strategies[]   + strategy_mode = include | exclude (default exclude)
     * An empty chip list means "no filter", matching the UI.
     */
    public function index(Request $request): JsonResponse
    {
        $uniId = $request->user()->uni_id;

        $rawExchange = $request->query('exchange', 'all');
        $exchange = is_string($rawExchange) ? strtolower(trim($rawExchange)) : '';
        $from = $this->date($request->query('from'));
        $to = $this->date($request->query('to'));
        $symbols = $this->chips($request->query('symbols'));
        $symbolMode = $this->mode($request->query('symbol_mode'));
        $strategies = $this->chips($request->query('strategies'));
        $strategyMode = $this->mode($request->query('strategy_mode'));

        // Only the binance_* tables exist today. Asking for another exchange
        // yields an empty dataset rather than silently serving Binance numbers.
        $supported = $exchange === 'all' || $exchange === 'binance';

        $accounts = $supported ? $this->stats->displayAccounts($uniId) : collect();

        $everyTrade = $supported
            ? DB::table('binance_pastpositions')
                ->where('uni_id', $uniId)
                ->orderBy('closed_at')
                ->get()
            : collect();

        $transactions = $supported
            ? DB::table('binance_transactions')
                ->where('uni_id', $uniId)
                ->orderBy('created_at')
                ->get()
            : collect();

        $passesChips = fn ($p) => $this->passes($symbols, $symbolMode, trim((string) $p->symbol))
            && $this->passes($strategies, $strategyMode, $this->strategyKey($p->strategy));

        // Date-scoped but NOT chip-scoped: this is what the chip lists are built
        // from, so a chip never vanishes the moment you click it.
        $inRange = $everyTrade->filter(
            fn ($p) => $this->withinRange((string) $p->closed_at, $from, $to)
        );

        // The fully filtered set every metric below is computed from.
        $past = $inRange->filter($passesChips)->values();

        // Chip-filtered but ALL-TIME — only the Return on Deposit card uses it.
        $allTimeChipped = $everyTrade->filter($passesChips)->values();

        $availableSymbols = $inRange
            ->map(fn ($p) => trim((string) $p->symbol))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();

        $availableStrategies = $inRange
            ->map(fn ($p) => $this->strategyKey($p->strategy))
            ->unique()
            ->sort()
            ->values()
            ->all();

        // ---- Capital & returns ----------------------------------------
        // Deliberately all-time: committed capital is a lifetime figure, so
        // narrowing the date range must not shrink the denominator.
        $deposits = (float) $transactions->where('type', 'DEPOSIT')->sum('amount');
        $withdrawals = (float) $transactions->where('type', 'WITHDRAWAL')->sum('amount');
        $baseline = $deposits - $withdrawals;

        $currentCapital = (float) $accounts->sum('balance');
        $totalUnrealized = (float) $accounts->sum('unrealized_pnl');
        $totalRealized = (float) $past->sum('realized_pnl');

        // Open positions carry a symbol but no strategy tag, so unrealized P&L
        // cannot be attributed to a chip selection. Whenever a chip filter is
        // active the headline drops to the filtered *realized* return instead
        // of mixing filtered realized with whole-account unrealized.
        $chipFiltered = $symbols !== [] || $strategies !== [];
        $totalReturnAbs = $chipFiltered ? $totalRealized : $totalRealized + $totalUnrealized;
        $totalReturnPct = $baseline > 0 ? round($totalReturnAbs / $baseline * 100, 2) : null;

        // ---- Return on deposit ----------------------------------------
        // Realized P&L measured against money actually paid in: deposits only,
        // withdrawals ignored. Honors the chip filters but stays all-time —
        // a deposit is a lifetime concept, so the date range is not applied.
        $depositRealized = (float) $allTimeChipped->sum('realized_pnl');
        $returnOnDeposit = [
            'pct' => $deposits > 0 ? round($depositRealized / $deposits * 100, 2) : null,
            'realized' => round($depositRealized, 2),
            'deposits' => round($deposits, 2),
            'trades' => $allTimeChipped->count(),
        ];

        // ---- Daily realized P&L (ascending by close date) -------------
        $dailyPnl = $past
            ->groupBy(fn ($p) => Carbon::parse($p->closed_at)->toDateString())
            ->map(fn ($rows) => round((float) $rows->sum('realized_pnl'), 2))
            ->sortKeys();

        $tradingDays = $dailyPnl->count();
        $avgDailyPnl = $tradingDays > 0 ? round($totalRealized / $tradingDays, 2) : null;

        $bestDay = null;
        $worstDay = null;
        foreach ($dailyPnl as $date => $pnl) {
            if ($bestDay === null || $pnl > $bestDay['pnl']) {
                $bestDay = ['date' => $date, 'pnl' => $pnl];
            }
            if ($worstDay === null || $pnl < $worstDay['pnl']) {
                $worstDay = ['date' => $date, 'pnl' => $pnl];
            }
        }

        // ---- Day-of-week breakdown (always all 7 keys, Mon..Sun) ------
        $dayOfWeek = [];
        foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $day) {
            $dayOfWeek[$day] = ['pnl' => 0.0, 'trades' => 0];
        }
        foreach ($past as $p) {
            $day = Carbon::parse($p->closed_at)->format('D');
            $dayOfWeek[$day]['pnl'] += (float) $p->realized_pnl;
            $dayOfWeek[$day]['trades']++;
        }
        foreach ($dayOfWeek as $day => $row) {
            $dayOfWeek[$day]['pnl'] = round($row['pnl'], 2);
        }

        // ---- Monthly breakdown (ascending) ----------------------------
        $monthly = [];
        $byMonth = $past
            ->groupBy(fn ($p) => Carbon::parse($p->closed_at)->format('Y-m'))
            ->sortKeys();
        foreach ($byMonth as $month => $rows) {
            $wins = $rows->where('realized_pnl', '>', 0)->count();
            $monthly[] = [
                'month' => $month,
                'pnl' => round((float) $rows->sum('realized_pnl'), 2),
                'trades' => $rows->count(),
                'win_rate' => $rows->count() ? round($wins / $rows->count() * 100, 2) : 0.0,
            ];
        }

        // ---- Per-symbol breakdown (top 12 by |realized P&L|) ----------
        $bySymbol = [];
        foreach ($past->groupBy('symbol') as $symbol => $rows) {
            $bySymbol[] = [
                'symbol' => $symbol,
                'trades' => $rows->count(),
                'realized_pnl' => round((float) $rows->sum('realized_pnl'), 2),
            ];
        }
        usort($bySymbol, fn ($a, $b) => abs($b['realized_pnl']) <=> abs($a['realized_pnl']));
        $bySymbol = array_slice($bySymbol, 0, 12);

        // ---- Per-exchange (array so new exchanges append later) -------
        $byExchange = [
            [
                'exchange' => 'Binance',
                'balance' => round($currentCapital, 2),
                'unrealized' => round($totalUnrealized, 2),
            ],
        ];

        // ---- Flows -----------------------------------------------------
        $recentFlows = $transactions
            ->sortByDesc('created_at')
            ->take(5)
            ->map(fn ($t) => [
                'type' => $t->type,
                'amount' => round((float) $t->amount, 2),
                'date' => Carbon::parse($t->created_at)->toDateString(),
            ])
            ->values()
            ->all();

        $flows = [
            'deposits' => round($deposits, 2),
            'withdrawals' => round($withdrawals, 2),
            'net_flow' => round($baseline, 2),
            'recent' => $recentFlows,
        ];

        // ---- Trade quality --------------------------------------------
        $wins = $past->where('realized_pnl', '>', 0);
        $losses = $past->where('realized_pnl', '<', 0);
        $grossWin = (float) $wins->sum('realized_pnl');
        $grossLoss = abs((float) $losses->sum('realized_pnl'));
        $tradeCount = $past->count();

        $quality = [
            'wins' => $wins->count(),
            'losses' => $losses->count(),
            'win_rate' => $tradeCount ? round($wins->count() / $tradeCount * 100, 2) : 0.0,
            'profit_factor' => $grossLoss > 0 ? round($grossWin / $grossLoss, 2) : null,
            'avg_win' => $wins->count() ? round($grossWin / $wins->count(), 2) : 0.0,
            'avg_loss' => $losses->count() ? round(-$grossLoss / $losses->count(), 2) : 0.0,
            'largest_win' => $tradeCount ? round(max(0.0, (float) $past->max('realized_pnl')), 2) : 0.0,
            'largest_loss' => $tradeCount ? round(min(0.0, (float) $past->min('realized_pnl')), 2) : 0.0,
            'expectancy' => $tradeCount ? round($totalRealized / $tradeCount, 2) : 0.0,
        ];

        // ---- Risk: drawdown on the cumulative daily P&L curve ---------
        // Curve seeded at baseline, then one point per trading day.
        $equity = $baseline;
        $peak = $baseline;
        $maxDrawdownAbs = 0.0;
        $peakAtMaxDrawdown = $baseline;
        foreach ($dailyPnl as $pnl) {
            $equity += $pnl;
            if ($equity > $peak) {
                $peak = $equity;
            }
            $drawdown = $peak - $equity;
            if ($drawdown > $maxDrawdownAbs) {
                $maxDrawdownAbs = $drawdown;
                $peakAtMaxDrawdown = $peak;
            }
        }
        $maxDrawdownPct = $peakAtMaxDrawdown > 0
            ? round($maxDrawdownAbs / $peakAtMaxDrawdown * 100, 2)
            : null;

        // Streaks: longest runs of consecutive winning / losing days.
        $streaks = $this->stats->dayStreaks($dailyPnl);
        $bestStreak = $streaks['max_win_streak_days'];
        $worstStreak = $streaks['max_loss_streak_days'];

        // ---- Risk: return-based ratios (daily fractional returns) -----
        // Reuse the same daily P&L series (ascending). Walk an equity
        // curve seeded at baseline; each day's return is pnl / equity
        // *before* that day's P&L is applied. Days where equity <= 0 are
        // skipped (no meaningful return when underwater / no capital).
        $returns = [];
        $equityR = $baseline;
        foreach ($dailyPnl as $pnl) {
            if ($equityR > 0) {
                $returns[] = $pnl / $equityR;
            }
            $equityR += $pnl;
        }

        $n = count($returns);
        $annualize = sqrt(252);

        $volatility = null;
        $sharpe = null;
        $sortino = null;
        $riskScore = null;

        if ($n >= 2) {
            $mean = array_sum($returns) / $n;

            // Sample standard deviation (denominator n-1).
            $sumSq = 0.0;
            foreach ($returns as $r) {
                $sumSq += ($r - $mean) ** 2;
            }
            $sd = sqrt($sumSq / ($n - 1));

            if ($sd > 0) {
                $volatility = round($sd * $annualize * 100, 2);
                $sharpe = round(($mean / $sd) * $annualize, 2);
            }

            // Downside deviation: sqrt( sum(min(r,0)^2) / n ). No losing
            // days => 0 => Sortino undefined (null, not infinite).
            $downSq = 0.0;
            foreach ($returns as $r) {
                $downSq += (min($r, 0.0)) ** 2;
            }
            $downsideDev = sqrt($downSq / $n);
            if ($downsideDev > 0) {
                $sortino = round(($mean / $downsideDev) * $annualize, 2);
            }

            // Composite 0-100 heuristic (higher = better): centred at 55,
            // rewards Sharpe, penalises drawdown depth. Clamped to [0,100].
            if ($sharpe !== null) {
                $rawScore = 55 + 12 * $sharpe - 0.4 * abs($maxDrawdownPct ?? 0);
                $riskScore = round(max(0.0, min(100.0, $rawScore)), 2);
            }
        }

        $risk = [
            'max_drawdown_abs' => round($maxDrawdownAbs, 2),
            'max_drawdown_pct' => $maxDrawdownPct,
            'best_streak' => $bestStreak,
            'worst_streak' => $worstStreak,
            'volatility' => $volatility,
            'sharpe' => $sharpe,
            'sortino' => $sortino,
            'risk_score' => $riskScore,
        ];

        return response()->json([
            'success' => true,
            'analytics' => [
                'baseline' => round($baseline, 2),
                'current_capital' => round($currentCapital, 2),
                'total_unrealized' => round($totalUnrealized, 2),
                'total_realized' => round($totalRealized, 2),
                'total_return_abs' => round($totalReturnAbs, 2),
                'total_return_pct' => $totalReturnPct,
                'return_on_deposit' => $returnOnDeposit,
                'filters' => [
                    // True once any chip is picked — the UI relabels the
                    // headline "Filtered Return" and drops the unrealized note.
                    'filtered' => $chipFiltered,
                    'available_symbols' => $availableSymbols,
                    'available_strategies' => $availableStrategies,
                ],
                'trading_days' => $tradingDays,
                'avg_daily_pnl' => $avgDailyPnl,
                'best_day' => $bestDay,
                'worst_day' => $worstDay,
                'daily_pnl' => $dailyPnl,
                'day_of_week' => $dayOfWeek,
                'monthly' => $monthly,
                'by_symbol' => $bySymbol,
                'by_exchange' => $byExchange,
                'flows' => $flows,
                'quality' => $quality,
                'risk' => $risk,
            ],
        ]);
    }

    /* ================= Filter-input parsing ============================
     * Filters are a view preference, not a command: anything unparseable is
     * ignored so a malformed query still renders a page instead of a 422.
     * ==================================================================*/

    /** A 'YYYY-MM-DD' bound, or null when absent / malformed. */
    private function date(mixed $raw): ?string
    {
        $value = is_string($raw) ? trim($raw) : '';

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }

    /** Chips arrive as `symbols[]=A&symbols[]=B`; a bare scalar is tolerated. */
    private function chips(mixed $raw): array
    {
        $values = is_array($raw) ? $raw : (is_string($raw) && $raw !== '' ? [$raw] : []);

        $out = [];
        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }
            $value = trim($value);
            if ($value !== '') {
                $out[] = $value;
            }
        }

        return array_values(array_unique($out));
    }

    /** Exclude is the default — it is the non-destructive reading of a chip. */
    private function mode(mixed $raw): string
    {
        return $raw === 'include' ? 'include' : 'exclude';
    }

    /** An empty selection passes everything, matching "no chips = no filter". */
    private function passes(array $selected, string $mode, string $value): bool
    {
        if ($selected === []) {
            return true;
        }

        $has = in_array($value, $selected, true);

        return $mode === 'include' ? $has : ! $has;
    }

    /** Untagged trades still need a chip, or they could never be filtered. */
    private function strategyKey(?string $raw): string
    {
        $key = trim((string) $raw);

        return $key === '' ? self::UNTAGGED : $key;
    }

    /** Inclusive on both ends, compared on the close DATE only. */
    private function withinRange(string $closedAt, ?string $from, ?string $to): bool
    {
        $day = substr($closedAt, 0, 10);

        return ($from === null || $day >= $from) && ($to === null || $day <= $to);
    }
}
