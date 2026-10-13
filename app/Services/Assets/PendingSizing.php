<?php

namespace App\Services\Assets;

use App\Models\Asset;
use App\Models\AssetChange;
use App\Services\EngineCache;
use App\Services\Exchanges\ExchangeSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Size changes wait for the open position to close (owner, 2026-10-14).
 *
 * A coin's sizing — base size, max position size, the streak ladder — decides
 * how big each entry is AND how many entries a stack may hold: the engine
 * measures an open stack in THIS account's entry size (`stacks_now = open ÷
 * entry`). Change the size in the middle of a stack and the open position is
 * re-measured in the new unit: 2 entries of 60 open, base raised to 120, and
 * the engine reads "1 of 3" and allows two more entries of 120 — a position
 * twice the intended maximum, and close messages that miscount increments.
 *
 * So a sizing change saved while the MAIN account holds the coin on that
 * exchange is stored as WAITING (`assets.pending_sizing`) and goes live the
 * moment that position closes; with nothing open it goes live at once, as
 * before. On an exchange with no main account, it waits until no account
 * there holds the coin. On/off, side and the kill switch are never delayed —
 * switching a coin off is a safety action.
 *
 * The engine never sees a waiting value: it keeps reading the live columns,
 * and this class swaps them and pings its cache, like any asset write.
 */
final class PendingSizing
{
    /** The sizing keys a change can carry — anything else on the asset applies immediately. */
    public const KEYS = ['base_size', 'max_increments', 'streak_sizing_enabled', 'streak_sizes'];

    public const UNCHANGED = 'unchanged';

    public const APPLIED = 'applied';

    public const WAITING = 'waiting';

    public const CANCELLED = 'cancelled';

    public function __construct(
        private StreakSizing $streakSizing,
        private AssetHistory $history,
        private EngineCache $engine,
    ) {}

    /** The sizing the engine trades with right now. @return array<string, mixed> */
    public static function live(Asset $asset): array
    {
        $asset->unsetRelation('streakSizes');

        return [
            'base_size' => (float) $asset->base_size,
            'max_increments' => (float) $asset->max_increments,
            'streak_sizing_enabled' => (bool) $asset->streak_sizing_enabled,
            'streak_sizes' => StreakSizing::ladder($asset),
        ];
    }

    /** The waiting change, or null when none. @return array<string, mixed>|null */
    public static function pending(Asset $asset): ?array
    {
        $raw = $asset->pending_sizing;
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (! is_array($raw)) {
            return null;
        }

        return self::normalise($raw);
    }

    /** What the admin last asked for: the waiting change if there is one, else the live sizing. */
    public static function intended(Asset $asset): array
    {
        return self::pending($asset) ?? self::live($asset);
    }

    /**
     * The full sizing a write asks for: the posted keys over what is intended
     * now. An absent key means "leave it", exactly like the ladder rule on the
     * asset form — so the card's on/off switch, which posts no sizing at all,
     * can never touch a waiting change.
     *
     * @param  array<string, mixed>  $posted
     * @return array<string, mixed>
     */
    public static function merge(Asset $asset, array $posted): array
    {
        $sizing = self::intended($asset);
        foreach (self::KEYS as $key) {
            if (array_key_exists($key, $posted)) {
                $sizing[$key] = $posted[$key];
            }
        }

        return self::normalise($sizing);
    }

    /**
     * A max position size smaller than one entry is a contradiction: the
     * engine would derive a cap of one entry that is itself over the limit.
     *
     * @param  array<string, mixed>  $sizing
     */
    public static function validate(array $sizing): void
    {
        $base = (float) $sizing['base_size'];
        $max = (float) $sizing['max_increments'];
        if ($max + 1e-9 < $base) {
            throw ValidationException::withMessages([
                'max_increments' => sprintf(
                    'Max position size (%s) cannot be smaller than the base size (%s) — one entry would already be over the limit.',
                    self::fmt($max), self::fmt($base),
                ),
            ]);
        }
    }

    /**
     * Who holds the coin right now — the reason a size change has to wait —
     * or null when nothing does. DB only (the positions the pollers and the
     * engine's write-through keep current), never an exchange call.
     *
     * @return array{who: string, side?: string, amount?: float, accounts?: int}|null
     */
    public function holder(Asset $asset): ?array
    {
        $exchange = StreakSizing::exchangeFor($asset);
        if ($exchange === null) {
            return null;
        }

        $open = DB::table(ExchangeSchema::for($exchange)->positions)
            ->where('symbol', $asset->ticker)
            ->whereRaw('ABS(position_amt) > 0.00000001');

        $masterKeys = KillSwitch::masterKeys($exchange);
        if ($masterKeys !== []) {
            $row = $open->whereIn('api_key', $masterKeys)->first(['position_side', 'position_amt']);
            if ($row === null) {
                return null;
            }
            $amount = (float) $row->position_amt;
            $side = in_array($row->position_side, ['LONG', 'SHORT'], true)
                ? $row->position_side
                : ($amount < 0 ? 'SHORT' : 'LONG');

            return ['who' => 'main', 'side' => $side, 'amount' => abs($amount)];
        }

        $accounts = $open->distinct()->count('api_key');

        return $accounts > 0 ? ['who' => 'accounts', 'accounts' => $accounts] : null;
    }

    /**
     * Save a sizing change; the caller holds the surrounding transaction.
     *
     * - UNCHANGED — it is what is already intended (live, or already waiting);
     * - APPLIED   — nothing holds the coin, so it is live now (the caller's
     *               "updated" history line carries the from → to);
     * - WAITING   — the main account holds the coin; stored until it closes;
     * - CANCELLED — it equals the live sizing while a change was waiting: the
     *               admin put the old sizes back, so the waiting change is dropped.
     *
     * @param  array<string, mixed>  $sizing  a full sizing from merge()
     * @param  array{uni_id: string, name: string}|null  $actor
     */
    public function save(Asset $asset, array $sizing, ?array $actor): string
    {
        $sizing = self::normalise($sizing);
        $live = self::live($asset);
        $pending = self::pending($asset);

        if (self::same($sizing, $live)) {
            if ($pending === null) {
                return self::UNCHANGED;
            }
            $this->clearPending($asset);
            $this->history->record(
                $asset, AssetChange::CANCELLED, AssetHistory::diff($live, $pending), $actor,
                'The waiting size change was dropped — the sizes were set back to what is live.',
            );

            return self::CANCELLED;
        }

        if ($pending !== null && self::same($sizing, $pending)) {
            return self::UNCHANGED;
        }

        $holder = $this->holder($asset);
        if ($holder === null) {
            $this->writeLive($asset, $sizing);
            if ($pending !== null) {
                $this->clearPending($asset);
            }

            return self::APPLIED;
        }

        $asset->forceFill([
            'pending_sizing' => $sizing,
            'pending_since' => now(),
            'pending_by' => $actor['uni_id'] ?? null,
            'pending_by_name' => $actor['name'] ?? null,
        ])->save();
        $this->history->record(
            $asset, AssetChange::WAITING, AssetHistory::diff($live, $sizing), $actor,
            'Waits for '.self::holderPhrase($asset, $holder).' to close.',
        );

        return self::WAITING;
    }

    /**
     * Put the waiting change live now — after the close, or by hand ("Apply
     * now"). Locked and re-read, so two triggers racing on the same close
     * apply it once. Returns false when nothing was waiting.
     *
     * @param  array{uni_id: string, name: string}|null  $actor  null = automatic
     */
    public function apply(Asset $asset, ?array $actor, string $note): bool
    {
        $applied = DB::transaction(function () use ($asset, $actor, $note) {
            $fresh = Asset::whereKey($asset->asset_id)->lockForUpdate()->first();
            $pending = $fresh ? self::pending($fresh) : null;
            if ($pending === null) {
                return false;
            }
            $before = AssetHistory::snapshot($fresh);
            $this->writeLive($fresh, $pending);
            $this->clearPending($fresh);
            $fresh->refresh();
            $this->history->record(
                $fresh, AssetChange::APPLIED, AssetHistory::diff($before, AssetHistory::snapshot($fresh)),
                $actor, $note,
            );

            return true;
        });

        if ($applied) {
            $asset->refresh();
            $this->engine->refreshAssets();
        }

        return $applied;
    }

    /** Drop the waiting change; the live sizing stays. Returns false when nothing was waiting. */
    public function cancel(Asset $asset, ?array $actor): bool
    {
        return DB::transaction(function () use ($asset, $actor) {
            $fresh = Asset::whereKey($asset->asset_id)->lockForUpdate()->first();
            $pending = $fresh ? self::pending($fresh) : null;
            if ($pending === null) {
                return false;
            }
            $this->clearPending($fresh);
            $this->history->record(
                $fresh, AssetChange::CANCELLED, AssetHistory::diff(self::live($fresh), $pending), $actor,
                'The waiting size change was cancelled — the live sizes stay.',
            );
            $asset->refresh();

            return true;
        });
    }

    /**
     * Put live every waiting change whose coin nobody holds any more. Called
     * after the engine's position and close syncs and once a minute by the
     * scheduler. Never throws — a failure here must not fail a sync, or the
     * engine would re-send the same rows into the same exception every tick.
     *
     * @return list<string> tickers that went live
     */
    public function applyDue(?string $exchange = null): array
    {
        try {
            $query = Asset::whereNotNull('pending_sizing');
            if ($exchange !== null) {
                $query->whereRaw('LOWER(broker) = ?', [strtolower(ExchangeSchema::for($exchange)->brokerLabel)]);
            }

            $applied = [];
            foreach ($query->get() as $asset) {
                if ($this->holder($asset) !== null) {
                    continue;
                }
                if ($this->apply($asset, AssetHistory::SYSTEM, 'Went live automatically — the position it was waiting for closed.')) {
                    $applied[] = (string) $asset->ticker;
                }
            }

            return $applied;
        } catch (Throwable $e) {
            Log::error('PendingSizing: could not apply waiting size changes.', [
                'exchange' => $exchange,
                'exception' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /** "the main account's SHORT" / "the 3 open positions on MEXC". */
    public static function holderPhrase(Asset $asset, array $holder): string
    {
        if (($holder['who'] ?? null) === 'main') {
            return "the main account's {$asset->ticker} ".strtolower($holder['side'] ?? 'position');
        }
        $n = (int) ($holder['accounts'] ?? 0);

        return $n === 1
            ? "the open {$asset->ticker} position"
            : "the {$n} open {$asset->ticker} positions";
    }

    /** @param  array<string, mixed>  $sizing */
    private function writeLive(Asset $asset, array $sizing): void
    {
        $asset->update([
            'base_size' => $sizing['base_size'],
            'max_increments' => $sizing['max_increments'],
        ]);
        $this->streakSizing->replace($asset, (bool) $sizing['streak_sizing_enabled'], $sizing['streak_sizes']);
    }

    private function clearPending(Asset $asset): void
    {
        $asset->forceFill([
            'pending_sizing' => null,
            'pending_since' => null,
            'pending_by' => null,
            'pending_by_name' => null,
        ])->save();
    }

    /**
     * One canonical shape, so a value posted as "60" and the stored 60.0
     * compare equal and a ladder lists its steps in one order.
     *
     * @param  array<string, mixed>  $sizing
     * @return array<string, mixed>
     */
    private static function normalise(array $sizing): array
    {
        $steps = collect($sizing['streak_sizes'] ?? [])
            ->map(fn ($s) => [
                'kind' => (string) ($s['kind'] ?? ''),
                'streak' => (int) ($s['streak'] ?? 0),
                'size' => (float) ($s['size'] ?? 0),
            ])
            ->sortBy([['kind', 'asc'], ['streak', 'asc']])
            ->values()
            ->all();

        return [
            'base_size' => (float) ($sizing['base_size'] ?? 0),
            'max_increments' => (float) ($sizing['max_increments'] ?? 0),
            'streak_sizing_enabled' => (bool) ($sizing['streak_sizing_enabled'] ?? false),
            'streak_sizes' => $steps,
        ];
    }

    /** @param  array<string, mixed>  $a  @param  array<string, mixed>  $b */
    private static function same(array $a, array $b): bool
    {
        return AssetHistory::diff($a, $b) === [];
    }

    private static function fmt(float $n): string
    {
        return rtrim(rtrim(number_format($n, 6, '.', ''), '0'), '.');
    }
}
