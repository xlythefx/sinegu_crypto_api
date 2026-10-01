<?php

namespace App\Http\Controllers;

use App\Models\UserCredential;
use App\Services\Auth\EmailVerification;
use App\Services\ReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function __construct(
        private ReferralService $referrals,
        private EmailVerification $verification,
    ) {
    }

    /**
     * POST /api/auth/register
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:user_credentials,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            // Deliberately NOT exists-validated: invalid referral codes are
            // silently ignored and registration proceeds (affiliate spec §2).
            'referral_code' => ['nullable', 'string', 'max:50'],
            // The Terms checkbox. `accepted` means it must be present AND true
            // — a missing field is a refusal, not a default yes. The version
            // is the "Last updated" date the form showed, so the row records
            // which text was agreed to, not merely that a box was ticked.
            'terms' => ['accepted'],
            'terms_version' => ['nullable', 'string', 'max:32'],
        ], [
            'terms.accepted' => 'You must accept the Terms and Conditions to create an account.',
        ]);

        $user = DB::transaction(function () use ($validated) {
            $user = UserCredential::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                // Proven by the mailed code (POST /auth/email/verify); until
                // then every guarded route answers 403 EMAIL_UNVERIFIED.
                'email_verified' => false,
                'terms_accepted_at' => now(),
                'terms_version' => $validated['terms_version'] ?? null,
                // New sign-ups wait in the admin approval queue (accept → active,
                // reject → suspended). Pending users can log in but are gated.
                'status' => 'pending',
            ]);

            $this->referrals->bind($validated['referral_code'] ?? null, $user->uni_id);

            return $user;
        });

        // Mail the verification code — after COMMIT, and best-effort: a mail
        // failure must not undo a registration ("Resend" is the way on). The
        // team's "someone registered" notice waits until the code is accepted
        // (EmailVerification::verify), so an address nobody proved never
        // reaches the approval desk.
        $this->verification->issue($user);

        // The token is still issued: the account exists, and the code screen
        // needs a session to know whose code is being typed.
        $token = $user->createToken('spa')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Account created',
            'token' => $token,
            'user' => $this->userPayload($user),
        ], 201);
    }

    /**
     * POST /api/auth/login
     *
     * Error codes mirror the mother API (USER_NOT_FOUND, INVALID_PASSWORD,
     * ACCOUNT_SUSPENDED) so the frontend can branch on them.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = UserCredential::where('email', $validated['email'])->first();

        if (! $user) {
            return response()->json([
                'success' => false,
                'error_code' => 'USER_NOT_FOUND',
                'message' => 'No account found with this email address',
            ], 401);
        }

        // A Discord-only account has no password to compare — say so, rather
        // than "incorrect password" against a hash that does not exist.
        if (! $user->hasPassword()) {
            return response()->json([
                'success' => false,
                'error_code' => 'DISCORD_ONLY',
                'message' => 'This account signs in with Discord. Use "Continue with Discord" below.',
            ], 401);
        }

        if (! Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'success' => false,
                'error_code' => 'INVALID_PASSWORD',
                'message' => 'Incorrect password. Please try again.',
            ], 401);
        }

        // Suspended accounts are blocked entirely; pending may still log in
        // (frontend gates broker features until an admin approves).
        if ($user->status === 'suspended') {
            return response()->json([
                'success' => false,
                'error_code' => 'ACCOUNT_SUSPENDED',
                'message' => 'Your account has been suspended. Please contact support.',
            ], 403);
        }

        $user->forceFill(['last_activity' => now()])->save();

        $token = $user->createToken('spa')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'token' => $token,
            'user' => $this->userPayload($user),
        ]);
    }

    /**
     * GET /api/auth/me (auth:sanctum)
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'user' => $this->userPayload($request->user()),
        ]);
    }

    /**
     * POST /api/auth/logout (auth:sanctum)
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['success' => true, 'message' => 'Logged out']);
    }

    private function userPayload(UserCredential $user): array
    {
        return $user->toAuthPayload();
    }
}
