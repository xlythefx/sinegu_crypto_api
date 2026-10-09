<?php

namespace App\Http\Controllers;

use App\Models\Asset;
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
 * A saved base_size applies to the next signal; the engine is never restarted
 * for a config change.
 *
 * The streak ladder (App\Services\Assets\StreakSizing) belongs to the same
 * asset: the form posts it alongside the asset fields, the Streak Sizing
 * Settings tab writes it alone, and both go through StreakSizing::replace. It is NEVER in
 * the trader catalog — like base_size, it is how big the bot trades.
 */
class AssetController extends Controller
{
    public function __construct(
        private EngineCache $engineCache,
        private StreakSizing $streakSizing,
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

    /** An asset as the admin screens read it: every column plus its ladder. */
    private function present(Asset $asset): array
    {
        $asset->loadMissing('streakSizes');

        return array_merge($asset->withoutRelations()->toArray(), [
            'streak_sizing_enabled' => (bool) $asset->streak_sizing_enabled,
            'streak_sizes' => StreakSizing::ladder($asset),
        ]);
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

    /** POST /api/admin/assets */
    public function store(Request $request): JsonResponse
    {
        $validated = $this->validated($request);
        $ladder = $this->validatedLadder($request);

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
        $this->engineCache->refreshAssets();

        return response()->json([
            'success' => true,
            'message' => "Asset \"{$asset->ticker}\" created",
            'asset' => $this->present($asset->fresh()),
        ], 201);
    }

    /**
     * PUT /api/admin/assets/{asset}
     *
     * Multipart file uploads reach here via POST + _method=PUT spoofing (PHP
     * only parses multipart bodies on POST). A new file replaces the old one;
     * remove_image=1 clears it; otherwise the existing image is preserved.
     */
    public function update(Request $request, Asset $asset): JsonResponse
    {
        $validated = $this->validated($request, $asset->asset_id);
        $ladder = $this->validatedLadder($request);

        if ($request->hasFile('asset_image')) {
            $this->deleteStoredImage($asset);
            $validated['asset_image'] = $request->file('asset_image')->store('asset-images', 'public');
        } elseif ($request->boolean('remove_image')) {
            $this->deleteStoredImage($asset);
            $validated['asset_image'] = null;
        }

        DB::transaction(function () use ($asset, $validated, $ladder) {
            $asset->update($validated);
            if ($ladder !== null) {
                $this->streakSizing->replace($asset, $ladder['enabled'], $ladder['steps']);
            }
        });
        $this->engineCache->refreshAssets();

        return response()->json([
            'success' => true,
            'message' => "Asset \"{$asset->ticker}\" updated",
            'asset' => $this->present($asset->fresh()),
        ]);
    }

    /**
     * PUT /api/admin/assets/{asset}/streak-sizing — the Streak Sizing Settings
     * tab. Body: {streak_sizing_enabled: bool, streak_sizes: [{kind, streak,
     * size}]}; the ladder is replaced whole (an empty list clears it).
     */
    public function updateStreakSizing(Request $request, Asset $asset): JsonResponse
    {
        $data = $request->validate(StreakSizing::rules());
        $this->streakSizing->replace($asset, (bool) $data['streak_sizing_enabled'], $data['streak_sizes'] ?? []);
        $this->engineCache->refreshAssets();

        return response()->json([
            'success' => true,
            'message' => "Streak sizing for \"{$asset->ticker}\" saved",
            'asset' => $this->present($asset->fresh()),
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
        if ($previous && ! preg_match('#^(https?://|data:)#i', $previous)) {
            Storage::disk('public')->delete($previous);
        }
    }

    /** DELETE /api/admin/assets/{asset} */
    public function destroy(Asset $asset): JsonResponse
    {
        $ticker = $asset->ticker;
        $asset->delete();
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

    private function validated(Request $request, ?int $ignoreId = null): array
    {
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
            'max_increments' => ['required', 'numeric', 'min:0.001'],
            'base_size' => ['required', 'numeric', 'gt:0'],
            'enabled' => ['required', 'boolean'],
            'asset_image' => ['nullable', 'image', 'mimes:jpeg,jpg,png,gif,webp,svg', 'max:2048'],
        ]);

        $validated['ticker'] = strtoupper(trim($validated['ticker']));

        // The image file is handled separately (stored → path); never persist
        // the UploadedFile object through the scalar column.
        unset($validated['asset_image']);

        return $validated;
    }
}
