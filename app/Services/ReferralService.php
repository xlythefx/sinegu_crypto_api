<?php

namespace App\Services;

use App\Exceptions\ReleaseRejectedException;
use App\Models\BinanceAccount;
use App\Models\Invoice;
use App\Models\ReferralCode;
use App\Models\ReferralTracking;
use App\Models\ReferrerPayout;
use App\Models\ReferrerPayoutItem;
use App\Models\UserCredential;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ReferralService — the single source of truth for the affiliate system.
 *
 * Everything the two surfaces (user dashboard, admin console) display is
 * derived HERE, once:
 *  - member status (new / active / overdue) — one rule, mapped to per-surface
 *    labels at the edge (admin "overdue" ≡ user "suspended", spec §2/§6.2);
 *  - commission math — bcmath on decimal strings at 8 dp, never PHP floats
 *    (spec §6.9); floats appear only in API serialization for display;
 *  - releasable vs released lines — line identity is the
 *    (referrer, referred user, exchange, month_year) triple, NEVER an invoice
 *    id, so payout history survives invoice regeneration (spec §6.10).
 *
 * Note on multiple accounts: invoices are unique per (exchange, account_id,
 * month), so a user with two accounts on one exchange can have two invoices for
 * the same (user, exchange, month) triple. Lines aggregate them (fee_paid = Σ).
 * If a further invoice for an already-released triple is paid later it cannot
 * be released again — accepted limitation of the regeneration-proof key.
 */
class ReferralService
{
    /** Invoice statuses that are not yet money in the bank. */
    private const UNPAID = ['pending', 'overdue', 'failed'];

    // ── Commission ────────────────────────────────────────────────────────────

    /** fee × pct / 100 at 8 dp. Null/empty percentage means no commission (spec §6.7). */
    public function commission(?string $fee, ?string $pct): string
    {
        $fee = $fee !== null && $fee !== '' ? $fee : '0';
        $pct = $pct !== null && $pct !== '' ? $pct : '0';

        return bcdiv(bcmul($fee, $pct, 8), '100', 8);
    }

    // ── Codes & binding ───────────────────────────────────────────────────────

    public function codeFor(string $uniId): ?ReferralCode
    {
        return ReferralCode::where('user_uni_id', $uniId)->first();
    }

    /** Generate-if-absent; race-safe via UNIQUE(user_uni_id) + refetch. */
    public function getOrCreateCode(UserCredential $user): ReferralCode
    {
        $existing = $this->codeFor($user->uni_id);
        if ($existing) {
            return $existing;
        }

        do {
            $code = strtoupper(Str::random(8));
        } while (ReferralCode::where('code', $code)->exists());

        try {
            return ReferralCode::create(['user_uni_id' => $user->uni_id, 'code' => $code]);
        } catch (\Illuminate\Database\QueryException) {
            // Concurrent create hit UNIQUE(user_uni_id) — the winner's row is ours.
            return $this->codeFor($user->uni_id);
        }
    }

    /**
     * Registration binding (spec §2): invalid codes are silently ignored,
     * self-referral is blocked, and the unique keys on referral_tracking make
     * re-binding a no-op (insertOrIgnore).
     */
    public function bind(?string $code, string $newUserUniId): void
    {
        if ($code === null || trim($code) === '') {
            return;
        }

        $row = ReferralCode::where('code', trim($code))->first();
        if (! $row || $row->user_uni_id === $newUserUniId) {
            return;
        }

        DB::table('referral_tracking')->insertOrIgnore([
            'referral_code' => $row->code,
            'referrer_uni_id' => $row->user_uni_id,
            'referred_user_uni_id' => $newUserUniId,
            'created_at' => now(),
        ]);
    }

    // ── Status derivation (the ONE rule, spec §2) ─────────────────────────────

    /**
     * Batch-derive internal status for a set of users. Internal values:
     * 'new' (no trading account yet) / 'overdue' (unpaid money past due —
     * pending past due_date, or hard 'overdue'/'failed' status) / 'active'.
     *
     * @param  string[]  $uniIds
     * @return array<string,string> uni_id => internal status
     */
    public function statusesFor(array $uniIds): array
    {
        if ($uniIds === []) {
            return [];
        }

        $withAccounts = array_flip($this->usersWithTradingAccounts($uniIds));
        $overdue = array_flip(
            Invoice::whereIn('user_id', $uniIds)
                ->where(function ($q) {
                    $q->where(function ($qq) {
                        $qq->where('status', 'pending')
                            ->whereNotNull('due_date')
                            ->whereDate('due_date', '<', today());
                    })->orWhereIn('status', ['overdue', 'failed']);
                })
                ->distinct()
                ->pluck('user_id')
                ->all()
        );

        $out = [];
        foreach ($uniIds as $id) {
            $out[$id] = ! isset($withAccounts[$id])
                ? 'new'
                : (isset($overdue[$id]) ? 'overdue' : 'active');
        }

        return $out;
    }

