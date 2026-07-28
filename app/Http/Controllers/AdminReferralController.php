<?php

namespace App\Http\Controllers;

use App\Exceptions\ReleaseRejectedException;
use App\Models\BankWireAccount;
use App\Models\CryptoWallet;
use App\Models\ReferralCode;
use App\Models\ReferralTracking;
use App\Models\ReferrerPayout;
use App\Models\UserCredential;
use App\Services\ReferralService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin affiliate console (inside the admin middleware group — real auth,
 * spec §6.6). The release endpoint is the only money write in the system:
 * crypto posts JSON, bank wire posts multipart, but BOTH run through the one
 * code path below (spec §6.4) and ReferralService::release re-validates every
 * line inside a transaction.
 */
class AdminReferralController extends Controller
{
    public function __construct(private ReferralService $referrals)
    {
    }

    /** GET /api/admin/affiliate/overview — every referrer + nested network + flags. */
    public function overview(): JsonResponse
    {
        return response()->json(['success' => true, 'referrers' => $this->referrals->overview()]);
    }

    /** GET /api/admin/affiliate/ledger — all envelopes, newest first, referrer joined. */
    public function ledger(): JsonResponse
    {
        $payouts = ReferrerPayout::withCount('items')->orderByDesc('paid_at')->get();
        $users = UserCredential::whereIn('uni_id', $payouts->pluck('referrer_uni_id')->unique()->all())
            ->get(['uni_id', 'name', 'email'])
            ->keyBy('uni_id');

        $rows = $payouts->map(function (ReferrerPayout $p) use ($users) {
            $u = $users->get($p->referrer_uni_id);

            return $p->toApiArray() + [
                'referrer_name' => $u->name ?? 'Deleted user',
                'referrer_email' => $u->email ?? null,
            ];
        })->values();

        return response()->json(['success' => true, 'payouts' => $rows]);
    }

    /** GET /api/admin/affiliate/ledger-stats — count + sum of everything sent. */
    public function ledgerStats(): JsonResponse
    {
        $row = ReferrerPayout::where('status', 'paid')
            ->selectRaw('COUNT(*) AS c, COALESCE(SUM(total_amount), 0) AS s')
            ->first();

        return response()->json([
            'success' => true,
            'total_sent_count' => (int) $row->c,
            'total_sent_amount' => round((float) $row->s, 2),
        ]);
    }

    /** GET /api/admin/affiliate/referrers/{uniId}/payouts — one referrer's envelopes (lazy sub-tab). */
    public function referrerPayouts(string $uniId): JsonResponse
    {
        $payouts = ReferrerPayout::where('referrer_uni_id', $uniId)
            ->withCount('items')
            ->orderByDesc('paid_at')
            ->get()
            ->map(fn (ReferrerPayout $p) => $p->toApiArray())
            ->values();

        return response()->json(['success' => true, 'payouts' => $payouts]);
    }

    /** GET /api/admin/affiliate/referrers/{uniId}/releasable — feeds the release screen. */
    public function referrerReleasable(string $uniId): JsonResponse
    {
        $referrer = UserCredential::find($uniId);
        if (! $referrer) {
            return $this->notFound('REFERRER_NOT_FOUND', 'Referrer not found.');
        }

        $code = ReferralCode::where('user_uni_id', $uniId)->first();

        return response()->json([
            'success' => true,
            'releasable_items' => $this->referrals->releasableLinesFor($referrer),
            'referrer_wallets' => CryptoWallet::where('uni_id', $uniId)
                ->orderByDesc('is_main')->orderBy('id')->get()
                ->map(fn ($w) => $w->toApiArray())->values(),
            'bank_wire_accounts' => BankWireAccount::where('uni_id', $uniId)
                ->orderByDesc('is_main')->orderBy('id')->get()
                ->map(fn ($b) => $b->toApiArray())->values(),
            'referrer_name' => $referrer->name,
            'referrer_code' => $code?->code,
            'affiliate_percentage' => $referrer->affiliate_percentage !== null
                ? (float) $referrer->affiliate_percentage
                : null,
        ]);
    }

