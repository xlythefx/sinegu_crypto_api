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
 * The same code proves two things, and the row says which: a sign-up proving
 * the address it registered with (`email_verified = 0`), or a verified user
 * proving a NEW address they want to move to (`pending_email` set). The code
 * is always mailed to the address being proven — for a change, the new inbox
 * — and `email` itself moves only when that inbox has typed it, so the old
 * address keeps every login and guarded route until then.
 *
 * The team's "someone registered" notice is sent HERE, on a sign-up's first
 * success — a sign-up nobody can prove owns the address is not yet something
 * the desk must act on. An email change is not a registration and sends none.
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

    /** verify(): a sign-up's address is proven; the team notice went out. */
    public const RESULT_VERIFIED = 'verified';

    /** verify(): a pending address became the live `email`; other sessions are gone. */
    public const RESULT_EMAIL_CHANGED = 'email_changed';

    /** verify(): right code, but another row took the pending address first. */
    public const RESULT_EMAIL_TAKEN = 'email_taken';

    /** verify(): wrong, expired or exhausted code — nothing changed. */
    public const RESULT_REJECTED = 'rejected';

    public function __construct(private AccountMail $mail)
    {
    }

    /**
     * The address the current code proves: the pending one while a change is
     * in flight, else the row's own. One rule, so issue() and resend can never
     * disagree about which inbox the code belongs in.
     */
    public function addressToProve(UserCredential $user): string
    {
        return (string) ($user->pending_email ?? $user->email);
    }

    /** True while a verified user has a new address waiting for its code. */
    public function hasPendingEmail(UserCredential $user): bool
    {
        return $user->pending_email !== null;
    }

    /**
     * Store a fresh code (resetting the attempt counter) and mail it.
     * The mail is best-effort: the code is saved first, so an SMTP outage
     * leaves "Resend" as the way forward instead of a failed registration.
     *
     * `$to` overrides the recipient; by default the code goes to the address
     * it proves (addressToProve), which is what every resend wants.
     */
    public function issue(UserCredential $user, ?string $to = null): void
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $user->forceFill([
            'verification_code' => $code,
            'verification_code_expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
            'verification_code_attempts' => 0,
        ])->save();

        $this->mail->verificationCode($user, $code, self::CODE_TTL_MINUTES, $to ?? $this->addressToProve($user));
    }

    /**
     * Check a code; one of the RESULT_* strings. On a miss against a live
     * code: count the attempt. An expired or exhausted code is refused
     * without counting.
     *
     * On a hit, the row decides what was proven: a pending address is applied
     * (or refused as taken); otherwise the row is marked verified and, if it
     * was not already, the team is told a sign-up is waiting for approval.
     */
    public function verify(UserCredential $user, string $code): string
    {
        if (! $this->isUsable($user)) {
            return self::RESULT_REJECTED;
        }

        if (! hash_equals((string) $user->verification_code, $code)) {
            $user->forceFill([
                'verification_code_attempts' => (int) $user->verification_code_attempts + 1,
            ])->save();

            return self::RESULT_REJECTED;
        }

        if ($this->hasPendingEmail($user)) {
            return $this->applyPendingEmail($user);
        }

        $wasVerified = (bool) $user->email_verified;

        $user->forceFill(['email_verified' => true] + $this->clearedCode())->save();

        // A row that was already verified had nothing to register — the notice
        // is for the approval queue, and it must not fire twice.
        if (! $wasVerified) {
            $via = $user->discord_id !== null ? AccountMail::VIA_DISCORD : AccountMail::VIA_PASSWORD;
            $this->mail->registrationPending($user, $via);
        }

        return self::RESULT_VERIFIED;
    }

    /**
     * Park a new address and mail it a code. `email` is untouched: the change
     * lands in verify() once the new inbox has proven itself. The caller has
     * already checked the password — this is the half after that proof.
     */
    public function beginEmailChange(UserCredential $user, string $newEmail): void
    {
        $user->forceFill(['pending_email' => $newEmail])->save();

        $this->issue($user, $newEmail);
    }

    /**
     * Drop an in-flight email change, code included. The row's `email` was
     * never touched, so there is nothing to restore.
     */
    public function cancelEmailChange(UserCredential $user): void
    {
        if (! $this->hasPendingEmail($user)) {
            return;
        }

        $user->forceFill(['pending_email' => null] + $this->clearedCode())->save();
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

    /**
     * The code was right and a new address is waiting: make it the live one.
     *
     * The uniqueness check runs AGAIN here, not only when the change was
     * requested — another sign-up may have registered the address in the
     * minutes between, and `pending_email` carries no unique index on purpose
     * (two users may be mid-change to the same inbox; only the first to prove
     * it gets it). A taken address clears the change rather than leaving a
     * code that can never succeed.
     *
     * Every OTHER session is revoked, the one typing the code kept: an email
     * change is a credential change, the same rule ProfileController applies
     * to a password change — a thief who got this far must not stay signed in
     * on the device they stole the token from.
     */
    private function applyPendingEmail(UserCredential $user): string
    {
        $newEmail = (string) $user->pending_email;

        $taken = UserCredential::query()
            ->where('email', $newEmail)
            ->where('uni_id', '!=', $user->uni_id)
            ->exists();

        if ($taken) {
            $user->forceFill(['pending_email' => null] + $this->clearedCode())->save();

            return self::RESULT_EMAIL_TAKEN;
        }

        $user->forceFill([
            'email' => $newEmail,
            'pending_email' => null,
            'email_verified' => true,
        ] + $this->clearedCode())->save();

        $keep = $user->currentAccessToken()?->id;
        $others = $user->tokens();
        if ($keep !== null) {
            $others->where('id', '!=', $keep);
        }
        $others->delete();

        return self::RESULT_EMAIL_CHANGED;
    }

    /** @return array<string, mixed> the code columns, cleared. */
    private function clearedCode(): array
    {
        return [
            'verification_code' => null,
            'verification_code_expires_at' => null,
            'verification_code_attempts' => 0,
        ];
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
