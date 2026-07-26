<?php

namespace App\Http\Controllers;

use App\Models\UserCredential;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * POST /api/auth/register
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:user_credentials,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = UserCredential::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            // Local dev: skip the email-verification flow for now.
            'email_verified' => true,
            // New sign-ups wait in the admin approval queue (accept → active,
            // reject → suspended). Pending users can log in but are gated.
            'status' => 'pending',
        ]);

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
