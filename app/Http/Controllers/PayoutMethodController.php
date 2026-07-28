<?php

namespace App\Http\Controllers;

use App\Models\BankWireAccount;
use App\Models\CryptoWallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * A user's saved payout methods (Settings → payment methods): crypto wallets
 * and bank-wire accounts. is_main marks the preferred method per type — it
 * pre-fills the admin affiliate release screen. Every route is ownership-
 * checked against the authenticated user.
 */
class PayoutMethodController extends Controller
{
    private const WALLET_RULES = [
        'network' => ['required', 'string', 'max:50'],
        'address' => ['required', 'string', 'max:255'],
        'name' => ['required', 'string', 'max:100'],
    ];

    private const BANK_RULES = [
        'label' => ['required', 'string', 'max:100'],
        'account_holder' => ['nullable', 'string', 'max:255'],
        'bank_name' => ['nullable', 'string', 'max:255'],
        'account_number' => ['nullable', 'string', 'max:100'],
        'routing_number' => ['nullable', 'string', 'max:100'],
        'iban' => ['nullable', 'string', 'max:100'],
        'swift_bic' => ['nullable', 'string', 'max:50'],
        'account_type' => ['nullable', 'string', 'max:50'],
        'bank_address' => ['nullable', 'string', 'max:500'],
        'currency' => ['nullable', 'string', 'max:10'],
    ];

    /** GET /api/payout-methods — wallets + bank accounts, main first. */
    public function index(Request $request): JsonResponse
    {
        $uniId = $request->user()->uni_id;

        return response()->json([
            'success' => true,
            'wallets' => CryptoWallet::where('uni_id', $uniId)
                ->orderByDesc('is_main')->orderBy('id')->get()
                ->map(fn ($w) => $w->toApiArray())->values(),
            'bank_accounts' => BankWireAccount::where('uni_id', $uniId)
                ->orderByDesc('is_main')->orderBy('id')->get()
                ->map(fn ($b) => $b->toApiArray())->values(),
        ]);
    }

    /** POST /api/payout-methods/wallets — the first wallet becomes main automatically. */
    public function storeWallet(Request $request): JsonResponse
    {
        $validated = $request->validate(self::WALLET_RULES);
        $uniId = $request->user()->uni_id;

        $wallet = CryptoWallet::create($validated + [
            'uni_id' => $uniId,
            'is_main' => ! CryptoWallet::where('uni_id', $uniId)->exists(),
        ]);

        return response()->json(['success' => true, 'wallet' => $wallet->toApiArray()], 201);
    }

    /** PUT /api/payout-methods/wallets/{id} */
    public function updateWallet(Request $request, int $id): JsonResponse
    {
        $wallet = CryptoWallet::where('uni_id', $request->user()->uni_id)->find($id);
        if (! $wallet) {
            return $this->notFound('WALLET_NOT_FOUND', 'Wallet not found.');
        }

        $wallet->update($request->validate(self::WALLET_RULES));

        return response()->json(['success' => true, 'wallet' => $wallet->toApiArray()]);
    }

    /** DELETE /api/payout-methods/wallets/{id} — deleting the main promotes the next one. */
    public function destroyWallet(Request $request, int $id): JsonResponse
    {
        $wallet = CryptoWallet::where('uni_id', $request->user()->uni_id)->find($id);
        if (! $wallet) {
            return $this->notFound('WALLET_NOT_FOUND', 'Wallet not found.');
        }

        $wasMain = $wallet->is_main;
        $wallet->delete();
        if ($wasMain) {
            CryptoWallet::where('uni_id', $request->user()->uni_id)
                ->orderBy('id')->first()?->update(['is_main' => true]);
        }

        return response()->json(['success' => true, 'message' => 'Wallet removed.']);
    }

    /** POST /api/payout-methods/banks */
    public function storeBank(Request $request): JsonResponse
    {
        $validated = $request->validate(self::BANK_RULES);
        $uniId = $request->user()->uni_id;

        $bank = BankWireAccount::create($validated + [
            'uni_id' => $uniId,
            'currency' => $validated['currency'] ?? 'USD',
            'is_main' => ! BankWireAccount::where('uni_id', $uniId)->exists(),
        ]);

        return response()->json(['success' => true, 'bank_account' => $bank->toApiArray()], 201);
    }

    /** PUT /api/payout-methods/banks/{id} */
    public function updateBank(Request $request, int $id): JsonResponse
    {
        $bank = BankWireAccount::where('uni_id', $request->user()->uni_id)->find($id);
        if (! $bank) {
            return $this->notFound('BANK_ACCOUNT_NOT_FOUND', 'Bank account not found.');
        }

        $bank->update($request->validate(self::BANK_RULES));

        return response()->json(['success' => true, 'bank_account' => $bank->toApiArray()]);
    }

    /** DELETE /api/payout-methods/banks/{id} */
    public function destroyBank(Request $request, int $id): JsonResponse
    {
        $bank = BankWireAccount::where('uni_id', $request->user()->uni_id)->find($id);
        if (! $bank) {
            return $this->notFound('BANK_ACCOUNT_NOT_FOUND', 'Bank account not found.');
        }

        $wasMain = $bank->is_main;
        $bank->delete();
        if ($wasMain) {
            BankWireAccount::where('uni_id', $request->user()->uni_id)
                ->orderBy('id')->first()?->update(['is_main' => true]);
        }

        return response()->json(['success' => true, 'message' => 'Bank account removed.']);
    }

    /** POST /api/payout-methods/set-main — one main per type; clears siblings atomically. */
    public function setMain(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(['wallet', 'bank'])],
            'id' => ['required', 'integer'],
        ]);

        $uniId = $request->user()->uni_id;
        $query = $validated['type'] === 'wallet'
            ? CryptoWallet::where('uni_id', $uniId)
            : BankWireAccount::where('uni_id', $uniId);

        $row = (clone $query)->find($validated['id']);
        if (! $row) {
            return $this->notFound('PAYOUT_METHOD_NOT_FOUND', 'Payout method not found.');
        }

        DB::transaction(function () use ($query, $row) {
            (clone $query)->where('id', '!=', $row->id)->update(['is_main' => false]);
            $row->update(['is_main' => true]);
        });

        return response()->json(['success' => true, 'message' => 'Main payout method updated.']);
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
