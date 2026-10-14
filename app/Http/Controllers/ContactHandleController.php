<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateContactHandlesRequest;
use App\Services\Signup\ContactHandles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Settings → the optional Telegram / Discord usernames a user first gave at
 * sign-up (auth:sanctum + account.active + email.verified). Typed by the
 * user and never verified; the staff read them on admin user detail
 * (AdminSignupInfoController). Normalisation and storage live in
 * ContactHandles, shared with both sign-up forms.
 */
class ContactHandleController extends Controller
{
    public function __construct(private ContactHandles $contacts) {}

    /**
     * GET /api/user/contacts
     * { success, contacts: { telegram: string|null, discord: string|null } }
     */
    public function show(Request $request): JsonResponse
    {
        return $this->contactsResponse($this->contacts->forUser($request->user()->uni_id));
    }

    /**
     * PUT /api/user/contacts  { telegram?, discord? }
     * Absent = unchanged, null / '' = cleared. Answers what is stored now,
     * in GET's shape. 422 with a field error for a username the platform
     * would not accept.
     */
    public function update(UpdateContactHandlesRequest $request): JsonResponse
    {
        return $this->contactsResponse(
            $this->contacts->save($request->user()->uni_id, $request->validated())
        );
    }

    private function contactsResponse(array $contacts): JsonResponse
    {
        return response()->json([
            'success' => true,
            'contacts' => [
                'telegram' => $contacts[ContactHandles::TELEGRAM],
                'discord' => $contacts[ContactHandles::DISCORD],
            ],
        ]);
    }
}
