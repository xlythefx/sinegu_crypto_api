<?php

namespace App\Services\Creatives;

use App\Models\CreativeGeneration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * How long a generation USUALLY takes, per model, measured on our own
 * finished generations (submit → we saw it done, so it includes our checking
 * delay — what the team actually waits). Feeds the editor's "usually about …"
 * beside a running scene. A model with fewer than MIN_SAMPLES finished runs
 * has no figure yet (null), never a guess.
 */
final class CreativeTimings
{
    public const CACHE_KEY = 'creatives:typical-seconds';

    public const MIN_SAMPLES = 3;

    private const DAYS = 60;

    /** Longer than this is a stuck row, not a duration. */
    private const MAX_SECONDS = 3 * 3600;

    /** @return array<string, int> model key → median seconds */
    public function typical(): array
    {
        return Cache::remember(self::CACHE_KEY, 600, function () {
            $rows = CreativeGeneration::query()
                ->whereIn('status', [CreativeGeneration::COMPLETED, CreativeGeneration::EXPIRED])
                ->whereNotNull('completed_at')
                ->where('created_at', '>=', now()->subDays(self::DAYS)->toDateTimeString())
                ->orderByDesc('id')
                ->limit(2000)
                ->get(['model', 'created_at', 'completed_at']);

            $byModel = [];
            foreach ($rows as $row) {
                $seconds = $row->completed_at->getTimestamp() - $row->created_at->getTimestamp();
                if ($seconds > 0 && $seconds <= self::MAX_SECONDS) {
                    $byModel[(string) $row->model][] = $seconds;
                }
            }

            $out = [];
            foreach ($byModel as $model => $durations) {
                if (count($durations) >= self::MIN_SAMPLES) {
                    $out[$model] = self::median($durations);
                }
            }

            return $out;
        });
    }

    /** The typical total of several models run one after another; null when any is unknown. */
    public function typicalChain(array $models): ?int
    {
        $typical = $this->typical();
        $total = 0;
        foreach ($models as $model) {
            if (! isset($typical[$model])) {
                return null;
            }
            $total += $typical[$model];
        }

        return $models === [] ? null : $total;
    }

    public static function seconds(?string $from, ?string $to): ?int
    {
        if ($from === null || $to === null) {
            return null;
        }

        return max(0, Carbon::parse($to)->getTimestamp() - Carbon::parse($from)->getTimestamp());
    }

    /** @param  list<int>  $values */
    private static function median(array $values): int
    {
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return (int) round($n % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2);
    }
}