    /**
     * POST /api/admin/affiliate/release — THE money write.
     * Crypto: JSON with tx_hash. Bank wire: multipart with proof_file
     * (→ private disk). Items are ALWAYS (referred_user, exchange, month)
     * triples — never invoice ids (spec §6.10).
     */
    public function release(Request $request): JsonResponse
    {
        // Multipart sends items as a JSON string; JSON sends a real array.
        // Normalize before validating so both encodings share one rule set.
        if (is_string($request->input('items'))) {
            $decoded = json_decode($request->input('items'), true);
            $request->merge(['items' => is_array($decoded) ? $decoded : []]);
        }
        $request->merge(['payment_method' => $request->input('payment_method', 'crypto')]);

        $validated = $request->validate([
            'referrer_uni_id' => ['required', 'string', 'max:36'],
            'payment_method' => ['required', Rule::in(['crypto', 'bank_wire'])],
            'payout_address' => ['required', 'string', 'max:255'],
            'tx_hash' => ['required_if:payment_method,crypto', 'nullable', 'string', 'max:255'],
            'proof_file' => ['required_if:payment_method,bank_wire', 'nullable', 'file', 'mimes:jpeg,jpg,png,gif,webp,pdf', 'max:5120'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.referred_user_uni_id' => ['required', 'string', 'max:36'],
            'items.*.exchange' => ['required', Rule::in(['binance', 'bybit', 'mexc'])],
            'items.*.month_year' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        $referrer = UserCredential::find($validated['referrer_uni_id']);
        if (! $referrer) {
            return $this->notFound('REFERRER_NOT_FOUND', 'Referrer not found.');
        }

        $proofPath = null;
        if ($validated['payment_method'] === 'bank_wire') {
            $proofPath = $request->file('proof_file')->store('referral-proofs', 'local');
        }

        try {
            $payout = $this->referrals->release(
                $referrer,
                $validated['items'],
                $validated['payment_method'],
                $validated['payout_address'],
                $validated['tx_hash'] ?? null,
                $proofPath,
            );
        } catch (ReleaseRejectedException $e) {
            $this->deleteProof($proofPath);

            return response()->json([
                'success' => false,
                'error_code' => 'RELEASE_REJECTED',
                'message' => $e->getMessage(),
            ], 422);
        } catch (QueryException $e) {
            $this->deleteProof($proofPath);

            // uq_payout_items_line backstop: a race the exists() check couldn't see.
            if (str_contains($e->getMessage(), 'uq_payout_items_line')) {
                return response()->json([
                    'success' => false,
                    'error_code' => 'RELEASE_REJECTED',
                    'message' => 'One of the selected items was already released.',
                ], 422);
            }

            throw $e;
        }

        return response()->json([
            'success' => true,
            'message' => 'Payout released.',
            'payout' => $payout->toApiArray() + [
                'items' => $payout->items->map(fn ($i) => $i->toApiArray())->values()->all(),
            ],
        ], 201);
    }

    /**
     * DELETE /api/admin/affiliate/payouts/{id} — the Undo, and the ONLY
     * rollback: items cascade-delete, which makes those lines releasable again.
     */
    public function destroyPayout(int $id): JsonResponse
    {
        $payout = ReferrerPayout::find($id);
        if (! $payout) {
            return $this->notFound('PAYOUT_NOT_FOUND', 'Payout not found.');
        }

        $proof = $payout->proof_file;
        $payout->delete();
        $this->deleteProof($proof);

        return response()->json(['success' => true, 'message' => 'Payout release undone.']);
    }

    /** GET /api/admin/affiliate/payouts/{id}/proof — authorized download from the private disk. */
    public function downloadProof(int $id): JsonResponse|StreamedResponse
    {
        $payout = ReferrerPayout::find($id);
        if (! $payout || ! $payout->proof_file || ! Storage::disk('local')->exists($payout->proof_file)) {
            return $this->notFound('PROOF_NOT_FOUND', 'No proof file for this payout.');
        }

        return Storage::disk('local')->download($payout->proof_file);
    }

    /** GET /api/admin/affiliate/users/{uniId}/referrals — read-only per-user listing (spec §5). */
    public function userReferrals(string $uniId): JsonResponse
    {
        $rows = ReferralTracking::where('referrer_uni_id', $uniId)
            ->orderByDesc('created_at')
            ->get();
        $names = UserCredential::whereIn('uni_id', $rows->pluck('referred_user_uni_id'))
            ->pluck('name', 'uni_id');

        return response()->json([
            'success' => true,
            'referrals' => $rows->map(fn (ReferralTracking $r) => [
                'referred_user_uni_id' => $r->referred_user_uni_id,
                'name' => $names[$r->referred_user_uni_id] ?? 'Unknown user',
                'referral_code' => $r->referral_code,
                'referred_at' => $r->created_at?->toDateTimeString(),
            ])->values(),
        ]);
    }

    private function deleteProof(?string $path): void
    {
        if ($path) {
            Storage::disk('local')->delete($path);
        }
    }

    private function notFound(string $code, string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error_code' => $code,
            'message' => $message,
        ], 404);
    }
}
