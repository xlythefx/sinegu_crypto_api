<?php

namespace App\Services\Assets;

use App\Models\Asset;
use App\Services\Exchanges\ExchangeSchema;
use Illuminate\Support\Facades\DB;

/**
 * Loss-streak sizing — the ONE definition of what a losing streak is and of
 * how an asset's ladder is stored. The engine applies the ladder; this class
 * answers the two questions it cannot answer itself: which steps the admin
 * configured, and how many losses in a row each account is on.
 *
 * Rules (owner, 2026-10-09):
 * - A streak is an account's OWN closes on one coin, long and short together,
 *   newest first, counted until the first win. Keyed by `api_key`, so a
 *   brand-new key starts at 0 = base size.
 * - One past-position row is one trade — a stacked position closes as one row,
 *   so three increments closing at a loss are ONE loss.
 * - A win is any `realized_pnl > 0` (the column is after fees from 2026-09-11
 *   on). Zero or negative is a loss. A row whose P&L is not written yet (the
 *   webhook close writes it in the background) is skipped, never guessed.
 * - Sandbox rows never count.
 * - Steps are manual sizes; a blank step is absent and the engine carries the
 *   previous one forward. Wins always mean base size — there is no win ladder.
 */
final class LossSizing
{
    /** Deepest step an admin may configure; also the most rows read per account. */
    public const MAX_STEPS = 10;

    /**
     * Validation rules for a ladder posted under `loss_sizes`. Shared by the
     * asset form (multipart) and the dedicated tab endpoint (JSON).
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'loss_sizing_enabled' => ['required', 'boolean'],
            'loss_sizes' => ['nullable', 'array', 'max:'.self::MAX_STEPS],
            'loss_sizes.*.losses' => ['required', 'integer', 'between:1,'.self::MAX_STEPS, 'distinct'],
            'loss_sizes.*.size' => ['required', 'numeric', 'gt:0'],
        ];
    }

    /**
     * Replace an asset's switch and ladder in one transaction. `$steps` is the
     * validated `loss_sizes` list; an empty list clears the ladder.
     *
     * @param  array<int, array{losses: int|string, size: float|string}>  $steps
     */
    public function replace(Asset $asset, bool $enabled, array $steps): void
    {
        DB::transaction(function () use ($asset, $enabled, $steps) {
            $asset->update(['loss_sizing_enabled' => $enabled]);
            $asset->lossSizes()->delete();
            foreach ($steps as $step) {
                $asset->lossSizes()->create([
                    'losses' => (int) $step['losses'],
                    'size' => (float) $step['size'],
                ]);
            }
        });
    }

    /**
     * The ladder as the API publishes it: shallowest step first.
     *
     * @return list<array{losses: int, size: float}>
     */
    public static function ladder(Asset $asset): array
    {
        return $asset->lossSizes
            ->map(fn ($s) => ['losses' => (int) $s->losses, 'size' => (float) $s->size])
            ->sortBy('losses')
            ->values()
            ->all();
    }

    /** Deepest configured step, or 0 when the ladder is empty. */
    public static function deepestStep(Asset $asset): int
    {
        return (int) ($asset->lossSizes->max('losses') ?? 0);
    }

    /**
     * Current losing streak per account on one coin: `[api_key => losses]`,
     * capped at `$depth` (a streak equal to $depth means "$depth or more").
     * Accounts with no known close on the coin are absent — read them as 0.
     *
     * One query for every account: the last $depth known closes per api_key,
     * newest first, served by the (api_key, symbol, closed_at) index.
     *
     * @param  list<string>|null  $apiKeys  narrow to these accounts (null = all)
     * @return array<string, int>
     */
    public function streaks(string $exchange, string $symbol, int $depth, ?array $apiKeys = null): array
    {
        $depth = max(1, min(self::MAX_STEPS, $depth));
        if ($apiKeys === []) {
            return [];
        }

        $inner = DB::table(ExchangeSchema::for($exchange)->pastPositions)
            ->select('api_key', 'realized_pnl')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY api_key ORDER BY closed_at DESC, id DESC) AS rn')
            ->where('symbol', $symbol)
            ->where('is_sandbox', 0)
            ->whereNotNull('realized_pnl');
        if ($apiKeys !== null) {
            $inner->whereIn('api_key', $apiKeys);
        }

        $rows = DB::query()
            ->fromSub($inner, 'recent')
            ->where('rn', '<=', $depth)
            ->orderBy('api_key')
            ->orderBy('rn')
            ->get(['api_key', 'realized_pnl']);

        $streaks = [];
        $closed = [];   // api_keys whose streak already hit a win
        foreach ($rows as $row) {
            $key = $row->api_key;
            $streaks[$key] ??= 0;
            if (isset($closed[$key])) {
                continue;
            }
            if ((float) $row->realized_pnl > 0) {
                $closed[$key] = true;
            } else {
                $streaks[$key]++;
            }
        }

        return $streaks;
    }

    /** The exchange whose accounts trade this asset (`assets.broker` → ExchangeSchema), or null. */
    public static function exchangeFor(Asset $asset): ?string
    {
        $broker = strtolower(trim((string) $asset->broker));
        foreach (ExchangeSchema::supported() as $exchange) {
            if (strtolower(ExchangeSchema::for($exchange)->brokerLabel) === $broker) {
                return $exchange;
            }
        }

        return null;
    }
}
