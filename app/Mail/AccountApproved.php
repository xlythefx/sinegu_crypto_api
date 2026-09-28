<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "An admin approved you" — the one email that tells a customer the wait is
 * over. Sent on the pending → active transition only; a rejection deliberately
 * sends nothing.
 *
 * Sent synchronously, like every other mail here: the box runs no queue worker,
 * and a queued mail nobody processes is a mail that never arrives.
 */
class AccountApproved extends PixelMail
{
    public function __construct(public string $name)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine('Your Pixel Alpha account is approved'));
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.account-approved',
            with: [
                'dashboardUrl' => self::siteUrl('/dashboard'),
                'guideUrl' => self::siteUrl('/docs/binance'),
            ],
        );
    }
}