    /** Per-surface label for the internal status (admin "overdue" ≡ user "suspended"). */
    public function memberLabel(string $internal, string $surface): string
    {
        return $internal === 'overdue'
            ? ($surface === 'admin' ? 'overdue' : 'suspended')
            : $internal;
    }

    /**
     * The ONLY place that knows which per-exchange account tables exist.
     * When bybit_* / mexc_* account tables land, union them here — no caller
     * changes needed. SoftDeletes on BinanceAccount excludes deleted rows.
     *
     * @return string[]
     */
    private function usersWithTradingAccounts(array $uniIds): array
    {
        return BinanceAccount::whereIn('uni_id', $uniIds)->distinct()->pluck('uni_id')->all();
    }

    private function isOverdueInvoice(Invoice $inv): bool
    {
        if (in_array($inv->status, ['overdue', 'failed'], true)) {
            return true;
        }

        return $inv->status === 'pending'
            && $inv->due_date !== null
            && $inv->due_date->lt(today());
    }

    // ── User dashboard payload ────────────────────────────────────────────────

    /**
     * Everything GET /referrals needs: members with server-computed money
     * fields, plus fully server-computed KPI stats (spec §6.1 — the client
     * never multiplies).
     *
     * @return array{referrals: array, stats: array}
     */
    public function dashboardFor(UserCredential $referrer): array
    {
        $built = $this->membersFor($referrer->uni_id, $referrer->affiliate_percentage, 'user');

        $payout = ReferrerPayout::where('referrer_uni_id', $referrer->uni_id)
            ->where('status', 'paid')
            ->selectRaw('COALESCE(SUM(total_amount), 0) AS total, MAX(paid_at) AS last_at')
            ->first();

        return [
            'referrals' => $built['members'],
            'stats' => [
                'total_referrals' => count($built['members']),
                'active_referrals' => $built['active_count'],
                'lifetime_earnings' => round((float) $payout->total, 2),
                'last_payout_at' => $payout->last_at,
                'pending_payout' => round((float) $built['unreleased_sum'], 2),
                'projected_commission' => round((float) $built['pending_commission_sum'], 2),
            ],
        ];
    }

