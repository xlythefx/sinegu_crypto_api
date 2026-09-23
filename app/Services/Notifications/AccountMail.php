<?php

namespace App\Services\Notifications;

use App\Mail\AccountApproved;
use App\Mail\NewRegistrationNotice;
use App\Models\UserCredential;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The two account-lifecycle emails: the admin's "someone is waiting" notice and
 * the customer's "you're approved".
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
        $to = trim((string) config('mail.admin_address'));

        if ($to === '') {
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
