<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** "A customer connected an exchange" — to the team, never to a customer. */
class ExchangeConnectedNotice extends PixelMail
{
    public function __construct(
        public string $name,
        public string $email,
        public string $uniId,
        /** Display label, "Binance". */
        public string $exchange,
        /** "Live" or "Demo". */
        public string $mode,
        /** Null until the balance poller has read the account. */
        public ?float $balance,
        public string $connectedAt,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine("{$this->name} connected {$this->exchange} ({$this->mode})"));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.team-exchange-connected', with: [
            'userUrl' => self::siteUrl('/admin/users/'.$this->uniId),
        ]);
    }
}
