<?php

namespace App\Services\Auth;

use App\Models\UserAccessEvent;
use App\Models\UserCredential;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The sign-up "fingerprint" (owner, 2026-10-14): one `user_access_events` row
 * per registration and per successful login — IP, country, device — read by
 * every staff role on admin user detail.
 *
 * BEST-EFFORT, like AccountMail and EngineCache: the login or the account is
 * the fact, this row is a note about it. A failed insert is logged and
 * swallowed; it must never turn a correct password into a 500. Callers run it
 * AFTER the transaction that created the user commits, so a failure here can
 * never roll a registration back either.
 *
 * What comes from where:
 *  - ip — $request->ip(). nginx restores the visitor's address from
 *    CF-Connecting-IP, trusted only from Cloudflare's ranges, so this is the
 *    real one on prod and 127.0.0.1 locally.
 *  - country — Cloudflare's CF-IPCountry header; null off Cloudflare (local)
 *    and for its "XX" (unknown).
 *  - device_type / browser / os — parsed from the User-Agent (DeviceInfo).
 *  - timezone / language / screen — the BROWSER's own report in `client`,
 *    kept only when the request says the visitor pressed "Accept all" on the
 *    cookie banner (`consent: 'all'`). Anything else and they are dropped
 *    here, server-side, even if the client sent them.
 * Client input is sanitised silently, never refused: a malformed timezone is
 * a null on a note, not a reason to fail a sign-in.
 */
final class AccessLog
{
    public const EVENT_REGISTER = 'register';

    public const EVENT_LOGIN = 'login';

    public const METHOD_PASSWORD = 'password';

    public const METHOD_DISCORD = 'discord';

    /** The cookie banner's "Accept all" — the only answer that keeps client fields. */
    public const CONSENT_ALL = 'all';

    /** The cookie banner's "Essential only". */
    public const CONSENT_ESSENTIAL = 'essential';

    private const USER_AGENT_MAX = 512;

    public function record(UserCredential $user, Request $request, string $event, string $method): void
    {
        try {
            UserAccessEvent::create(['uni_id' => $user->uni_id, 'event' => $event, 'method' => $method]
                + self::describe($request));
        } catch (Throwable $e) {
            Log::warning("AccessLog: the {$event} event could not be recorded.", [
                'uni_id' => $user->uni_id,
                'method' => $method,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Everything the row says about the request itself (no user, no event).
     *
     * @return array<string, ?string>
     */
    public static function describe(Request $request): array
    {
        $userAgent = self::userAgent($request->userAgent());
        $consent = self::consent($request->input('consent'));
        $client = $request->input('client');
        $client = is_array($client) && $consent === self::CONSENT_ALL ? $client : [];

        return [
            'ip' => $request->ip(),
            'country' => self::country($request->header('CF-IPCountry')),
            'user_agent' => $userAgent,
            'timezone' => self::clean($client['timezone'] ?? null, 64, '#^[A-Za-z0-9_+\-/]+$#'),
            'language' => self::clean($client['language'] ?? null, 35, '#^[A-Za-z0-9-]+$#'),
            'screen' => self::clean($client['screen'] ?? null, 16, '#^\d{2,5}x\d{2,5}$#'),
            'consent' => $consent,
        ] + DeviceInfo::parse($userAgent);
    }

    /** ISO 3166 alpha-2 as Cloudflare sends it (it also uses "T1" for Tor); "XX" is its "unknown". */
    private static function country(mixed $header): ?string
    {
        if (! is_string($header)) {
            return null;
        }

        $code = strtoupper(trim($header));

        return preg_match('/^[A-Z0-9]{2}$/', $code) && $code !== 'XX' ? $code : null;
    }

    /**
     * Truncated to the column, and scrubbed to valid UTF-8: the header is
     * whatever the client chose to send, and one invalid byte would make
     * MySQL refuse the whole row.
     */
    private static function userAgent(?string $ua): ?string
    {
        $ua = trim(mb_scrub((string) $ua, 'UTF-8'));

        return $ua === '' ? null : mb_substr($ua, 0, self::USER_AGENT_MAX);
    }

    private static function consent(mixed $value): ?string
    {
        return in_array($value, [self::CONSENT_ALL, self::CONSENT_ESSENTIAL], true) ? $value : null;
    }

    /** A client-reported string kept only if it is short and shaped as expected; null otherwise. */
    private static function clean(mixed $value, int $max, string $pattern): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' && strlen($value) <= $max && preg_match($pattern, $value) ? $value : null;
    }
}
