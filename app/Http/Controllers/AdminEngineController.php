<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
