<?php

namespace App\Http\Controllers;

use App\Services\EngineCache;
use App\Services\Exchanges\ExchangeSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Admin Dashboard → Open positions: every open position on every venue, a
 * forced re-read from the exchanges, and a manual close of the rows the admin
 * ticks. Admin-only (`admin` middleware).
 *
 * The browser names rows by `{exchange, id}` and nothing else. Who owns the
 * row, which symbol and which side are resolved HERE from the DB, so a client
 * can never ask the engine to close something that is not on this list — and
 * the engine call that results is echoed back verbatim (`engine_request`) so
 * the page can show exactly what was sent.
 */
class AdminOpenPositionsController extends Controller
{
    /**
     * One forced read per 30 s for the whole platform, not per admin: it reads
     * every account, and every account shares one IP weight budget at the
     * exchange whoever pressed the button.
     */
    public const REFRESH_COOLDOWN = 30;

    private const REFRESH_KEY = 'admin:open-positions:refreshed-at';

    private const MAX_CLOSE = 50;

    public function __construct(private EngineCache $engine) {}

    /** GET /api/admin/open-positions */
    public function index(): JsonResponse
    {
        $positions = collect(ExchangeSchema::supported())
            ->flatMap(fn (string $exchange) => $this->rows($exchange))
            // api_key is how close() re-syncs the account; it never leaves the server.
            ->map(fn (array $row) => collect($row)->except('api_key')->all())
            ->sortBy([['owner_name', 'asc'], ['symbol', 'asc']])
            ->values();

        return response()->json([
            'success' => true,
            'positions' => $positions,
            'refresh' => $this->refreshState(),
        ]);
    }

    /**
     * POST /api/admin/open-positions/refresh
     * Ask the engine to read every account's open positions now, instead of at
     * the poller's next tick (300 s). 429 inside the cooldown.
     */
    public function refresh(): JsonResponse
    {
        // add() is atomic: two admins pressing at once start ONE read.
        if (! Cache::add(self::REFRESH_KEY, now()->getTimestamp(), self::REFRESH_COOLDOWN)) {
            return response()->json([
                'success' => false,
                'error_code' => 'REFRESH_COOLDOWN',
                'message' => 'Positions were just fetched. Try again in a few seconds.',
                'refresh' => $this->refreshState(),
            ], 429);
        }

        $reached = $this->engine->syncPositions(null);

        return response()->json([
            'success' => $reached,
            'message' => $reached
                ? 'Open positions fetched from the exchanges.'
                : 'Could not reach the trading engine — showing the last synced positions.',
            'refresh' => $this->refreshState(),
        ], $reached ? 200 : 503);
    }

    /**
     * POST /api/admin/open-positions/close
     * Body: {"positions": [{"exchange": "binance", "id": 12}, …]}
     */
    public function close(Request $request): JsonResponse
    {
        $data = $request->validate([
            'positions' => ['required', 'array', 'min:1', 'max:'.self::MAX_CLOSE],
            'positions.*.exchange' => ['required', 'string', Rule::in(ExchangeSchema::supported())],
            'positions.*.id' => ['required', 'integer', 'min:1'],
        ]);

        $wanted = collect($data['positions'])->groupBy('exchange');
        $resolved = $wanted->flatMap(
            fn (Collection $items, string $exchange) => $this->rows($exchange, $items->pluck('id')->map(fn ($id) => (int) $id)->all())
        );

        $missing = collect($data['positions'])
            ->reject(fn ($p) => $resolved->contains(fn ($r) => $r['exchange'] === $p['exchange'] && $r['id'] === (int) $p['id']))
            ->values();
        if ($missing->isNotEmpty()) {
            return response()->json([
                'success' => false,
                'error_code' => 'POSITION_GONE',
                'message' => 'Some positions are no longer open. Reload the list and try again.',
                'missing' => $missing,
            ], 409);
        }

        $blocked = $resolved->reject(fn ($r) => $r['closable'])->values();
        if ($blocked->isNotEmpty()) {
            return response()->json([
                'success' => false,
                'error_code' => 'NOT_CLOSABLE',
                'message' => 'The engine cannot close some of these positions.',
                'blocked' => $blocked->map(fn ($r) => [
                    'exchange' => $r['exchange'], 'id' => $r['id'], 'reason' => $r['blocked_reason'],
                ]),
            ], 422);
        }

        // One entry per (venue, owner, symbol, side): the engine closes the
        // whole side, so two rows of the same position are one close.
        $engineRequest = $resolved
            ->map(fn ($r) => [
                'exchange' => $r['exchange'],
                'uni_id' => $r['uni_id'],
                'symbol' => $r['symbol'],
                'side' => $r['side'],
            ])
            ->unique(fn ($p) => implode('|', $p))
            ->values()
            ->all();

        set_time_limit(120);
        $answer = $this->engine->closePositions($engineRequest);

        // Whatever filled, read those accounts again so the list shows the
        // exchange's truth rather than the engine's write-through zero.
        if ($answer['error'] === null) {
            $this->engine->syncPositions($resolved->pluck('api_key')->unique()->values()->all(), 20);
        }

        $ok = $answer['error'] === null;

        return response()->json([
            'success' => $ok && (bool) data_get($answer['body'], 'success', false),
            'message' => $ok ? $this->summarise($answer['body']) : $answer['error'],
            'engine_request' => ['positions' => $engineRequest],
            'engine_status' => $answer['status'],
            'engine_response' => $answer['body'],
        ], $ok ? 200 : 502);
    }

