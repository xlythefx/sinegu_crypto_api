<?php

namespace App\Http\Controllers;

use App\Models\BinanceAccount;
use App\Models\UserCredential;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Admin "sandbox" testing endpoints (auth:sanctum + admin middleware).
 * Create disposable test users and seed closed positions to exercise the
 * statistics/analytics pipeline. Everything created here is tagged
 * is_sandbox = true so it can be wiped without touching real user data.
 */
class SandboxController extends Controller
{
    /** Symbols used when randomizing positions. */
    private const SYMBOLS = ['BTCUSDT', 'ETHUSDT', 'SOLUSDT', 'BNBUSDT', 'XRPUSDT', 'ADAUSDT'];

    /** Strategy labels used when randomizing positions (null = unlabeled). */
    private const STRATEGIES = ['Momentum', 'MeanRev', 'Breakout', 'Scalp', null];

    /** Default realized-P&L bounds used by the date-range randomizer. */
    private const PNL_MIN = -400.0;

    private const PNL_MAX = 700.0;

    /** Hard cap on the rows one date-range insert may produce (~a year). */
    private const MAX_RANGE_DAYS = 366;

    /**
     * GET /api/admin/sandbox/users
     * Every sandbox user with a count of their sandbox positions.
     */
    public function listUsers(Request $request): JsonResponse
    {
        $users = UserCredential::where('is_sandbox', true)
            ->orderByDesc('created_at')
            ->get(['uni_id', 'name', 'email', 'status', 'type', 'created_at']);

        return response()->json([
            'success' => true,
            'users' => $users->map(fn ($u) => [
                'uni_id' => $u->uni_id,
                'name' => $u->name,
                'email' => $u->email,
                'status' => $u->status,
                'type' => $u->type,
                'created_at' => $u->created_at?->toISOString(),
                'positions_count' => DB::table('binance_pastpositions')
                    ->where('uni_id', $u->uni_id)
                    ->where('is_sandbox', true)
                    ->count(),
            ])->values(),
        ]);
    }

