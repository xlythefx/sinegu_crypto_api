<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Base for every Pixel Alpha email.
 *
 * Its one job is the preview subject: Admin → Sandbox → Email Templates sends
 * sample copies to whoever is reviewing the wording, and a reviewer's inbox
 * must never hold a "Your invoice is due" that reads as a real bill. Laravel
 * re-applies the envelope's subject on send, so a prefix cannot be bolted on
 * from outside — each envelope builds its subject through subjectLine().
 *
 * Every child takes SCALARS, never models: the same classes render from the
 * sample data in EmailCatalogue, and a template that can only be drawn from a
 * real DB row cannot be reviewed before it is sent.
 */
abstract class PixelMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $subjectPrefix = '';

    /** Mark this copy as a sample: "[Preview] …" in the subject. */
    public function asPreview(): static
    {
        $this->subjectPrefix = '[Preview] ';

        return $this;
    }

    protected function subjectLine(string $subject): string
    {
        return $this->subjectPrefix.$subject;
    }

    /** An absolute link into the site (the dashboard, a guide, the admin portal). */
    protected static function siteUrl(string $path = ''): string
    {
        return rtrim((string) config('mail.site_url'), '/').$path;
    }
}
