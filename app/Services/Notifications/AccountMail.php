<?php

namespace App\Services\Notifications;

use App\Mail\AccountApproved;
use App\Mail\EmailVerificationCode;
use App\Mail\NewRegistrationNotice;
use App\Models\UserCredential;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The account-lifecycle emails: the sign-up's verification code, the admin's
 * "someone is waiting" notice and the customer's "you're approved".
 *
 * BEST-EFFORT, like EngineCache and DiscordRoleSync. A registration that 500s
 * because an SMTP host was unreachable loses the account the user just created;
 * an approval that fails the same way leaves an admin unsure whether the status
 * changed. The status change is the fact, the email is the notification — so a
 * failure is logged and swallowed, never propagated.
 *
 * Every send is synchronous for the reason in the Mailables: no queue worker
 * runs on either box.
 */
class AccountMail
{
    /** How a row was created, as the admin notice prints it. */
    public const VIA_PASSWORD = 'Email + password';

    public const VIA_DISCORD = 'Discord';

    /**
     * Tell the admin desk that a pending registration is waiting.
     * Silent (but logged) when no admin address is configured.
     */
    public function registrationPending(UserCredential $user, string $via): void
    {
        $to = self::teamRecipients();

        if ($to === []) {
            Log::warning('AccountMail: no mail.admin_address configured — registration notice skipped.', [
                'uni_id' => $user->uni_id,
            ]);

            return;
        }

        $this->attempt('registration-notice', $user->uni_id, fn () => Mail::to($to)->send(
            new NewRegistrationNotice(
                name: (string) $user->name,
                email: (string) $user->email,
                uniId: (string) $user->uni_id,
                via: $via,
                // The DB runs on UTC and an email has no reader to localise
                // for, so the zone is named rather than implied.
                registeredAt: ($user->created_at ?? now())->format('d M Y, H:i').' UTC',
            )
        ));
    }

    /**
     * Who receives team notices: MAIL_ADMIN_ADDRESS, a comma-separated list
     * (support@ plus whoever else on the team asked to be told). Anything that
     * is not a valid address is dropped rather than failing every send.
     *
     * @return list<string>
     */
    public static function teamRecipients(): array
    {
        $list = array_map('trim', explode(',', (string) config('mail.admin_address')));

        return array_values(array_unique(array_filter(
            $list,
            fn (string $a) => filter_var($a, FILTER_VALIDATE_EMAIL) !== false,
        )));
    }

    /** Tell the user their account was approved. Nothing is sent on rejection. */
    public function approved(UserCredential $user): void
    {
        $to = trim((string) $user->email);

        if ($to === '') {
            return;
        }

        $this->attempt('account-approved', $user->uni_id, fn () => Mail::to($to)->send(
            new AccountApproved(name: (string) $user->name)
        ));
    }

    /**
     * Mail a sign-up their email-verification code. Best-effort like the rest:
     * the code is stored before this runs, so a mail outage leaves the user on
     * the code screen with "Resend" — never a 500 that loses the registration.
     *
     * `$to` is the address being PROVEN, which for an email change is the new
     * one, not the row's — the code must land in the inbox whose ownership it
     * establishes, or the old inbox could vouch for an address it never saw.
     */
    public function verificationCode(UserCredential $user, string $code, int $ttlMinutes, ?string $to = null): void
    {
        $to = trim((string) ($to ?? $user->email));

        if ($to === '') {
            return;
        }

        $this->attempt('email-verification-code', $user->uni_id, fn () => Mail::to($to)->send(
            new EmailVerificationCode(name: (string) $user->name, code: $code, ttlMinutes: $ttlMinutes)
        ));
    }

    private function attempt(string $what, ?string $uniId, callable $send): void
    {
        try {
            $send();
        } catch (Throwable $e) {
            Log::warning("AccountMail: {$what} failed to send.", [
                'uni_id' => $uniId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
