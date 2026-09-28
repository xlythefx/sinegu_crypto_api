<?php

namespace App\Http\Controllers;

use App\Services\Admin\AdminInsights;
use App\Services\Exchanges\ExchangeSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * GET /api/admin/insights/* — the admin dashboard's tabs (admin middleware).
 *
 * Each answer is cached for a minute: the dashboard is opened often and
 * every tab walks every exchange's tables, while nothing on it needs to be
 * fresher than the pollers that feed it. POST /admin/cache/clear drops them
 * (its optimize:clear empties the application cache).
 */
class AdminInsightsController extends Controller
{
    public const CACHE_PREFIX = 'admin-insights:';

    private const TTL = 60;

    public function __construct(private AdminInsights $insights) {}

    public function overview(): JsonResponse
    {
        return $this->answer('overview', fn () => $this->insights->overview());
    }

    public function customers(): JsonResponse
    {
        return $this->answer('customers', fn () => $this->insights->customers());
    }

    public function money(): JsonResponse
    {
        return $this->answer('money', fn () => $this->insights->money());
    }

    public function system(): JsonResponse
    {
        return $this->answer('system', fn () => $this->insights->system());
    }

    /** ?scope=all|customers|master — the Overview's Platform view. */
    public function platform(Request $request): JsonResponse
    {
        $scope = $this->scope($request);
        if ($scope === null) {
            return response()->json(['success' => false, 'error' => 'SCOPE_NOT_SUPPORTED'], 400);
        }

        return $this->answer('platform:'.$scope, fn () => $this->insights->platform($scope));
    }

    /** ?scope= — the pooled P&L calendar, `days` shaped like /admin/daily-pnl. */
    public function platformDailyPnl(Request $request): JsonResponse
    {
        $scope = $this->scope($request);
        if ($scope === null) {
            return response()->json(['success' => false, 'error' => 'SCOPE_NOT_SUPPORTED'], 400);
        }

        return $this->answer(
            'platform-days:'.$scope,
            fn () => ['days' => $this->insights->platformDailyPnl($scope)],
        );
    }

    /** ?exchange=&from=YYYY-MM-DD&to=YYYY-MM-DD */
    public function strategies(Request $request): JsonResponse
    {
        $exchange = $request->query('exchange');
        if ($exchange !== null && $exchange !== '' && $exchange !== 'all' && ! ExchangeSchema::isSupported($exchange)) {
            return response()->json(['success' => false, 'error' => 'EXCHANGE_NOT_SUPPORTED'], 400);
        }
        $exchange = in_array($exchange, [null, '', 'all'], true) ? null : $exchange;
        $from = $this->date($request->query('from'));
        $to = $this->date($request->query('to'));

        return $this->answer(
            'strategies:'.($exchange ?? 'all').':'.($from ?? '').':'.($to ?? ''),
            fn () => $this->insights->strategyInsights($exchange, $from, $to),
        );
    }

    /**
     * The payload is cached as PLAIN ARRAYS, never as the Collections and
     * Carbons AdminInsights builds. `cache.serializable_classes` is false, so
     * an object read back from the cache is a `__PHP_Incomplete_Class` — which
     * JSON-encodes as an object, not a list. Only the request that computed
     * the entry was right; every hit for the next minute sent
     * `blocked_keys: {…}` and the Overview tab crashed on `.slice`
     * (2026-09-28). Round-tripping through JSON here yields exactly what the
     * response would have carried.
     */
    private function answer(string $name, callable $compute): JsonResponse
    {
        return response()->json(
            ['success' => true] + Cache::remember(
                self::CACHE_PREFIX.$name,
                self::TTL,
                fn () => json_decode(json_encode($compute()), true),
            )
        );
    }

    /** Missing means all; anything unknown is null (answered 400). */
    private function scope(Request $request): ?string
    {
        $raw = $request->query('scope');
        if ($raw === null || $raw === '') {
            return 'all';
        }

        return is_string($raw) && in_array($raw, AdminInsights::PLATFORM_SCOPES, true) ? $raw : null;
    }

    private function date(mixed $raw): ?string
    {
        return is_string($raw) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) ? $raw : null;
    }
}
