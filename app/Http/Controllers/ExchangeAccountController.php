<?php

namespace App\Http\Controllers;

use App\Models\BinanceAccount;
use App\Services\EngineCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * Exchange accounts connected by the user (Binance today; Bybit/MEXC later).
 * All routes are auth:sanctum and scoped to the authenticated user's uni_id.
 *
 * Connecting and disconnecting change WHO the engine trades, so both tell the
 * engine to drop its cached account list — see {@see EngineCache}. Without
 * that a new account sits idle until the engine's TTL expires, which is the
 * difference between "I connected and it traded the next signal" and "I
 * connected and nothing happened for a minute and a half".
 */
class ExchangeAccountController extends Controller
{
    /** Seconds between manual balance refreshes, per account. */
    private const BALANCE_REFRESH_COOLDOWN = 60;

    public function __construct(private EngineCache $engineCache) {}

    /**
     * GET /api/exchange/accounts
     * Active (non-deleted) exchange accounts, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $accounts = BinanceAccount::where('uni_id', $request->user()->uni_id)
            ->orderByDesc('created_at')
            ->get()
            ->map(function (BinanceAccount $account) {
                // The deadline is derived, never stored: one source of truth
                // (key_blocked_at) and no second column to drift from it.
                $account->setAttribute(
                    'key_grace_ends_at',
                    $account->keyGraceEndsAt()?->toIso8601String()
                );

                return $account;
            });

        return response()->json([
            'success' => true,
            'accounts' => $accounts,
            // The address the user has to allow-list to fix a blocked key.
            'server_ip' => config('services.engine.public_ip'),
        ]);
    }

    /**
     * POST /api/exchange/binance
     * Connect a Binance account (from the connect wizard).
     */
    public function storeBinance(Request $request): JsonResponse
    {
        // Only approved accounts may connect an exchange — the engine trades
        // every non-suspended account, so pending users must not slip a key in.
        if ($request->user()->status !== 'active') {
            return response()->json([
                'success' => false,
                'error_code' => 'PENDING_APPROVAL',
                'message' => 'Your account is pending approval. You can connect an exchange once an admin approves you.',
            ], 403);
        }

        // One Binance account per user — soft-deleted (disconnected) rows don't count.
        $alreadyConnected = BinanceAccount::where('uni_id', $request->user()->uni_id)->exists();

        if ($alreadyConnected) {
            return response()->json([
                'success' => false,
                'message' => 'You already have a Binance account connected. Disconnect it first to connect a different one.',
            ], 422);
        }

        // Reconnecting a key you previously disconnected REVIVES that row
        // instead of failing "already connected". A disconnect is a soft
        // delete, and `api_key`/`name` carry table-wide UNIQUE indexes, so the
        // old row is the only row those credentials can ever live in — without
        // this, disconnecting a key locked its owner out of it forever, which
        // is exactly the path a blocked-key recovery walks down.
        $existing = BinanceAccount::withTrashed()
            ->where('api_key', (string) $request->input('api_key'))
            ->first();

        // Someone else's disconnected row is NOT revivable: it carries their
        // trade history, and handing that to whoever pastes the same key would
        // move one user's P&L onto another's dashboard. (A live row belonging
        // to anyone is caught by the unique rule below as "already connected".)
        if ($existing && $existing->trashed() && $existing->uni_id !== $request->user()->uni_id) {
            return response()->json([
                'success' => false,
                'error_code' => 'API_KEY_TAKEN',
                'message' => 'This API key is registered to a different account. Create a new key on Binance, or contact support.',
            ], 422);
        }

        $revivable = $existing && $existing->trashed() ? $existing : null;

        $validated = $request->validate([
            // Name is unique table-wide (the index is not partial), so a
            // disconnected row still holds its name — except the one we are
            // about to revive, which is being renamed anyway.
            'name' => [
                'required', 'string', 'max:128',
                Rule::unique('binance_accounts', 'name')->ignore($revivable?->id),
            ],
            // Live rows only: a disconnected key is free to come back.
            'api_key' => [
                'required', 'string', 'max:128',
                Rule::unique('binance_accounts', 'api_key')->whereNull('deleted_at'),
            ],
            'secret_key' => ['required', 'string', 'max:128'],
            // Picks the network the engine talks to for this account: demo
            // routes every call to the Binance futures testnet, live to
            // mainnet (see accounts_api.py::base_for). The keys are NOT
            // interchangeable — a testnet key is issued by a different site —
            // so this is chosen by the user in the connect wizard, not guessed.
            'demo' => ['sometimes', 'boolean'],
        ], [
            'name.unique' => 'An account with this name already exists.',
            'api_key.unique' => 'This API key is already connected.',
        ]);

        $fields = [
            ...$validated,
            // Absent means live: the column defaults to 0, and the safe
            // failure for a missing flag is "trade where the keys came from".
            'demo' => (bool) ($validated['demo'] ?? false),
            'uni_id' => $request->user()->uni_id,
        ];

        if ($revivable) {
            // New credentials, new label, trading again — and the key verdict
            // is reset, because whatever the exchange said before the
            // disconnect says nothing about the key being pasted now. The next
            // poller tick settles it for real.
            $revivable->fill($fields + [
                'enabled' => true,
                'key_status' => BinanceAccount::KEY_OK,
                'key_error_code' => null,
                'key_error_reason' => null,
                'key_error_message' => null,
                'key_blocked_at' => null,
            ]);
            $revivable->restore();

            $account = $revivable;
        } else {
            $account = BinanceAccount::create($fields);
        }

        // Deliberately NOT reset on a revive: balance, initial_deposit and the
        // invoice high-water mark. It is the same account coming back, its
        // deposits are still in binance_transactions, and letting a
        // disconnect/reconnect wipe the HWM would make billing optional.

        // Tradeable from the next signal, not from the next TTL expiry.
        $this->engineCache->refreshAccounts();

        return response()->json([
            'success' => true,
            'reconnected' => $revivable !== null,
            'message' => $revivable
                ? 'Binance account reconnected'
                : 'Binance account connected',
            // refresh() picks up DB defaults (currency_type, enabled, created_at)
            'account' => $account->refresh(),
        ], 201);
    }

