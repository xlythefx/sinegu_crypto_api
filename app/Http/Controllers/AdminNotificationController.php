<?php

namespace App\Http\Controllers;

use App\Services\Admin\AdminAudit;
use App\Services\Notifications\TeamNotices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin → Notifications (Dima, 2026-11-23): per team email, on/off and who
 * receives it. Full admins only (the `admin` route group). TeamNotices holds
 * the rules; this only validates and saves.
 */
class AdminNotificationController extends Controller
{
    public function __construct(private AdminAudit $audit) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'notices' => TeamNotices::all(),
            'delivers' => config('mail.default') !== 'log' && config('mail.default') !== 'array',
        ]);
    }

    /**
     * PUT /admin/notifications/{slug} {enabled, recipients: string[] | null}
     * recipients null = back to the server's default list.
     */
    public function update(Request $request, string $slug): JsonResponse
    {
        if (! TeamNotices::exists($slug)) {
            return response()->json(['success' => false, 'error_code' => 'NOTICE_NOT_FOUND', 'message' => 'No such notice.'], 404);
        }
        $request->validate([
            'enabled' => ['required', 'boolean'],
            'recipients' => ['present', 'nullable', 'array', 'max:'.TeamNotices::MAX_RECIPIENTS],
            'recipients.*' => ['string', 'max:191'],
        ]);
        $notice = TeamNotices::NOTICES[$slug];
        $enabled = $request->boolean('enabled');
        $raw = $request->input('recipients');
        $recipients = null;

        if ($raw !== null) {
            $bad = array_values(array_filter(
                (array) $raw,
                fn ($a) => filter_var(trim((string) $a), FILTER_VALIDATE_EMAIL) === false,
            ));
            if ($bad !== []) {
                return response()->json([
                    'success' => false,
                    'error_code' => 'INVALID_EMAIL',
                    'message' => 'Not an email address: '.implode(', ', array_slice($bad, 0, 3)),
                    'errors' => ['recipients' => ['Not an email address: '.implode(', ', array_slice($bad, 0, 3))]],
                ], 422);
            }
            $recipients = TeamNotices::clean($raw);
        }

        if ($notice['critical']) {
            if (! $enabled) {
                return response()->json([
                    'success' => false,
                    'error_code' => 'NOTICE_CRITICAL',
                    'message' => 'This alert cannot be switched off: it is how a failed close or a serious fault reaches a person.',
                ], 422);
            }
            $effective = $recipients ?? TeamNotices::defaultList($slug);
            if ($effective === []) {
                return response()->json([
                    'success' => false,
                    'error_code' => 'NOTICE_NEEDS_RECIPIENT',
                    'message' => 'This alert needs at least one email address.',
                ], 422);
            }
        }

        $before = collect(TeamNotices::all())->firstWhere('slug', $slug);
        TeamNotices::save($slug, $enabled, $recipients, $request->user()?->uni_id);
        $after = collect(TeamNotices::all())->firstWhere('slug', $slug);

        $this->audit->record($request->user()?->uni_id, null, 'notification.updated',
            'Notification "'.$notice['title'].'": '.($after['enabled'] ? 'on' : 'off').', to '
                .($after['recipients'] === [] ? 'nobody' : implode(', ', $after['recipients'])),
            ['slug' => $slug, 'before' => ['enabled' => $before['enabled'], 'recipients' => $before['recipients']],
                'after' => ['enabled' => $after['enabled'], 'recipients' => $after['recipients']]],
        );

        return response()->json(['success' => true, 'notice' => $after]);
    }
}
