<?php

namespace App\Services\DevBot;

use App\Models\Asset;
use App\Models\DevBotAction;
use App\Services\Assets\AssetCreator;
use App\Services\Exchanges\ExchangeSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * A button tap in the developer bot, applied (2026-11-22).
 *
 * Only the configured chat's owner reaches here (DevBotWebhookController
 * checks the Telegram user id). Each suggestion is answered ONCE: the row is
 * locked and must still be open, so a double tap — or Telegram re-delivering
 * the same update — cannot add a coin twice. The coin is added through
 * AssetCreator, the same path as Trading Assets → Add, so its change history
 * names who approved it from Telegram.
 */
class DevBotActions
{
    public function __construct(private AssetCreator $creator) {}

    /**
     * @param  string  $choice  an option index, or "skip"
     * @return array{text: string, toast: string, action: ?DevBotAction}
     */
    public function resolve(int $actionId, string $choice, string $who): array
    {
        return DB::transaction(function () use ($actionId, $choice, $who) {
            $action = DevBotAction::whereKey($actionId)->lockForUpdate()->first();
            if ($action === null) {
                return ['text' => '', 'toast' => 'That suggestion no longer exists.', 'action' => null];
            }
            if ($action->status !== DevBotAction::OPEN) {
                return ['text' => '', 'toast' => 'Already handled: '.($action->result_text ?? $action->status), 'action' => null];
            }

            if ($choice === 'skip') {
                return $this->close($action, DevBotAction::SKIPPED, null, "⏭ Skipped by {$who}. The coin was not added.", 'Skipped', $who);
            }

            $options = $action->payload['options'] ?? [];
            $option = ctype_digit($choice) ? ($options[(int) $choice] ?? null) : null;
            if (! is_array($option)) {
                return ['text' => '', 'toast' => 'That button is no longer valid.', 'action' => null];
            }

            $broker = ExchangeSchema::for($action->exchange)->brokerLabel;
            if (Asset::where('broker', $broker)->where('ticker', $action->ticker)->exists()) {
                return $this->close($action, DevBotAction::ALREADY, $option,
                    "ℹ️ {$action->ticker} was already added on Trading Assets — nothing changed.", 'Already added', $who);
            }

            $qty = (float) $option['qty'];
            $max = round($qty * CoinSuggestion::ENTRIES, 8);
            $leverage = (int) ($action->payload['leverage'] ?? CoinSuggestion::DEFAULT_LEVERAGE);
            try {
                $asset = $this->creator->create([
                    'ticker' => $action->ticker,
                    'type' => 'Cryptocurrency',
                    'broker' => $broker,
                    'side' => $action->side ?? 'ALL',
                    'base_size' => $qty,
                    'max_increments' => $max,
                    'leverage' => $leverage,
                    'enabled' => true,
                ], null, ['uni_id' => null, 'name' => "{$who} (Telegram)"],
                    "Added from the developer bot: \${$option['cost']} margin per entry at {$leverage}×.");
            } catch (ValidationException $e) {
                return $this->close($action, DevBotAction::FAILED, $option,
                    '❌ Not added: '.collect($e->errors())->flatten()->first(), 'Not added', $who);
            } catch (Throwable $e) {
                return $this->close($action, DevBotAction::FAILED, $option,
                    '❌ Not added: the server could not save it ('.mb_substr($e->getMessage(), 0, 120).').', 'Not added', $who);
            }

            $coin = preg_replace('/USDT$|USDC$/', '', $action->ticker) ?: $action->ticker;

            return $this->close($action, DevBotAction::APPLIED, $option + ['asset_id' => $asset->asset_id],
                "✅ Added by {$who}: {$action->ticker} {$asset->side}, ".DevBotReporter::num($qty)." {$coin} per entry, "
                .DevBotReporter::num($max)." max, {$leverage}× — live for the next signal.", 'Added', $who);
        });
    }

    /** @return array{text: string, toast: string, action: DevBotAction} */
    private function close(DevBotAction $action, string $status, ?array $chosen, string $text, string $toast, string $who): array
    {
        $action->forceFill([
            'status' => $status,
            'chosen' => $chosen,
            'result_text' => $text,
            'resolved_by' => mb_substr($who, 0, 100),
            'resolved_at' => now(),
        ])->save();

        return ['text' => $text, 'toast' => $toast, 'action' => $action];
    }
}
