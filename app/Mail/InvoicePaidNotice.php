<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** "A customer paid" — to the team, alongside the customer's PaymentReceived. */
class InvoicePaidNotice extends PixelMail
{
    public function __construct(
        public string $name,
        public string $email,
        public string $uniId,
        public int $invoiceId,
        public string $period,
        public float $amount,
        public string $method,
        public string $reference,
        public string $paidAt,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine(
            "Paid: {$this->name} — \$".number_format($this->amount, 2)." ({$this->period})"
        ));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.team-invoice-paid', with: [
            'invoicesUrl' => self::siteUrl('/admin/invoices'),
            'userUrl' => self::siteUrl('/admin/users/'.$this->uniId),
        ]);
    }
}
