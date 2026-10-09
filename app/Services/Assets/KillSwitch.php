<?php

namespace App\Services\Assets;

use App\Http\Controllers\AdminInsightsController;
use App\Mail\AssetKillSwitchNotice;
use App\Models\Asset;
use App\Services\EngineCache;
use App\Services\Exchanges\ExchangeSchema;
use App\Services\Notifications\AccountMail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The per-asset kill switch (owner, 2026-10-09): when the MASTER account
 * loses `assets.kill_switch_losses` trades in a row on a coin, the coin is
 * switched off for everyone — the same `enabled = 0` an admin sets by hand,
 * so the engine stops opening trades on it while exits still close whatever
 * is open (exits skip every asset gate). Turning it back on is manual.
 *
 * Only the master's trades count: every account follows the master's
 * signals, so the master's results ARE the strategy's results, and "whose
 * three losses?" has one answer. Only real money counts (demo 0, not
 * sandbox), and only closes after `kill_switch_armed_at` — the moment the
 * switch was set or the coin was last turned back on.
 *
 * Checked after every past-positions sync that carries a master row, because
 * that is where a close — and its P&L, which can land a few minutes after the
 * close itself — reaches the database. Announced three ways, all best-effort
 * (the switched-off asset row is the fact): a caution strip on the Admin
 * Overview, an email to the team list, a message to the admin Telegram group
 * through the engine.
 */
final class KillSwitch
{
    public function __construct(private EngineCache $engine) {}

    /**
     * After a past-positions sync: check every coin the batch touched for the
     * master. Never throws — a failure here must not fail the sync, or the
     * engine would re-send the same rows into the same exception every tick.
     *
     * @param  array<int, array{api_key: string, symbol: string}>  $rows
     * @return list<string> tickers switched off by this call
     */
    public function checkAfterSync(string $exchange, array $rows): array
    {
        try {
            $masterKeys = $this->masterKeys($exchange);
            if ($masterKeys === []) {
                return [];
            }
            $symbols = collect($rows)
                ->filter(fn ($r) => in_array($r['api_key'] ?? null, $masterKeys, true))
                ->pluck('symbol')
                ->unique()
                ->values()
                ->all();
            if ($symbols === []) {
                return [];
            }

            $tripped = [];
            $assets = Asset::where('broker', ExchangeSchema::for($exchange)->brokerLabel)
                ->whereIn('ticker', $symbols)
                ->where('enabled', true)
                ->where('kill_switch_losses', '>', 0)
                ->get();
            foreach ($assets as $asset) {
                $losses = $this->masterLossRun($exchange, $asset, $masterKeys);
                if ($losses >= (int) $asset->kill_switch_losses && $this->trip($asset, $losses, $exchange)) {
                    $tripped[] = $asset->ticker;
                }
            }

            return $tripped;
        } catch (Throwable $e) {
            Log::error('KillSwitch: check failed after a past-positions sync.', [
                'exchange' => $exchange,
                'exception' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * The master's current losing run on the asset's coin, counting only
     * closes after the switch was armed and at most `kill_switch_losses` deep.
     * A close whose P&L is not written yet is skipped, never guessed.
     *
     * @param  list<string>  $masterKeys
     */
    public function masterLossRun(string $exchange, Asset $asset, array $masterKeys): int
    {
        $limit = max(1, (int) $asset->kill_switch_losses);
        $query = DB::table(ExchangeSchema::for($exchange)->pastPositions)
            ->whereIn('api_key', $masterKeys)
            ->where('symbol', $asset->ticker)
            ->where('is_sandbox', 0)
            ->whereNotNull('realized_pnl')
            ->orderByDesc('closed_at')
            ->orderByDesc('id')
            ->limit($limit);
        if ($asset->kill_switch_armed_at !== null) {
            $query->where('closed_at', '>', $asset->kill_switch_armed_at->toDateTimeString());
        }

        $losses = 0;
        foreach ($query->pluck('realized_pnl') as $pnl) {
            if ((float) $pnl > 0) {
                break;
            }
            $losses++;
        }

        return $losses;
    }

    /**
     * Switch the asset off and announce it. The update is conditional on the
     * asset still being enabled, so two syncs racing on the same close switch
     * it off — and email the team — exactly once. Returns false when it was
     * already off.
     */
    public function trip(Asset $asset, int $losses, string $exchange): bool
    {
        $now = now();
        $reason = "The master lost {$losses} trades in a row on {$asset->ticker}.";

        $updated = Asset::where('asset_id', $asset->asset_id)
            ->where('enabled', true)
            ->update([
                'enabled' => false,
                'killed_at' => $now,
                'killed_reason' => $reason,
                'updated_at' => $now,
            ]);
        if ($updated === 0) {
            return false;
        }

        Log::warning('KillSwitch: asset switched off.', [
            'asset_id' => $asset->asset_id, 'ticker' => $asset->ticker, 'exchange' => $exchange, 'losses' => $losses,
        ]);

        self::forgetOverview();
        $this->engine->refreshAssets();
        $this->engine->killSwitchTripped($exchange, $asset->ticker, $losses, $reason);
        $this->emailTeam($asset, $losses, $reason, $now->format('j M Y, H:i').' UTC');

        return true;
    }

    /** The Overview is cached for a minute; a tripped (or cleared) kill switch must not wait for it. */
    public static function forgetOverview(): void
    {
        Cache::forget(AdminInsightsController::CACHE_PREFIX.'overview');
    }

    /** The master's real-money keys on the venue (live accounts only). @return list<string> */
    private function masterKeys(string $exchange): array
    {
        $schema = ExchangeSchema::for($exchange);
        $t = $schema->accountsTable;

        return $schema->accountQuery()
            ->join('user_credentials', 'user_credentials.uni_id', '=', "{$t}.uni_id")
            ->where('user_credentials.type', 'master')
            ->where("{$t}.demo", 0)
            ->where("{$t}.is_sandbox", 0)
            ->pluck("{$t}.api_key")
            ->map(fn ($k) => (string) $k)
            ->all();
    }

    /** Best-effort, like every team notice: the switched-off asset is the fact. */
    private function emailTeam(Asset $asset, int $losses, string $reason, string $when): void
    {
        $to = AccountMail::teamRecipients();
        if ($to === []) {
            Log::warning('KillSwitch: no mail.admin_address configured — kill switch notice skipped.', [
                'asset_id' => $asset->asset_id,
            ]);

            return;
        }

        try {
            Mail::to($to)->send(new AssetKillSwitchNotice(
                ticker: (string) $asset->ticker,
                broker: (string) ($asset->broker ?? ''),
                losses: $losses,
                reason: $reason,
                trippedAt: $when,
            ));
        } catch (Throwable $e) {
            Log::warning('KillSwitch: team notice could not be sent.', [
                'asset_id' => $asset->asset_id,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
