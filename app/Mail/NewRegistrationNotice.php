<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "Somebody registered and is waiting for approval" — to the admin desk, never
 * to a customer. A pending account can sign in but cannot connect an exchange,
 * so nothing happens for that user until this mail is acted on.
 *
 * Scalars rather than the model: the same payload feeds `mail:preview`, and a
 * template that can only be rendered from a real DB row cannot be reviewed
 * before it is sent.
 */
class NewRegistrationNotice extends PixelMail
{
    public function __construct(
        public string $name,
        public string $email,
        public string $uniId,
        /** How they arrived — AccountMail::VIA_*. */
        public string $via,
        /** Already formatted, with its zone named: an email has no reader zone. */
        public string $registeredAt,
        // Who they are and where they signed up from (Dima, 2026-11-25), so
        // the desk can reach them or spot a fake without opening the profile.
        // Null = not on record, and the email says so rather than guessing.
        /** The sign-up's IP address (their registration, not the code entry). */
        public ?string $ip = null,
        /** Country from that IP, e.g. "Thailand (TH)" — AccessLog::location(). */
        public ?string $location = null,
        /** Telegram username without the @, as typed (unverified). */
        public ?string $telegram = null,
        /** WhatsApp number in E.164, as typed (unverified). */
        public ?string $whatsapp = null,
        /** The sign-up's device, e.g. "Chrome 131 on Windows · computer" — DeviceInfo::describe(). */
        public ?string $device = null,
        /** "Where did you hear about us?" as one line — SignupSources::describe(). */
        public ?string $heardFrom = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine('New Pixel Alpha registration — '.$this->name));
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
