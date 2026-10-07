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
     * A real bcrypt hash of a throwaway string, checked when the email is
     * UNKNOWN so that branch costs the same as a wrong password. bcrypt is
     * deliberately slow; skipping it for a missing row would make "no such
     * account" measurable from the response time even though the body no
     * longer says it. Cost 12 — the rounds real rows are stored at.
     */
    private const DUMMY_HASH = '$2y$12$geVBqL2VdtbjCcjo84G1M.WclN7fr5WPV4.unGwAkvK3BytKSxDo2';

    /**
     * POST /api/auth/login
     *
     * Error codes the frontend branches on: INVALID_CREDENTIALS, DISCORD_ONLY,
     * ACCOUNT_SUSPENDED. The mother API's USER_NOT_FOUND / INVALID_PASSWORD
     * pair was mirrored until 2026-10-07; it told anyone holding a list of
     * addresses which of them have an account here — an account that holds
     * exchange API keys — so the two are now ONE answer: same code, same
     * message, same bcrypt cost (DUMMY_HASH). The per-account limiter on the
     * route (`throttle:login`) is the brake on guessing against that answer.
     *
     * DISCORD_ONLY stays distinct on purpose. A Discord-only row has no hash
     * to check, and "use the Discord button" is the one thing that user needs
     * to hear; the hint it leaks — this email is Discord-linked — is small
     * beside sending them round a password form that can never work.
     * ACCOUNT_SUSPENDED is only reached after a correct password, so it tells
     * a stranger nothing.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = UserCredential::where('email', $validated['email'])->first();

        if (! $user) {
            // Spend the bcrypt either way — see DUMMY_HASH.
            Hash::check($validated['password'], self::DUMMY_HASH);

            return $this->invalidCredentials();
        }

        // A Discord-only account has no password to compare — say so, rather
        // than "incorrect" against a hash that does not exist.
        if (! $user->hasPassword()) {
            return response()->json([
                'success' => false,
                'error_code' => 'DISCORD_ONLY',
                'message' => 'This account signs in with Discord. Use "Continue with Discord" below.',
            ], 401);
        }

        if (! Hash::check($validated['password'], $user->password)) {
            return $this->invalidCredentials();
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

    /** The one refusal for unknown-email and wrong-password alike (see login()). */
    private function invalidCredentials(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error_code' => 'INVALID_CREDENTIALS',
            'message' => 'Email or password is incorrect.',
        ], 401);
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
