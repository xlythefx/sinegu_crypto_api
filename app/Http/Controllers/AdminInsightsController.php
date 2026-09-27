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

    private function answer(string $name, callable $compute): JsonResponse
    {
        return response()->json(
            ['success' => true] + Cache::remember(self::CACHE_PREFIX.$name, self::TTL, $compute)
        );
    }

    private function date(mixed $raw): ?string
    {
        return is_string($raw) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) ? $raw : null;
    }
}
