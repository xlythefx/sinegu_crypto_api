<?php

namespace App\Services\Assets;

use App\Models\Asset;
use App\Models\AssetStreakSize;
use App\Services\Exchanges\ExchangeSchema;
use App\Services\Positions\CloseSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Streak sizing — the ONE definition of what a winning or losing run is and
 * of how an asset's ladder is stored. The engine applies the ladder; this
 * class answers the two questions it cannot answer itself: which steps the
 * admin configured, and which run each account is on.
 *
 * Rules (owner, 2026-10-09):
 * - A run is an account's OWN closes on one coin, long and short together,
 *   newest first, counted while they keep the same result. It is SIGNED:
 *   -3 = three losses in a row, +2 = two wins in a row, 0 = no known close.
 *   Keyed by `api_key`, so a brand-new key starts at 0 = base size.
 * - One past-position row is one trade — a stacked position closes as one row.
 * - A win is any `realized_pnl > 0` (after fees from 2026-09-11 on); zero or
 *   negative is a loss. A row whose P&L is not written yet is skipped, never
 *   guessed. Sandbox rows never count, nor do closes made OUTSIDE the bot
 *   (`close_source = outside`, owner 2026-10-14).
 * - Steps are manual sizes per kind ('loss' after N losses, 'win' after N
 *   wins). The deepest step of the run's kind at or below its length applies;
 *   none reached = base size. A win ends a losing run and vice versa, so with
 *   no win steps configured any win means base size (the loss-only behaviour).
 * - **Customers follow the MASTER's run (owner + Dima, 2026-11-13).** Every
 *   account owned by a `user` or the `master` sizes on the master's run on
 *   the coin, on the same venue (`followedRuns`), not its own. On SANTOS a
 *   customer whose fill came out a few cents worse than the master's turned
 *   a master WIN into a fee-sized loss of their own, and traded the next
 *   (best) trade at a third of their size. Testers and other staff keep their
 *   OWN run — testers trade a different signal feed. A venue with no
 *   real-money master (`KillSwitch::masterKeys` empty — Bybit's master is
 *   demo) keeps every account on its own run, as before.
 */
final class StreakSizing
{
    /** Deepest step an admin may configure per kind; also the most rows read per account. */
    public const MAX_STEPS = 10;

    /** Owner roles whose accounts size on the master's run instead of their own. */
    public const MASTER_FOLLOWERS = ['user', 'master'];

    public const KINDS = [AssetStreakSize::LOSS, AssetStreakSize::WIN];

    /**
     * Validation rules for a ladder posted under `streak_sizes`. Shared by the
     * asset form (multipart) and the dedicated tab endpoint (JSON). A repeated
     * (kind, streak) pair is caught by validateDistinct() — 'distinct' only
     * sees one field.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'streak_sizing_enabled' => ['required', 'boolean'],
            'streak_sizes' => ['nullable', 'array', 'max:'.(self::MAX_STEPS * 2)],
            'streak_sizes.*.kind' => ['required', 'string', 'in:'.implode(',', self::KINDS)],
            'streak_sizes.*.streak' => ['required', 'integer', 'between:1,'.self::MAX_STEPS],
            'streak_sizes.*.size' => ['required', 'numeric', 'gt:0'],
        ];
    }

    /**
     * Refuse a ladder that names the same (kind, streak) twice — the unique key
     * would otherwise turn it into a 500 halfway through the replace.
     *
     * @param  array<int, array{kind: string, streak: int|string, size: float|string}>  $steps
     */
    public static function validateDistinct(array $steps): void
    {
        $seen = [];
        foreach ($steps as $i => $step) {
            $key = $step['kind'].':'.(int) $step['streak'];
            if (isset($seen[$key])) {
                $noun = $step['kind'] === AssetStreakSize::WIN ? 'wins' : 'losses';
                throw ValidationException::withMessages([
                    "streak_sizes.{$i}.streak" => "The step after {$step['streak']} {$noun} in a row is listed twice.",
                ]);
            }
            $seen[$key] = true;
        }
    }

    /**
     * Replace an asset's switch and ladder in one transaction. `$steps` is the
     * validated `streak_sizes` list; an empty list clears the ladder.
     *
     * @param  array<int, array{kind: string, streak: int|string, size: float|string}>  $steps
     */
    public function replace(Asset $asset, bool $enabled, array $steps): void
    {
        self::validateDistinct($steps);

        DB::transaction(function () use ($asset, $enabled, $steps) {
            $asset->update(['streak_sizing_enabled' => $enabled]);
            $asset->streakSizes()->delete();
            foreach ($steps as $step) {
                $asset->streakSizes()->create([
                    'kind' => $step['kind'],
                    'streak' => (int) $step['streak'],
                    'size' => (float) $step['size'],
                ]);
            }
        });
    }

    /**
     * The ladder as the API publishes it: loss steps then win steps, each
     * shallowest first.
     *
     * @return list<array{kind: string, streak: int, size: float}>
     */
    public static function ladder(Asset $asset): array
    {
        return $asset->streakSizes
            ->map(fn ($s) => ['kind' => $s->kind, 'streak' => (int) $s->streak, 'size' => (float) $s->size])
            ->sortBy([['kind', 'asc'], ['streak', 'asc']])
            ->values()
            ->all();
    }

