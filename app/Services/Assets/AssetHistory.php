<?php

namespace App\Services\Assets;

use App\Models\Asset;
use App\Models\AssetChange;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The change history of asset settings (owner's request, 2026-10-14): every
 * change, who made it, from → to. Written by every path that changes how a
 * coin trades — the asset form, the card's on/off switch, the Streak Sizing
 * Settings tab, a waiting size going live, the kill switch — so "who set
 * RENDER to 180, and when?" is one look instead of an investigation.
 *
 * Recording is best-effort: the setting is the fact, the history line is the
 * telling, and a failed insert must never undo or fail the change itself.
 */
final class AssetHistory
{
    /** What the history tracks — every column that changes how, or whether, a coin trades. */
    public const FIELDS = [
        'ticker', 'type', 'broker', 'side', 'base_size', 'max_increments', 'enabled',
        'kill_switch_losses', 'streak_sizing_enabled', 'streak_sizes', 'asset_image',
    ];

    /** The actor for a change made by the system rather than a person. */
    public const SYSTEM = null;

    /**
     * The tracked settings as they are now. The ladder is a list so two
     * snapshots compare by value; the image is the stored path, not the URL.
     *
     * @return array<string, mixed>
     */
    public static function snapshot(Asset $asset): array
    {
        $asset->unsetRelation('streakSizes');

        return [
            'ticker' => $asset->ticker,
            'type' => $asset->type,
            'broker' => $asset->broker,
            'side' => $asset->side,
            'base_size' => (float) $asset->base_size,
            'max_increments' => (float) $asset->max_increments,
            'enabled' => (bool) $asset->enabled,
            'kill_switch_losses' => $asset->kill_switch_losses === null ? null : (int) $asset->kill_switch_losses,
            'streak_sizing_enabled' => (bool) $asset->streak_sizing_enabled,
            'streak_sizes' => StreakSizing::ladder($asset),
            'asset_image' => $asset->getRawOriginal('asset_image'),
        ];
    }

    /**
     * `{field: {from, to}}` for every tracked field that differs. A field
     * missing from one side is null there.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public static function diff(?array $before, ?array $after): array
    {
        $changes = [];
        foreach (self::FIELDS as $field) {
            $from = $before[$field] ?? null;
            $to = $after[$field] ?? null;
            if (! self::same($from, $to)) {
                $changes[$field] = ['from' => $from, 'to' => $to];
            }
        }

        return $changes;
    }

    /** Who is acting on a request: the signed-in admin, or the system when there is none. */
    public static function actor(?Request $request): ?array
    {
        $user = $request?->user();
        if ($user === null) {
            return self::SYSTEM;
        }

        return ['uni_id' => (string) $user->uni_id, 'name' => (string) ($user->name ?: $user->email)];
    }

    /**
     * Write one history line. An `updated` line with nothing in it is not
     * written — re-saving a form unchanged is not a change.
     *
     * @param  array<string, array{from: mixed, to: mixed}>  $changes
     * @param  array{uni_id: string, name: string}|null  $actor  null = the system
     */
    public function record(
        Asset $asset,
        string $event,
        array $changes,
        ?array $actor,
        ?string $note = null,
        ?string $systemName = null,
    ): void {
        if ($changes === [] && in_array($event, [AssetChange::UPDATED, AssetChange::WAITING], true)) {
            return;
        }

        try {
            AssetChange::create([
                'asset_id' => $asset->asset_id,
                'ticker' => (string) $asset->ticker,
                'broker' => $asset->broker,
                'event' => $event,
                'actor_uni_id' => $actor['uni_id'] ?? null,
                'actor_name' => $actor['name'] ?? ($systemName ?? 'Automatic'),
                'changes' => $changes === [] ? null : $changes,
                'note' => $note === null ? null : mb_substr($note, 0, 255),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('AssetHistory: could not record a change.', [
                'asset_id' => $asset->asset_id,
                'event' => $event,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /** Floats compare as numbers (a DB round trip turns 60 into 60.0), everything else strictly. */
    private static function same(mixed $a, mixed $b): bool
    {
        if (is_numeric($a) && is_numeric($b) && ! is_bool($a) && ! is_bool($b)) {
            return abs((float) $a - (float) $b) < 1e-9;
        }
        if (is_array($a) && is_array($b)) {
            return json_encode(self::normalise($a)) === json_encode(self::normalise($b));
        }

        return $a === $b;
    }

    /** A ladder's sizes as floats, so [size: 40] and [size: 40.0] compare equal. */
    private static function normalise(array $list): array
    {
        return array_map(
            fn ($item) => is_array($item)
                ? array_map(fn ($v) => is_numeric($v) && ! is_bool($v) ? (float) $v : $v, $item)
                : $item,
            $list,
        );
    }
}
