<?php

namespace App\Http\Controllers;

use App\Models\UserCredential;
use App\Services\Discord\DiscordGateway;
use App\Services\Discord\DiscordRoleSync;
use App\Services\ReferralService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * "Sign in with Discord" — the OAuth2 authorization-code flow, exchanged
 * SERVER-SIDE (the client secret never reaches the browser), issuing the same
 * Sanctum token password login does. The API has no session, so:
 *
 *  - CSRF is the BROWSER's job: the SPA generates the `state` nonce, keeps it,
 *    and compares it on return before ever calling here. A server-side state
 *    store would add nothing — anyone can obtain an issued state — so there is
 *    deliberately none.
 *  - The client-sent `redirect_uri` is checked against the config allow-list
 *    and echoed into the token exchange, where Discord enforces equality.
 *  - What cannot finish in one request is parked in the cache under a random
 *    single-use token for 15 minutes: the Terms step for a first-time user,
 *    and the password step for an email that already has an account. The
 *    payload carries the user's Discord access token (needed to join them to
 *    the server once the row exists) and is ENCRYPTED because the prod cache
 *    store is the database.
 *
 * An email that already has an account is NEVER auto-linked, verified or not:
 * register() marks every address verified without checking it and the profile
 * lets anyone change theirs, so a match proves nothing about who owns the
 * account here. The password does. Wrong-password attempts are capped per
 * token, the route is throttled like login, and the token expires.
 *
 * Discord-only accounts are created with `password = NULL`; login answers
 * DISCORD_ONLY for them, and Settings offers "Set a password".
 */
class DiscordAuthController extends Controller
{
    /** How long a parked signup / link may wait for the user to finish. */
    private const PENDING_TTL_MINUTES = 15;

    /** Wrong-password attempts per link token before it is voided. */
    private const MAX_LINK_ATTEMPTS = 5;

    public function __construct(
        private DiscordGateway $discord,
        private DiscordRoleSync $roles,
        private ReferralService $referrals,
    ) {}

    /**
     * GET /api/auth/discord/config
     *
     * `enabled` decides whether /auth shows the button; `configured` whether
     * the flow works at all (a developer rehearses via /auth/discord/start
     * while the button is still hidden). Never a secret: the authorize URL
     * carries the PUBLIC client id, which every OAuth redirect exposes anyway.
     */
    public function config(): JsonResponse
    {
        $configured = $this->discord->configured();

        return response()->json([
            'success' => true,
            'configured' => $configured,
            'enabled' => $configured && $this->discord->loginPublic(),
            'authorize_url' => $this->discord->authorizeUrl(),
            'redirect_uris' => $this->discord->redirectUris(),
        ]);
    }

