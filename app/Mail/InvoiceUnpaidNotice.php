<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "This customer still hasn't paid" — to the team, sent with every customer
 * PaymentReminder so whoever follows up knows who to chase before trading
 * pauses. Carries the customer's contact details for exactly that.
 */
class InvoiceUnpaidNotice extends PixelMail
{
    public function __construct(
        public string $name,
        public string $email,
        public string $uniId,
        public int $invoiceId,
        public string $period,
        public float $amount,
        public int $daysOpen,
        public string $issuedOn,
        public string $pauseDate,
        /** True on the pause day: the account has just been paused. */
        public bool $paused = false,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine($this->paused
            ? "Trading paused: {$this->name} — invoice unpaid after {$this->daysOpen} days"
            : "Unpaid after {$this->daysOpen} days: {$this->name} — \$".number_format($this->amount, 2)
        ));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.team-invoice-unpaid', with: [
            'invoicesUrl' => self::siteUrl('/admin/invoices'),
            'userUrl' => self::siteUrl('/admin/users/'.$this->uniId),
        ]);
    }
}
