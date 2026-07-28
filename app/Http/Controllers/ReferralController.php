<?php

namespace App\Http\Controllers;

use App\Models\CommunityDetail;
use App\Models\ReferralTracking;
use App\Models\ReferrerPayout;
use App\Services\ReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * User-side affiliate endpoints (auth:sanctum). All money figures come from
 * ReferralService — the frontend never computes commission (spec §6.1).
 */
class ReferralController extends Controller
{
    public function __construct(private ReferralService $referrals)
    {
    }

    /**
     * GET /api/referrals — the whole referrals-dashboard payload:
     * code, community, server-computed KPI stats, and the network array
     * (user-surface labels: the overdue state reads "suspended").
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $code = $this->referrals->codeFor($user->uni_id);
        $dashboard = $this->referrals->dashboardFor($user);

        return response()->json([
            'success' => true,
            'code' => $code?->code,
            'referral_id' => $code?->id,
            'affiliate_percentage' => $user->affiliate_percentage !== null
                ? (float) $user->affiliate_percentage
                : null,
            'community' => $code?->community?->toApiArray(),
            'stats' => $dashboard['stats'],
            'referrals' => $dashboard['referrals'],
        ]);
    }

    /** POST /api/referrals/code — generate if absent; idempotent. */
    public function createCode(Request $request): JsonResponse
    {
        $code = $this->referrals->getOrCreateCode($request->user());

        return response()->json([
            'success' => true,
            'code' => $code->code,
            'referral_id' => $code->id,
        ]);
    }

    /** GET /api/referrals/community */
    public function community(Request $request): JsonResponse
    {
        $code = $this->referrals->codeFor($request->user()->uni_id);

        return response()->json([
            'success' => true,
            'community' => $code?->community?->toApiArray(),
        ]);
    }

    /**
     * POST /api/referrals/community — create or update the 1:1 profile.
     * Both fields required. Creates the referral code implicitly if absent
     * (the profile is meaningless without one).
     */
    public function saveCommunity(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'community_name' => ['required', 'string', 'max:255'],
            'bio' => ['required', 'string', 'max:2000'],
        ]);

        $code = $this->referrals->getOrCreateCode($request->user());
        $community = CommunityDetail::updateOrCreate(
            ['referral_id' => $code->id],
            $validated,
        );

        return response()->json(['success' => true, 'community' => $community->toApiArray()]);
    }

    /**
     * POST /api/referrals/members/remove — delete the tracking row only.
     * Payout history is intentionally untouched (spec §2, Removal).
     */
    public function removeMember(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'referred_user_uni_id' => ['required', 'string', 'max:36'],
        ]);

        $deleted = ReferralTracking::where('referrer_uni_id', $request->user()->uni_id)
            ->where('referred_user_uni_id', $validated['referred_user_uni_id'])
            ->delete();

        if ($deleted === 0) {
            return response()->json([
                'success' => false,
                'error_code' => 'REFERRAL_NOT_FOUND',
                'message' => 'That user is not in your network.',
            ], 404);
        }

        return response()->json(['success' => true, 'message' => 'Referral removed.']);
    }

    /** GET /api/referrals/payouts — the caller's released envelopes, newest first. */
    public function payouts(Request $request): JsonResponse
    {
        $payouts = ReferrerPayout::where('referrer_uni_id', $request->user()->uni_id)
            ->withCount('items')
            ->orderByDesc('paid_at')
            ->get()
            ->map(fn (ReferrerPayout $p) => $p->toApiArray())
            ->values();

        return response()->json(['success' => true, 'payouts' => $payouts]);
    }
}
