<?php

namespace App\Http\Controllers;

use App\Models\UserAccessEvent;
use App\Models\UserCredential;
use App\Services\Auth\AccessLog;
use App\Services\Signup\ContactHandles;
use App\Services\Signup\SignupSources;
use Illuminate\Http\JsonResponse;

/**
 * Admin user detail → sign-up details (auth:sanctum + staff middleware).
 *
 * How the user found us, the Telegram / Discord handles they gave, and the
 * device "fingerprint" of the registration and of every login since
 * (AccessLog). In the STAFF group on purpose: the owner asked that every
 * admin role see it, collaborators included, IP addresses included. Users
 * who registered before 2026-10-14 have none of it on record — every field
 * then reads null / empty rather than being guessed.
 */
class AdminSignupInfoController extends Controller
{
    /** Newest access events returned — the registration plus recent logins. */
    private const EVENTS_LIMIT = 25;

    public function __construct(
        private SignupSources $sources,
        private ContactHandles $contacts,
    ) {}

    /**
     * GET /api/admin/users/{uniId}/signup-info
     *
     * { success, signup: { registered_at, method, sources, contacts, first }, events }
     * `first` is the registration event; `events` are newest first.
     */
    public function show(string $uniId): JsonResponse
    {
        $user = UserCredential::find($uniId);
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found.',
            ], 404);
        }

        $first = UserAccessEvent::where('uni_id', $uniId)
            ->where('event', AccessLog::EVENT_REGISTER)
            ->orderBy('id')
            ->first();

        $events = UserAccessEvent::where('uni_id', $uniId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::EVENTS_LIMIT)
            ->get();

        $contacts = $this->contacts->forUser($uniId);

        return response()->json([
            'success' => true,
            'signup' => [
                'registered_at' => $user->created_at?->toISOString(),
                'method' => $first?->method,
                'sources' => $this->sources->forUser($uniId),
                'contacts' => [
                    'telegram' => $contacts[ContactHandles::TELEGRAM],
                    'discord' => $contacts[ContactHandles::DISCORD],
                    // The Discord account actually LINKED for sign-in — proven
                    // by OAuth, unlike the typed handle beside it.
                    'discord_linked' => $user->hasDiscord() ? $user->discord_username : null,
                ],
                'first' => $first?->toPayload(),
            ],
            'events' => $events->map(fn (UserAccessEvent $e) => $e->toPayload())->values(),
        ]);
    }
}
