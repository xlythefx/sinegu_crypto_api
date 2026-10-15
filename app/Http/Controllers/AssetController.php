<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AssetChange;
use App\Services\Assets\AssetHistory;
use App\Services\Assets\KillSwitch;
use App\Services\Assets\PendingSizing;
use App\Services\Assets\StreakSizing;
use App\Services\EngineCache;
use App\Services\Exchanges\ExchangeSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Trading assets catalog — admin-managed (routes behind the 'admin' middleware).
 *
 * Every write here changes how the engine sizes trades (base_size,
 * max_increments), which direction it may take (side), or whether the ticker
 * trades at all (enabled) — so each one invalidates the engine's asset cache.
 * The engine is never restarted for a config change.
 *
 * SIZING (base size, max position size, the streak ladder) goes through
 * App\Services\Assets\PendingSizing: saved while the main account holds the
 * coin, it WAITS and goes live when that position closes; with nothing open it
 * applies at once. Everything else (on/off, side, kill switch, name, image)
 * applies immediately. Every change is written to the asset's history
 * (App\Services\Assets\AssetHistory).
 *
 * The streak ladder belongs to the same asset: the form posts it alongside the
 * asset fields, the Streak Sizing Settings tab writes it alone. It is NEVER in
 * the trader catalog — like base_size, it is how big the bot trades.
 */
class AssetController extends Controller
{
    public function __construct(
        private EngineCache $engineCache,
        private StreakSizing $streakSizing,
        private PendingSizing $pendingSizing,
        private AssetHistory $history,
    ) {}

