<?php

namespace App\Services\Visits;

/**
 * The public pages the visitor counter counts — the marketing site, the
 * guides, sign-in / register and the legal pages. Anything else (the
 * dashboard, the admin portal, a path nobody links to) is refused as
 * `unknown_page`, so a scripted ping cannot write junk into "Top pages".
 *
 * Client twin: `src/lib/visitPages.ts` in the frontend, which decides which
 * routes send a ping at all. A new public page goes in BOTH lists.
 */
final class VisitPages
{
    public const PATHS = [
        '/',
        '/v2',
        '/auth',
        '/trading-bot',
        '/faq',
        '/docs/binance',
        '/binance-offer',
        '/contact',
        '/terms',
        '/privacy',
        '/risk',
    ];

    /** A counted path for whatever the client sent, or null when it is not one. */
    public static function normalise(mixed $raw): ?string
    {
        if (! is_string($raw) || strlen($raw) > 200) {
            return null;
        }

        // Path only: a query or a fragment is not a different page.
        $path = strtolower(trim(substr($raw, 0, strcspn($raw, '?#'))));
        $path = '/'.trim($path, '/');

        return in_array($path, self::PATHS, true) ? $path : null;
    }
}
