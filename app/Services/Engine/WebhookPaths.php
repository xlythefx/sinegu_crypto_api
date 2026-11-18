<?php

namespace App\Services\Engine;

use App\Models\UserCredential;

/**
 * The engine's webhook paths — one per venue PER SIGNAL FEED — in the one
 * place this API spells them.
 *
 * The PATH a signal is posted to decides two things on the engine: which
 * venue's accounts trade it, and which feed it belongs to.
 *  - `main` — TradingView's alerts. Fanned out to every account whose
 *    `feed` is main (customers, staff, the master).
 *  - `test` — the internal test feed, with its own secret
 *    (ENGINE_TEST_WEBHOOK_SECRET here, BINANCE_ABCD_TEST_WEBHOOK_SECRET on the
 *    engine). Fanned out to tester-owned accounts ONLY
 *    (UserCredential::TESTER), and a tester account never receives a main
 *    signal. The feed is derived from the owner's role by
 *    EngineController::accounts; the engine never reads a role.
 *  - `submaster` — the SUB-MASTER's own signals (2026-11-18), with its own
 *    secret (ENGINE_SUBMASTER_WEBHOOK_SECRET here,
 *    BINANCE_ABCD_SUBMASTER_WEBHOOK_SECRET on the engine). Fanned out to
 *    sub-master-owned accounts ONLY (UserCredential::SUBMASTER), which never
 *    receive a main signal either. Private like the test feed.
 *
 * These maps MUST stay in step with trading-flask's `hooks.WEBHOOK_PATHS` /
 * `hooks.TEST_WEBHOOK_PATHS` AND with the deploy script's
 * `ENGINE_WEBHOOK_PATHS`, which is what nginx actually proxies to the engine
 * — a path missing there is a 404 at nginx before the engine ever sees it.
 * Readers: the manual-trade console (AdminManualTradeController) and the
 * webhook overview (AdminWebhookController).
 */
final class WebhookPaths
{
    /** TradingView's feed — everyone except testers. */
    public const MAIN = [
        'binance' => '/binance_abcd_webhook',
        'bybit' => '/bybit_abcd_webhook',
        'mexc' => '/mexc_abcd_webhook',
    ];

    /** The internal test feed — tester-owned accounts only. */
    public const TEST = [
        'binance' => '/binance_test_webhook',
        'bybit' => '/bybit_test_webhook',
        'mexc' => '/mexc_test_webhook',
    ];

    /** The sub-master's own feed — sub-master-owned accounts only. */
    public const SUBMASTER = [
        'binance' => '/binance_submaster_webhook',
        'bybit' => '/bybit_submaster_webhook',
        'mexc' => '/mexc_submaster_webhook',
    ];

    /** Every feed, main first — the order every listing uses. */
    public const FEEDS = ['main', 'test', 'submaster'];

    /**
     * The feed an account follows, from its OWNER's role — the one rule, read
     * by the engine's account list, the audit of every fill (FeedAudit), the
     * manual-trade console, Open positions and the Webhooks page.
     */
    public static function feedForRole(?string $ownerType): string
    {
        return match ($ownerType) {
            UserCredential::TESTER => 'test',
            UserCredential::SUBMASTER => 'submaster',
            default => 'main',
        };
    }

    /** The role a non-main feed belongs to; null for main (everyone else). */
    public static function roleForFeed(string $feed): ?string
    {
        return match ($feed) {
            'test' => UserCredential::TESTER,
            'submaster' => UserCredential::SUBMASTER,
            default => null,
        };
    }

    /** The path a signal for this venue + feed is posted to, or null when there is none. */
    public static function path(string $exchange, string $feed): ?string
    {
        return match ($feed) {
            'main' => self::MAIN[$exchange] ?? null,
            'test' => self::TEST[$exchange] ?? null,
            'submaster' => self::SUBMASTER[$exchange] ?? null,
            default => null,
        };
    }

    /**
     * Venues the engine has a webhook path for (Binance, Bybit, MEXC — the
     * same order as ExchangeSchema::supported()).
     *
     * @return list<string>
     */
    public static function exchanges(): array
    {
        return array_keys(self::MAIN);
    }

    public static function isFeed(mixed $feed): bool
    {
        return is_string($feed) && in_array($feed, self::FEEDS, true);
    }
}
