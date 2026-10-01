<?php

namespace App\Services\Auth;

use App\Models\UserCredential;
use App\Services\Notifications\AccountMail;
use Illuminate\Support\Carbon;

/**
 * Email verification by a mailed 6-digit code, on the `verification_code*`
 * columns user_credentials has carried since it was ported. The same pattern
 * as PasswordResetController (random_int, short TTL, attempt cap,
 * hash_equals), with one difference: the user is already signed in, so the
 * code is checked against THEIR row, never looked up by an email in the body.
 *
 * The team's "someone registered" notice is sent HERE, on success — a sign-up
 * nobody can prove owns the address is not yet something the desk must act on.
 */
class EmailVerification
{
    /** Minutes a code stays valid. Short, because the code is stored in clear. */
    public const CODE_TTL_MINUTES = 15;

    /** Wrong guesses before the code is void; only a resend unlocks it again. */
    public const MAX_ATTEMPTS = 5;

    /**
     * Seconds between two sends. Derived from the stored expiry
     * (sent_at = expires_at - TTL), so no "last sent" column is needed.
     */
    public const RESEND_COOLDOWN_SECONDS = 60;

    public function __construct(private AccountMail $mail)
    {
    }

    /**
     * Store a fresh code (resetting the attempt counter) and mail it.
     * The mail is best-effort: the code is saved first, so an SMTP outage
     * leaves "Resend" as the way forward instead of a failed registration.
     */
    public function issue(UserCredential $user): void
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $user->forceFill([
            'verification_code' => $code,
            'verification_code_expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
            'verification_code_attempts' => 0,
        ])->save();

        $this->mail->verificationCode($user, $code, self::CODE_TTL_MINUTES);
    }

    /**
     * Check a code. On success: mark verified, clear the code, and tell the
     * team a verified sign-up is waiting for approval. On a miss against a
     * live code: count the attempt. An expired or exhausted code is refused
     * without counting.
     */
    public function verify(UserCredential $user, string $code): bool
    {
        if (! $this->isUsable($user)) {
            return false;
        }

        if (! hash_equals((string) $user->verification_code, $code)) {
            $user->forceFill([
                'verification_code_attempts' => (int) $user->verification_code_attempts + 1,
            ])->save();

            return false;
        }

        $user->forceFill([
            'email_verified' => true,
            'verification_code' => null,
            'verification_code_expires_at' => null,
            'verification_code_attempts' => 0,
        ])->save();

        $via = $user->discord_id !== null ? AccountMail::VIA_DISCORD : AccountMail::VIA_PASSWORD;
        $this->mail->registrationPending($user, $via);

        return true;
    }

    /** Guesses left on the current code (0 = locked; resend to unlock). */
    public function attemptsLeft(UserCredential $user): int
    {
        return max(0, self::MAX_ATTEMPTS - (int) $user->verification_code_attempts);
    }

    /** True when there is no live code to check — none issued, or past its expiry. */
    public function isExpired(UserCredential $user): bool
    {
        $expiresAt = $this->expiresAt($user);

        return $user->verification_code === null
            || $expiresAt === null
            || ! now()->lessThan($expiresAt);
    }

    /** Seconds until another code may be sent (0 = now). */
    public function resendWaitSeconds(UserCredential $user): int
    {
        $expiresAt = $this->expiresAt($user);

        if ($expiresAt === null) {
            return 0;
        }

        $sentAt = $expiresAt->copy()->subMinutes(self::CODE_TTL_MINUTES);
        $readyAt = $sentAt->addSeconds(self::RESEND_COOLDOWN_SECONDS);

        return now()->lessThan($readyAt)
            ? (int) ceil(now()->diffInMilliseconds($readyAt, true) / 1000)
            : 0;
    }

    private function isUsable(UserCredential $user): bool
    {
        return ! $this->isExpired($user) && $this->attemptsLeft($user) > 0;
    }

    private function expiresAt(UserCredential $user): ?Carbon
    {
        $value = $user->verification_code_expires_at;

        return $value === null ? null : Carbon::parse($value);
    }
}
