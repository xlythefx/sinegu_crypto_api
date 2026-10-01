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
 */
class EmailVerificationController extends Controller
{
    public function __construct(private EmailVerification $verification)
    {
    }

    /**
     * POST /api/auth/email/verify  { code }
     *
     * 200 { success, message, user }  — verified (idempotent for an already-verified user)
     * 422 { success:false, error_code:'INVALID_CODE', code:'INVALID_CODE', message, attempts_left, expired }
     */
    public function verify(VerifyEmailRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($user->email_verified) {
            return $this->verified($user, 'Email already verified.');
        }

        if ($this->verification->verify($user, $request->validated('code'))) {
            return $this->verified($user->refresh(), 'Email verified.');
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
     * 200 { success, message, retry_after: 60 }   — a fresh code was sent
     * 200 { success, message, user }              — already verified, nothing sent
     * 409 { success:false, error_code:'RESEND_TOO_SOON', code:'RESEND_TOO_SOON', message, retry_after }
     */
    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->email_verified) {
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

    private function verified($user, string $message): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'user' => $user->toAuthPayload(),
        ]);
    }
}
