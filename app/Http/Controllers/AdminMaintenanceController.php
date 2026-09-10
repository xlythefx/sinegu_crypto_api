<?php

namespace App\Http\Controllers;

use App\Services\EngineCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;

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
    public function __construct(private EngineCache $engineCache) {}

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
            // The manual catch-all. Every write that changes what the engine
            // trades now invalidates on its own (see EngineCache), so this
            // button is a backstop, not the mechanism.
            'engine' => $this->engineCache->refreshAll(),
        ]);
    }
}
