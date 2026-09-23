<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureAdmin;
use App\Models\ExchangeAccount;
use App\Services\Discord\DiscordRoleSync;
use App\Services\EngineCache;
use App\Services\Exchanges\ExchangeSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * Exchange accounts connected by the user — Binance and MEXC today, one
 * account per user PER exchange. All routes are auth:sanctum and scoped to the
 * authenticated user's uni_id.
 *
 * Every exchange keeps its own accounts table (ExchangeSchema), so an account
 * is addressed by (exchange, id) — ids collide across tables — and every row
 * this controller returns carries its `exchange` so the client can route the
 * rename / refresh / disconnect back to the right table.
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

    /**
     * Exchanges with a futures TESTNET the engine can route a demo account to.
     * Binance: testnet.binancefuture.com (separate keys). MEXC:
     * futures.testnet.mexc.com — same login and same API keys as live, but the
     * key must carry NO IP binding (the testnet sits behind a CDN and refuses
     * IP-bound keys). A venue without one would refuse `demo` here, because a
     * demo row on it could only be a live account wearing the wrong badge.
     */
    private const HAS_TESTNET = ['binance' => true, 'mexc' => true];

    public function __construct(
        private EngineCache $engineCache,
        private DiscordRoleSync $discordRoles,
    ) {}

    /**
     * GET /api/exchange/accounts
     * Active (non-deleted) accounts on every exchange, newest first, each row
     * stamped with its `exchange`.
     */
    public function index(Request $request): JsonResponse
    {
        $uniId = $request->user()->uni_id;
        $accounts = collect();

        foreach (ExchangeSchema::supported() as $exchange) {
            $rows = ExchangeSchema::for($exchange)->accountQuery()
                ->where('uni_id', $uniId)
                ->get()
                ->map(fn (ExchangeAccount $account) => $this->present($account, $exchange));
            $accounts = $accounts->concat($rows);
        }

        return response()->json([
            'success' => true,
            'accounts' => $accounts->sortByDesc(fn ($a) => (string) $a->created_at)->values(),
            // The address the user has to allow-list to fix a blocked key.
            'server_ip' => config('services.engine.public_ip'),
        ]);
    }

    /**
     * POST /api/exchange/{exchange}
     * Connect an account on one exchange (from the connect wizard). The old
     * POST /api/exchange/binance is the same route with the exchange filled in.
     */
    public function store(Request $request, string $exchange): JsonResponse
    {
        if ($unsupported = $this->guardExchange($exchange)) {
            return $unsupported;
        }
        $schema = ExchangeSchema::for($exchange);
        $label = $schema->brokerLabel;
        $table = $schema->accountsTable;

        // A venue that works in the engine but is not yet offered to customers
        // (config/exchanges.php). Connecting only: an account already on a
        // restricted venue keeps trading and can still be renamed or
        // disconnected — closing a venue must not trap someone's keys in it.
        if ($restricted = $this->guardStaffOnlyExchange($exchange, $request->user(), $label)) {
            return $restricted;
        }

        // Only approved accounts may connect an exchange — the engine trades
        // every non-suspended account, so pending users must not slip a key in.
        if ($request->user()->status !== 'active') {
            return response()->json([
                'success' => false,
                'error_code' => 'PENDING_APPROVAL',
                'message' => 'Your account is pending approval. You can connect an exchange once an admin approves you.',
            ], 403);
        }

        // One account per user PER EXCHANGE — soft-deleted (disconnected) rows
        // don't count, and a Binance account does not use up the MEXC slot.
        $alreadyConnected = $schema->accountQuery()->where('uni_id', $request->user()->uni_id)->exists();

        if ($alreadyConnected) {
            return response()->json([
                'success' => false,
                'error_code' => 'ALREADY_CONNECTED',
                'message' => "You already have a {$label} account connected. Disconnect it first to connect a different one.",
            ], 422);
        }

        // Reconnecting a key you previously disconnected REVIVES that row
        // instead of failing "already connected". A disconnect is a soft
        // delete, and `api_key`/`name` carry table-wide UNIQUE indexes, so the
        // old row is the only row those credentials can ever live in — without
        // this, disconnecting a key locked its owner out of it forever, which
        // is exactly the path a blocked-key recovery walks down.
        $existing = $schema->accountQuery()->withTrashed()
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
                'message' => "This API key is registered to a different account. Create a new key on {$label}, or contact support.",
            ], 422);
        }

        $revivable = $existing && $existing->trashed() ? $existing : null;

        $validated = $request->validate([
            // Name is unique table-wide (the index is not partial), so a
            // disconnected row still holds its name — except the one we are
            // about to revive, which is being renamed anyway.
            'name' => [
                'required', 'string', 'max:128',
                Rule::unique($table, 'name')->ignore($revivable?->id),
            ],
            // Live rows only: a disconnected key is free to come back.
            'api_key' => [
                'required', 'string', 'max:128',
                Rule::unique($table, 'api_key')->whereNull('deleted_at'),
            ],
            'secret_key' => ['required', 'string', 'max:128'],
            // Picks the network the engine talks to for this account: demo
            // routes every call to the exchange's futures testnet, live to
            // mainnet. The keys are NOT interchangeable — a testnet key is
            // issued by a different site — so this is chosen by the user in
            // the connect wizard, not guessed. Exchanges without a testnet
            // refuse `true` below.
            'demo' => ['sometimes', 'boolean'],
        ], [
            'name.unique' => 'An account with this name already exists.',
            'api_key.unique' => 'This API key is already connected.',
        ]);

        $demo = (bool) ($validated['demo'] ?? false);
        if ($demo && ! (self::HAS_TESTNET[$exchange] ?? false)) {
            return response()->json([
                'success' => false,
                'error_code' => 'DEMO_NOT_AVAILABLE',
                'message' => "{$label} has no futures testnet — connect live keys, or start with a Binance demo account.",
                'errors' => ['demo' => ["{$label} has no futures testnet."]],
            ], 422);
        }

        $fields = [
            ...$validated,
            // Absent means live: the column defaults to 0, and the safe
            // failure for a missing flag is "trade where the keys came from".
            'demo' => $demo,
            'uni_id' => $request->user()->uni_id,
        ];

        if ($revivable) {
            // New credentials, new label, trading again — and the key verdict
            // is reset, because whatever the exchange said before the
            // disconnect says nothing about the key being pasted now. The next
            // poller tick settles it for real.
            $revivable->fill($fields + [
                'enabled' => true,
                'key_status' => ExchangeAccount::KEY_OK,
                'key_error_code' => null,
                'key_error_reason' => null,
                'key_error_message' => null,
                'key_blocked_at' => null,
            ]);
            $revivable->restore();

            $account = $revivable;
        } else {
            $account = ($schema->accountModel)::create($fields);
        }

        // Deliberately NOT reset on a revive: balance, initial_deposit and the
        // invoice high-water mark. It is the same account coming back, its
        // deposits are still in its transactions table, and letting a
        // disconnect/reconnect wipe the HWM would make billing optional.

        // Tradeable from the next signal, not from the next TTL expiry.
        $this->engineCache->refreshAccounts();
        // A live account is what earns the Discord "Trader" role (demo does not).
        $this->discordRoles->syncUser($request->user());

        return response()->json([
            'success' => true,
            'reconnected' => $revivable !== null,
            'message' => $revivable
                ? "{$label} account reconnected"
                : "{$label} account connected",
            // refresh() picks up DB defaults (currency_type, enabled, created_at)
            'account' => $this->present($account->refresh(), $exchange),
        ], 201);
    }

    /** Legacy route: PUT /api/exchange/accounts/{id} (Binance). */
    public function updateBinance(Request $request, int $id): JsonResponse
    {
        return $this->update($request, 'binance', $id);
    }

    /**
     * PUT /api/exchange/{exchange}/accounts/{id}
     * Rename one of the user's accounts. The name is a display label only —
     * the engine keys accounts by api_key/uni_id and uses the name for logs and
     * Telegram lines — so a rename never affects trading.
     */
    public function update(Request $request, string $exchange, int $id): JsonResponse
    {
        if ($unsupported = $this->guardExchange($exchange)) {
            return $unsupported;
        }
        $schema = ExchangeSchema::for($exchange);
        $account = $schema->accountQuery()->where('uni_id', $request->user()->uni_id)->find($id);

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
                Rule::unique($schema->accountsTable, 'name')->ignore($account->id),
            ],
        ], [
            'name.unique' => 'An account with this name already exists.',
        ]);

        $account->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Account renamed',
            'account' => $this->present($account->refresh(), $exchange),
        ]);
    }

    /** Legacy route: POST /api/exchange/accounts/{id}/refresh-balance (Binance). */
    public function refreshBalanceBinance(Request $request, int $id): JsonResponse
    {
        return $this->refreshBalance($request, 'binance', $id);
    }

    /**
     * POST /api/exchange/{exchange}/accounts/{id}/refresh-balance
     *
     * Pull this ONE account's balance from the exchange now, instead of waiting
     * for the poller's next tick — so someone who has just connected sees a real
     * number rather than "Awaiting sync" for five minutes.
     *
     * The cooldown is enforced HERE, not in the button: a client-side timer is
     * a courtesy, and the thing being protected is our rate limit at the
     * exchange, which a page reload or a curl would otherwise walk straight
     * past. `Cache::add` is the atomic claim, so two rapid clicks cannot both win.
     */
    public function refreshBalance(Request $request, string $exchange, int $id): JsonResponse
    {
        if ($unsupported = $this->guardExchange($exchange)) {
            return $unsupported;
        }
        $account = ExchangeSchema::for($exchange)->accountQuery()
            ->where('uni_id', $request->user()->uni_id)->find($id);

        if (! $account) {
            return response()->json(['success' => false, 'message' => 'Account not found.'], 404);
        }

        $key = "exchange:balance-refresh:{$exchange}:{$account->id}";
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

        // The engine reads the exchange and posts the row back to /api/engine/*,
        // so the fresh figure is in the DB by the time this returns. That round
        // trip also settles the key verdict, which is why this doubles as the
        // "I've fixed my IP allow-list, recheck" button. The engine finds the
        // account by api_key across every venue it runs.
        $synced = $this->engineCache->syncBalances([$account->api_key]);

        $account->refresh();

        return response()->json([
            'success' => $synced,
            'message' => $synced
                ? 'Balance updated.'
                : 'Could not reach the exchange right now. Your balance will update automatically.',
            'account' => $this->present($account, $exchange),
            'retry_after' => self::BALANCE_REFRESH_COOLDOWN,
        ], $synced ? 200 : 503);
    }

    /** Legacy route: DELETE /api/exchange/accounts/{id} (Binance). */
    public function destroyBinance(Request $request, int $id): JsonResponse
    {
        return $this->destroy($request, 'binance', $id);
    }

    /**
     * DELETE /api/exchange/{exchange}/accounts/{id}
     * Soft-delete (disconnect) one of the user's accounts.
     */
    public function destroy(Request $request, string $exchange, int $id): JsonResponse
    {
        if ($unsupported = $this->guardExchange($exchange)) {
            return $unsupported;
        }
        $account = ExchangeSchema::for($exchange)->accountQuery()
            ->where('uni_id', $request->user()->uni_id)->find($id);

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
        $this->discordRoles->syncUser($request->user());

        return response()->json([
            'success' => true,
            'message' => 'Account disconnected',
        ]);
    }

    /** Null when the exchange is wired; a 400 otherwise (bybit, today). */
    private function guardExchange(string $exchange): ?JsonResponse
    {
        if (ExchangeSchema::isSupported($exchange)) {
            return null;
        }

        return response()->json([
            'success' => false,
            'error_code' => 'EXCHANGE_NOT_SUPPORTED',
            'message' => ucfirst($exchange).' is coming soon.',
        ], 400);
    }

    /**
     * Null when this user may CONNECT this venue; a 403 otherwise.
     *
     * The roles are EnsureAdmin's, not a second list — whoever may open the
     * admin portal is who may connect a venue that is not on sale yet, and one
     * list cannot drift from itself.
     *
     * The message says "not open yet" rather than "staff only": to a customer
     * that is the true and complete answer, and it matches the "Coming soon"
     * the connect wizard shows them for the same venue.
     */
    private function guardStaffOnlyExchange(string $exchange, $user, string $label): ?JsonResponse
    {
        $restricted = (array) config('exchanges.staff_only', []);

        if (! in_array($exchange, $restricted, true)) {
            return null;
        }

        if ($user && in_array($user->type, EnsureAdmin::ROLES, true)) {
            return null;
        }

        return response()->json([
            'success' => false,
            'error_code' => 'EXCHANGE_RESTRICTED',
            'message' => "{$label} is not open for connections yet. Connect Binance for now — we will announce {$label} when it opens.",
        ], 403);
    }

    /**
     * The row as the client sees it: which exchange it lives on (the client
     * routes every later call by it) and the derived disconnect deadline. The
     * deadline is derived, never stored: one source of truth (key_blocked_at)
     * and no second column to drift from it.
     */
    private function present(ExchangeAccount $account, string $exchange): ExchangeAccount
    {
        $account->setAttribute('exchange', $exchange);
        $account->setAttribute('key_grace_ends_at', $account->keyGraceEndsAt()?->toIso8601String());

        return $account;
    }
}
