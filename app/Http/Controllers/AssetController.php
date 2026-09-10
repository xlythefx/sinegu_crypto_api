<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Services\EngineCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
 */
class AssetController extends Controller
{
    public function __construct(private EngineCache $engineCache) {}

    /** GET /api/admin/assets */
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'assets' => Asset::orderBy('ticker')->get(),
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

        if ($request->hasFile('asset_image')) {
            $validated['asset_image'] = $request->file('asset_image')->store('asset-images', 'public');
        }

        $asset = Asset::create($validated);
        $this->engineCache->refreshAssets();

        return response()->json([
            'success' => true,
            'message' => "Asset \"{$asset->ticker}\" created",
            'asset' => $asset,
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

        if ($request->hasFile('asset_image')) {
            $this->deleteStoredImage($asset);
            $validated['asset_image'] = $request->file('asset_image')->store('asset-images', 'public');
        } elseif ($request->boolean('remove_image')) {
            $this->deleteStoredImage($asset);
            $validated['asset_image'] = null;
        }

        $asset->update($validated);
        $this->engineCache->refreshAssets();

        return response()->json([
            'success' => true,
            'message' => "Asset \"{$asset->ticker}\" updated",
            'asset' => $asset->fresh(),
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
