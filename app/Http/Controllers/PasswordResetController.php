<?php

namespace App\Http\Controllers;

use App\Mail\PasswordResetCode;
use App\Models\UserCredential;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * Forgot-password by emailed 6-digit code, on the `reset_code*` columns the
 * user_credentials table has carried since it was ported (plus
 * `reset_code_sent_at`, 2026-10-07).
 *
 * A code rather than a signed link because the frontend is a SPA on a
 * different origin than the API and the user may open the mail on their phone
 * while the form sits open on a laptop — a code is typed anywhere, a link has
 * to land in the right tab. Its weakness (six digits are guessable) is closed
 * by the TTL, the attempt cap, and the per-account rules in forgot() that
 * stop a reissue from refunding either — not by the code's length.
 */
class PasswordResetController extends Controller
{
    /** Minutes a code stays valid. Short, because the code is stored in clear. */
    public const CODE_TTL_MINUTES = 15;

    /**
     * Wrong guesses before the code is void. 5 attempts against 1,000,000 codes
     * is a 0.0005% chance per window — and it IS per window, because a
     * reissue inside the window inherits both the count and the expiry (see
     * forgot()); the route's per-email limiter makes even reissuing slow.
     */
    public const MAX_ATTEMPTS = 5;

    /**
     * Seconds between two mails for one account, counted from
     * `reset_code_sent_at`. It cannot be derived from the expiry the way
     * EmailVerification::resendWaitSeconds does it, because here a reissue
     * inside the window does not move the expiry: derived that way, every
     * reissue after the first would read as "cooldown already past".
     */
    public const RESEND_COOLDOWN_SECONDS = 60;

    /**
     * POST /api/auth/forgot-password
     *
     * Always answers 200 with the same message, whether or not the address is
     * registered — and whether or not a code was actually sent: this is the
     * one endpoint attackers script to test which emails have accounts, so
     * nothing in the response may depend on the row. (Login gives one answer
     * for unknown-email and wrong-password for the same reason.)
     *
     * Two per-ACCOUNT rules, both blind to the caller's IP, because the route
     * throttle's IP half is walked past by a proxy pool:
     *
     * - A 60 s resend cooldown. Inside it the call is a no-op that still
     *   answers 200: no new code, no mail. That is the cap on flooding a
     *   victim's inbox, and on how fast a code can be rotated.
     * - A reissue INSIDE a live window keeps the window: the new code
     *   inherits both `reset_code_attempts` and `reset_code_expires_at`. Only
     *   once the previous code has expired does a request get a fresh 15
     *   minutes with the count back at 0. Until 2026-10-07 every call reset
     *   the count, so "forgot → 5 guesses → forgot → 5 more" gave unlimited
     *   rounds against one address, bounded only by the per-IP limit. The
     *   first fix carried the count but restarted the window on each
     *   reissue — which let an attacker who had spent the 5 keep the
     *   account's reset dead INDEFINITELY by reissuing every 61 s, the
     *   window never running out under them. Inheriting the expiry too makes
     *   it 5 guesses per 15-minute window per account, whatever the IP, and
     *   the owner can always reset again once the window has run out.
     */
    public function forgot(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = UserCredential::where('email', $validated['email'])->first();

        if ($user && $this->resendWaitSeconds($user) === 0) {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $live = $this->hasLiveCode($user);

            $user->forceFill([
                'reset_code' => $code,
                'reset_code_sent_at' => now(),
                'reset_code_expires_at' => $live
                    ? $user->reset_code_expires_at
                    : now()->addMinutes(self::CODE_TTL_MINUTES),
                'reset_code_attempts' => $live ? (int) $user->reset_code_attempts : 0,
            ])->save();

            Mail::to($user->email)->send(new PasswordResetCode($user->name, $code, self::CODE_TTL_MINUTES));
        }

        return response()->json([
            'success' => true,
            'message' => 'If that email is registered, a reset code is on its way.',
        ]);
    }

    /**
     * POST /api/auth/reset-password
     *
     * One error code for every refusal (INVALID_CODE) so the response does not
     * say whether the email, the code, or the expiry was the problem.
     */
    public function reset(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'digits:6'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = UserCredential::where('email', $validated['email'])->first();

        $usable = $user
            && $this->hasLiveCode($user)
            && $user->reset_code_attempts < self::MAX_ATTEMPTS;

        if (! $usable) {
            return $this->invalidCode();
        }

        if (! hash_equals($user->reset_code, $validated['code'])) {
            $user->forceFill(['reset_code_attempts' => $user->reset_code_attempts + 1])->save();

            return $this->invalidCode();
        }

        // `reset_code_sent_at` is left alone on purpose: the cooldown is the
        // time since the last MAIL, and a successful reset does not unsend it.
        $user->forceFill([
            'password' => Hash::make($validated['password']),
            'reset_code' => null,
            'reset_code_expires_at' => null,
            'reset_code_attempts' => 0,
        ])->save();

        // Every existing session goes: a reset is what a user does when they
        // suspect someone else is logged in, and a live token would survive it.
        $user->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Password updated. You can sign in with the new one.',
        ]);
    }

    private function invalidCode(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error_code' => 'INVALID_CODE',
            'message' => 'That code is not valid or has expired. Request a new one.',
        ], 422);
    }

    /** True while a stored code has not passed its expiry — spent guesses or not. */
    private function hasLiveCode(UserCredential $user): bool
    {
        $expiresAt = $this->expiresAt($user);

        return $user->reset_code !== null
            && $expiresAt !== null
            && now()->lessThan($expiresAt);
    }

    /** Seconds until another code may be mailed (0 = now). */
    private function resendWaitSeconds(UserCredential $user): int
    {
        $sentAt = $this->sentAt($user);

        if ($sentAt === null) {
            return 0;
        }

        $readyAt = $sentAt->addSeconds(self::RESEND_COOLDOWN_SECONDS);

        return now()->lessThan($readyAt)
            ? (int) ceil(now()->diffInMilliseconds($readyAt, true) / 1000)
            : 0;
    }

    /**
     * When the current code was mailed. A row whose code predates the column
     * (2026-10-07) has only its expiry, which for such a code still equals
     * sent_at + TTL — the old derivation, kept until that code is replaced.
     */
    private function sentAt(UserCredential $user): ?Carbon
    {
        if ($user->reset_code_sent_at !== null) {
            return Carbon::parse($user->reset_code_sent_at);
        }

        return $this->expiresAt($user)?->subMinutes(self::CODE_TTL_MINUTES);
    }

    /** Neither datetime column is cast on the model, so strings come back from the DB. */
    private function expiresAt(UserCredential $user): ?Carbon
    {
        $value = $user->reset_code_expires_at;

        return $value === null ? null : Carbon::parse($value);
    }
}
