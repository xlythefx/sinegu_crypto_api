<?php

namespace App\Http\Controllers;

use App\Http\Requests\VerifyEmailRequest;
use App\Services\Auth\EmailVerification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The signed-in half of sign-up: prove the address by typing the mailed code.
 * Both routes sit under auth:sanctum but OUTSIDE the email.verified gate —
 * they are the only way through it.
 *
 * The same two routes finish an EMAIL CHANGE (ProfileController::updateProfile
 * parks the new address as `pending_email` and mails it a code): a verified
 * user with a change in flight is not "already verified" here, and the code
 * they type moves `email` to the new address.
 */
class EmailVerificationController extends Controller
{
    public function __construct(private EmailVerification $verification)
    {
    }

    /**
     * POST /api/auth/email/verify  { code }
     *
     * 200 { success, message, user }  — verified, or the pending email applied (idempotent for an already-verified user)
     * 422 { success:false, error_code:'INVALID_CODE', code:'INVALID_CODE', message, attempts_left, expired }
     * 422 { success:false, error_code:'EMAIL_TAKEN', code:'EMAIL_TAKEN', message, user } — right code, but the
     *     pending address was registered by someone else meanwhile; the change is dropped
     */
    public function verify(VerifyEmailRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($this->nothingToProve($user)) {
            return $this->verified($user, 'Email already verified.');
        }

        $result = $this->verification->verify($user, $request->validated('code'));

        if ($result === EmailVerification::RESULT_VERIFIED) {
            return $this->verified($user->refresh(), 'Email verified.');
        }

        if ($result === EmailVerification::RESULT_EMAIL_CHANGED) {
            return $this->verified($user->refresh(), 'Your email address has been updated.');
        }

        if ($result === EmailVerification::RESULT_EMAIL_TAKEN) {
            return response()->json([
                'success' => false,
                'error_code' => 'EMAIL_TAKEN',
                'code' => 'EMAIL_TAKEN',
                'message' => 'That email address is now used by another account. Your email was not changed.',
                'user' => $user->refresh()->toAuthPayload(),
            ], 422);
        }

        $user->refresh();
        $expired = $this->verification->isExpired($user);
        $attemptsLeft = $expired ? 0 : $this->verification->attemptsLeft($user);

        $message = match (true) {
            $expired => 'That code has expired. Request a new one.',
            $attemptsLeft === 0 => 'Too many wrong attempts. Request a new code.',
            default => 'That code is not correct.',
        };

        return response()->json([
            'success' => false,
            'error_code' => 'INVALID_CODE',
            'code' => 'INVALID_CODE',
            'message' => $message,
            'attempts_left' => $attemptsLeft,
            'expired' => $expired,
        ], 422);
    }

    /**
     * POST /api/auth/email/resend
     *
     * 200 { success, message, retry_after: 60 }   — a fresh code was sent (to the pending address, when one is set)
     * 200 { success, message, user }              — already verified, nothing sent
     * 409 { success:false, error_code:'RESEND_TOO_SOON', code:'RESEND_TOO_SOON', message, retry_after }
     */
    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($this->nothingToProve($user)) {
            return $this->verified($user, 'Email already verified.');
        }

        $wait = $this->verification->resendWaitSeconds($user);
        if ($wait > 0) {
            return response()->json([
                'success' => false,
                'error_code' => 'RESEND_TOO_SOON',
                'code' => 'RESEND_TOO_SOON',
                'message' => "Please wait {$wait} seconds before requesting another code.",
                'retry_after' => $wait,
            ], 409);
        }

        $this->verification->issue($user);

        return response()->json([
            'success' => true,
            'message' => 'A new code is on its way.',
            'retry_after' => EmailVerification::RESEND_COOLDOWN_SECONDS,
        ]);
    }

    /**
     * A verified row with no change in flight has no address left to prove;
     * a verified row WITH one is here to finish that change.
     */
    private function nothingToProve($user): bool
    {
        return $user->email_verified && ! $this->verification->hasPendingEmail($user);
    }

    private function verified($user, string $message): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'user' => $user->toAuthPayload(),
        ]);
    }
}
