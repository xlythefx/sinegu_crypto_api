<?php

namespace App\Http\Controllers;

use App\Models\BinanceAccount;
use App\Services\EngineCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Admin API-key inventory (auth:sanctum + admin middleware) — every exchange
 * account in the system with its owner, at /admin/api-keys.
 *
 * The user-facing ExchangeAccountController answers "my accounts"; this
 * answers "all of them, including the broken and the disconnected". It is a
 * support screen, so:
 *  - **Secrets never leave the server.** Rows carry a `api_key_hint` (first 6
 *    + last 4), never the api_key in full and never `secret_key`. Enough to
 *    match a key in the Binance UI, useless to anyone who copies the page.
 *  - **Nothing here talks to Binance.** The listing reads columns the engine
 *    writes, so opening the page costs no exchange requests. Rechecking one
 *    key is a separate, explicit action (admin/engine/key-issues/{id}/recheck).
 *  - **Disconnect is the same soft delete the trader and the grace-period
 *    sweep perform** — the row keeps its history (positions and invoices are
 *    joined on api_key) and the user's one-account slot is freed.
 *  - **Permanent delete exists but is narrow**: only a key that is not working
 *    (refused by the exchange, or already disconnected) and has no invoices.
 *    See {@see purgeBlockedReason()} — it is a tool for clearing rubbish rows,
 *    not an account-management action.
 */
class AdminApiKeyController extends Controller
{
    /** Upper bound on one bulk call — a bad filter should not wipe the table. */
    private const MAX_BULK = 500;

    public function __construct(private EngineCache $engineCache) {}

    /**
     * GET /api/admin/api-keys
     *
     * Every account ever connected, soft-deleted ones included, newest first.
     * `counts` is computed here rather than in the browser so the chips, the
     * filters and any future consumer share one definition of "faulty".
     */
    public function index(): JsonResponse
    {
        $accounts = BinanceAccount::withTrashed()
            ->leftJoin('user_credentials', 'user_credentials.uni_id', '=', 'binance_accounts.uni_id')
            ->orderByDesc('binance_accounts.created_at')
            ->get([
                'binance_accounts.id',
                'binance_accounts.name',
                'binance_accounts.uni_id',
                'binance_accounts.api_key',
                'binance_accounts.demo',
                'binance_accounts.enabled',
                'binance_accounts.is_sandbox',
                'binance_accounts.balance',
                'binance_accounts.unrealized_pnl',
                'binance_accounts.currency_type',
                'binance_accounts.created_at',
                'binance_accounts.deleted_at',
                'binance_accounts.key_status',
                'binance_accounts.key_error_code',
                'binance_accounts.key_error_reason',
                'binance_accounts.key_error_message',
                'binance_accounts.key_blocked_at',
                'binance_accounts.key_checked_at',
                'user_credentials.name as owner_name',
                'user_credentials.email as owner_email',
                'user_credentials.status as owner_status',
                'user_credentials.type as owner_type',
            ]);

        // What each key would take with it if it were erased. Three grouped
        // queries, not one per row — this page lists every account ever made.
        $usage = $this->usageFor($accounts->pluck('api_key')->all(), $accounts->pluck('id')->all());

        $rows = $accounts->map(fn ($a) => $this->row($a, $usage))->values();

        // A disconnected row is neither disabled nor faulty for counting
        // purposes — it is gone, and listing it under "needs attention" would
        // put permanently-resolved accounts in the queue forever.
        $live = $rows->where('deleted_at', null);

        return response()->json([
            'success' => true,
            'grace_days' => BinanceAccount::KEY_GRACE_DAYS,
            'server_ip' => config('services.engine.public_ip'),
            'counts' => [
                'all' => $rows->count(),
                'connected' => $live->count(),
                'faulty' => $live->where('key_blocked', true)->count(),
                'disabled' => $live->where('enabled', false)->count(),
                'disconnected' => $rows->count() - $live->count(),
                'sandbox' => $rows->where('is_sandbox', true)->count(),
            ],
            'keys' => $rows,
        ]);
    }

