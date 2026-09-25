<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\GuardsEngineExchange;
use App\Models\ExchangeAccount;
use App\Services\Discord\DiscordRoleSync;
use App\Services\EngineCache;
use App\Services\Exchanges\ExchangeSchema;
use App\Services\Exchanges\TransferLedger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Admin API-key inventory (auth:sanctum + admin middleware) — every exchange
 * account in the system with its owner, at /admin/api-keys.
 *
 * The user-facing ExchangeAccountController answers "my accounts"; this
 * answers "all of them, on every exchange, including the broken and the
 * disconnected". Accounts live in one table per exchange (ExchangeSchema),
 * so ids collide across venues: every row carries its `exchange`, and every
 * write is addressed as /admin/api-keys/{exchange}/{id}, never by id alone.
 * It is a support screen, so:
 *  - **Secrets never leave the server.** Rows carry a `api_key_hint` (first 6
 *    + last 4), never the api_key in full and never `secret_key`. Enough to
 *    match a key in the exchange's UI, useless to anyone who copies the page.
 *  - **Nothing here talks to an exchange.** The listing reads columns the
 *    engine writes, so opening the page costs no exchange requests. Rechecking
 *    one key is a separate, explicit action
 *    (admin/engine/key-issues/{exchange}/{id}/recheck).
 *  - **Disconnect is the same soft delete the trader and the grace-period
 *    sweep perform** — the row keeps its history (positions and invoices are
 *    joined on api_key) and the user's one-account-per-exchange slot is freed.
 *  - **Permanent delete exists but is narrow**: only a key that is not working
 *    (refused by the exchange, or already disconnected) and has no invoices.
 *    See {@see purgeBlockedReason()} — it is a tool for clearing rubbish rows,
 *    not an account-management action.
 */
class AdminApiKeyController extends Controller
{
    use GuardsEngineExchange;

    /** Upper bound on one bulk call — a bad filter should not wipe the table. */
    private const MAX_BULK = 500;

    public function __construct(
        private EngineCache $engineCache,
        private DiscordRoleSync $discordRoles,
    ) {}

