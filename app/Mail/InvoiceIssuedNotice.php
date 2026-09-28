<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** "A customer was invoiced" — to the team, alongside the customer's InvoiceIssued. */
class InvoiceIssuedNotice extends PixelMail
{
    public function __construct(
        public string $name,
        public string $email,
        public string $uniId,
        public int $invoiceId,
        public string $period,
        public string $exchange,
        public float $amount,
        public string $dueDate,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine(
            "Invoice issued: {$this->name} — \$".number_format($this->amount, 2)." ({$this->period})"
        ));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.team-invoice-issued', with: [
            'invoicesUrl' => self::siteUrl('/admin/invoices'),
            'userUrl' => self::siteUrl('/admin/users/'.$this->uniId),
        ]);
    }
}