    /**
     * PUT /api/admin/api-keys/{id}
     *
     * Rename, and enable / disable. Deliberately NOT editable here: the key
     * and secret themselves (re-keying is the owner's job, from their own
     * connect flow — an admin typing someone's credentials in is how a
     * support screen turns into a credential store) and `demo`, which decides
     * testnet-vs-real orders and must not be a two-click accident.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $account = BinanceAccount::withTrashed()->find($id);
        if (! $account) {
            return $this->notFound();
        }

        $validated = $request->validate([
            'name' => [
                'sometimes',
                'string',
                'max:128',
                Rule::unique('binance_accounts', 'name')->ignore($account->id),
            ],
            'enabled' => ['sometimes', 'boolean'],
        ], [
            'name.unique' => 'An account with this name already exists.',
        ]);

        $wasEnabled = (bool) $account->enabled;
        $account->update($validated);

        // Only an enabled-flag change alters who the fan-out reaches; a rename
        // is a label, so it does not deserve a cache invalidation.
        if (array_key_exists('enabled', $validated) && (bool) $validated['enabled'] !== $wasEnabled) {
            $this->engineCache->refreshAccounts();
        }

        return response()->json([
            'success' => true,
            'message' => 'API key updated.',
            'key' => $this->rowFor($account->id),
        ]);
    }

    /**
     * DELETE /api/admin/api-keys/{id}
     * Disconnect one account (soft delete), exactly as the trader's own
     * disconnect does — including telling the engine immediately, so a key the
     * admin has just pulled cannot receive one more signal on the cache TTL.
     */
    public function destroy(int $id): JsonResponse
    {
        $account = BinanceAccount::withTrashed()->find($id);
        if (! $account) {
            return $this->notFound();
        }

        if ($account->trashed()) {
            return response()->json([
                'success' => false,
                'message' => 'That account is already disconnected.',
            ], 422);
        }

        $account->delete();
        $this->engineCache->refreshAccounts();

        return response()->json([
            'success' => true,
            'message' => 'API key disconnected.',
        ]);
    }

    /**
     * DELETE /api/admin/api-keys/{id}/purge
     *
     * Erase the row and this account's own market data — the only hard delete
     * on this screen, and the only way a stored credential ever leaves the
     * database. Guarded by {@see purgeBlockedReason()}: not-working keys only,
     * never one with invoices.
     *
     * The guard is re-evaluated HERE, not trusted from the listing the admin
     * was looking at: the engine can clear a key's blocked flag between the
     * page loading and the button being pressed, and a working key must not be
     * erasable on the strength of a stale row.
     */
    public function purge(int $id): JsonResponse
    {
        $account = BinanceAccount::withTrashed()->find($id);
        if (! $account) {
            return $this->notFound();
        }

        $usage = $this->usageFor([$account->api_key], [$account->id]);
        $reason = $this->purgeBlockedReason($account, $usage);
        if ($reason !== null) {
            return response()->json([
                'success' => false,
                'error_code' => 'PURGE_REFUSED',
                'message' => $reason,
            ], 422);
        }

        $apiKey = $account->api_key;

        // One transaction: a half-purged account (rows gone, credential left
        // behind) is worse than either outcome.
        $removed = DB::transaction(function () use ($account, $apiKey) {
            $counts = [
                'positions' => DB::table('binance_positions')->where('api_key', $apiKey)->delete(),
                'trades' => DB::table('binance_pastpositions')->where('api_key', $apiKey)->delete(),
                'transactions' => DB::table('binance_transactions')->where('api_key', $apiKey)->delete(),
            ];

            $account->forceDelete();

            return $counts;
        });

        // A blocked-but-connected key is still in the engine's account list
        // (blocked accounts stay listed so recovery can be detected), so the
        // cache must drop a credential that no longer exists.
        $this->engineCache->refreshAccounts();

        return response()->json([
            'success' => true,
            'message' => 'API key permanently deleted.',
            'removed' => $removed,
        ]);
    }

