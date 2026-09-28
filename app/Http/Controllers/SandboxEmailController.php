<?php

namespace App\Http\Controllers;

use App\Services\Notifications\AccountMail;
use App\Services\Notifications\EmailCatalogue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Admin → Sandbox → Email Templates: every email the product sends, rendered
 * from the real Mailables with sample data, plus "send me a copy" so the
 * wording can be approved in an actual inbox.
 *
 * A test copy is always a sample: "[Preview]" in the subject and invented
 * figures in the body. Nothing here reads or mails a real customer.
 */
class SandboxEmailController extends Controller
{
    /** GET /admin/sandbox/emails — the catalogue, each entry rendered. */
    public function index(): JsonResponse
    {
        $emails = array_map(fn (array $e) => [
            'slug' => $e['slug'],
            'audience' => $e['audience'],
            'title' => $e['title'],
            'trigger' => $e['trigger'],
            'to' => $e['to'],
            'live' => $e['live'],
            'note' => $e['note'],
            'subject' => (string) $e['mail']->envelope()->subject,
            'html' => $e['mail']->render(),
        ], EmailCatalogue::all());

        return response()->json([
            'success' => true,
            'emails' => $emails,
            'from' => (string) config('mail.from.address'),
            'team_recipients' => AccountMail::teamRecipients(),
            // `log` means a "send" is written to the log, not delivered — the
            // page says so rather than reporting a send that went nowhere.
            'delivers' => config('mail.default') !== 'log' && config('mail.default') !== 'array',
        ]);
    }

    /**
     * POST /admin/sandbox/emails/send { to, slug? } — mail a preview copy of
     * one template, or of every template when no slug is given.
     */
    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'to' => ['required', 'email:filter', 'max:190'],
            'slug' => ['nullable', 'string', 'max:64'],
        ]);

        $entries = EmailCatalogue::all();
        if (! empty($data['slug'])) {
            $entries = array_values(array_filter($entries, fn ($e) => $e['slug'] === $data['slug']));
            if ($entries === []) {
                return response()->json(['success' => false, 'message' => 'Unknown email template.'], 404);
            }
        }

        $sent = 0;
        foreach ($entries as $e) {
            try {
                Mail::to($data['to'])->send($e['mail']->asPreview());
                $sent++;
            } catch (Throwable $ex) {
                Log::warning('SandboxEmail: preview send failed.', ['slug' => $e['slug'], 'error' => $ex->getMessage()]);

                return response()->json([
                    'success' => false,
                    'sent' => $sent,
                    'message' => "Sending \"{$e['title']}\" failed: the mail server did not accept it.",
                ], 502);
            }
        }

        return response()->json([
            'success' => true,
            'sent' => $sent,
            'delivers' => config('mail.default') !== 'log' && config('mail.default') !== 'array',
        ]);
    }
}