    /** Deepest configured step of either kind, or 0 when the ladder is empty. */
    public static function deepestStep(Asset $asset): int
    {
        return (int) ($asset->streakSizes->max('streak') ?? 0);
    }

    /**
     * Current run per account on one coin: `[api_key => signed run]`, the
     * length capped at `$depth` (|run| equal to $depth means "$depth or more").
     * Accounts with no known close on the coin are absent — read them as 0.
     *
     * One query for every account: the last $depth known closes per api_key,
     * newest first, served by the (api_key, symbol, closed_at) index.
     *
     * @param  list<string>|null  $apiKeys  narrow to these accounts (null = all)
     * @return array<string, int>
     */
    public function runs(string $exchange, string $symbol, int $depth, ?array $apiKeys = null): array
    {
        $depth = max(1, min(self::MAX_STEPS, $depth));
        if ($apiKeys === []) {
            return [];
        }

        $inner = $this->countedCloses($exchange, $symbol)
            ->select('api_key', 'realized_pnl')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY api_key ORDER BY closed_at DESC, id DESC) AS rn');
        if ($apiKeys !== null) {
            $inner->whereIn('api_key', $apiKeys);
        }

        $rows = DB::query()
            ->fromSub($inner, 'recent')
            ->where('rn', '<=', $depth)
            ->orderBy('api_key')
            ->orderBy('rn')
            ->get(['api_key', 'realized_pnl']);

        $runs = [];
        $ended = [];   // api_keys whose run already met the opposite result
        foreach ($rows as $row) {
            $key = $row->api_key;
            if (isset($ended[$key])) {
                continue;
            }
            $sign = (float) $row->realized_pnl > 0 ? 1 : -1;
            $current = $runs[$key] ?? 0;
            if ($current !== 0 && ($current > 0) !== ($sign > 0)) {
                $ended[$key] = true;

                continue;
            }
            $runs[$key] = $current + $sign;
        }

        return $runs;
    }

    /**
     * The run each account SIZES on — what the engine reads: `runs()` with
     * every customer's and the master's own run replaced by the master's
     * (see the class docblock). Same shape as runs(): absent = 0 = base size.
     * With no real-money master on the venue it is exactly runs().
     *
     * @param  list<string>|null  $apiKeys  narrow to these accounts (null = all)
     * @return array<string, int>
     */
    public function followedRuns(string $exchange, string $symbol, int $depth, ?array $apiKeys = null): array
    {
        $runs = $this->runs($exchange, $symbol, $depth, $apiKeys);
        $masterRun = $this->masterRun($exchange, $symbol, $depth);
        if ($masterRun === null) {
            return $runs;
        }

        foreach (self::followerKeys($exchange) as $key) {
            if ($apiKeys !== null && ! in_array($key, $apiKeys, true)) {
                continue;
            }
            if ($masterRun === 0) {
                unset($runs[$key]);
            } else {
                $runs[$key] = $masterRun;
            }
        }

        return $runs;
    }

    /**
     * The master's signed run on the coin, its real-money accounts on the
     * venue read as ONE history (newest first) — or null when the venue has
     * no real-money master, which means "nobody to follow".
     */
    public function masterRun(string $exchange, string $symbol, int $depth): ?int
    {
        $masterKeys = KillSwitch::masterKeys($exchange);
        if ($masterKeys === []) {
            return null;
        }
        $depth = max(1, min(self::MAX_STEPS, $depth));

        $pnls = $this->countedCloses($exchange, $symbol)
            ->whereIn('api_key', $masterKeys)
            ->orderByDesc('closed_at')
            ->orderByDesc('id')
            ->limit($depth)
            ->pluck('realized_pnl');

        $run = 0;
        foreach ($pnls as $pnl) {
            $sign = (float) $pnl > 0 ? 1 : -1;
            if ($run !== 0 && ($run > 0) !== ($sign > 0)) {
                break;
            }
            $run += $sign;
        }

        return $run;
    }

    /**
     * Accounts on the venue that follow the master's run: owned by a role in
     * MASTER_FOLLOWERS. Disconnected rows too — harmless, and a reconnect
     * revives the same key. @return list<string>
     */
    public static function followerKeys(string $exchange): array
    {
        $schema = ExchangeSchema::for($exchange);
        $t = $schema->accountsTable;

        return DB::table($t)
            ->join('user_credentials', 'user_credentials.uni_id', '=', "{$t}.uni_id")
            ->whereIn('user_credentials.type', self::MASTER_FOLLOWERS)
            ->pluck("{$t}.api_key")
            ->map(fn ($k) => (string) $k)
            ->all();
    }

    /**
     * The closes a run counts on one coin: known P&L, never sandbox, never a
     * close made outside the bot (the customer by hand, their own TP/SL — not
     * the strategy's trade).
     */
    private function countedCloses(string $exchange, string $symbol): \Illuminate\Database\Query\Builder
    {
        return DB::table(ExchangeSchema::for($exchange)->pastPositions)
            ->where('symbol', $symbol)
            ->where('is_sandbox', 0)
            ->whereNotNull('realized_pnl')
            ->where(fn ($q) => $q->whereNull('close_source')->orWhere('close_source', '!=', CloseSource::OUTSIDE));
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
