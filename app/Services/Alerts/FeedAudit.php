<?php

namespace App\Services\Alerts;

use App\Models\RiskAlert;
use App\Models\TradeLog;
use App\Models\UserCredential;
use App\Services\Engine\WebhookPaths;
use App\Services\Exchanges\ExchangeSchema;
use Throwable;

/**
 * Checks every signal row the engine writes (2026-11-18, the user's request
 * with the sub-master role): **did a trade reach an account outside its
 * feed?** The engine is what keeps the feeds apart, so this check deliberately
 * does NOT trust it — it reads each filled account's owner role here, now,
 * and compares it with the row's feed through the ONE rule
 * (WebhookPaths::feedForRole).
 *
 * Only ENTRIES are judged (BUY / SELL, live signal or retry). A close may
 * reach any feed's account on purpose — the admin's manual close names its
 * accounts, and an open position must always be closable.
 *
 * A breach is written onto the row (`trade_logs.breach`, the proof the Signal
 * Log shows) and raised as a high-risk alert: emailed, on the Overview, and
 * posted to the admin Telegram group. Never throws into the ingest.
 */
class FeedAudit
{
    public function __construct(private RiskAlerts $alerts)
    {
    }

    /** @return list<array{uni_id: string, owner: ?string, role: ?string, account: ?string, expected_feed: string}> the breached accounts */
    public function check(TradeLog $log): array
    {
        try {
            return $this->run($log);
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /** @return list<array{uni_id: string, owner: ?string, role: ?string, account: ?string, expected_feed: string}> */
    private function run(TradeLog $log): array
    {
        if (! in_array(strtoupper((string) $log->action), ['BUY', 'SELL'], true)) {
            return [];
        }
        $feed = WebhookPaths::isFeed($log->feed) ? $log->feed : 'main';
        $filled = [];
        foreach ((array) $log->details as $d) {
            if (is_array($d) && ($d['status'] ?? null) === 'filled' && ! empty($d['uni_id'])) {
                $filled[(string) $d['uni_id']] = (string) ($d['account'] ?? '');
            }
        }
        if ($filled === []) {
            return [];
        }

        $owners = UserCredential::query()->whereIn('uni_id', array_keys($filled))->get(['uni_id', 'name', 'type'])->keyBy('uni_id');
        $breach = [];
        foreach ($filled as $uniId => $account) {
            $owner = $owners->get($uniId);
            $expected = WebhookPaths::feedForRole($owner?->type);
            if ($expected !== $feed) {
                $breach[] = [
                    'uni_id' => $uniId,
                    'owner' => $owner?->name,
                    'role' => $owner?->type,
                    'account' => $account !== '' ? $account : null,
                    'expected_feed' => $expected,
                ];
            }
        }
        if ($breach === []) {
            return [];
        }

        $log->forceFill(['breach' => $breach])->save();

        $broker = ExchangeSchema::isSupported((string) $log->exchange)
            ? ExchangeSchema::for((string) $log->exchange)->brokerLabel : ucfirst((string) $log->exchange);
        $side = strtoupper((string) $log->action) === 'BUY' ? 'LONG' : 'SHORT';
        $lines = [
            sprintf('A %s entry on %s %s came in on the %s, and these accounts were filled although they do not belong to it:',
                $side, $log->ticker, $broker, self::feedName($feed)),
        ];
        foreach ($breach as $b) {
            $lines[] = sprintf('• %s%s — role %s, should only trade the %s.',
                $b['owner'] ?? 'Unknown user',
                $b['account'] && $b['account'] !== $b['owner'] ? ' · '.$b['account'] : '',
                $b['role'] ?? 'unknown',
                self::feedName($b['expected_feed']));
        }
        $lines[] = 'Check those positions now and close them by hand if they should not be open. Signal Log row #'.$log->id.' has every account.';

        $this->alerts->raise(
            RiskAlert::FEED_BREACH,
            'log:'.$log->id,
            sprintf('%s %s on %s reached %d account(s) outside the %s', $side, $log->ticker, $broker, count($breach), self::feedName($feed)),
            $lines,
            (string) $log->exchange,
            $log->id,
            telegram: true,
        );

        return $breach;
    }

    public static function feedName(string $feed): string
    {
        return match ($feed) {
            'test' => 'test feed',
            'submaster' => 'sub-master feed',
            default => 'main feed',
        };
    }
}
