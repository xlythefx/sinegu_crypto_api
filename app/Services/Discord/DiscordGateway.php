<?php

namespace App\Services\Discord;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The one class that talks to Discord: the OAuth2 exchange behind "Sign in
 * with Discord", and the bot calls that put a user in the server and set
 * their roles.
 *
 * Hand-rolled rather than Socialite because the bot half needs an HTTP client
 * of its own anyway, and the WAMP cacert workaround must apply to every call
 * uniformly. Same posture as {@see \App\Services\EngineCache}: nothing here
 * throws. A Discord outage answers null/false and a warning in the log, and
 * the caller decides what that means — for the login flow it is a 401, for a
 * role sync it is "try again tonight". A token is never written to the log;
 * failures quote Discord's own JSON `code`/`message` only.
 *
 * Facts this class encodes (docs.discord.com, 2026-09-21):
 *  - the token endpoint wants a FORM body, client id + secret as fields;
 *  - `/users/@me` carries `email` and `verified` only with the `email` scope;
 *  - Add Guild Member answers 201 when it added, 204 when they were already
 *    in — and the `roles` it accepts apply only on 201, which is why roles are
 *    always reconciled separately;
 *  - a bot must send `Authorization: Bot …` AND a `DiscordBot (url, version)`
 *    User-Agent, or Cloudflare blocks it;
 *  - 401/403 responses count toward a Cloudflare ban, so a caller looping
 *    over users must stop on repeated auth failures (DiscordSyncRoles does).
 */
class DiscordGateway
{
    public const API_BASE = 'https://discord.com/api/v10';

    public const AUTHORIZE_URL = 'https://discord.com/oauth2/authorize';

    /** What the login asks for: profile + email, and the right to join them to the server. */
    public const SCOPES = 'identify email guilds.join';

    /** Discord's JSON error code for "that user is not in this guild". */
    public const CODE_UNKNOWN_MEMBER = 10007;

    // ---- configuration ---------------------------------------------------

    /** The OAuth app is set up — the login flow can run. */
    public function configured(): bool
    {
        return $this->clientId() !== '' && (string) config('services.discord.client_secret') !== '';
    }

    /** The bot is set up — users can be joined and given roles. */
    public function botConfigured(): bool
    {
        return (string) config('services.discord.bot_token') !== '' && $this->guildId() !== '';
    }

    /** Whether the button is shown on /auth (rollout switch, not a gate). */
    public function loginPublic(): bool
    {
        return (bool) config('services.discord.login_public', false);
    }

    /** @return list<string> */
    public function redirectUris(): array
    {
        return array_values(array_filter((array) config('services.discord.redirect_uris', [])));
    }

    public function isAllowedRedirectUri(string $uri): bool
    {
        return in_array($uri, $this->redirectUris(), true);
    }