    /** GET /api/admin/assets */
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'assets' => Asset::with('streakSizes')->orderBy('ticker')->get()
                ->map(fn (Asset $a) => $this->present($a)),
        ]);
    }

    /**
     * An asset as the admin screens read it: every column plus its ladder,
     * the waiting size change (with what it waits for), and the main
     * account's balance + the last signal price, so the form can say what one
     * entry is in coins and dollars before it is saved.
     */
    private function present(Asset $asset): array
    {
        $asset->loadMissing('streakSizes');
        $pending = PendingSizing::pending($asset);

        $row = $asset->withoutRelations()->toArray();
        unset($row['pending_sizing'], $row['pending_since'], $row['pending_by'], $row['pending_by_name']);

        return array_merge($row, [
            'streak_sizing_enabled' => (bool) $asset->streak_sizing_enabled,
            'streak_sizes' => StreakSizing::ladder($asset),
            'pending_sizing' => $pending === null ? null : $pending + [
                'since' => $asset->pending_since?->toIso8601String(),
                'by_name' => $asset->pending_by_name,
                // null = nothing holds the coin any more; it goes live within a minute.
                'waits_for' => $this->pendingSizing->holder($asset),
            ],
            'main_account' => $this->mainAccount($asset),
        ]);
    }

    /**
     * The main account's balance on the asset's exchange and the coin's last
     * signal price — the inputs for "one entry ≈ 2,100 RENDER ≈ $4,600" on
     * the form. DB only; either may be null (no main account there, no signal
     * yet).
     *
     * @return array{balance: float|null, price: float|null}
     */
    private function mainAccount(Asset $asset): array
    {
        $exchange = StreakSizing::exchangeFor($asset);
        if ($exchange === null) {
            return ['balance' => null, 'price' => null];
        }
        $keys = KillSwitch::masterKeys($exchange);
        $balance = $keys === [] ? null : ExchangeSchema::for($exchange)->accountQuery()
            ->whereIn('api_key', $keys)
            ->max('balance');
        // Main feed only: a test provider's price is not the market's.
        $price = DB::table('trade_logs')
            ->where('exchange', $exchange)
            ->where('feed', 'main')
            ->where('ticker', $asset->ticker)
            ->whereNotNull('price')
            ->orderByDesc('id')
            ->value('price');

        return [
            'balance' => $balance === null ? null : round((float) $balance, 2),
            'price' => $price === null ? null : (float) $price,
        ];
    }

    /**
     * GET /api/assets — trader-facing catalog (auth, no admin role required).
     *
     * Only enabled assets, and deliberately WITHOUT the sizing columns
     * (base_size / max_increments): traders see what the bot trades, never how
     * large it trades it.
     */
    public function catalog(): JsonResponse
    {
        $assets = Asset::where('enabled', true)
            ->orderBy('ticker')
            ->get(['asset_id', 'ticker', 'type', 'broker', 'side', 'asset_image'])
            ->map(fn (Asset $a) => [
                'asset_id' => $a->asset_id,
                'ticker' => $a->ticker,
                'type' => $a->type,
                'broker' => $a->broker,
                'side' => $a->side,
                'asset_image' => $a->asset_image,
            ]);

        return response()->json([
            'success' => true,
            'assets' => $assets,
        ]);
    }

    /** POST /api/admin/assets — a new asset trades nothing yet, so its sizing applies at once. */
    public function store(Request $request): JsonResponse
    {
        $validated = $this->validated($request);
        $ladder = $this->validatedLadder($request);
        PendingSizing::validate($validated);

        if ($request->hasFile('asset_image')) {
            $validated['asset_image'] = $request->file('asset_image')->store('asset-images', 'public');
        }

        $asset = DB::transaction(function () use ($validated, $ladder) {
            $asset = Asset::create($validated);
            if ($ladder !== null) {
                $this->streakSizing->replace($asset, $ladder['enabled'], $ladder['steps']);
            }

            return $asset;
        });
        $asset = $asset->fresh();
        $this->history->record(
            $asset, AssetChange::CREATED, AssetHistory::diff(null, AssetHistory::snapshot($asset)),
            AssetHistory::actor($request),
        );
        $this->engineCache->refreshAssets();

        return response()->json([
            'success' => true,
            'message' => "Asset \"{$asset->ticker}\" created",
            'asset' => $this->present($asset),
        ], 201);
    }

    /**
     * PUT /api/admin/assets/{asset}
     *
     * Multipart file uploads reach here via POST + _method=PUT spoofing (PHP
     * only parses multipart bodies on POST). A new file replaces the old one;
     * remove_image=1 clears it; otherwise the existing image is preserved.
     *
     * base_size / max_increments are optional here: absent = unchanged, which
     * is what the card's on/off switch sends. `sizing` in the answer says what
     * happened to a size change: applied | waiting | cancelled | unchanged.
     */
    public function update(Request $request, Asset $asset): JsonResponse
    {
        $validated = $this->validated($request, $asset->asset_id);
        $ladder = $this->validatedLadder($request);
        $actor = AssetHistory::actor($request);

        $posted = array_intersect_key($validated, array_flip(['base_size', 'max_increments']));
        unset($validated['base_size'], $validated['max_increments']);
        if ($ladder !== null) {
            $posted['streak_sizing_enabled'] = $ladder['enabled'];
            $posted['streak_sizes'] = $ladder['steps'];
        }
        $sizing = $posted === [] ? null : PendingSizing::merge($asset, $posted);
        // Checked before anything is written, so a refused size leaves the
        // asset (and its image) exactly as it was.
        if ($sizing !== null && (isset($posted['base_size']) || isset($posted['max_increments']))) {
            PendingSizing::validate($sizing);
        }

        if ($request->hasFile('asset_image')) {
            $this->deleteStoredImage($asset);
            $validated['asset_image'] = $request->file('asset_image')->store('asset-images', 'public');
        } elseif ($request->boolean('remove_image')) {
            $this->deleteStoredImage($asset);
            $validated['asset_image'] = null;
        }

        $before = AssetHistory::snapshot($asset);
        $outcome = DB::transaction(function () use ($asset, $validated, $sizing, $actor) {
            $asset->update($validated);

            return $sizing === null
                ? PendingSizing::UNCHANGED
                : $this->pendingSizing->save($asset, $sizing, $actor);
        });
        $asset->refresh();
        $this->history->record($asset, AssetChange::UPDATED, AssetHistory::diff($before, AssetHistory::snapshot($asset)), $actor);

        // Turning a kill-switched coin back on clears the trip (Asset::booted);
        // the Overview's caution strip must not linger for its cache minute.
        if ($asset->wasChanged('killed_at') || ($before['enabled'] === false && $asset->enabled)) {
            KillSwitch::forgetOverview();
        }
        $this->engineCache->refreshAssets();

        return response()->json([
            'success' => true,
            'message' => $outcome === PendingSizing::WAITING
                ? "Asset \"{$asset->ticker}\" saved — the new size waits for the open position to close."
                : "Asset \"{$asset->ticker}\" updated",
            'sizing' => $outcome,
            'asset' => $this->present($asset),
        ]);
    }

    /**
     * PUT /api/admin/assets/{asset}/streak-sizing — the Streak Sizing Settings
     * tab. Body: {streak_sizing_enabled: bool, streak_sizes: [{kind, streak,
     * size}]}; the ladder is replaced whole (an empty list clears it). The
     * ladder is sizing, so it waits for an open position like base size; the
     * kill switch on the same card applies at once.
     */
    public function updateStreakSizing(Request $request, Asset $asset): JsonResponse
    {
        $data = $request->validate(StreakSizing::rules() + [
            // The kill switch lives on the same card: absent = unchanged, null = off.
            'kill_switch_losses' => ['sometimes', 'nullable', 'integer', 'between:1,'.StreakSizing::MAX_STEPS],
        ]);
        StreakSizing::validateDistinct($data['streak_sizes'] ?? []);
        $actor = AssetHistory::actor($request);
        $sizing = PendingSizing::merge($asset, [
            'streak_sizing_enabled' => (bool) $data['streak_sizing_enabled'],
            'streak_sizes' => $data['streak_sizes'] ?? [],
        ]);

        $before = AssetHistory::snapshot($asset);
        $outcome = DB::transaction(function () use ($asset, $data, $request, $sizing, $actor) {
            $outcome = $this->pendingSizing->save($asset, $sizing, $actor);
            if ($request->exists('kill_switch_losses')) {
                $asset->update(['kill_switch_losses' => $data['kill_switch_losses'] ?? null]);
            }

            return $outcome;
        });
        $asset->refresh();
        $this->history->record($asset, AssetChange::UPDATED, AssetHistory::diff($before, AssetHistory::snapshot($asset)), $actor);
        $this->engineCache->refreshAssets();

        return response()->json([
            'success' => true,
            'message' => $outcome === PendingSizing::WAITING
                ? "Streak sizing for \"{$asset->ticker}\" saved — it waits for the open position to close."
                : "Streak sizing for \"{$asset->ticker}\" saved",
            'sizing' => $outcome,
            'asset' => $this->present($asset),
        ]);
    }

    /**
     * POST /api/admin/assets/{asset}/pending-sizing/apply — "Apply now": put
     * the waiting size live without waiting for the close. For emergencies;
     * the open stack is then measured in the new size.
     */
    public function applyPending(Request $request, Asset $asset): JsonResponse
    {
        $applied = $this->pendingSizing->apply(
            $asset, AssetHistory::actor($request),
            'Applied by hand before the position closed.',
        );
        if (! $applied) {
            return response()->json([
                'success' => false,
                'error_code' => 'NOTHING_WAITING',
                'message' => "There is no waiting size change on \"{$asset->ticker}\".",
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => "The new size for \"{$asset->ticker}\" is live",
            'asset' => $this->present($asset->fresh()),
        ]);
    }

    /** DELETE /api/admin/assets/{asset}/pending-sizing — drop the waiting change; the live size stays. */
    public function cancelPending(Request $request, Asset $asset): JsonResponse
    {
        if (! $this->pendingSizing->cancel($asset, AssetHistory::actor($request))) {
            return response()->json([
                'success' => false,
                'error_code' => 'NOTHING_WAITING',
                'message' => "There is no waiting size change on \"{$asset->ticker}\".",
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => "The waiting size change on \"{$asset->ticker}\" was cancelled",
            'asset' => $this->present($asset->fresh()),
        ]);
    }

    /**
     * GET /api/admin/assets/history?asset_id=&broker=&limit=&before_id= — the
     * change history, newest first. `before_id` pages back.
     */
    public function history(Request $request): JsonResponse
    {
        $data = $request->validate([
            'asset_id' => ['nullable', 'integer'],
            'broker' => ['nullable', 'string', 'max:64'],
            'limit' => ['nullable', 'integer', 'between:1,200'],
            'before_id' => ['nullable', 'integer'],
        ]);
        $limit = (int) ($data['limit'] ?? 100);

        $query = AssetChange::query()->orderByDesc('id');
        if (! empty($data['asset_id'])) {
            $query->where('asset_id', $data['asset_id']);
        }
        if (! empty($data['broker'])) {
            $query->whereRaw('LOWER(broker) = ?', [strtolower($data['broker'])]);
        }
        if (! empty($data['before_id'])) {
            $query->where('id', '<', $data['before_id']);
        }
        $rows = $query->limit($limit + 1)->get();

        return response()->json([
            'success' => true,
            'changes' => $rows->take($limit)->map(fn (AssetChange $c) => [
                'id' => $c->id,
                'asset_id' => $c->asset_id,
                'ticker' => $c->ticker,
                'broker' => $c->broker,
                'event' => $c->event,
                'actor_name' => $c->actor_name,
                'is_system' => $c->actor_uni_id === null,
                'changes' => $c->changes ?? (object) [],
                'note' => $c->note,
                'created_at' => $c->created_at?->toIso8601String(),
            ])->values(),
            'has_more' => $rows->count() > $limit,
        ]);
    }

    /**
     * GET /api/admin/assets/{asset}/streaks — "right now": how many of the
     * accounts the engine trades on this asset's venue are on each run.
     * `run` is signed (-3 = three losses in a row, +2 = two wins, 0 = no
     * close yet) and capped at depth — the deepest configured step of either
     * kind, 10 with no ladder — so ±depth reads "this many or more". Only
     * non-empty buckets are listed. DB only (the same read the engine makes),
     * never an exchange call.
     */
    public function streaks(Asset $asset): JsonResponse
    {
        $exchange = StreakSizing::exchangeFor($asset);
        $depth = StreakSizing::deepestStep($asset) ?: StreakSizing::MAX_STEPS;
        if ($exchange === null) {
            return response()->json([
                'success' => true, 'exchange' => null, 'symbol' => $asset->ticker,
                'depth' => $depth, 'accounts' => 0, 'runs' => [],
            ]);
        }

        // The same population EngineController::accounts hands the engine.
        $schema = ExchangeSchema::for($exchange);
        $t = $schema->accountsTable;
        $apiKeys = $schema->accountQuery()
            ->join('user_credentials', 'user_credentials.uni_id', '=', "{$t}.uni_id")
            ->where("{$t}.enabled", 1)
            ->where("{$t}.is_sandbox", 0)
            ->where('user_credentials.status', '!=', 'suspended')
            ->pluck("{$t}.api_key")
            ->all();

        $runs = $this->streakSizing->runs($exchange, $asset->ticker, $depth, $apiKeys);
        $buckets = [];
        foreach ($apiKeys as $key) {
            $run = $runs[$key] ?? 0;
            $buckets[$run] = ($buckets[$run] ?? 0) + 1;
        }
        ksort($buckets);

        return response()->json([
            'success' => true,
            'exchange' => $exchange,
            'symbol' => $asset->ticker,
            'depth' => $depth,
            'accounts' => count($apiKeys),
            'runs' => collect($buckets)
                ->map(fn (int $n, int $run) => ['run' => $run, 'accounts' => $n])
                ->values(),
        ]);
    }

    /** Delete the asset's previously stored image file (skips external URLs). */
    private function deleteStoredImage(Asset $asset): void
    {
        $previous = $asset->getRawOriginal('asset_image');
        if ($previous && ! preg_match(Asset::EXTERNAL_IMAGE, $previous)) {
            Storage::disk('public')->delete($previous);
        }
    }

    /** DELETE /api/admin/assets/{asset} */
    public function destroy(Request $request, Asset $asset): JsonResponse
    {
        $ticker = $asset->ticker;
        $before = AssetHistory::snapshot($asset);
        $asset->delete();
        $this->history->record($asset, AssetChange::DELETED, AssetHistory::diff($before, null), AssetHistory::actor($request));
        $this->engineCache->refreshAssets();

        return response()->json([
            'success' => true,
            'message' => "Asset \"{$ticker}\" deleted",
        ]);
    }

    /**
     * The ladder posted with the asset form, or null when the form did not
     * send one. Absent means UNCHANGED, never "cleared": the asset card's
     * enable/disable toggle re-posts only the asset fields, and must not wipe
     * a ladder it never displayed. `streak_sizing_enabled` is the marker — a
     * form that sends it owns the ladder (no `streak_sizes` = no steps).
     * Fully validated here, before the asset row or its image is written.
     *
     * @return array{enabled: bool, steps: array}|null
     */
    private function validatedLadder(Request $request): ?array
    {
        if (! $request->has('streak_sizing_enabled')) {
            return null;
        }
        $data = $request->validate(StreakSizing::rules());
        StreakSizing::validateDistinct($data['streak_sizes'] ?? []);

        return [
            'enabled' => (bool) $data['streak_sizing_enabled'],
            'steps' => $data['streak_sizes'] ?? [],
        ];
    }

    /**
     * @param  int|null  $ignoreId  the asset being updated; null = a new asset,
     *                              whose sizing is required
     */
    private function validated(Request $request, ?int $ignoreId = null): array
    {
        $sizing = $ignoreId === null ? 'required' : 'sometimes';

        $validated = $request->validate([
            'ticker' => [
                'required', 'string', 'max:20',
                Rule::unique('assets', 'ticker')
                    ->where('broker', $request->input('broker'))
                    ->ignore($ignoreId, 'asset_id'),
            ],
            'type' => ['nullable', 'string', 'max:32'],
            'broker' => ['nullable', 'string', 'max:64'],
            'side' => ['required', Rule::in(['ALL', 'LONG', 'SHORT'])],
            // The column is the max position SIZE (not a count) — see PendingSizing::validate.
            'max_increments' => [$sizing, 'numeric', 'min:0.001'],
            'base_size' => [$sizing, 'numeric', 'gt:0'],
            'enabled' => ['required', 'boolean'],
            // Kill switch (KillSwitch): absent = unchanged, '' / null = off.
            'kill_switch_losses' => ['sometimes', 'nullable', 'integer', 'between:1,'.StreakSizing::MAX_STEPS],
            'asset_image' => ['nullable', 'image', 'mimes:jpeg,jpg,png,gif,webp,svg', 'max:2048'],
        ]);

        $validated['ticker'] = strtoupper(trim($validated['ticker']));
        foreach (['base_size', 'max_increments'] as $key) {
            if (isset($validated[$key])) {
                $validated[$key] = (float) $validated[$key];
            }
        }

        // The image file is handled separately (stored → path); never persist
        // the UploadedFile object through the scalar column.
        unset($validated['asset_image']);

        return $validated;
    }
}