    /**
     * Build the member items for ONE referrer. Batch-fetches every sidecar with
     * whereIn — no per-member queries (spec §6.3).
     *
     * @return array{members: array, active_count: int, any_overdue: bool, any_pending: bool,
     *               paid_commission_sum: string, unreleased_sum: string, pending_commission_sum: string}
     */
    public function membersFor(string $referrerUniId, ?string $pct, string $surface): array
    {
        $tracking = ReferralTracking::where('referrer_uni_id', $referrerUniId)
            ->orderByDesc('created_at')
            ->get();

        $ids = $tracking->pluck('referred_user_uni_id')->all();

        $users = UserCredential::whereIn('uni_id', $ids)
            ->get(['uni_id', 'name', 'email', 'realized_percentage', 'unrealized_percentage'])
            ->keyBy('uni_id');
        $invoicesByUser = Invoice::whereIn('user_id', $ids)->get()->groupBy('user_id');
        $itemsByUser = ReferrerPayoutItem::where('referrer_uni_id', $referrerUniId)
            ->whereIn('referred_user_uni_id', $ids)
            ->get()
            ->groupBy('referred_user_uni_id');
        $statuses = $this->statusesFor($ids);
        $realized = DB::table('binance_pastpositions')->whereIn('uni_id', $ids)
            ->groupBy('uni_id')->selectRaw('uni_id, SUM(realized_pnl) AS s')->pluck('s', 'uni_id');
        $unrealized = DB::table('binance_positions')->whereIn('uni_id', $ids)
            ->groupBy('uni_id')->selectRaw('uni_id, SUM(unrealized_profit) AS s')->pluck('s', 'uni_id');

        $members = [];
        $activeCount = 0;
        $anyOverdue = false;
        $anyPending = false;
        $paidCommissionSum = '0';
        $unreleasedSum = '0';
        $pendingCommissionSum = '0';

        foreach ($tracking as $edge) {
            $uniId = $edge->referred_user_uni_id;
            $user = $users->get($uniId);
            $invoices = $invoicesByUser->get($uniId, collect());
            $items = $itemsByUser->get($uniId, collect());
            $internal = $statuses[$uniId] ?? 'new';

            if ($internal === 'active') {
                $activeCount++;
            }
            if ($internal === 'overdue') {
                $anyOverdue = true;
            }

            // Released commission per exchange (frozen 8-dp strings) + covered triples.
            $releasedByExchange = [];
            $releasedTriples = [];
            foreach ($items as $item) {
                $releasedByExchange[$item->exchange] = bcadd(
                    $releasedByExchange[$item->exchange] ?? '0',
                    (string) $item->commission,
                    8
                );
                $releasedTriples[$item->exchange.'|'.$item->month_year] = true;
            }

            // Per-exchange breakdown (aggregates across accounts).
            $breakdown = [];
            $paidTriples = [];
            foreach ($invoices->groupBy('exchange') as $exchange => $rows) {
                $paidFee = '0';
                $pendingFee = '0';
                $hasOverdue = false;
                foreach ($rows as $inv) {
                    $fee = (string) ($inv->total_fee ?? '0');
                    if ($inv->status === 'paid') {
                        $paidFee = bcadd($paidFee, $fee, 8);
                        $paidTriples[$exchange.'|'.$inv->month_year] = true;
                    } else {
                        $pendingFee = bcadd($pendingFee, $fee, 8);
                        $hasOverdue = $hasOverdue || $this->isOverdueInvoice($inv);
                    }
                }

                $paidCommission = $this->commission($paidFee, $pct);
                $pendingCommission = $this->commission($pendingFee, $pct);
                $releasedCommission = $releasedByExchange[$exchange] ?? '0';
                $unreleased = bcsub($paidCommission, $releasedCommission, 8);
                if (bccomp($unreleased, '0', 8) < 0) {
                    $unreleased = '0'; // released history can exceed a regenerated invoice's fee
                }

                if ($pendingFee !== '0' && bccomp($pendingFee, '0', 8) > 0) {
                    $anyPending = true;
                }
                $paidCommissionSum = bcadd($paidCommissionSum, $paidCommission, 8);
                $unreleasedSum = bcadd($unreleasedSum, $unreleased, 8);
                $pendingCommissionSum = bcadd($pendingCommissionSum, $pendingCommission, 8);

                $breakdown[] = [
                    'exchange' => $exchange,
                    'paid_fee' => round((float) $paidFee, 2),
                    'pending_fee' => round((float) $pendingFee, 2),
                    'paid_commission' => round((float) $paidCommission, 2),
                    'pending_commission' => round((float) $pendingCommission, 2),
                    'released_commission' => round((float) $releasedCommission, 2),
                    'unreleased_commission' => round((float) $unreleased, 2),
                    'has_overdue' => $hasOverdue,
                ];
            }

            // Latest paid / latest unpaid invoice (month_year strings sort chronologically, spec §6.8).
            $latestPaid = $invoices->where('status', 'paid')->sortByDesc('month_year')->first();
            $latestUnpaid = $invoices->whereIn('status', self::UNPAID)->sortByDesc('month_year')->first();
            $latest = $invoices->sortByDesc('month_year')->first();

            $lastEarnings = $latestPaid ? (string) ($latestPaid->total_fee ?? '0') : null;

            // Release coverage over paid (exchange|month) triples.
            $covered = count(array_intersect_key($paidTriples, $releasedTriples));
            $paymentReleaseStatus = $paidTriples === []
                ? 'n_a'
                : ($covered === 0 ? 'pending' : ($covered === count($paidTriples) ? 'paid' : 'partial'));

            $members[] = [
                'user_uni_id' => $uniId,
                'name' => $user->name ?? 'Unknown user',
                'referred_at' => $edge->created_at?->toDateTimeString(),
                'status' => $this->memberLabel($internal, $surface),
                'last_earnings' => $latestPaid ? round((float) $lastEarnings, 2) : null,
                'last_earnings_month' => $latestPaid?->month_year,
                'fee' => $latestUnpaid ? round((float) ($latestUnpaid->total_fee ?? 0), 2) : null,
                'your_fee' => $latestPaid ? round((float) $this->commission($lastEarnings, $pct), 2) : null,
                'payment_status' => $latest ? ($latest->status === 'paid' ? 'paid' : 'pending') : null,
                'payment_release_status' => $paymentReleaseStatus,
                'broker_breakdown' => $breakdown,
                'realized_pnl' => round((float) ($realized[$uniId] ?? 0), 2),
                'unrealized_pnl' => round((float) ($unrealized[$uniId] ?? 0), 2),
                'realized_percentage' => $user?->realized_percentage !== null ? (float) $user->realized_percentage : null,
                'unrealized_percentage' => $user?->unrealized_percentage !== null ? (float) $user->unrealized_percentage : null,
            ];
        }

        return [
            'members' => $members,
            'active_count' => $activeCount,
            'any_overdue' => $anyOverdue,
            'any_pending' => $anyPending,
            'paid_commission_sum' => $paidCommissionSum,
            'unreleased_sum' => $unreleasedSum,
            'pending_commission_sum' => $pendingCommissionSum,
        ];
    }