    /**
     * GET /api/admin/api-keys
     *
     * Every account ever connected on every exchange, soft-deleted ones
     * included, newest first. `counts` is computed here rather than in the
     * browser so the chips, the filters and any future consumer share one
     * definition of "faulty".
     */
    public function index(): JsonResponse
    {
        $rows = collect();
        foreach (ExchangeSchema::supported() as $exchange) {
            $schema = ExchangeSchema::for($exchange);
            $accounts = $this->listing($schema)->get($this->columns($schema));

            // What each key would take with it if it were erased. Three grouped
            // queries per exchange, not one per row — this page lists every
            // account ever made.
            $usage = $this->usageFor(
                $schema,
                $accounts->pluck('api_key')->all(),
                $accounts->pluck('id')->all(),
            );

            $rows = $rows->concat($accounts->map(fn ($a) => $this->row($a, $schema, $usage)));
        }

        $rows = $rows->sortByDesc('created_at')->values();

        // A disconnected row is neither disabled nor faulty for counting
        // purposes — it is gone, and listing it under "needs attention" would
        // put permanently-resolved accounts in the queue forever.
        $live = $rows->where('deleted_at', null);

        return response()->json([
            'success' => true,
            'grace_days' => ExchangeAccount::KEY_GRACE_DAYS,
            'server_ip' => config('services.engine.public_ip'),
            'exchanges' => ExchangeSchema::supported(),
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
     * PUT /api/admin/api-keys/{exchange}/{id}
     *
     * Rename, and enable / disable. Deliberately NOT editable here: the key
     * and secret themselves (re-keying is the owner's job, from their own
     * connect flow — an admin typing someone's credentials in is how a
     * support screen turns into a credential store) and `demo`, which decides
     * testnet-vs-real orders and must not be a two-click accident.
     */
    public function update(Request $request, string $exchange, int $id): JsonResponse
    {
        if ($unsupported = $this->guardExchange($exchange)) {
            return $unsupported;
        }
        $schema = $this->schema($exchange);

        $account = $this->find($schema, $id);
        if (! $account) {
            return $this->notFound();
        }

        $validated = $request->validate([
            'name' => [
                'sometimes',
                'string',
                'max:128',
                Rule::unique($schema->accountsTable, 'name')->ignore($account->id),
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
            'key' => $this->rowFor($schema, $account->id),
        ]);
    }

    /**
     * GET /api/admin/api-keys/{exchange}/{id}/ledger
     *
     * Preview: every transfer the exchange still reports for this account,
     * which of them we already store, and what `initial_deposit` and the
     * total deposit would become ({@see TransferLedger}). Writes nothing — but
     * it is the one read on this screen that spends exchange calls (a
     * weight-30 call per 1000 ledger rows), made by the engine, which holds
     * the key and is allow-listed at the exchange.
     */
    public function ledger(string $exchange, int $id, TransferLedger $ledger): JsonResponse
    {
        $resolved = $this->ledgerPlan($exchange, $id, $ledger);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        return response()->json(['success' => true, 'plan' => TransferLedger::public($resolved['plan'])]);
    }

    /**
     * POST /api/admin/api-keys/{exchange}/{id}/ledger
     * Body: {expected_initial: number, expected_missing: string[]}
     *
     * Apply. The ledger is READ AGAIN rather than trusting the preview, and
     * the write goes ahead only when it still matches what the admin
     * confirmed — a transfer landing in between must be seen before it is
     * stored, not after (same rule as bulk delete: act on exactly what was
     * on screen).
     */
    public function applyLedger(Request $request, string $exchange, int $id, TransferLedger $ledger): JsonResponse
    {
        $expected = $request->validate([
            'expected_initial' => ['required', 'numeric'],
            'expected_missing' => ['present', 'array'],
            'expected_missing.*' => ['string'],
        ]);

        $resolved = $this->ledgerPlan($exchange, $id, $ledger);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        ['plan' => $plan, 'account' => $account] = $resolved;

        if ($plan['problems'] !== []) {
            return response()->json([
                'success' => false,
                'error' => 'LEDGER_PROBLEMS',
                'message' => implode(' ', $plan['problems']),
                'plan' => TransferLedger::public($plan),
            ], 422);
        }

        $missingNow = array_column(array_filter($plan['transfers'], fn ($t) => ! $t['stored']), 'tran_id');
        $sameMissing = collect($missingNow)->sort()->values()->all()
            === collect($expected['expected_missing'])->map(fn ($v) => (string) $v)->sort()->values()->all();
        if (! $sameMissing || abs($plan['initial_deposit']['after'] - (float) $expected['expected_initial']) >= 0.01) {
            return response()->json([
                'success' => false,
                'error' => 'LEDGER_CHANGED',
                'message' => 'The exchange history changed since the preview. Review it again before applying.',
                'plan' => TransferLedger::public($plan),
            ], 409);
        }

        $inserted = $ledger->apply($account, $plan);
        // total_deposit (the deposit gate's input) just changed.
        $this->engineCache->refreshAccounts();

        return response()->json([
            'success' => true,
            'message' => $inserted > 0
                ? "Stored {$inserted} transfer(s) and set the starting balance."
                : 'Starting balance updated.',
            'inserted' => $inserted,
            'key' => $this->rowFor($this->schema($exchange), $account->id),
        ]);
    }

    /**
     * The account + its freshly read plan, or the response explaining why
     * there is none.
     *
     * @return array{plan: array<string, mixed>, account: ExchangeAccount}|JsonResponse
     */
    private function ledgerPlan(string $exchange, int $id, TransferLedger $ledger): array|JsonResponse
    {
        if ($unsupported = $this->guardExchange($exchange)) {
            return $unsupported;
        }
        $account = $this->find($this->schema($exchange), $id);
        if (! $account) {
            return $this->notFound();
        }
        if ($account->trashed()) {
            return response()->json([
                'success' => false,
                'message' => 'That account is disconnected; the engine no longer holds its key.',
            ], 422);
        }

        $read = $this->engineCache->ledger($account->api_key);
        if ($read['ledger'] === null) {
            return response()->json(['success' => false, 'error' => 'LEDGER_UNAVAILABLE', 'message' => $read['error']], 502);
        }

        return ['plan' => $ledger->plan($account, $exchange, $read['ledger']), 'account' => $account];
    }

    /**
     * DELETE /api/admin/api-keys/{exchange}/{id}
     * Disconnect one account (soft delete), exactly as the trader's own
     * disconnect does — including telling the engine immediately, so a key the
     * admin has just pulled cannot receive one more signal on the cache TTL.
     */
    public function destroy(string $exchange, int $id): JsonResponse
    {
        if ($unsupported = $this->guardExchange($exchange)) {
            return $unsupported;
        }

        $account = $this->find($this->schema($exchange), $id);
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
        // The owner may have just lost their last live account → Trader role off.
        $this->discordRoles->syncUniId($account->uni_id);

        return response()->json([
            'success' => true,
            'message' => 'API key disconnected.',
        ]);
    }

    /**
     * DELETE /api/admin/api-keys/{exchange}/{id}/purge
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
    public function purge(string $exchange, int $id): JsonResponse
    {
        if ($unsupported = $this->guardExchange($exchange)) {
            return $unsupported;
        }
        $schema = $this->schema($exchange);

        $account = $this->find($schema, $id);
        if (! $account) {
            return $this->notFound();
        }

        $usage = $this->usageFor($schema, [$account->api_key], [$account->id]);
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
        // behind) is worse than either outcome. Only THIS exchange's tables —
        // the api_key is venue-specific and so is every row keyed by it.
        $removed = DB::transaction(function () use ($account, $apiKey, $schema) {
            $counts = [
                'positions' => DB::table($schema->positions)->where('api_key', $apiKey)->delete(),
                'trades' => DB::table($schema->pastPositions)->where('api_key', $apiKey)->delete(),
                'transactions' => DB::table($schema->transactions)->where('api_key', $apiKey)->delete(),
            ];

            $account->forceDelete();

            return $counts;
        });

        // A blocked-but-connected key is still in the engine's account list
        // (blocked accounts stay listed so recovery can be detected), so the
        // cache must drop a credential that no longer exists.
        $this->engineCache->refreshAccounts();
        $this->discordRoles->syncUniId($account->uni_id);

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
     * The keys are REQUIRED and come from the client as (exchange, id) pairs —
     * an id alone names a different account on every exchange. A server-side
     * "delete everything currently faulty" would act on rows the admin never
     * saw (the engine can flag another account between the page loading and
     * the button being pressed), so what gets deleted is exactly what was on
     * screen. Already-disconnected keys are skipped, not errors — two admins
     * pressing the same button must not produce a failure.
     */
    public function bulkDestroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'keys' => ['required', 'array', 'min:1', 'max:'.self::MAX_BULK],
            'keys.*.exchange' => ['required', 'string', Rule::in(ExchangeSchema::supported())],
            'keys.*.id' => ['required', 'integer'],
        ]);

        $deleted = 0;
        $skipped = 0;
        $missing = 0;
        $owners = [];

        foreach (collect($validated['keys'])->groupBy('exchange') as $exchange => $refs) {
            $ids = $refs->pluck('id')->map(fn ($id) => (int) $id)->unique()->values()->all();

            $accounts = ExchangeSchema::for($exchange)->accountQuery()
                ->withTrashed()
                ->whereIn('id', $ids)
                ->get();
            $deletable = $accounts->reject(fn ($a) => $a->trashed());

            foreach ($deletable as $account) {
                $account->delete();
                $owners[$account->uni_id] = true;
            }

            $deleted += $deletable->count();
            $skipped += $accounts->count() - $deletable->count();
            $missing += count($ids) - $accounts->count();
        }

        // One invalidation for the whole batch, not one per row.
        if ($deleted > 0) {
            $this->engineCache->refreshAccounts();
        }
        // One Discord sync per OWNER, not per key.
        foreach (array_keys($owners) as $uniId) {
            $this->discordRoles->syncUniId((string) $uniId);
        }

        return response()->json([
            'success' => true,
            'deleted' => $deleted,
            'skipped' => $skipped + $missing,
            'message' => $deleted === 1
                ? '1 API key disconnected.'
                : "{$deleted} API keys disconnected.",
        ]);
    }

    /** Every row of one exchange's table, trashed included, owner joined. */
    private function listing(ExchangeSchema $schema): Builder
    {
        $table = $schema->accountsTable;

        return $schema->accountQuery()
            ->withTrashed()
            ->leftJoin('user_credentials', 'user_credentials.uni_id', '=', "{$table}.uni_id")
            ->orderByDesc("{$table}.created_at");
    }

    /** The listing's select list, qualified by the exchange's table. */
    private function columns(ExchangeSchema $schema): array
    {
        $table = $schema->accountsTable;

        return [
            "{$table}.id",
            "{$table}.name",
            "{$table}.uni_id",
            "{$table}.api_key",
            "{$table}.demo",
            "{$table}.enabled",
            "{$table}.is_sandbox",
            "{$table}.balance",
            "{$table}.unrealized_pnl",
            "{$table}.currency_type",
            "{$table}.created_at",
            "{$table}.deleted_at",
            "{$table}.key_status",
            "{$table}.key_error_code",
            "{$table}.key_error_reason",
            "{$table}.key_error_message",
            "{$table}.key_blocked_at",
            "{$table}.key_checked_at",
            'user_credentials.name as owner_name',
            'user_credentials.email as owner_email',
            'user_credentials.status as owner_status',
            'user_credentials.type as owner_type',
        ];
    }

    /** One account by (exchange, id), soft-deleted included. */
    private function find(ExchangeSchema $schema, int $id): ?ExchangeAccount
    {
        return $schema->accountQuery()->withTrashed()->find($id);
    }

    /**
     * Row counts for every listed key of one exchange, in three grouped
     * queries against that exchange's own tables.
     *
     * `invoices` matches on account_id OR api_key: an invoice carries both, and
     * a row that matched on only one of them would under-report the billing
     * history the purge guard is protecting. Narrowed to the exchange as well,
     * because `account_id` repeats across the per-exchange tables — a Binance
     * invoice must not shield the MEXC account that happens to share its id.
     *
     * @param  string[]  $apiKeys
     * @param  int[]  $ids
     */
    private function usageFor(ExchangeSchema $schema, array $apiKeys, array $ids): array
    {
        if ($apiKeys === []) {
            return ['trades' => [], 'positions' => [], 'transactions' => [], 'invoices' => collect()];
        }

        $countBy = fn (string $table) => DB::table($table)
            ->whereIn('api_key', $apiKeys)
            ->groupBy('api_key')
            ->selectRaw('api_key, COUNT(*) AS n')
            ->pluck('n', 'api_key')
            ->all();

        $invoices = DB::table('invoices')
            ->where('exchange', $schema->exchange)
            ->where(function ($q) use ($ids, $apiKeys) {
                $q->whereIn('account_id', $ids)->orWhereIn('api_key', $apiKeys);
            })
            ->get(['account_id', 'api_key']);

        return [
            'trades' => $countBy($schema->pastPositions),
            'positions' => $countBy($schema->positions),
            'transactions' => $countBy($schema->transactions),
            'invoices' => $invoices,
        ];
    }

    /** How many invoices belong to one account, by either identifier. */
    private function invoiceCount(array $usage, int $id, string $apiKey): int
    {
        $rows = $usage['invoices'] ?? null;
        if (! $rows instanceof Collection || $rows->isEmpty()) {
            return 0;
        }

        return $rows->filter(
            fn ($i) => (int) $i->account_id === $id || $i->api_key === $apiKey
        )->count();
    }

    /** One listing row. `$a` is the joined model or a re-read one. */
    private function row(object $a, ExchangeSchema $schema, array $usage = []): array
    {
        $blockedAt = $a->key_blocked_at ? Carbon::parse($a->key_blocked_at) : null;
        $blocked = $a->key_status === ExchangeAccount::KEY_BLOCKED;
        $graceEnds = $blocked && $blockedAt
            ? $blockedAt->copy()->addDays(ExchangeAccount::KEY_GRACE_DAYS)
            : null;

        return [
            'id' => $a->id,
            // The table this id lives in — the client routes every write by it.
            'exchange' => $schema->exchange,
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
        $blocked = $a->key_status === ExchangeAccount::KEY_BLOCKED;
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
    private function rowFor(ExchangeSchema $schema, int $id): array
    {
        $account = $this->listing($schema)
            ->where("{$schema->accountsTable}.id", $id)
            ->first($this->columns($schema));

        return $this->row(
            $account,
            $schema,
            $this->usageFor($schema, [$account->api_key], [(int) $account->id]),
        );
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