    /**
     * POST /api/auth/discord/callback  { code, redirect_uri }
     *
     * Answers one of three shapes, all 200:
     *   { status: 'logged_in', token, user }             — known Discord id
     *   { status: 'password_required', link_token, profile } — email has an account
     *   { status: 'terms_required', signup_token, profile }  — new user
     */
    public function callback(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:512'],
            'redirect_uri' => ['required', 'string', 'max:512'],
        ]);

        $profile = $this->resolveProfile($validated['code'], $validated['redirect_uri']);
        if ($profile instanceof JsonResponse) {
            return $profile;
        }

        // 1. A linked account: plain login.
        $user = UserCredential::where('discord_id', $profile['id'])->first();
        if ($user) {
            if ($user->status === 'suspended') {
                return $this->suspended();
            }

            $user->forceFill([
                'discord_username' => $profile['username'],
                'last_activity' => now(),
            ])->save();

            return $this->loggedIn($user, $profile['access_token'], 'Login successful');
        }

        // 2. The email already has an account: prove it is theirs first.
        $existing = UserCredential::where('email', $profile['email'])->first();
        if ($existing) {
            if ($existing->status === 'suspended') {
                return $this->suspended();
            }

            $linkToken = $this->park('discord-link', [
                'profile' => $profile,
                'uni_id' => $existing->uni_id,
                'attempts_left' => self::MAX_LINK_ATTEMPTS,
            ]);

            return response()->json([
                'success' => true,
                'status' => 'password_required',
                'link_token' => $linkToken,
                'profile' => $this->publicProfile($profile),
            ]);
        }

        // 3. Nobody yet: the Terms come before the row.
        $signupToken = $this->park('discord-signup', ['profile' => $profile]);

        return response()->json([
            'success' => true,
            'status' => 'terms_required',
            'signup_token' => $signupToken,
            'profile' => $this->publicProfile($profile),
        ]);
    }

    /**
     * POST /api/auth/discord/link-with-password  { link_token, password }
     *
     * The existing-email path: the account's own password is what proves the
     * person clicking "Continue with Discord" owns it.
     */
    public function linkWithPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'link_token' => ['required', 'string', 'max:128'],
            'password' => ['required', 'string'],
        ]);

        $key = 'discord-link:'.$validated['link_token'];
        $parked = $this->unpark($key);
        if ($parked === null) {
            return $this->expired('LINK_EXPIRED', 'That sign-in took too long. Start again with Discord.');
        }

        $user = UserCredential::find($parked['uni_id']);
        if (! $user) {
            Cache::forget($key);

            return $this->expired('LINK_EXPIRED', 'That sign-in took too long. Start again with Discord.');
        }

        if (! $user->hasPassword()) {
            // A Discord-only account under this email, reached from a DIFFERENT
            // Discord account: there is no password to prove anything with.
            Cache::forget($key);

            return response()->json([
                'success' => false,
                'error_code' => 'NO_PASSWORD',
                'message' => 'This email belongs to an account that signs in with a different Discord account.',
            ], 409);
        }

        if (! Hash::check($validated['password'], $user->password)) {
            $left = (int) $parked['attempts_left'] - 1;
            if ($left <= 0) {
                Cache::forget($key);
            } else {
                $this->repark($key, ['attempts_left' => $left] + $parked);
            }

            return response()->json([
                'success' => false,
                'error_code' => 'INVALID_PASSWORD',
                'message' => 'Incorrect password. Please try again.',
                'attempts_left' => max($left, 0),
            ], 401);
        }

        Cache::forget($key);

        if ($user->status === 'suspended') {
            return $this->suspended();
        }
        if ($refused = $this->linkRefusal($user, $parked['profile']['id'])) {
            return $refused;
        }

        $this->attach($user, $parked['profile']);
        $user->forceFill(['last_activity' => now()])->save();

        return $this->loggedIn($user, $parked['profile']['access_token'], 'Discord connected');
    }

    /**
     * POST /api/auth/discord/complete
     *   { signup_token, terms, terms_version?, referral_code?, name? }
     *
     * Mirrors AuthController::register for a user who arrived through Discord:
     * the Terms are mandatory, invalid referral codes are silently ignored,
     * and the row starts in the approval queue.
     */
    public function complete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'signup_token' => ['required', 'string', 'max:128'],
            'terms' => ['accepted'],
            'terms_version' => ['nullable', 'string', 'max:32'],
            'referral_code' => ['nullable', 'string', 'max:50'],
            'name' => ['nullable', 'string', 'max:255'],
        ], [
            'terms.accepted' => 'You must accept the Terms and Conditions to create an account.',
        ]);

        $key = 'discord-signup:'.$validated['signup_token'];
        $parked = $this->unpark($key);
        if ($parked === null) {
            return $this->expired('SIGNUP_EXPIRED', 'That sign-up took too long. Start again with Discord.');
        }
        Cache::forget($key);

        $profile = $parked['profile'];

        // The world may have moved while the Terms were on screen.
        if (UserCredential::where('discord_id', $profile['id'])->exists()) {
            return $this->discordTaken();
        }
        if (UserCredential::where('email', $profile['email'])->exists()) {
            return response()->json([
                'success' => false,
                'error_code' => 'EMAIL_TAKEN',
                'message' => 'An account with this email already exists. Sign in with Discord again to connect it.',
            ], 409);
        }

        $name = trim((string) ($validated['name'] ?? ''));
        if ($name === '') {
            $name = $profile['global_name'] ?: $profile['username'];
        }

        try {
            $user = DB::transaction(function () use ($validated, $profile, $name) {
                $user = UserCredential::create([
                    'name' => $name,
                    'email' => $profile['email'],
                    'password' => null,
                    // Discord's word on the address, unlike register()'s blanket true.
                    'email_verified' => $profile['verified'],
                    'terms_accepted_at' => now(),
                    'terms_version' => $validated['terms_version'] ?? null,
                    'discord_id' => $profile['id'],
                    'discord_username' => $profile['username'],
                    'discord_linked_at' => now(),
                    'user_profile' => $profile['avatar_url'],
                    'status' => 'pending',
                ]);

                $this->referrals->bind($validated['referral_code'] ?? null, $user->uni_id);

                return $user;
            });
        } catch (QueryException) {
            // The unique index on discord_id (or email) won the race.
            return $this->discordTaken();
        }

        return $this->loggedIn($user, $profile['access_token'], 'Account created', 201);
    }

    /**
     * POST /api/user/discord/link  { code, redirect_uri }  (auth:sanctum)
     *
     * Settings → "Connect Discord" for a signed-in user. Same exchange, no
     * password step — being logged in is the proof.
     */
    public function link(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:512'],
            'redirect_uri' => ['required', 'string', 'max:512'],
        ]);

        $user = $request->user();

        $profile = $this->resolveProfile($validated['code'], $validated['redirect_uri']);
        if ($profile instanceof JsonResponse) {
            return $profile;
        }

        if ($refused = $this->linkRefusal($user, $profile['id'])) {
            return $refused;
        }

        $this->attach($user, $profile);
        $this->roles->syncUser($user, $profile['access_token']);

        return response()->json([
            'success' => true,
            'message' => 'Discord connected',
            'user' => $user->toAuthPayload(),
        ]);
    }

    /**
     * DELETE /api/user/discord  (auth:sanctum)
     *
     * Refused while the account has no password — unlinking would leave no
     * way in. The managed roles come off first, while the id is still known.
     */
    public function unlink(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasDiscord()) {
            return response()->json([
                'success' => false,
                'error_code' => 'NOT_LINKED',
                'message' => 'No Discord account is connected.',
            ], 409);
        }
        if (! $user->hasPassword()) {
            return response()->json([
                'success' => false,
                'error_code' => 'NO_PASSWORD',
                'message' => 'Set a password first — Discord is currently the only way into this account.',
            ], 409);
        }

        $this->roles->revoke($user);

        $user->forceFill([
            'discord_id' => null,
            'discord_username' => null,
            'discord_linked_at' => null,
        ])->save();

        return response()->json([
            'success' => true,
            'message' => 'Discord disconnected',
            'user' => $user->toAuthPayload(),
        ]);
    }

    // ---- helpers ---------------------------------------------------------

    /**
     * Code → token → profile, with every refusal already shaped as a response.
     *
     * @return array{id: string, username: string, global_name: ?string, avatar: ?string, avatar_url: string, email: string, verified: bool, access_token: string}|JsonResponse
     */
    private function resolveProfile(string $code, string $redirectUri): array|JsonResponse
    {
        if (! $this->discord->configured()) {
            return response()->json([
                'success' => false,
                'error_code' => 'DISCORD_NOT_CONFIGURED',
                'message' => 'Discord sign-in is not available yet.',
            ], 503);
        }

        if (! $this->discord->isAllowedRedirectUri($redirectUri)) {
            return response()->json([
                'success' => false,
                'error_code' => 'REDIRECT_URI_NOT_ALLOWED',
                'message' => 'This site is not registered for Discord sign-in.',
            ], 422);
        }

        $token = $this->discord->exchangeCode($code, $redirectUri);
        $me = $token ? $this->discord->me($token['access_token']) : null;

        if ($me === null) {
            return response()->json([
                'success' => false,
                'error_code' => 'DISCORD_DENIED',
                'message' => 'Discord did not confirm the sign-in. Please try again.',
            ], 401);
        }

        if ($me['email'] === null) {
            return response()->json([
                'success' => false,
                'error_code' => 'DISCORD_NO_EMAIL',
                'message' => 'Your Discord account has no email address we can use. Add one on Discord, or register with email.',
            ], 422);
        }

        return $me + [
            'avatar_url' => DiscordGateway::avatarUrl($me['id'], $me['avatar']),
            'access_token' => $token['access_token'],
        ];
    }

    /** What the SPA may show while the user finishes — never the token. */
    private function publicProfile(array $profile): array
    {
        return [
            'name' => $profile['global_name'] ?: $profile['username'],
            'username' => $profile['username'],
            'email' => $profile['email'],
            'avatar_url' => $profile['avatar_url'],
        ];
    }

    /** Null when the link may proceed; the 409 otherwise. */
    private function linkRefusal(UserCredential $user, string $discordId): ?JsonResponse
    {
        if ($user->hasDiscord()) {
            return response()->json([
                'success' => false,
                'error_code' => 'ALREADY_LINKED',
                'message' => 'This account already has a Discord account connected. Disconnect it first.',
            ], 409);
        }

        $taken = UserCredential::where('discord_id', $discordId)
            ->where('uni_id', '!=', $user->uni_id)
            ->exists();
        if ($taken) {
            return $this->discordTaken();
        }

        return null;
    }

    private function attach(UserCredential $user, array $profile): void
    {
        $user->forceFill([
            'discord_id' => $profile['id'],
            'discord_username' => $profile['username'],
            'discord_linked_at' => now(),
        ])->save();
    }

    /** Token + join + roles, in that order; the join is best-effort. */
    private function loggedIn(UserCredential $user, string $accessToken, string $message, int $status = 200): JsonResponse
    {
        $token = $user->createToken('spa')->plainTextToken;

        $this->roles->syncUser($user, $accessToken);

        return response()->json([
            'success' => true,
            'status' => 'logged_in',
            'message' => $message,
            'token' => $token,
            'user' => $user->toAuthPayload(),
        ], $status);
    }

    private function park(string $prefix, array $payload): string
    {
        $token = Str::random(48);
        $this->repark("{$prefix}:{$token}", $payload);

        return $token;
    }

    private function repark(string $key, array $payload): void
    {
        Cache::put($key, Crypt::encryptString(json_encode($payload)), now()->addMinutes(self::PENDING_TTL_MINUTES));
    }

    private function unpark(string $key): ?array
    {
        $raw = Cache::get($key);
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $payload = json_decode(Crypt::decryptString($raw), true);
        } catch (\Throwable) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }

    private function expired(string $code, string $message): JsonResponse
    {
        return response()->json(['success' => false, 'error_code' => $code, 'message' => $message], 410);
    }

    private function suspended(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error_code' => 'ACCOUNT_SUSPENDED',
            'message' => 'Your account has been suspended. Please contact support.',
        ], 403);
    }

    private function discordTaken(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error_code' => 'DISCORD_TAKEN',
            'message' => 'That Discord account is already connected to another Pixel Alpha account.',
        ], 409);
    }
}
