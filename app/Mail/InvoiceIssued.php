<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "Your invoice for <month> is ready" — to the customer when an invoice is
 * generated. Day 0 of the payment-reminder schedule.
 *
 * It RESTATES the invoice, never recomputes it: every figure is handed in off
 * the invoice row, the same rule as the printable invoice document.
 */
class InvoiceIssued extends PixelMail
{
    public function __construct(
        public string $name,
        public int $invoiceId,
        /** "September 2026". */
        public string $period,
        /** Display label, "Binance". */
        public string $exchange,
        /** New profit above the high-water mark the fee is charged on. */
        public float $profit,
        /** Whole percent, 20. */
        public float $feeRate,
        public float $amount,
        /** Already formatted, "7 Oct 2026". */
        public string $dueDate,
        /** Fee on open positions at month end (0 when none) — listed on its own line. */
        public float $unrealizedFee = 0.0,
        public float $unrealizedRate = 0.0,
        public float $unrealizedProfit = 0.0,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine("Your Pixel Alpha invoice for {$this->period}"));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.invoice-issued', with: [
            'invoiceUrl' => self::siteUrl('/dashboard/invoices/'.$this->invoiceId),
        ]);
    }
}