    /**
     * POST /api/admin/sandbox/users
     * Create a disposable sandbox user (all fields optional → sensible defaults).
     */
    public function createUser(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'unique:user_credentials,email'],
            'password' => ['nullable', 'string', 'min:6'],
            'status' => ['nullable', 'in:pending,active,suspended'],
            'type' => ['nullable', 'in:user,admin,master,developer'],
        ]);

        $email = $validated['email'] ?? null;
        if (! $email) {
            do {
                $email = 'sandbox+'.Str::random(8).'@test.local';
            } while (UserCredential::where('email', $email)->exists());
        }

        $user = UserCredential::create([
            'name' => $validated['name'] ?? 'Test User',
            'email' => $email,
            'password' => $validated['password'] ?? 'password123',
            'status' => $validated['status'] ?? 'pending',
            'type' => $validated['type'] ?? 'user',
            'is_sandbox' => true,
            'email_verified' => true,
        ]);

        return response()->json([
            'success' => true,
            'user' => [
                'uni_id' => $user->uni_id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->status,
                'type' => $user->type,
                'is_sandbox' => true,
                'created_at' => $user->created_at?->toISOString(),
            ],
        ], 201);
    }

    /**
     * DELETE /api/admin/sandbox/users/{uniId}
     * Remove a sandbox user together with their sandbox positions & accounts.
     * Refuses to delete non-sandbox (real) users.
     */
    public function deleteUser(Request $request, string $uniId): JsonResponse
    {
        $user = UserCredential::find($uniId);

        if (! $user) {
            return response()->json([
                'success' => false,
                'error_code' => 'USER_NOT_FOUND',
                'message' => 'User not found.',
            ], 404);
        }

        if (! $user->is_sandbox) {
            return response()->json([
                'success' => false,
                'error_code' => 'NOT_SANDBOX',
                'message' => 'Only sandbox users can be deleted here.',
            ], 422);
        }

        DB::table('binance_pastpositions')
            ->where('uni_id', $uniId)
            ->where('is_sandbox', true)
            ->delete();

        BinanceAccount::where('uni_id', $uniId)
            ->where('is_sandbox', true)
            ->forceDelete();

        $user->delete();

        return response()->json([
            'success' => true,
            'deleted_user' => $uniId,
        ]);
    }

    /**
     * POST /api/admin/sandbox/positions
     * Insert closed positions for a user — either from an explicit position
     * template or randomized — resolving/creating a sandbox account to own them.
     *
     * Two modes:
     *  - count (default): insert `count` rows walking backwards from closed_at.
     *  - range: insert exactly one row per calendar day between date_from and
     *    date_to (inclusive), each with a realized P&L randomized inside
     *    [pnl_min, pnl_max] and an exit price derived to match it.
     */
    public function insertPositions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'uni_id' => ['required', 'exists:user_credentials,uni_id'],
            'mode' => ['nullable', 'in:count,range'],
            'count' => ['nullable', 'integer', 'min:1', 'max:200'],
            'date_from' => ['required_if:mode,range', 'date'],
            'date_to' => ['required_if:mode,range', 'date', 'after_or_equal:date_from'],
            'pnl_min' => ['nullable', 'numeric'],
            'pnl_max' => ['nullable', 'numeric', 'gte:pnl_min'],
            'randomize' => ['nullable', 'boolean'],
            'position' => ['nullable', 'array'],
            'position.symbol' => ['string'],
            'position.position_side' => ['in:LONG,SHORT'],
            'position.position_amt' => ['numeric'],
            'position.entry_price' => ['nullable', 'numeric'],
            'position.exit_price' => ['nullable', 'numeric'],
            'position.realized_pnl' => ['numeric'],
            'position.side' => ['nullable', 'in:BUY,SELL'],
            'position.strategy' => ['nullable', 'string', 'max:100'],
            'position.closed_at' => ['nullable', 'date'],
        ]);

        $uniId = $validated['uni_id'];
        $mode = $validated['mode'] ?? 'count';
        $isRange = $mode === 'range';
        $count = $validated['count'] ?? 1;
        $randomize = $validated['randomize'] ?? false;
        $position = $validated['position'] ?? null;

        if (! $randomize && ! $position) {
            return response()->json([
                'success' => false,
                'error_code' => 'POSITION_REQUIRED',
                'message' => 'A position template is required when randomize is false.',
            ], 422);
        }

        // Range mode drives the row count off the calendar: one row per day.
        $rangeStart = null;
        $pnlMin = (float) ($validated['pnl_min'] ?? self::PNL_MIN);
        $pnlMax = (float) ($validated['pnl_max'] ?? self::PNL_MAX);
        if ($pnlMin > $pnlMax) {
            [$pnlMin, $pnlMax] = [$pnlMax, $pnlMin];
        }

        if ($isRange) {
            $rangeStart = Carbon::parse($validated['date_from'])->startOfDay();
            $rangeEnd = Carbon::parse($validated['date_to'])->startOfDay();
            // Inclusive day count; rounded so a DST-shifted diff can't drop a day.
            $count = (int) round($rangeStart->diffInDays($rangeEnd)) + 1;

            if ($count > self::MAX_RANGE_DAYS) {
                return response()->json([
                    'success' => false,
                    'error_code' => 'RANGE_TOO_LONG',
                    'message' => 'The date range covers '.$count.' days; the maximum is '.self::MAX_RANGE_DAYS.'.',
                ], 422);
            }
        }

        $user = UserCredential::find($uniId);

        // Resolve or create a sandbox account to own these rows.
        $acct = BinanceAccount::where('uni_id', $uniId)->withTrashed()->first();
        if (! $acct) {
            $apiKey = 'SBX-'.substr($uniId, 0, 8).'-'.Str::random(4);
            while (BinanceAccount::withTrashed()->where('api_key', $apiKey)->exists()) {
                $apiKey = 'SBX-'.substr($uniId, 0, 8).'-'.Str::random(4);
            }

            $name = 'Sandbox '.$user->name.' '.Str::random(4);
            while (BinanceAccount::withTrashed()->where('name', $name)->exists()) {
                $name = 'Sandbox '.$user->name.' '.Str::random(4);
            }

            $acct = BinanceAccount::create([
                'api_key' => $apiKey,
                'uni_id' => $uniId,
                'secret_key' => 'sandbox',
                'name' => $name,
                'balance' => 10000,
                'unrealized_pnl' => 0,
                'initial_deposit' => 10000,
                'currency_type' => 'USDT',
                'demo' => 1,
                'enabled' => 1,
                'is_sandbox' => true,
                'created_at' => now(),
            ]);
        }

        $apiKey = $acct->api_key;
        $now = now();
        // Base for unique order ids within this batch (and vs. existing rows).
        $orderBase = (int) ((int) $now->timestamp.str_pad((string) mt_rand(0, 999), 3, '0', STR_PAD_LEFT));

        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            if ($randomize) {
                $symbol = self::SYMBOLS[array_rand(self::SYMBOLS)];
                $side = mt_rand(0, 1) ? 'LONG' : 'SHORT';
                $amt = round(mt_rand(1, 5000) / 100, 3); // 0.01 .. 50.000
                $entry = round(mt_rand(50, 120000) / 10, 8);
                // Winners/losers mix: exit drifts up or down from entry.
                $drift = mt_rand(-500, 500) / 10000; // -5% .. +5%
                $exit = round($entry * (1 + $drift), 8);
                $pnl = round(mt_rand(-40000, 70000) / 100, 8); // -400.00 .. 700.00
                $strategy = self::STRATEGIES[array_rand(self::STRATEGIES)];
                $closedAt = $now->copy()
                    ->subDays(mt_rand(0, $count))
                    ->setTime(mt_rand(9, 20), mt_rand(0, 59));
                $closeSide = $side === 'LONG' ? 'SELL' : 'BUY';
            } else {
                $symbol = $position['symbol'];
                $side = $position['position_side'];
                $amt = $position['position_amt'];
                $entry = $position['entry_price'] ?? null;
                $exit = $position['exit_price'] ?? null;
                $pnl = $position['realized_pnl'];
                $strategy = $position['strategy'] ?? null;
                $baseClosed = isset($position['closed_at'])
                    ? Carbon::parse($position['closed_at'])
                    : $now->copy();
                // Spread rows over time so they don't collide / stack on one day.
                $closedAt = $baseClosed->copy()->subDays($i);
                $closeSide = $position['side'] ?? ($side === 'LONG' ? 'SELL' : 'BUY');
            }

            // Range mode owns the P&L and the timing regardless of where the
            // instrument came from: one row per day, P&L rolled in-range and
            // the exit price back-solved so entry/exit/P&L stay coherent.
            if ($isRange) {
                $pnl = round(mt_rand((int) round($pnlMin * 100), (int) round($pnlMax * 100)) / 100, 8);
                $closedAt = $rangeStart->copy()
                    ->addDays($i)
                    ->setTime(mt_rand(9, 20), mt_rand(0, 59));

                if ($entry !== null && (float) $amt > 0) {
                    $move = $pnl / (float) $amt;
                    $exit = round((float) $entry + ($side === 'LONG' ? $move : -$move), 8);
                }
            }

            $rows[] = [
                'api_key' => $apiKey,
                'uni_id' => $uniId,
                'symbol' => $symbol,
                'position_side' => $side,
                'position_amt' => $amt,
                'entry_price' => $entry,
                'exit_price' => $exit,
                'realized_pnl' => $pnl,
                'side' => $closeSide,
                'order_id' => $orderBase + $i,
                'closed_at' => $closedAt,
                'strategy' => $strategy,
                'is_sandbox' => true,
                'created_at' => $now,
            ];
        }

        DB::table('binance_pastpositions')->insert($rows);

        return response()->json([
            'success' => true,
            'inserted' => count($rows),
            'mode' => $mode,
            'account' => [
                'api_key' => $acct->api_key,
                'name' => $acct->name,
            ],
        ], 201);
    }

    /**
     * DELETE /api/admin/sandbox/users/{uniId}/positions
     * Wipe a user's sandbox positions (keeps the user & account).
     */
    public function clearPositions(Request $request, string $uniId): JsonResponse
    {
        $user = UserCredential::find($uniId);

        if (! $user) {
            return response()->json([
                'success' => false,
                'error_code' => 'USER_NOT_FOUND',
                'message' => 'User not found.',
            ], 404);
        }

        $deleted = DB::table('binance_pastpositions')
            ->where('uni_id', $uniId)
            ->where('is_sandbox', true)
            ->delete();

        return response()->json([
            'success' => true,
            'deleted' => $deleted,
        ]);
    }
}
