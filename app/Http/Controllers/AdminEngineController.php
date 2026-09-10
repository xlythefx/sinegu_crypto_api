<?php

namespace App\Http\Controllers;

use App\Models\BinanceAccount;
use App\Services\EngineCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * Admin ops surface for the Python trading engine's systemd service running on
 * this same box: state + health, journal tail, and restart.
 *
 * Every shell command is a FIXED argv — nothing user-supplied ever reaches the
 * command line (the only input, `lines`, is clamped to an int and passed as its
 * own argv element). Restart depends on the sudoers rule installed by
 * deploy-engine:
 *   www-data ALL=(root) NOPASSWD: /usr/bin/systemctl restart sinegualerts-engine
 * and journal reads on www-data belonging to the systemd-journal group.
 */
class AdminEngineController extends Controller
{
    private function service(): string
    {
        return (string) config('services.engine.service', 'sinegualerts-engine');
    }

    /** systemd exists here? Overridable via config so tests can fake Linux. */
    private function systemdAvailable(): bool
    {
        $override = config('services.engine.systemd');

        return $override !== null ? (bool) $override : PHP_OS_FAMILY === 'Linux';
    }

    private function state(): ?string
    {
        if (! $this->systemdAvailable()) {
            return null;
        }
        $result = Process::timeout(5)->run(['systemctl', 'is-active', $this->service()]);
        $state = trim($result->output());

        return $state !== '' ? $state : null;
    }

    private function activeSince(): ?string
    {
        if (! $this->systemdAvailable()) {
            return null;
        }
        $result = Process::timeout(5)->run([
            'systemctl', 'show', $this->service(), '-p', 'ActiveEnterTimestamp', '--value',
        ]);
        $since = trim($result->output());

        return $since !== '' ? $since : null;
    }

