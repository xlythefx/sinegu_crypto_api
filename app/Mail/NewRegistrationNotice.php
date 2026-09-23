<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Somebody registered and is waiting for approval" — to the admin desk, never
 * to a customer. A pending account can sign in but cannot connect an exchange,
 * so nothing happens for that user until this mail is acted on.
 *
 * Scalars rather than the model: the same payload feeds `mail:preview`, and a
 * template that can only be rendered from a real DB row cannot be reviewed
 * before it is sent.
 */
class NewRegistrationNotice extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public string $email,
        public string $uniId,
        /** How they arrived — AccountMail::VIA_*. */
        public string $via,
        /** Already formatted, with its zone named: an email has no reader zone. */
        public string $registeredAt,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'New Pixel Alpha registration — '.$this->name);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.new-registration',
            with: [
                'reviewUrl' => rtrim((string) config('mail.site_url'), '/').'/admin/users',
            ],
        );
    }
}