    /**
     * PUT /api/exchange/accounts/{id}
     * Rename one of the user's accounts. The name is a display label only —
     * the engine keys accounts by id/uni_id and uses the name for logs and
     * Telegram lines — so a rename never affects trading.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $account = BinanceAccount::where('uni_id', $request->user()->uni_id)->find($id);

        if (! $account) {
            return response()->json([
                'success' => false,
                'message' => 'Account not found.',
            ], 404);
        }

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:128',
                Rule::unique('binance_accounts', 'name')->ignore($account->id),
            ],
        ], [
            'name.unique' => 'An account with this name already exists.',
        ]);

        $account->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Account renamed',
            'account' => $account->refresh(),
        ]);
    }

    /**
     * POST /api/exchange/accounts/{id}/refresh-balance
     *
     * Pull this ONE account's balance from the exchange now, instead of waiting
     * for the poller's next tick — so someone who has just connected sees a real
     * number rather than "Awaiting sync" for five minutes.
     *
     * The cooldown is enforced HERE, not in the button: a client-side timer is
     * a courtesy, and the thing being protected is our Binance rate limit, which
     * a page reload or a curl would otherwise walk straight past. `Cache::add`
     * is the atomic claim, so two rapid clicks cannot both win.
     */
    public function refreshBalance(Request $request, int $id): JsonResponse
    {
        $account = BinanceAccount::where('uni_id', $request->user()->uni_id)->find($id);

        if (! $account) {
            return response()->json(['success' => false, 'message' => 'Account not found.'], 404);
        }

        $key = "exchange:balance-refresh:{$account->id}";
        $readyAt = now()->addSeconds(self::BALANCE_REFRESH_COOLDOWN)->getTimestamp();

        if (! Cache::add($key, $readyAt, self::BALANCE_REFRESH_COOLDOWN)) {
            $retryAfter = max(1, (int) Cache::get($key, $readyAt) - now()->getTimestamp());

            return response()->json([
                'success' => false,
                'error_code' => 'COOLDOWN',
                'message' => "Balance was just refreshed. Try again in {$retryAfter}s.",
                'retry_after' => $retryAfter,
            ], 429);
        }

        // The engine reads Binance and posts the row back to /api/engine/*, so
        // the fresh figure is in the DB by the time this returns. That round
        // trip also settles the key verdict, which is why this doubles as the
        // "I've fixed my IP allow-list, recheck" button.
        $synced = $this->engineCache->syncBalances([$account->api_key]);

        $account->refresh();
        $account->setAttribute('key_grace_ends_at', $account->keyGraceEndsAt()?->toIso8601String());

        return response()->json([
            'success' => $synced,
            'message' => $synced
                ? 'Balance updated.'
                : 'Could not reach the exchange right now. Your balance will update automatically.',
            'account' => $account,
            'retry_after' => self::BALANCE_REFRESH_COOLDOWN,
        ], $synced ? 200 : 503);
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

        // Stops receiving the fan-out now — a disconnect that lingers for a
        // TTL is trading with keys the user believes they revoked.
        $this->engineCache->refreshAccounts();

        return response()->json([
            'success' => true,
            'message' => 'Account disconnected',
        ]);
    }
}
