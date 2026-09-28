<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The unpaid-invoice reminders, one class for every step of the schedule so
 * the three read as one escalating conversation rather than three strangers:
 *
 *   STAGE_GENTLE — day 2: a friendly nudge.
 *   STAGE_FIRM   — day 3: names the date trading pauses.
 *   STAGE_PAUSED — the pause day: trading has stopped; paying resumes it.
 *
 * Day 0 is InvoiceIssued itself. "Paused", never "deleted": an overdue account
 * is disabled (enabled = 0), its keys and history stay, and settling the
 * invoice switches it back on by itself.
 */
class PaymentReminder extends PixelMail
{
    public const STAGE_GENTLE = 'gentle';

    public const STAGE_FIRM = 'firm';

    public const STAGE_PAUSED = 'paused';

    public function __construct(
        public string $stage,
        public string $name,
        public int $invoiceId,
        public string $period,
        public float $amount,
        public string $issuedOn,
        /** The day trading pauses if still unpaid, formatted. */
        public string $pauseDate,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine(match ($this->stage) {
            self::STAGE_GENTLE => "Reminder: your {$this->period} invoice is waiting",
            self::STAGE_FIRM => "Your {$this->period} invoice is still unpaid — trading pauses on {$this->pauseDate}",
            default => 'Trading on your account is paused — invoice unpaid',
        }));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.payment-reminder', with: [
            'invoiceUrl' => self::siteUrl('/dashboard/invoices/'.$this->invoiceId),
        ]);
    }
}
