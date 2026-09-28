<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "Thanks for paying" — to the customer once an invoice settles. If trading
 * was paused for it, it says trading is back on.
 */
class PaymentReceived extends PixelMail
{
    public function __construct(
        public string $name,
        public int $invoiceId,
        public string $period,
        public float $amount,
        public string $paidAt,
        /** "USDT (TRC-20)". */
        public string $method,
        /** The on-chain transaction id or provider reference. */
        public string $reference,
        public bool $wasPaused = false,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine("Payment received — thank you ({$this->period})"));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.payment-received', with: [
            'invoiceUrl' => self::siteUrl('/dashboard/invoices/'.$this->invoiceId),
        ]);
    }
}
