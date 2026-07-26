<?php

namespace App\Http\Controllers;

use App\Models\BinanceAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Exchange accounts connected by the user (Binance today; Bybit/MEXC later).
 * All routes are auth:sanctum and scoped to the authenticated user's uni_id.
 */
class ExchangeAccountController extends Controller
{
    /**
     * GET /api/exchange/accounts
     * Active (non-deleted) exchange accounts, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $accounts = BinanceAccount::where('uni_id', $request->user()->uni_id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['success' => true, 'accounts' => $accounts]);
    }

    /**
     * POST /api/exchange/binance
     * Connect a Binance account (from the connect wizard).
     */
    public function storeBinance(Request $request): JsonResponse
    {
        // One Binance account per user — soft-deleted (disconnected) rows don't count.
        $alreadyConnected = BinanceAccount::where('uni_id', $request->user()->uni_id)->exists();

        if ($alreadyConnected) {
            return response()->json([
                'success' => false,
                'message' => 'You already have a Binance account connected. Disconnect it first to connect a different one.',
            ], 422);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128', Rule::unique('binance_accounts', 'name')],
            'api_key' => ['required', 'string', 'max:128', Rule::unique('binance_accounts', 'api_key')],
            'secret_key' => ['required', 'string', 'max:128'],
        ], [
            'name.unique' => 'An account with this name already exists.',
            'api_key.unique' => 'This API key is already connected.',
        ]);

        $account = BinanceAccount::create([
            ...$validated,
            'uni_id' => $request->user()->uni_id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Binance account connected',
            // refresh() picks up DB defaults (currency_type, enabled, created_at)
            'account' => $account->refresh(),
        ], 201);
    }

    /**
     * DELETE /api/exchange/accounts/{id}
     * Soft-delete (disconnect) one of the user's accounts.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $account = BinanceAccount::where('uni_id', $request->user()->uni_id)->find($id);

        if (! $account) {
            return response()->json([
                'success' => false,
                'message' => 'Account not found.',
            ], 404);
        }

        $account->delete();

        return response()->json([
            'success' => true,
            'message' => 'Account disconnected',
        ]);
    }
}