    /**
     * Open rows on one venue, joined to their account and owner, each with the
     * verdict "can the engine close this?" — the engine only trades accounts
     * its own accounts endpoint returns (enabled, connected, not a sandbox,
     * owner not suspended), so anything else would come back "no accounts".
     *
     * @param  list<int>|null  $ids
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(string $exchange, ?array $ids = null): Collection
    {
        $schema = ExchangeSchema::for($exchange);
        $query = DB::table("{$schema->positions} as p")
            ->leftJoin("{$schema->accountsTable} as a", 'p.api_key', '=', 'a.api_key')
            ->leftJoin('user_credentials as u', 'p.uni_id', '=', 'u.uni_id')
            ->where('p.position_amt', '!=', 0);
        if ($ids !== null) {
            $query->whereIn('p.id', $ids);
        }

        return $query->get([
            'p.id', 'p.api_key', 'p.uni_id', 'p.symbol', 'p.position_side', 'p.position_amt',
            'p.entry_price', 'p.mark_price', 'p.unrealized_profit', 'p.update_time', 'p.created_at',
            'a.id as account_id', 'a.name as account_name', 'a.enabled', 'a.demo', 'a.is_sandbox',
            'a.deleted_at', 'a.key_status',
            'u.name as owner_name', 'u.type as owner_type', 'u.status as owner_status',
        ])->map(function ($p) use ($exchange) {
            $amount = (float) $p->position_amt;
            $side = in_array($p->position_side, ['LONG', 'SHORT'], true)
                ? $p->position_side
                : ($amount < 0 ? 'SHORT' : 'LONG');
            $reason = $this->blockedReason($p);

            return [
                'id' => (int) $p->id,
                'exchange' => $exchange,
                'uni_id' => $p->uni_id,
                'api_key' => $p->api_key,
                'account_id' => $p->account_id === null ? null : (int) $p->account_id,
                'account_name' => $p->account_name,
                'owner_name' => $p->owner_name ?: 'Unknown user',
                'owner_type' => $p->owner_type,
                'demo' => (bool) $p->demo,
                'key_blocked' => $p->key_status !== null && $p->key_status !== 'ok',
                'symbol' => $p->symbol,
                'side' => $side,
                'size' => abs($amount),
                'entry_price' => $p->entry_price === null ? null : (float) $p->entry_price,
                'mark_price' => $p->mark_price === null ? null : (float) $p->mark_price,
                'unrealized_pnl' => $p->unrealized_profit === null ? null : round((float) $p->unrealized_profit, 2),
                'updated_at' => $p->update_time
                    ? Carbon::createFromTimestampMs((int) $p->update_time)->utc()->toIso8601String()
                    : ($p->created_at ? Carbon::parse($p->created_at, 'UTC')->toIso8601String() : null),
                'closable' => $reason === null,
                'blocked_reason' => $reason,
            ];
        });
    }

    private function blockedReason(object $p): ?string
    {
        return match (true) {
            $p->account_id === null, $p->deleted_at !== null => 'Account disconnected — close it on the exchange.',
            (int) $p->is_sandbox === 1 => 'Sandbox account — nothing real to close.',
            (int) $p->enabled !== 1 => 'Account disabled (e.g. unpaid invoice) — the engine does not trade it.',
            $p->owner_status === 'suspended' => 'Owner suspended — the engine does not trade it.',
            default => null,
        };
    }

    /** @return array{last_at: ?string, cooldown_seconds: int, retry_after: int} */
    private function refreshState(): array
    {
        $at = Cache::get(self::REFRESH_KEY);
        $retry = $at ? max(0, self::REFRESH_COOLDOWN - (now()->getTimestamp() - (int) $at)) : 0;

        return [
            'last_at' => $at ? Carbon::createFromTimestamp((int) $at)->utc()->toIso8601String() : null,
            'cooldown_seconds' => self::REFRESH_COOLDOWN,
            'retry_after' => $retry,
        ];
    }

    private function summarise(mixed $body): string
    {
        $filled = (int) data_get($body, 'filled', 0);
        $failed = (int) data_get($body, 'failed', 0);
        $skipped = (int) data_get($body, 'skipped', 0);
        $running = (int) data_get($body, 'running', 0);

        $parts = ["{$filled} closed"];
        if ($failed) {
            $parts[] = "{$failed} failed";
        }
        if ($skipped) {
            $parts[] = "{$skipped} already flat";
        }
        if ($running) {
            $parts[] = "{$running} still running — check the Signal Log";
        }

        return implode(' · ', $parts).'.';
    }
}
