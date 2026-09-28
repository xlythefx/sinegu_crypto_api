<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The six-digit password reset code. Sent synchronously — the user is
 * staring at the "check your email" screen, and a queued mail on a box with
 * no queue worker is a mail that never arrives.
 */
class PasswordResetCode extends PixelMail
{
    public function __construct(
        public string $name,
        public string $code,
        public int $ttlMinutes,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine('Your Pixel Alpha password reset code'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.password-reset-code');
    }
}
