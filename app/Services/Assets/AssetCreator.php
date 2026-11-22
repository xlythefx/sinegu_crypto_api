<?php

namespace App\Services\Assets;

use App\Models\Asset;
use App\Models\AssetChange;
use App\Services\EngineCache;
use Illuminate\Support\Facades\DB;

/**
 * Adding a trading asset — the ONE path, whoever adds it: the admin form
 * (AssetController::store) and the developer error bot's "add this coin"
 * button (App\Services\DevBot\DevBotActions). Same checks, same change-history
 * line, same engine refresh, so a coin added from Telegram is indistinguishable
 * from one added on Trading Assets except for who the history names.
 *
 * A new asset trades nothing yet, so its sizing applies at once.
 */
class AssetCreator
{
    public function __construct(
        private StreakSizing $streakSizing,
        private AssetHistory $history,
        private EngineCache $engineCache,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  already validated asset columns
     * @param  array{enabled: bool, steps: array}|null  $ladder  null = no streak ladder given
     * @param  array{uni_id: ?string, name: string}|null  $actor  null = the system
     */
    public function create(array $attributes, ?array $ladder, ?array $actor, ?string $note = null): Asset
    {
        PendingSizing::validate($attributes);

        $asset = DB::transaction(function () use ($attributes, $ladder) {
            $asset = Asset::create($attributes);
            if ($ladder !== null) {
                $this->streakSizing->replace($asset, $ladder['enabled'], $ladder['steps']);
            }

            return $asset;
        });
        $asset = $asset->fresh();
        $this->history->record(
            $asset, AssetChange::CREATED, AssetHistory::diff(null, AssetHistory::snapshot($asset)), $actor, $note,
        );
        $this->engineCache->refreshAssets();

        return $asset;
    }
}
