<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateContactHandlesRequest;
use App\Services\Signup\ContactHandles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Settings → the Telegram / Discord usernames and WhatsApp number a user first
 * gave at sign-up (auth:sanctum + account.active + email.verified). Telegram
 * and WhatsApp are required (ContactHandles::REQUIRED) — changed here, never
 * cleared. Typed by the user and never verified; the staff read them on admin
 * user detail (AdminSignupInfoController). Normalisation and storage live in
 * ContactHandles, shared with both sign-up forms.
 */
class ContactHandleController extends Controller
{
    public function __construct(private ContactHandles $contacts) {}

    /**
     * GET /api/user/contacts
     * { success, contacts: { telegram, discord, whatsapp }, missing: string[],
     *   connect_locked: bool }
     * `missing` = the required ones not given yet; `connect_locked` = this
     * user may not connect an exchange until they are (customers only). The
     * dashboard's reminder and the connect page's lock read these two, so
     * the rule lives here and nowhere in the client.
     */
    public function show(Request $request): JsonResponse
    {
        return $this->contactsResponse($request, $this->contacts->forUser($request->user()->uni_id));
    }

    /**
     * PUT /api/user/contacts  { telegram?, discord?, whatsapp? }
     * Absent = unchanged; null / '' clears Discord and is refused for the
     * required two. Answers what is stored now, in GET's shape. 422 with a
     * field error for a value the platform would not accept.
     */
    public function update(UpdateContactHandlesRequest $request): JsonResponse
    {
        return $this->contactsResponse(
            $request,
            $this->contacts->save($request->user()->uni_id, $request->validated())
        );
    }

    private function contactsResponse(Request $request, array $contacts): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'contacts' => [
                'telegram' => $contacts[ContactHandles::TELEGRAM],
                'discord' => $contacts[ContactHandles::DISCORD],
                'whatsapp' => $contacts[ContactHandles::WHATSAPP],
            ],
            'missing' => $this->contacts->missingFor($user->uni_id, $contacts),
            'connect_locked' => $this->contacts->connectLocked($user, $contacts),
        ]);
    }
}