    // ── Admin: overview & releasable ──────────────────────────────────────────

    /** Every referrer + nested members + rollup flags (admin-surface labels). */
    public function overview(): array
    {
        $codes = ReferralCode::with('community')->orderBy('id')->get();
        $referrerIds = $codes->pluck('user_uni_id')->all();

        $referrers = UserCredential::whereIn('uni_id', $referrerIds)
            ->get(['uni_id', 'name', 'email', 'affiliate_percentage'])
            ->keyBy('uni_id');

        $payoutFlags = ReferrerPayout::whereIn('referrer_uni_id', $referrerIds)
            ->where('status', 'paid')
            ->groupBy('referrer_uni_id')
            ->selectRaw('referrer_uni_id, COUNT(*) AS c')
            ->pluck('c', 'referrer_uni_id');

        $out = [];
        foreach ($codes as $code) {
            $user = $referrers->get($code->user_uni_id);
            if (! $user) {
                continue;
            }

            $built = $this->membersFor($code->user_uni_id, $user->affiliate_percentage, 'admin');

            $out[] = [
                'referral_id' => $code->id,
                'code' => $code->code,
                'user_uni_id' => $code->user_uni_id,
                'referrer_name' => $user->name,
                'referrer_email' => $user->email,
                'affiliate_percentage' => $user->affiliate_percentage !== null ? (float) $user->affiliate_percentage : null,
                'community_name' => $code->community?->community_name,
                'referrals' => $built['members'],
                'referrer_overdue' => $built['any_overdue'],
                'referrer_pending' => $built['any_pending'],
                'total_commission_earned' => round((float) $built['paid_commission_sum'], 2),
                'releasable_commission_total' => round((float) $built['unreleased_sum'], 2),
                'projected_commission_total' => round((float) $built['pending_commission_sum'], 2),
                'has_releasable' => bccomp($built['unreleased_sum'], '0', 8) > 0,
                'has_released' => ($payoutFlags[$code->user_uni_id] ?? 0) > 0,
            ];
        }

        return $out;
    }

    /**
     * Releasable lines for one referrer: paid invoices of tracked users,
     * aggregated per (referred user, exchange, month) triple, minus already
     * released triples. Sorted referred-name asc, month desc.
     */
    public function releasableLinesFor(UserCredential $referrer): array
    {
        $pct = $referrer->affiliate_percentage;

        $trackedIds = ReferralTracking::where('referrer_uni_id', $referrer->uni_id)
            ->pluck('referred_user_uni_id')
            ->all();
        if ($trackedIds === []) {
            return [];
        }

        $names = UserCredential::whereIn('uni_id', $trackedIds)->pluck('name', 'uni_id');
        $released = ReferrerPayoutItem::where('referrer_uni_id', $referrer->uni_id)->get()
            ->keyBy(fn ($i) => $i->referred_user_uni_id.'|'.$i->exchange.'|'.$i->month_year);

        $lines = [];
        $paid = Invoice::whereIn('user_id', $trackedIds)->where('status', 'paid')->get();
        foreach ($paid->groupBy(fn ($inv) => $inv->user_id.'|'.$inv->exchange.'|'.$inv->month_year) as $key => $rows) {
            if ($released->has($key)) {
                continue;
            }
            $feePaid = '0';
            foreach ($rows as $inv) {
                $feePaid = bcadd($feePaid, (string) ($inv->total_fee ?? '0'), 8);
            }
            $first = $rows->first();
            $lines[] = [
                'referred_user_uni_id' => $first->user_id,
                'referred_name' => $names[$first->user_id] ?? 'Unknown user',
                'exchange' => $first->exchange,
                'month_year' => $first->month_year,
                'fee_paid' => round((float) $feePaid, 2),
                'commission' => round((float) $this->commission($feePaid, $pct), 2),
            ];
        }

        usort($lines, fn ($a, $b) => [$a['referred_name'], $b['month_year']] <=> [$b['referred_name'], $a['month_year']]);

        return $lines;
    }

