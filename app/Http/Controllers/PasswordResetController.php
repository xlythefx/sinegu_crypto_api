<?php

namespace App\Http\Controllers;

use App\Mail\PasswordResetCode;
use App\Models\UserCredential;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * Forgot-password by emailed 6-digit code, on the `reset_code*` columns the
 * user_credentials table has carried since it was ported.
 *
 * A code rather than a signed link because the frontend is a SPA on a
 * different origin than the API and the user may open the mail on their phone
 * while the form sits open on a laptop — a code is typed anywhere, a link has
 * to land in the right tab. Its weakness (six digits are guessable) is closed
 * by the two limits below, not by the code's length.
 */
class PasswordResetController extends Controller
{
    /** Minutes a code stays valid. Short, because the code is stored in clear. */
    public const CODE_TTL_MINUTES = 15;

    /**
     * Wrong guesses before the code is void. 5 attempts against 1,000,000 codes
     * is a 0.0005% chance — the per-IP throttle on the route makes even that
     * expensive to try.
     */
    public const MAX_ATTEMPTS = 5;

    /**
     * POST /api/auth/forgot-password
     *
     * Always answers 200 with the same message, whether or not the address is
     * registered: this endpoint must not be a way to test which emails have
     * accounts. (Login already reveals USER_NOT_FOUND — that is a separate,
     * older decision — but a reset endpoint is the one attackers script.)
     */
    public function forgot(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = UserCredential::where('email', $validated['email'])->first();

        if ($user) {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            $user->forceFill([
                'reset_code' => $code,
                'reset_code_expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
                'reset_code_attempts' => 0,
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
            && $user->reset_code !== null
            && $user->reset_code_expires_at !== null
            && now()->lessThan($user->reset_code_expires_at)
            && $user->reset_code_attempts < self::MAX_ATTEMPTS;

        if (! $usable) {
            return $this->invalidCode();
        }

        if (! hash_equals($user->reset_code, $validated['code'])) {
            $user->forceFill(['reset_code_attempts' => $user->reset_code_attempts + 1])->save();

            return $this->invalidCode();
        }

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
}
