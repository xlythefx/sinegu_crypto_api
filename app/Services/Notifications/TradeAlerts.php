<?php

namespace App\Services\Notifications;

use App\Mail\TradeExitStuckNotice;
use App\Models\UserCredential;
use App\Services\Exchanges\ExchangeSchema;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Failed-trade emails (2026-11-12, the user's request).
 *
 * Two kinds, chosen so the inbox only hears about what needs a person:
 * - an HOURLY summary (TradeFailureDigest, `trade-alerts:digest`) of refused
 *   orders, entries shrunk or skipped at the exchange's position limit, and
 *   problem rejections — sent only when there is something in it;
 * - an IMMEDIATE email when a CLOSE was refused outright or the retry queue gave
 *   up on it (the engine's red "MANUAL ACTION" moment): a real position may
 *   still be open, and that must not wait for the hour.
 *
 * Recipients: `mail.trade_alerts` (TRADE_ALERT_EMAILS), its own list rather
 * than the team list. Empty = off. Sending is best-effort like AccountMail:
 * a mail failure is logged and never fails the caller.
 */
class TradeAlerts
{
    /** @return list<string> */
    public static function recipients(): array
    {
        $list = array_map('trim', explode(',', (string) config('mail.trade_alerts')));

        return array_values(array_unique(array_filter(
            $list,
            fn (string $a) => filter_var($a, FILTER_VALIDATE_EMAIL) !== false,
        )));
    }

    /**
     * "Owner name · account name" per uni_id on a venue, for rows the engine
     * could only identify by id (the retry queue carries ids, not names).
     *
     * @param  list<string>  $uniIds
     * @return array<string, array{owner: ?string, account: ?string}>
     */
    public static function names(string $exchange, array $uniIds): array
    {
        $uniIds = array_values(array_unique(array_filter(array_map('strval', $uniIds))));
        if ($uniIds === []) {
            return [];
        }
        $owners = UserCredential::query()->whereIn('uni_id', $uniIds)->pluck('name', 'uni_id');
        $accounts = [];
        if (ExchangeSchema::isSupported($exchange)) {
            $model = ExchangeSchema::for($exchange)->accountModel;
            $accounts = $model::query()->whereIn('uni_id', $uniIds)->orderByDesc('id')->pluck('name', 'uni_id')->all();
        }
        $out = [];
        foreach ($uniIds as $id) {
            $out[$id] = ['owner' => $owners[$id] ?? null, 'account' => $accounts[$id] ?? null];
        }

        return $out;
    }

    /**
     * The immediate email for a close nobody is coming to fix.
     *
     * @param  list<array{uni_id?: ?string, name?: ?string, error?: ?string}>  $accounts
     * @return bool whether a mail was handed to the mailer
     */
    public function exitStuck(string $exchange, string $action, string $ticker, string $why, ?int $attempts, array $accounts, int $total): bool
    {
        $to = self::recipients();
        if ($to === [] || $accounts === []) {
            return false;
        }
        $names = self::names($exchange, array_map(fn ($a) => (string) ($a['uni_id'] ?? ''), $accounts));
        $rows = [];
        foreach ($accounts as $a) {
            $n = $names[(string) ($a['uni_id'] ?? '')] ?? ['owner' => null, 'account' => null];
            $rows[] = [
                'owner' => $n['owner'],
                'account' => ($a['name'] ?? null) ?: $n['account'],
                'error' => $a['error'] ?? null,
            ];
        }
        $broker = ExchangeSchema::isSupported($exchange) ? ExchangeSchema::for($exchange)->brokerLabel : ucfirst($exchange);

        try {
            Mail::to($to)->send(new TradeExitStuckNotice(
                broker: $broker,
                ticker: strtoupper($ticker),
                side: str_ends_with(strtoupper($action), 'SHORT') ? 'SHORT' : 'LONG',
                why: $why,
                attempts: $attempts,
                accounts: $rows,
                total: max($total, count($rows)),
                at: now()->utc()->format('j M Y, H:i').' UTC',
            ));

            return true;
        } catch (Throwable $e) {
            Log::warning('TradeAlerts: stuck-exit email could not be sent.', ['ticker' => $ticker, 'exception' => $e->getMessage()]);

            return false;
        }
    }
}