    /**
     * The authorize URL WITHOUT `state` and `redirect_uri` — the SPA appends
     * both, because the nonce is browser-held (that is the CSRF defence) and
     * the redirect must be its own origin. Null until the app is configured.
     */
    public function authorizeUrl(): ?string
    {
        if (! $this->configured()) {
            return null;
        }

        return self::AUTHORIZE_URL.'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId(),
            'scope' => self::SCOPES,
            'prompt' => 'none',
        ]);
    }

    /**
     * Presence booleans only — the same rule PaymentController's debug
     * envelope follows: a diagnostic must never become a key leak.
     */
    public function diagnostics(): array
    {
        return [
            'client_id_set' => $this->clientId() !== '',
            'client_secret_set' => (string) config('services.discord.client_secret') !== '',
            'bot_token_set' => (string) config('services.discord.bot_token') !== '',
            'guild_id_set' => $this->guildId() !== '',
            'role_member_set' => (string) config('services.discord.role_member_id') !== '',
            'role_trader_set' => (string) config('services.discord.role_trader_id') !== '',
            'login_public' => $this->loginPublic(),
            'redirect_uris' => $this->redirectUris(),
        ];
    }

    // ---- OAuth2 ----------------------------------------------------------

    /**
     * Swap the authorization code for a user token. `$redirectUri` must equal
     * the one the browser was sent to — Discord enforces it, and the caller
     * has already checked it against the allow-list.
     *
     * @return array{access_token: string, scope: string, expires_in: int}|null
     */
    public function exchangeCode(string $code, string $redirectUri): ?array
    {
        $response = $this->send(fn () => $this->http()->asForm()->post(self::API_BASE.'/oauth2/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $this->clientId(),
            'client_secret' => (string) config('services.discord.client_secret'),
        ]), 'oauth.token');

        if ($response === null || ! $response->successful()) {
            return null;
        }

        $data = (array) $response->json();
        $token = (string) ($data['access_token'] ?? '');
        if ($token === '') {
            return null;
        }

        return [
            'access_token' => $token,
            'scope' => (string) ($data['scope'] ?? ''),
            'expires_in' => (int) ($data['expires_in'] ?? 0),
        ];
    }

    /**
     * The signed-in Discord user. `id` is returned as a STRING (a snowflake
     * overflows a JS number); `email` is null when the token has no email
     * scope or the account has none; `verified` is Discord's own word on the
     * address and proves nothing about ours.
     *
     * @return array{id: string, username: string, global_name: ?string, avatar: ?string, email: ?string, verified: bool}|null
     */
    public function me(string $accessToken): ?array
    {
        $response = $this->send(
            fn () => $this->http()->withToken($accessToken)->get(self::API_BASE.'/users/@me'),
            'users.me',
        );

        if ($response === null || ! $response->successful()) {
            return null;
        }

        $data = (array) $response->json();
        $id = (string) ($data['id'] ?? '');
        if ($id === '') {
            return null;
        }

        return [
            'id' => $id,
            'username' => (string) ($data['username'] ?? ''),
            'global_name' => isset($data['global_name']) ? (string) $data['global_name'] : null,
            'avatar' => isset($data['avatar']) ? (string) $data['avatar'] : null,
            'email' => isset($data['email']) && $data['email'] !== '' ? (string) $data['email'] : null,
            'verified' => (bool) ($data['verified'] ?? false),
        ];
    }

    /** CDN URL for a user's avatar; Discord's default avatar when they have none. */
    public static function avatarUrl(string $userId, ?string $avatarHash): string
    {
        if ($avatarHash !== null && $avatarHash !== '') {
            return "https://cdn.discordapp.com/avatars/{$userId}/{$avatarHash}.png?size=256";
        }

        // Discord's own rule for the default avatar of a new-style username
        // (snowflakes fit a 64-bit PHP int; only the shift needs the integer).
        $index = (int) ((((int) $userId) >> 22) % 6);

        return "https://cdn.discordapp.com/embed/avatars/{$index}.png";
    }

    // ---- bot: guild membership + roles -------------------------------------

    /**
     * Put the user in the server with THEIR token (needs `guilds.join`) and
     * OUR bot (needs Create Invite). True on 201 (added) and 204 (already
     * there). Roles are deliberately not passed: they apply on 201 only, so
     * the caller reconciles them afterwards either way.
     */
    public function addGuildMember(string $userId, string $accessToken): bool
    {
        if (! $this->botConfigured()) {
            return false;
        }

        $response = $this->send(
            fn () => $this->bot()->put($this->guildUrl("/members/{$userId}"), ['access_token' => $accessToken]),
            'guild.join',
        );

        return $response !== null && in_array($response->status(), [200, 201, 204], true);
    }

    /**
     * The role ids the user currently holds in the server. Null when they are
     * not a member (404 / 10007) OR when Discord could not be asked — both
     * mean "do not touch anything", which is why the two share an answer.
     *
     * @return list<string>|null
     */
    public function memberRoles(string $userId): ?array
    {
        if (! $this->botConfigured()) {
            return null;
        }

        $response = $this->send(
            fn () => $this->bot()->get($this->guildUrl("/members/{$userId}")),
            'guild.member',
            quiet404: true,
        );

        if ($response === null || ! $response->successful()) {
            return null;
        }

        $roles = (array) ($response->json('roles') ?? []);

        return array_values(array_map('strval', $roles));
    }

    /** True on 204. Honours one `retry_after` on a 429 — role edits share a bucket. */
    public function addRole(string $userId, string $roleId): bool
    {
        return $this->roleCall('put', $userId, $roleId, 'role.add');
    }

    public function removeRole(string $userId, string $roleId): bool
    {
        return $this->roleCall('delete', $userId, $roleId, 'role.remove');
    }

    // ---- transport -------------------------------------------------------

    private function roleCall(string $method, string $userId, string $roleId, string $what): bool
    {
        if (! $this->botConfigured() || $roleId === '') {
            return false;
        }

        $url = $this->guildUrl("/members/{$userId}/roles/{$roleId}");
        $response = $this->send(fn () => $this->bot()->{$method}($url), $what);

        if ($response !== null && $response->status() === 429) {
            $wait = (float) ($response->json('retry_after') ?? $response->header('Retry-After') ?? 1);
            usleep((int) (min(max($wait, 0.2), 5) * 1_000_000));
            $response = $this->send(fn () => $this->bot()->{$method}($url), $what.'.retry');
        }

        return $response !== null && in_array($response->status(), [200, 204], true);
    }

    /**
     * Run one request; never throw. A null return means Discord was never
     * reached (the caller cannot tell "refused" from "unreachable" otherwise,
     * and the two deserve different log lines).
     *
     * @param  callable(): Response  $call
     */
    private function send(callable $call, string $what, bool $quiet404 = false): ?Response
    {
        try {
            $response = $call();
        } catch (ConnectionException $e) {
            Log::warning('Discord is unreachable.', ['what' => $what, 'exception' => $e->getMessage()]);

            return null;
        } catch (\Throwable $e) {
            Log::warning('Discord call failed.', ['what' => $what, 'exception' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful() && ! ($quiet404 && $response->status() === 404)) {
            Log::warning('Discord refused a request.', [
                'what' => $what,
                'status' => $response->status(),
                // Discord's own error, never the request (which carries a token).
                'code' => $response->json('code'),
                'message' => $response->json('message') ?? $response->json('error_description') ?? $response->json('error'),
            ]);
        }

        return $response;
    }

    private function http(): PendingRequest
    {
        $conf = (array) config('services.discord');

        $request = Http::timeout((int) ($conf['timeout'] ?? 5))
            ->connectTimeout((int) ($conf['connect_timeout'] ?? 3))
            ->withUserAgent((string) ($conf['user_agent'] ?? 'DiscordBot (https://pixel-alpha.com, 1.0)'))
            ->acceptJson();

        $ca = (string) ($conf['ca_bundle'] ?? '');
        if ($ca !== '') {
            $request = $request->withOptions(['verify' => $ca]);
        }

        return $request;
    }

    private function bot(): PendingRequest
    {
        return $this->http()->withToken((string) config('services.discord.bot_token'), 'Bot');
    }

    private function guildUrl(string $path): string
    {
        return self::API_BASE.'/guilds/'.$this->guildId().$path;
    }

    private function clientId(): string
    {
        return (string) config('services.discord.client_id');
    }

    private function guildId(): string
    {
        return (string) config('services.discord.guild_id');
    }
}