    /** The engine's own /health JSON (reachable even on local dev). */
    private function health(): ?array
    {
        $base = rtrim((string) config('services.engine.targets.local', 'http://127.0.0.1:5010'), '/');

        try {
            $response = Http::timeout(3)->get("{$base}/health");

            return $response->successful() ? $response->json() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * GET /api/admin/engine/key-issues
     *
     * Accounts the exchange is currently refusing, newest fault first, with
     * whose they are and how long they have left before the automatic
     * disconnect. Support's view of a failure that is otherwise invisible:
     * the trader sees "connected", the balance freezes, and no trades arrive.
     *
     * Reads columns the engine writes — it never touches Binance itself, so
     * opening this page costs nothing at the exchange.
     */
    public function keyIssues(): JsonResponse
    {
        $blocked = BinanceAccount::query()
            ->leftJoin('user_credentials', 'user_credentials.uni_id', '=', 'binance_accounts.uni_id')
            ->where('binance_accounts.key_status', BinanceAccount::KEY_BLOCKED)
            ->orderByDesc('binance_accounts.key_blocked_at')
            ->get([
                'binance_accounts.id',
                'binance_accounts.name',
                'binance_accounts.uni_id',
                'binance_accounts.api_key',
                'binance_accounts.demo',
                'binance_accounts.enabled',
                'binance_accounts.balance',
                'binance_accounts.key_error_code',
                'binance_accounts.key_error_reason',
                'binance_accounts.key_error_message',
                'binance_accounts.key_blocked_at',
                'binance_accounts.key_checked_at',
                'user_credentials.name as owner_name',
                'user_credentials.email as owner_email',
                'user_credentials.status as owner_status',
            ]);

        return response()->json([
            'success' => true,
            'grace_days' => BinanceAccount::KEY_GRACE_DAYS,
            'server_ip' => config('services.engine.public_ip'),
            'accounts' => $blocked->map(function ($a) {
                $blockedAt = $a->key_blocked_at ? Carbon::parse($a->key_blocked_at) : null;
                $graceEnds = $blockedAt?->copy()->addDays(BinanceAccount::KEY_GRACE_DAYS);

                return [
                    'id' => $a->id,
                    'name' => $a->name,
                    'uni_id' => $a->uni_id,
                    // Enough to identify the key in the Binance UI, never the
                    // whole credential — this is a support screen, not a vault.
                    'api_key_hint' => substr((string) $a->api_key, 0, 6).'…'
                        .substr((string) $a->api_key, -4),
                    'owner_name' => $a->owner_name,
                    'owner_email' => $a->owner_email,
                    'owner_status' => $a->owner_status,
                    'demo' => (bool) $a->demo,
                    'enabled' => (bool) $a->enabled,
                    'balance' => $a->balance !== null ? (float) $a->balance : null,
                    'error_code' => $a->key_error_code,
                    'error_reason' => $a->key_error_reason,
                    'error_message' => $a->key_error_message,
                    'blocked_at' => $blockedAt?->toIso8601String(),
                    'checked_at' => $a->key_checked_at
                        ? Carbon::parse($a->key_checked_at)->toIso8601String()
                        : null,
                    'grace_ends_at' => $graceEnds?->toIso8601String(),
                    // Negative means the daily sweep simply has not run yet.
                    'days_left' => $graceEnds ? (int) ceil(now()->floatDiffInDays($graceEnds, false)) : null,
                ];
            })->values(),
        ]);
    }

    /**
     * POST /api/admin/engine/key-issues/{id}/recheck
     *
     * Re-test one account against the exchange from here, instead of waiting
     * for a poller tick or asking the trader to press their own button. The
     * engine's verdict lands on the row, so a fixed allow-list clears the flag
     * immediately.
     */
    public function recheckKey(int $id, EngineCache $engineCache): JsonResponse
    {
        $account = BinanceAccount::find($id);
        if (! $account) {
            return response()->json(['success' => false, 'message' => 'Account not found.'], 404);
        }

        $reached = $engineCache->syncBalances([$account->api_key]);
        $account->refresh();

        return response()->json([
            'success' => $reached,
            'status' => $account->key_status,
            'cleared' => ! $account->keyIsBlocked(),
            'message' => $reached
                ? ($account->keyIsBlocked()
                    ? 'Still refused by the exchange.'
                    : 'Key works again — the account is trading.')
                : 'Could not reach the engine.',
        ], $reached ? 200 : 503);
    }

    public function status(): JsonResponse
    {
        $available = $this->systemdAvailable();
        $state = $this->state();
        $health = $this->health();

        $message = null;
        if (! $available) {
            $message = 'systemd is not available here — engine control works on the prod server only.';
        } elseif ($state !== 'active') {
            $message = 'The engine service is not running.';
        } elseif ($health === null) {
            $message = 'Service is active but /health did not answer — the engine may still be starting.';
        }

        return response()->json([
            'success' => true,
            'engine' => [
                'service' => $this->service(),
                'available' => $available,
                'state' => $state,
                'active_since' => $this->activeSince(),
                'health' => $health,
                'message' => $message,
            ],
        ]);
    }

    public function logs(Request $request): JsonResponse
    {
        $lines = max(10, min(1000, (int) $request->query('lines', 200)));

        if (! $this->systemdAvailable()) {
            return response()->json(['success' => true, 'available' => false, 'lines' => []]);
        }

        $result = Process::timeout(10)->run([
            'journalctl', '-u', $this->service(), '-n', (string) $lines, '--no-pager', '-o', 'short-iso',
        ]);

        if ($result->failed()) {
            return response()->json([
                'success' => false,
                'message' => 'Could not read the journal: '.trim($result->errorOutput() ?: $result->output()),
            ], 500);
        }

        $output = trim($result->output());

        return response()->json([
            'success' => true,
            'available' => true,
            'lines' => $output === '' ? [] : explode("\n", $output),
        ]);
    }

    public function restart(): JsonResponse
    {
        if (! $this->systemdAvailable()) {
            return response()->json([
                'success' => false,
                'message' => 'Restart is only available on the prod server.',
            ], 422);
        }

        $result = Process::timeout(30)->run(['sudo', '-n', 'systemctl', 'restart', $this->service()]);

        if ($result->failed()) {
            return response()->json([
                'success' => false,
                'message' => 'Restart failed: '.trim($result->errorOutput() ?: $result->output()),
            ], 500);
        }

        sleep(2); // give systemd a beat so the returned state is meaningful

        return response()->json(['success' => true, 'state' => $this->state()]);
    }
}
