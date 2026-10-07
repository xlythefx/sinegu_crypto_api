<?php

namespace App\Http\Controllers;

use App\Services\Auth\EmailVerification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function __construct(private EmailVerification $verification)
    {
    }

    /**
     * PUT /api/user/profile (auth:sanctum)  { name, email, current_password? }
     *
     * The name saves outright. The EMAIL does not: a different address needs
     * the current password and then proves itself by the mailed code before
     * `email` moves (EmailVerificationController::verify). Until then the row
     * carries it as `pending_email` and the OLD address keeps every login and
     * guarded route — so a typo cannot lock the owner out, and a stolen bearer
     * token (the SPA keeps it in localStorage) cannot re-point the account at
     * an attacker's inbox and collect it through forgot-password.
     *
     * 200 { success, message, user }  — name saved; and, for a new address, a code mailed to it
     * 422 PASSWORD_REQUIRED | NO_PASSWORD | INVALID_PASSWORD — nothing saved, the name included
     * 409 RESEND_TOO_SOON { retry_after } — a code went out under a minute ago
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('user_credentials', 'email')->ignore($user->uni_id, 'uni_id'),
            ],
            'current_password' => ['nullable', 'string'],
        ]);

        $newEmail = trim($validated['email']);
        $emailChanges = strcasecmp($newEmail, trim((string) $user->email)) !== 0;

        if ($emailChanges) {
            if ($refusal = $this->refuseEmailChange($user, $validated['current_password'] ?? null)) {
                return $refusal;
            }
        }

        $user->forceFill(['name' => $validated['name']])->save();

        if (! $emailChanges) {
            // Back on the live address: whatever change was in flight is off.
            $this->verification->cancelEmailChange($user);

            return $this->profileSaved($user, 'Profile updated');
        }

        $this->verification->beginEmailChange($user, $newEmail);

        return $this->profileSaved($user, "We sent a code to {$newEmail}. Your email changes once you enter it.");
    }

    /**
     * DELETE /api/user/email/pending (auth:sanctum)
     *
     * Drop an email change that has not been proven. `email` was never
     * touched, so this is a cancel, not a revert.
     */
    public function cancelPendingEmail(Request $request): JsonResponse
    {
        $user = $request->user();

        $this->verification->cancelEmailChange($user);

        return $this->profileSaved($user, 'Email change cancelled');
    }

    /**
     * Why a new address may not be parked yet, or null to go ahead.
     *
     * The password comes BEFORE anything is written so a refusal leaves the
     * row exactly as it was. A Discord-only account has no password to prove
     * with, and is told to set one first (the same NO_PASSWORD the Settings
     * page already handles for change-password). The send cooldown is the
     * one resend enforces: without it this route would mail a code to any
     * address on every request, a mail bomb aimed from a logged-in account.
     */
    private function refuseEmailChange($user, ?string $currentPassword): ?JsonResponse
    {
        if (! $user->hasPassword()) {
            return response()->json([
                'success' => false,
                'error_code' => 'NO_PASSWORD',
                'message' => 'Set a password before changing your email.',
            ], 422);
        }

        if ($currentPassword === null || $currentPassword === '') {
            return response()->json([
                'success' => false,
                'error_code' => 'PASSWORD_REQUIRED',
                'message' => 'Enter your current password to change your email.',
            ], 422);
        }

        if (! Hash::check($currentPassword, $user->password)) {
            return response()->json([
                'success' => false,
                'error_code' => 'INVALID_PASSWORD',
                'message' => 'Current password is incorrect.',
            ], 422);
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

        return null;
    }

    private function profileSaved($user, string $message): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'user' => $user->toAuthPayload(),
        ]);
    }

    /**
     * POST /api/user/image (auth:sanctum)
     * Upload a profile or banner image, store it on the public disk, and
     * persist its path to user_credentials. Replaces any previous image.
     */
    public function uploadImage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(['profile', 'banner'])],
            'image' => ['required', 'image', 'mimes:jpeg,jpg,png,gif,webp', 'max:5120'],
        ]);

        $user = $request->user();
        $column = $validated['type'] === 'banner' ? 'user_banner' : 'user_profile';

        // Remove the previous file if it was a stored path (not an external URL)
        $previous = $user->getRawOriginal($column);
        if ($previous && ! preg_match('#^(https?://|data:)#i', $previous)) {
            Storage::disk('public')->delete($previous);
        }

        $path = $request->file('image')->store("{$validated['type']}s", 'public');

        $user->forceFill([$column => $path])->save();

        return response()->json([
            'success' => true,
            'message' => ucfirst($validated['type']) . ' image updated',
            'user' => $user->toAuthPayload(),
        ]);
    }

    /**
     * PUT /api/user/password (auth:sanctum)
     */
    public function updatePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $request->user();

        if (! $user->hasPassword()) {
            // A Discord-only account has nothing to compare against; the
            // "set a password" route is the one for it.
            return response()->json([
                'success' => false,
                'error_code' => 'NO_PASSWORD',
                'message' => 'This account has no password yet. Set one first.',
            ], 422);
        }

        if (! Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'success' => false,
                'error_code' => 'INVALID_PASSWORD',
                'message' => 'Current password is incorrect.',
            ], 422);
        }

        $this->storePassword($request, $validated['new_password']);

        return response()->json([
            'success' => true,
            'message' => 'Password updated',
        ]);
    }

    /**
     * POST /api/user/password/set (auth:sanctum)
     *
     * The first password of an account that was created through Discord.
     * Being signed in is the proof — there is no current password to ask
     * for — and it is allowed ONLY while the column is NULL: once a password
     * exists, changing it goes through updatePassword() and its check.
     */
    public function setPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $request->user();

        if ($user->hasPassword()) {
            return response()->json([
                'success' => false,
                'error_code' => 'HAS_PASSWORD',
                'message' => 'This account already has a password. Use "Change password" instead.',
            ], 409);
        }

        $this->storePassword($request, $validated['password']);

        return response()->json([
            'success' => true,
            'message' => 'Password set',
            'user' => $user->toAuthPayload(),
        ]);
    }

    /** Save the new password and revoke every other session, keeping this one. */
    private function storePassword(Request $request, string $password): void
    {
        $user = $request->user();

        // The model's 'hashed' cast hashes this on save (same as register()).
        $user->password = $password;
        $user->save();

        $user->tokens()
            ->where('id', '!=', $request->user()->currentAccessToken()->id)
            ->delete();
    }
}
