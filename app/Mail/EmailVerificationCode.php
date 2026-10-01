<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The six-digit code that proves a new sign-up owns the address they typed.
 * Sent synchronously, like the reset code — the user is staring at the
 * "check your email" screen and no queue worker runs on either box.
 */
class EmailVerificationCode extends PixelMail
{
    public function __construct(
        public string $name,
        public string $code,
        public int $ttlMinutes,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine('Your Pixel Alpha verification code'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.email-verification-code');
    }
}
