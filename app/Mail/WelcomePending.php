<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "Thanks for registering" — to the customer the moment they sign up, while
 * their account waits in the approval queue. Points them at the FAQ and the
 * exchange guide so the wait is spent preparing their API keys.
 */
class WelcomePending extends PixelMail
{
    public function __construct(public string $name)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine('Welcome to Pixel Alpha — your account is being reviewed'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.welcome', with: [
            'faqUrl' => self::siteUrl('/faq'),
            'guideUrl' => self::siteUrl('/docs/binance'),
            'dashboardUrl' => self::siteUrl('/dashboard'),
        ]);
    }
}