    // ── The money write (spec §2 release integrity) ───────────────────────────

    /**
     * Release commission lines as one payout envelope + N frozen items,
     * atomically. Re-validates EVERY triple inside the transaction and rejects
     * the whole request on the first failure. total_amount is server-computed
     * (2-dp audit figure); the 8-dp truth lives on the items.
     *
     * @param  array<int,array{referred_user_uni_id:string,exchange:string,month_year:string}>  $triples
     */
    public function release(
        UserCredential $referrer,
        array $triples,
        string $paymentMethod,
        string $payoutAddress,
        ?string $txHash = null,
        ?string $proofPath = null,
    ): ReferrerPayout {
        // Dedupe (a double-submitted line is one line, not an error).
        $unique = [];
        foreach ($triples as $t) {
            $unique[$t['referred_user_uni_id'].'|'.$t['exchange'].'|'.$t['month_year']] = $t;
        }
        $triples = array_values($unique);
        if ($triples === []) {
            throw new ReleaseRejectedException('No items to release.');
        }

        $pct = $referrer->affiliate_percentage;
        $names = UserCredential::whereIn('uni_id', array_column($triples, 'referred_user_uni_id'))
            ->pluck('name', 'uni_id');

        return DB::transaction(function () use ($referrer, $triples, $pct, $names, $paymentMethod, $payoutAddress, $txHash, $proofPath) {
            $now = now();
            $rows = [];
            $total = '0';

            foreach ($triples as $t) {
                $uniId = $t['referred_user_uni_id'];
                $label = $names[$uniId] ?? $uniId;

                if (ReferrerPayoutItem::where('referrer_uni_id', $referrer->uni_id)
                    ->where('referred_user_uni_id', $uniId)
                    ->where('exchange', $t['exchange'])
                    ->where('month_year', $t['month_year'])
                    ->lockForUpdate()
                    ->exists()) {
                    throw new ReleaseRejectedException(
                        "Item already released: {$t['exchange']} {$t['month_year']} for {$label}"
                    );
                }

                $invoices = Invoice::where('user_id', $uniId)
                    ->where('exchange', $t['exchange'])
                    ->where('month_year', $t['month_year'])
                    ->where('status', 'paid')
                    ->lockForUpdate()
                    ->get();
                if ($invoices->isEmpty()) {
                    throw new ReleaseRejectedException(
                        "No paid invoice for {$t['exchange']} {$t['month_year']} / {$label}"
                    );
                }

                $feePaid = '0';
                foreach ($invoices as $inv) {
                    $feePaid = bcadd($feePaid, (string) ($inv->total_fee ?? '0'), 8);
                }
                $commission = $this->commission($feePaid, $pct);

                $rows[] = [
                    'referrer_uni_id' => $referrer->uni_id,
                    'referred_user_uni_id' => $uniId,
                    'exchange' => $t['exchange'],
                    'month_year' => $t['month_year'],
                    'fee_paid' => $feePaid,
                    'commission' => $commission,
                    'released_at' => $now,
                ];
                $total = bcadd($total, $commission, 8);
            }

            $months = array_values(array_unique(array_column($triples, 'month_year')));

            $payout = ReferrerPayout::create([
                'referrer_uni_id' => $referrer->uni_id,
                'month_year' => count($months) === 1 ? $months[0] : null,
                'total_amount' => round((float) $total, 2),
                'payout_address' => $payoutAddress,
                'tx_hash' => $txHash,
                'paid_at' => $now,
                'status' => 'paid',
                'payment_method' => $paymentMethod,
                'proof_file' => $proofPath,
            ]);

            foreach ($rows as $row) {
                // uq_payout_items_line is the final backstop against races the
                // exists() check above cannot see.
                $payout->items()->create($row);
            }

            return $payout->load('items');
        });
    }
}
