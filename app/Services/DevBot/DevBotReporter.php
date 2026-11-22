<?php

namespace App\Services\DevBot;

use App\Models\Asset;
use App\Models\DevBotAction;
use App\Models\DevBotSetting;
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
        if (! $this->telegram->configured() || ! DevBotSetting::allows('risk')) {
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

    /**
     * A server error (any exception Laravel reports — a crash in a request,
     * a command, a scheduled job). Once an hour per place it was thrown.
     */
    public function exception(Throwable $e): void
    {
        if (! $this->telegram->configured() || ! DevBotSetting::allows('errors')) {
            return;
        }
        try {
            $file = str_replace([base_path().DIRECTORY_SEPARATOR, '\\'], ['', '/'], $e->getFile());
            $sig = substr(md5(get_class($e).'|'.$file.'|'.$e->getLine()), 0, 16);
            if (! Cache::add("devbot:exc:told:{$sig}", 1, now()->addMinutes(self::DEDUPE_MINUTES))) {
                return;
            }
            $where = app()->runningInConsole()
                ? 'command: '.implode(' ', array_slice((array) ($_SERVER['argv'] ?? []), 1, 3))
                : (request() ? request()->method().' /'.ltrim(request()->path(), '/') : 'unknown');
            $message = $this->scrub(mb_substr($e->getMessage(), 0, 400));
            Cache::put("devbot:exc:{$sig}", [
                'class' => get_class($e), 'message' => $message, 'at' => "{$file}:{$e->getLine()}", 'where' => $where,
            ], now()->addDays(7));
            $this->telegram->send(implode("\n", [
                '💥 <b>Server error</b> · '.e(class_basename($e)),
                '<code>'.e($message !== '' ? $message : '(no message)').'</code>',
                'at '.e("{$file}:{$e->getLine()}"),
                e($where),
                "\nThe same error is muted for an hour.",
            ]), [[['text' => '🤖 Investigate on my PC', 'callback_data' => "dx:{$sig}"]]]);
        } catch (Throwable) {
            // Reporting an error must never cause one.
        }
    }

    /** A scheduled job that ended with a failure code. Once an hour per job. */
    public function scheduledFailure(string $command, int $exitCode): void
    {
        if (! $this->telegram->configured() || ! DevBotSetting::allows('scheduled')) {
            return;
        }
        try {
            $name = preg_match("/artisan'?\s+(.+)$/", $command, $m) ? trim($m[1], " '") : $command;
            if (! Cache::add('devbot:sched:told:'.md5($name), 1, now()->addMinutes(self::DEDUPE_MINUTES))) {
                return;
            }
            $this->telegram->send(implode("\n", [
                '⏰ <b>Scheduled job failed</b>',
                '<code>'.e(mb_substr($name, 0, 200)).'</code> ended with code '.$exitCode,
                "\nIf it threw an error, that arrives as its own message. Muted for an hour.",
            ]));
        } catch (Throwable) {
        }
    }

    private function scrub(string $text): string
    {
        foreach (['services.dev_bot.token', 'services.dev_bot.agent_key', 'services.engine.secret', 'services.anthropic.key'] as $k) {
            $v = (string) config($k, '');
            if (strlen($v) >= 8) {
                $text = str_replace($v, '***', $text);
            }
        }

        return $text;
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
            $groups[$c['key']] ??= ['label' => $c['label'], 'accounts' => [], 'text' => null, 'leverage_refused' => null];
            if (! empty($detail['leverage_refused'])) {
                $groups[$c['key']]['leverage_refused'] ??= (int) ($detail['leverage'] ?? $log->leverage ?? 0);
            }
            $name = $detail['account'] ?? $detail['name'] ?? null;
            if ($name !== null && $name !== '') {
                $groups[$c['key']]['accounts'][] = (string) $name;
            }
            $text = trim((string) ($detail['error'] ?? $detail['reason'] ?? ''));
            $groups[$c['key']]['text'] ??= $text !== '' ? $text : null;
        }

        foreach ($groups as $key => $group) {
            if ($key === 'asset_missing' && DevBotSetting::allows('coins') && $this->suggestCoin($log)) {
                continue;
            }
            if (! DevBotSetting::allows('signals')) {
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
                $group['leverage_refused'] !== null ? $this->leverageHint($log, (int) $group['leverage_refused']) : null,
                "\nSignal Log row #{$log->id} · repeats are muted for an hour",
                $this->link('/admin/trade-logs?view=issues', 'Open the Signal Log'),
            ])), [[['text' => '🤖 Investigate on my PC', 'callback_data' => "dj:{$log->id}:{$key}"]]]);
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

        $s = $this->coins->build($ticker, $log->leverage !== null ? (int) $log->leverage : null);
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

    /**
     * Binance refused the leverage an entry asked for: say the coin's real
     * maximum and where the asked-for number came from — the coin's own
     * leverage on Trading Assets (which wins), or the alert's JSON.
     */
    private function leverageHint(TradeLog $log, int $asked): string
    {
        $ticker = strtoupper((string) $log->ticker);
        $coin = preg_replace('/USDT$|USDC$/', '', $ticker) ?: $ticker;
        $max = $log->exchange === 'binance' ? $this->coins->maxLeverage($ticker) : null;
        $limit = $max !== null ? "Binance allows at most {$max}× on {$coin}." : "Binance's limit for {$coin} could not be read.";
        $target = $max ?? 'a lower number';
        $broker = ExchangeSchema::isSupported((string) $log->exchange) ? ExchangeSchema::for((string) $log->exchange)->brokerLabel : null;
        $own = $broker ? Asset::where('broker', $broker)->where('ticker', $ticker)->value('leverage') : null;

        $fix = $own !== null
            ? "The {$asked}× comes from the coin's own leverage in Trading Assets — set it to {$target} there."
            : 'The '.$asked.'× comes from the alert — change <code>"leverage": '.$asked.'</code> to <code>"leverage": '.$target.'</code> in its JSON, or set the coin’s leverage in Trading Assets (that wins over the alert).';

        return "⚠️ <b>{$asked}× leverage refused.</b> ".e($limit).' '.$fix;
    }

    /** The suggestion's text — kept on the row so the answer can be appended to it. */
    public static function coinMessage(TradeLog $log, array $s, string $side, string $signalLine): string
    {
        $leverage = (int) $s['leverage'];
        $max = $s['max_leverage'] ?? null;
        $alert = (int) ($s['alert_leverage'] ?? $leverage);
        $leverageLine = $s['leverage_known'] ?? false
            ? "Leverage {$leverage}× (Binance allows up to {$max}× on this coin)"
            : "Leverage {$leverage}× — ⚠️ Binance's limit for this coin could not be read, check it before choosing";
        $coin = preg_replace('/USDT$|USDC$/', '', (string) $s['ticker']) ?: (string) $s['ticker'];
        $alertLine = $max !== null && $alert > $max
            ? "\n⚠️ <b>Your alert sends {$alert}×, but Binance allows at most {$max}× on {$coin}.</b> "
                ."The coin is saved at {$max}× (the coin's own leverage wins over the alert), so it will still trade. "
                .'To make the alert match, change <code>"leverage": '.$alert.'</code> to <code>"leverage": '.$max.'</code> in its JSON.'
            : null;
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
            $alertLine,
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
