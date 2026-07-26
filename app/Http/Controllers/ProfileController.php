<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    /**
     * PUT /api/user/profile (auth:sanctum)
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
        ]);

        $user->fill($validated)->save();

        return response()->json([
            'success' => true,
            'message' => 'Profile updated',
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

        if (! Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'success' => false,
                'error_code' => 'INVALID_PASSWORD',
                'message' => 'Current password is incorrect.',
            ], 422);
        }

        // The model's 'hashed' cast hashes this on save (same as register()).
        $user->password = $validated['new_password'];
        $user->save();

        // Revoke every other session; keep the token making this request.
        $user->tokens()
            ->where('id', '!=', $request->user()->currentAccessToken()->id)
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'Password updated',
        ]);
    }
}