    /**
     * POST /api/admin/api-keys/bulk-delete
     *
     * Disconnect several at once — the "clear out the faulty keys" button.
     *
     * The ids are REQUIRED and come from the client. A server-side
     * "delete everything currently faulty" would act on rows the admin never
     * saw (the engine can flag another account between the page loading and
     * the button being pressed), so what gets deleted is exactly what was on
     * screen. Already-disconnected ids are skipped, not errors — two admins
     * pressing the same button must not produce a failure.
     */
    public function bulkDestroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_BULK],
            'ids.*' => ['integer'],
        ]);

        $ids = array_values(array_unique($validated['ids']));

        $accounts = BinanceAccount::withTrashed()->whereIn('id', $ids)->get();
        $deletable = $accounts->reject(fn ($a) => $a->trashed());

        foreach ($deletable as $account) {
            $account->delete();
        }

        // One invalidation for the whole batch, not one per row.
        if ($deletable->isNotEmpty()) {
            $this->engineCache->refreshAccounts();
        }

        $deleted = $deletable->count();
        $skipped = $accounts->count() - $deleted;
        $missing = count($ids) - $accounts->count();

        return response()->json([
            'success' => true,
            'deleted' => $deleted,
            'skipped' => $skipped + $missing,
            'message' => $deleted === 1
                ? '1 API key disconnected.'
                : "{$deleted} API keys disconnected.",
        ]);
    }

    /**
     * Row counts for every listed key, in three grouped queries.
     *
     * `invoices` matches on account_id OR api_key: an invoice carries both, and
     * a row that matched on only one of them would under-report the billing
     * history the purge guard is protecting.
     *
     * @param  string[]  $apiKeys
     * @param  int[]  $ids
     */
    private function usageFor(array $apiKeys, array $ids): array
    {
        if ($apiKeys === []) {
            return ['trades' => [], 'positions' => [], 'transactions' => [], 'invoices' => []];
        }

        $countBy = fn (string $table) => DB::table($table)
            ->whereIn('api_key', $apiKeys)
            ->groupBy('api_key')
            ->selectRaw('api_key, COUNT(*) AS n')
            ->pluck('n', 'api_key')
            ->all();

        $invoices = DB::table('invoices')
            ->where(function ($q) use ($ids, $apiKeys) {
                $q->whereIn('account_id', $ids)->orWhereIn('api_key', $apiKeys);
            })
            ->get(['account_id', 'api_key']);

        return [
            'trades' => $countBy('binance_pastpositions'),
            'positions' => $countBy('binance_positions'),
            'transactions' => $countBy('binance_transactions'),
            'invoices' => $invoices,
        ];
    }

    /** How many invoices belong to one account, by either identifier. */
    private function invoiceCount(array $usage, int $id, string $apiKey): int
    {
        $rows = $usage['invoices'] ?? null;
        if (! $rows) {
            return 0;
        }

        return $rows->filter(
            fn ($i) => (int) $i->account_id === $id || $i->api_key === $apiKey
        )->count();
    }

    /** One listing row. `$a` is the joined stdClass or a re-read model. */
    private function row(object $a, array $usage = []): array
    {
        $blockedAt = $a->key_blocked_at ? Carbon::parse($a->key_blocked_at) : null;
        $blocked = $a->key_status === BinanceAccount::KEY_BLOCKED;
        $graceEnds = $blocked && $blockedAt
            ? $blockedAt->copy()->addDays(BinanceAccount::KEY_GRACE_DAYS)
            : null;

        return [
            'id' => $a->id,
            // Hard-coded until bybit_*/mexc_* land; the column does not exist
            // yet, and inventing one here would be a schema claim.
            'exchange' => 'binance',
            'name' => $a->name,
            // First 6 + last 4. The full key and the secret stay server-side.
            'api_key_hint' => $this->maskKey((string) $a->api_key),
            'demo' => (bool) $a->demo,
            'enabled' => (bool) $a->enabled,
            'is_sandbox' => (bool) $a->is_sandbox,
            'balance' => $a->balance !== null ? round((float) $a->balance, 2) : null,
            'unrealized_pnl' => $a->unrealized_pnl !== null ? round((float) $a->unrealized_pnl, 2) : null,
            'currency_type' => $a->currency_type,
            'key_status' => $a->key_status,
            'key_blocked' => $blocked,
            'error_code' => $a->key_error_code,
            'error_reason' => $a->key_error_reason,
            'error_message' => $a->key_error_message,
            'blocked_at' => $blockedAt?->toIso8601String(),
            'checked_at' => $a->key_checked_at
                ? Carbon::parse($a->key_checked_at)->toIso8601String()
                : null,
            'grace_ends_at' => $graceEnds?->toIso8601String(),
            // Negative simply means the daily sweep has not run yet.
            'days_left' => $graceEnds ? (int) ceil(now()->floatDiffInDays($graceEnds, false)) : null,
            'created_at' => $a->created_at
                ? Carbon::parse($a->created_at)->toIso8601String()
                : null,
            'deleted_at' => $a->deleted_at
                ? Carbon::parse($a->deleted_at)->toIso8601String()
                : null,
            'owner' => [
                'uni_id' => $a->uni_id,
                'name' => $a->owner_name ?? null,
                'email' => $a->owner_email ?? null,
                'status' => $a->owner_status ?? null,
                'type' => $a->owner_type ?? null,
            ],
            // What a permanent delete would take with it, so the confirmation
            // can name it instead of saying "this cannot be undone" and hoping.
            'usage' => [
                'trades' => (int) ($usage['trades'][$a->api_key] ?? 0),
                'positions' => (int) ($usage['positions'][$a->api_key] ?? 0),
                'transactions' => (int) ($usage['transactions'][$a->api_key] ?? 0),
                'invoices' => $this->invoiceCount($usage, (int) $a->id, (string) $a->api_key),
            ],
            'purgeable' => $this->purgeBlockedReason($a, $usage) === null,
            'purge_blocked_reason' => $this->purgeBlockedReason($a, $usage),
        ];
    }

    /**
     * Why this row may NOT be erased from the database, or null when it may.
     *
     * Two independent gates, and the UI mirrors both:
     *  1. **Only a key that is not working.** A healthy, connected account is
     *     someone's live trading setup; permanent delete is a cleanup tool for
     *     rubbish rows, not an account-management action. "Not working" means
     *     the exchange is refusing it, or it is already disconnected.
     *  2. **Never one with invoices.** Billing is the audit trail — an invoice
     *     whose account row vanished cannot be explained to the person who
     *     paid it. Those stay disconnected forever instead.
     * Closed trades do NOT block the purge; they are cascaded, and the
     * confirmation states how many, because a key that traded and was then
     * abandoned is exactly what an admin wants to clear out.
     */
    private function purgeBlockedReason(object $a, array $usage = []): ?string
    {
        $blocked = $a->key_status === BinanceAccount::KEY_BLOCKED;
        $disconnected = $a->deleted_at !== null;

        if (! $blocked && ! $disconnected) {
            return 'Only a key the exchange is refusing, or one already disconnected, can be deleted permanently.';
        }

        $invoices = $this->invoiceCount($usage, (int) $a->id, (string) $a->api_key);
        if ($invoices > 0) {
            return $invoices === 1
                ? 'This account has 1 invoice — billing history cannot be erased. Disconnect it instead.'
                : "This account has {$invoices} invoices — billing history cannot be erased. Disconnect it instead.";
        }

        return null;
    }

    /**
     * One re-read row, complete with its usage counts — so a single-row
     * response carries the same `purgeable` verdict the listing would.
     */
    private function rowFor(int $id): array
    {
        $account = $this->reload($id);

        return $this->row(
            $account,
            $this->usageFor([$account->api_key], [(int) $account->id]),
        );
    }

    /** Re-read one row through the same join the listing uses. */
    private function reload(int $id): object
    {
        return BinanceAccount::withTrashed()
            ->leftJoin('user_credentials', 'user_credentials.uni_id', '=', 'binance_accounts.uni_id')
            ->where('binance_accounts.id', $id)
            ->first([
                'binance_accounts.*',
                'user_credentials.name as owner_name',
                'user_credentials.email as owner_email',
                'user_credentials.status as owner_status',
                'user_credentials.type as owner_type',
            ]);
    }

    private function maskKey(string $key): string
    {
        return strlen($key) > 10
            ? substr($key, 0, 6).'…'.substr($key, -4)
            : str_repeat('•', 8);
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'API key not found.',
        ], 404);
    }
}
