<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

/**
 * Admin maintenance actions — currently the cache flush behind the
 * "Clear caches" button on the admin dashboard.
 *
 * Note for anyone chasing a stale FRONTEND: this endpoint does not touch
 * browser caches and cannot. Clients revalidate because nginx serves
 * index.html with `Cache-Control: no-cache` (see the vhost in
 * .claude/deploy_sinegualcrypto.py); the hashed /assets/* bundles are
 * immutable and change name every build. What this clears is server-side:
 * Laravel's config/route/view caches and the engine's in-memory
 * account/asset caches.
 */
class AdminMaintenanceController extends Controller
{
    public function clearCaches(): JsonResponse
    {
        $cleared = [];
        $failed = [];

        // optimize:clear drops config/route/view/event caches, then we rebuild
        // the two that matter for performance. A failure here is not fatal —
        // Laravel simply runs uncached — so each step is reported individually.
        foreach (['optimize:clear', 'config:cache', 'route:cache'] as $command) {
            try {
                Artisan::call($command);
                $cleared[] = $command;
            } catch (\Throwable $e) {
                $failed[] = "{$command}: {$e->getMessage()}";
            }
        }

        return response()->json([
            'success' => $failed === [],
            'cleared' => $cleared,
            'failed' => $failed,
            'engine' => $this->refreshEngineCaches(),
        ]);
    }

    /**
     * Ask the trading engine to drop its cached account + asset lists so a
     * change made in admin is picked up now instead of at the next TTL expiry.
     */
    private function refreshEngineCaches(): array
    {
        $base = rtrim((string) config('services.engine.targets.local', 'http://127.0.0.1:5010'), '/');
        $secret = (string) (config('services.engine.webhook_secrets.binance') ?? '');
        if ($secret === '') {
            return ['refreshed' => [], 'error' => 'No engine webhook secret configured.'];
        }

        $refreshed = [];
        foreach (['refresh-accounts', 'refresh-assets'] as $path) {
            try {
                $response = Http::withHeaders(['X-Admin-Secret' => $secret])
                    ->timeout(8)
                    ->post("{$base}/admin/{$path}");
                if ($response->successful()) {
                    $refreshed[] = $path;
                }
            } catch (\Throwable) {
                // engine down / unreachable — reported by the empty list
            }
        }

        return [
            'refreshed' => $refreshed,
            'error' => $refreshed === [] ? 'Engine did not respond.' : null,
        ];
    }
}
