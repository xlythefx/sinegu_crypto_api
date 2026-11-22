<?php

namespace App\Services\DevBot;

use App\Models\Asset;
use App\Models\DevBotAction;
use App\Models\RiskAlert;
use App\Models\TradeLog;
use App\Services\Admin\SignalHealth;
use App\Services\Alerts\RiskAlerts;
use App\Services\Exchanges\ExchangeSchema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What the developer error bot says (2026-11-22, Christian's DM).
 *
 *  - Every signal outcome SignalHealth calls a PROBLEM, one message per
 *    (venue, feed, coin, reason) per hour — a blocked key repeating on every
 *    signal is told once, not forty times. Normal outcomes (stack full,
 *    nothing to close, coin switched off) are never sent.
 *  - A coin the signal named but Trading Assets does not have (Binance, an
 *    entry): size buttons instead of a plain message (CoinSuggestion), one
 *    open suggestion per coin; later refusals only count against it.
 *  - Every NEW high-risk alert (RiskAlerts::raise), whatever raised it.
 *    `assets_unavailable` is therefore not repeated from the signal side.
 *
 * Never throws: it runs after the engine has its answer, and a Telegram
 * outage must never surface anywhere else.
 */
class DevBotReporter
{
    public const DEDUPE_MINUTES = 60;

    /** Told by the risk-alert path instead. */
    private const SKIP_KEYS = ['assets_unavailable'];

    public function __construct(private DevBotTelegram $telegram, private CoinSuggestion $coins) {}

    public function tradeLog(TradeLog $log): void
    {
        if (! $this->telegram->configured()) {
            return;
        }
        try {
            $this->reportTradeLog($log);
        } catch (Throwable $e) {
            Log::warning('DevBot: could not report a signal.', ['trade_log_id' => $log->id, 'error' => $e->getMessage()]);
        }
    }

    public function riskAlert(RiskAlert $alert): void
    {
        if (! $this->telegram->configured()) {
            return;
        }
        try {
            $label = RiskAlerts::LABELS[$alert->kind] ?? $alert->kind;
            $lines = array_map(fn ($l) => e((string) $l), array_values($alert->lines ?? []));
            $this->telegram->send(implode("\n", array_filter([
                '🚨 <b>'.e($label).'</b>',
                e((string) $alert->title),
                $lines === [] ? null : "\n".implode("\n", $lines),
                $alert->trade_log_id ? "\nSignal Log row #{$alert->trade_log_id}" : null,
                $this->link('/admin', 'Open Needs attention'),
            ])));
        } catch (Throwable $e) {
            Log::warning('DevBot: could not report a risk alert.', ['id' => $alert->id, 'error' => $e->getMessage()]);
        }
    }

    private function reportTradeLog(TradeLog $log): void
    {
        $groups = [];
        foreach ((array) $log->details as $detail) {
            if (! is_array($detail)) {
                continue;
            }
            $c = SignalHealth::classify($detail, $log->category);
            if ($c === null || $c['severity'] !== SignalHealth::PROBLEM || in_array($c['key'], self::SKIP_KEYS, true)) {
                continue;
            }
            $groups[$c['key']] ??= ['label' => $c['label'], 'accounts' => [], 'text' => null];
            $name = $detail['account'] ?? $detail['name'] ?? null;
            if ($name !== null && $name !== '') {
                $groups[$c['key']]['accounts'][] = (string) $name;
            }
            $text = trim((string) ($detail['error'] ?? $detail['reason'] ?? ''));
            $groups[$c['key']]['text'] ??= $text !== '' ? $text : null;
        }

        foreach ($groups as $key => $group) {
            if ($key === 'asset_missing' && $this->suggestCoin($log)) {
                continue;
            }
            $dedupe = "devbot:told:{$log->exchange}:{$log->feed}:{$log->ticker}:{$key}";
            if (! Cache::add($dedupe, 1, now()->addMinutes(self::DEDUPE_MINUTES))) {
                continue;
            }
            $accounts = array_values(array_unique($group['accounts']));
            $this->telegram->send(implode("\n", array_filter([
                '🔴 <b>'.e($group['label']).'</b>',
                $this->signalLine($log),
                $accounts === [] ? null : 'Accounts: '.e(implode(', ', array_slice($accounts, 0, 8))).(count($accounts) > 8 ? ' +'.(count($accounts) - 8) : ''),
                $group['text'] ? '<code>'.e(mb_substr($group['text'], 0, 300)).'</code>' : null,
                "\nSignal Log row #{$log->id} · repeats are muted for an hour",
                $this->link('/admin/trade-logs?view=issues', 'Open the Signal Log'),
            ])));
        }
    }

    /**
     * Size buttons for a coin that is not set up. False = not a case this
     * handles (another venue, an exit) — the caller sends the plain message.
     */
    private function suggestCoin(TradeLog $log): bool
    {
        $side = match (strtoupper((string) $log->action)) {
            'BUY' => 'LONG',
            'SELL' => 'SHORT',
            default => null,
        };
        if ($log->exchange !== 'binance' || $side === null) {
            return false;
        }
        $broker = ExchangeSchema::for('binance')->brokerLabel;
        $ticker = strtoupper((string) $log->ticker);
        if (Asset::where('broker', $broker)->where('ticker', $ticker)->exists()) {
            return true; // added since the signal — nothing to suggest
        }

        $open = DevBotAction::where('kind', DevBotAction::ADD_ASSET)->where('exchange', 'binance')
            ->where('ticker', $ticker)->where('status', DevBotAction::OPEN)->latest('id')->first();
        if ($open) {
            $open->increment('occurrences');

            return true;
        }

        $s = $this->coins->build($ticker);
        if (! $s['ok']) {
            if (Cache::add("devbot:told:binance:{$ticker}:suggest_failed", 1, now()->addMinutes(self::DEDUPE_MINUTES))) {
                $this->telegram->send(implode("\n", [
                    '🟠 <b>Coin not added yet</b>',
                    $this->signalLine($log),
                    'No size buttons: '.e($s['error'] ?? 'unknown reason'),
                    "\nSignal Log row #{$log->id}",
                    $this->link('/admin/assets', 'Add it in Trading Assets'),
                ]));
            }

            return true;
        }

        $action = DevBotAction::create([
            'kind' => DevBotAction::ADD_ASSET,
            'exchange' => 'binance',
            'ticker' => $ticker,
            'side' => $side,
            'feed' => $log->feed,
            'trade_log_id' => $log->id,
            'payload' => $s,
        ]);
        $html = self::coinMessage($log, $s, $side, $this->signalLine($log));
        $action->payload = $s + ['message_html' => $html];
        $action->save();

        $keyboard = [];
        foreach ($s['options'] as $i => $o) {
            $keyboard[] = [['text' => self::optionLabel($o, $ticker), 'callback_data' => "da:{$action->id}:{$i}"]];
        }
        $keyboard[] = [['text' => 'Skip', 'callback_data' => "da:{$action->id}:skip"]];

        $messageId = $this->telegram->send($html, $keyboard);
        $action->forceFill(['message_id' => $messageId])->save();

        return true;
    }

    /** The suggestion's text — kept on the row so the answer can be appended to it. */
    public static function coinMessage(TradeLog $log, array $s, string $side, string $signalLine): string
    {
        $leverage = (int) $s['leverage'];
        $max = $s['max_leverage'] ?? null;
        $leverageLine = $s['leverage_known'] ?? false
            ? "Leverage {$leverage}× (Binance allows up to {$max}× on this coin)"
            : "Leverage {$leverage}× — ⚠️ Binance's limit for this coin could not be read, check it before choosing";
        $accounts = $s['accounts'] ?? [];
        $who = ($accounts['count'] ?? 0) > 0
            ? 'Shared by every Binance account: '.$accounts['count'].($accounts['count'] === 1 ? ' real-money account trades' : ' real-money accounts trade').' this coin setting'
                .(($accounts['biggest_multiple'] ?? 1) > 1
                    ? ', and the biggest ('.($accounts['biggest_is_master'] ? 'the master' : 'by balance').') trades '.self::num((float) $accounts['biggest_multiple']).'× these sizes.'
                    : '.')
            : null;

        return implode("\n", array_filter([
            '🟠 <b>'.e((string) $s['ticker']).' is not set up — signal refused</b>',
            $signalLine,
            '',
            'Price '.self::num((float) $s['price']).' · '.$leverageLine,
            "Side {$side} (from the signal) · max size = ".CoinSuggestion::ENTRIES.' entries',
            'Each button = margin per entry for a $1,000 account. Tap one to add the coin now.',
            $who,
        ], fn ($l) => $l !== null));
    }

    /** "$10 → 160 ASR (~$100 position)" */
    public static function optionLabel(array $o, string $ticker): string
    {
        $coin = preg_replace('/USDT$|USDC$/', '', $ticker) ?: $ticker;

        return '$'.$o['cost'].' → '.self::num((float) $o['qty']).' '.$coin.' (~$'.self::num(round((float) $o['notional'])).' position)';
    }

    private function signalLine(TradeLog $log): string
    {
        $venue = ExchangeSchema::isSupported((string) $log->exchange)
            ? ExchangeSchema::for((string) $log->exchange)->brokerLabel : ucfirst((string) $log->exchange);
        $feed = match ((string) $log->feed) {
            'submaster' => 'sub-master feed',
            'test' => 'test feed',
            default => 'main feed',
        };
        $at = $log->ts ? \Illuminate\Support\Carbon::parse($log->ts)->utc()->format('j M H:i').' UTC' : '';

        return e(strtoupper((string) $log->action).' '.$log->ticker)." · {$venue} · {$feed}".($at !== '' ? " · {$at}" : '');
    }

    private function link(string $path, string $label): string
    {
        $base = rtrim((string) config('services.dev_bot.site_url', 'https://pixel-alpha.com'), '/');

        return '<a href="'.e($base.$path).'">'.e($label).' →</a>';
    }

    public static function num(float $n): string
    {
        $s = number_format($n, $n >= 100 ? 0 : ($n >= 1 ? 4 : 6), '.', ',');

        return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
    }
}
