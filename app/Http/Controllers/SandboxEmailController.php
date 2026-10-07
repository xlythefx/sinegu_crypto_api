<?php

namespace App\Http\Controllers;

use App\Services\Notifications\AccountMail;
use App\Services\Notifications\EmailCatalogue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Admin → Sandbox → Email Templates: every email the product sends, rendered
 * from the real Mailables with sample data, plus "send me a copy" so the
 * wording can be approved in an actual inbox.
 *
 * A test copy is always a sample: "[Preview]" in the subject and invented
 * figures in the body. Nothing here reads or mails a real customer.
 *
 * And it goes to a REVIEWER only: the admin pressing the button, or a team
 * mailbox (MAIL_PREVIEW_DOMAINS). One call with no slug mails every template
 * — a dozen emails from support@ — to whatever address it is given, so an
 * unrestricted recipient would turn any admin login into a spam relay
 * signed by the product.
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

        if (! $this->recipientAllowed($data['to'], $request->user()?->email)) {
            return response()->json([
                'success' => false,
                'error_code' => 'RECIPIENT_NOT_ALLOWED',
                'message' => 'A preview can only be sent to your own address or a team mailbox ('
                    .implode(', ', $this->previewDomains()).').',
            ], 422);
        }

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

    /**
     * The caller's own address, or any address on a team domain. Compared
     * lower-cased, on the whole address and on the part after the LAST "@"
     * (the validator already guarantees there is one) — so a look-alike such
     * as pixel-alpha.com.evil.example is a different domain, not a match.
     */
    private function recipientAllowed(string $to, ?string $own): bool
    {
        $to = strtolower(trim($to));
        if ($own !== null && $to === strtolower(trim($own))) {
            return true;
        }

        $domain = strtolower(Str::afterLast($to, '@'));

        return $domain !== '' && in_array($domain, $this->previewDomains(), true);
    }

    /** @return string[] config('mail.preview_domains'), trimmed and lower-cased */
    private function previewDomains(): array
    {
        return array_values(array_filter(array_map(
            fn ($domain) => strtolower(trim((string) $domain)),
            (array) config('mail.preview_domains', []),
        )));
    }
}
