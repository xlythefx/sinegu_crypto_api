<?php

namespace App\Services\Notifications;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Who receives each TEAM email (Dima, 2026-11-23: "a notification area so I
 * can opt in and add emails… and manage it myself"). Admin → Notifications.
 *
 * Every team notice asks `recipients($slug)` — never AccountMail::teamRecipients()
 * or TradeAlerts::recipients() directly, which are now only the DEFAULT lists
 * from the server's config:
 *  - no `notification_settings` row, or `recipients` NULL → the default list
 *    (MAIL_ADMIN_ADDRESS for 'team', TRADE_ALERT_EMAILS for 'alerts'), so a
 *    box behaves exactly as before until someone edits a notice;
 *  - a row → its own list, and nothing at all while `enabled` is off.
 *
 * CRITICAL notices (a close that failed, a high-risk alert) can be neither
 * switched off nor saved with an empty list: on a real-money engine those are
 * the emails that must reach somebody. An unreadable settings table falls back
 * to the default list for the same reason — an alarm never goes quiet by
 * accident.
 */
class TeamNotices
{
    public const DEFAULT_TEAM = 'team';

    public const DEFAULT_ALERTS = 'alerts';

    /** Display order of the page. Slugs match EmailCatalogue (its preview). */
    public const NOTICES = [
        'team-new-registration' => ['group' => 'Customers', 'title' => 'New sign-up', 'default' => self::DEFAULT_TEAM, 'critical' => false,
            'when' => 'A new person confirms their email address and is waiting for approval.'],
        'team-exchange-connected' => ['group' => 'Customers', 'title' => 'A customer connected an exchange', 'default' => self::DEFAULT_TEAM, 'critical' => false,
            'when' => 'A customer connects (or reconnects) a Binance, Bybit or MEXC account, live or demo. Staff accounts are not announced.'],
        'team-invoice-paid' => ['group' => 'Billing', 'title' => 'Invoice paid', 'default' => self::DEFAULT_TEAM, 'critical' => false,
            'when' => 'A customer\'s invoice is paid: by card, USDT, or marked paid by an admin.'],
        'team-payment-disputed' => ['group' => 'Billing', 'title' => 'Disputed crypto payment', 'default' => self::DEFAULT_TEAM, 'critical' => false,
            'when' => 'A second customer claims a USDT payment that is already on another invoice.'],
        'team-kill-switch' => ['group' => 'Trading', 'title' => 'Kill switch switched a coin off', 'default' => self::DEFAULT_TEAM, 'critical' => false,
            'when' => 'The master lost the set number of trades in a row on a coin, so the coin was turned off for everyone.'],
        'trade-failure-summary' => ['group' => 'Trading', 'title' => 'Hourly summary of trades that need a look', 'default' => self::DEFAULT_ALERTS, 'critical' => false,
            'when' => 'Every hour, only when an order was refused, an entry was shrunk or skipped, or a signal was rejected.'],
        'trade-exit-stuck' => ['group' => 'Trading', 'title' => 'A close failed: positions may still be open', 'default' => self::DEFAULT_ALERTS, 'critical' => true,
            'when' => 'At once, when the exchange refuses a close or the bot gives up retrying one.'],
        'risk-alert' => ['group' => 'Trading', 'title' => 'High-risk alert', 'default' => self::DEFAULT_ALERTS, 'critical' => true,
            'when' => 'At once: a trade reached the wrong accounts, a close failed for good, the kill switch tripped, the master\'s key was refused, or the bot engine stopped answering.'],
    ];

    public const MAX_RECIPIENTS = 20;

    public static function exists(string $slug): bool
    {
        return isset(self::NOTICES[$slug]);
    }

    /** @return list<string> */
    public static function defaultList(string $slug): array
    {
        return (self::NOTICES[$slug]['default'] ?? self::DEFAULT_TEAM) === self::DEFAULT_ALERTS
            ? TradeAlerts::recipients()
            : AccountMail::teamRecipients();
    }

    /**
     * The addresses a notice goes to right now; [] = it is not sent.
     *
     * @return list<string>
     */
    public static function recipients(string $slug): array
    {
        $row = self::row($slug);
        if ($row === null) {
            return self::defaultList($slug);
        }
        if (! $row['enabled'] && ! (self::NOTICES[$slug]['critical'] ?? false)) {
            return [];
        }

        return $row['recipients'] ?? self::defaultList($slug);
    }

    /**
     * Every notice as the admin page shows it.
     *
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        $rows = [];
        try {
            foreach (DB::table('notification_settings')->get() as $r) {
                $rows[$r->slug] = $r;
            }
        } catch (Throwable) {
        }
        $names = [];
        $updaters = collect($rows)->pluck('updated_by')->filter()->unique()->all();
        if ($updaters) {
            $names = DB::table('user_credentials')->whereIn('uni_id', $updaters)->pluck('name', 'uni_id')->all();
        }

        $out = [];
        foreach (self::NOTICES as $slug => $n) {
            $r = $rows[$slug] ?? null;
            $custom = $r !== null && $r->recipients !== null;
            $out[] = [
                'slug' => $slug,
                'group' => $n['group'],
                'title' => $n['title'],
                'when' => $n['when'],
                'critical' => $n['critical'],
                'enabled' => $n['critical'] ? true : ($r === null ? true : (bool) $r->enabled),
                'uses_default' => ! $custom,
                'recipients' => $custom ? self::clean(json_decode((string) $r->recipients, true)) : self::defaultList($slug),
                'default_recipients' => self::defaultList($slug),
                'default_source' => $n['default'] === self::DEFAULT_ALERTS ? 'TRADE_ALERT_EMAILS' : 'MAIL_ADMIN_ADDRESS',
                'updated_at' => $r?->updated_at ? \Illuminate\Support\Carbon::parse($r->updated_at, 'UTC')->toIso8601String() : null,
                'updated_by' => $r?->updated_by ? ($names[$r->updated_by] ?? $r->updated_by) : null,
            ];
        }

        return $out;
    }

    /**
     * Save one notice. `$recipients` null = back to the default list.
     *
     * @param  list<string>|null  $recipients
     */
    public static function save(string $slug, bool $enabled, ?array $recipients, ?string $actor): void
    {
        $values = [
            'enabled' => $enabled,
            'recipients' => $recipients === null ? null : json_encode(array_values($recipients)),
            'updated_by' => $actor,
            'updated_at' => now(),
        ];
        $table = DB::table('notification_settings');
        if ($table->clone()->where('slug', $slug)->exists()) {
            $table->where('slug', $slug)->update($values);
        } else {
            $table->insert($values + ['slug' => $slug, 'created_at' => now()]);
        }
    }

    /**
     * Valid, lower-cased, de-duplicated addresses.
     *
     * @return list<string>
     */
    public static function clean(mixed $list): array
    {
        if (! is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $a) {
            $a = strtolower(trim((string) $a));
            if ($a !== '' && filter_var($a, FILTER_VALIDATE_EMAIL) !== false) {
                $out[$a] = true;
            }
        }

        return array_keys($out);
    }

    /** @return array{enabled: bool, recipients: ?list<string>}|null */
    private static function row(string $slug): ?array
    {
        try {
            $r = DB::table('notification_settings')->where('slug', $slug)->first();
        } catch (Throwable $e) {
            Log::warning('TeamNotices: settings unreadable, using the default list.', ['slug' => $slug, 'error' => $e->getMessage()]);

            return null;
        }
        if ($r === null) {
            return null;
        }

        return [
            'enabled' => (bool) $r->enabled,
            'recipients' => $r->recipients === null ? null : self::clean(json_decode((string) $r->recipients, true)),
        ];
    }
}
