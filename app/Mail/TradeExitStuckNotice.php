<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "A close failed: positions may still be open" — sent at once to the
 * failed-trade list (App\Services\Notifications\TradeAlerts::exitStuck).
 *
 * The one failed-trade email that does not wait for the hourly summary: the
 * exchange refused a CLOSE outright, or the bot's retries ran out, so a real
 * position may still be open and nobody else is going to close it.
 */
class TradeExitStuckNotice extends PixelMail
{
    /**
     * @param  list<array{owner: ?string, account: ?string, error: ?string}>  $accounts
     */
    public function __construct(
        public string $broker,
        public string $ticker,
        public string $side,
        public string $why,
        public ?int $attempts,
        public array $accounts,
        public int $total,
        public string $at,
    ) {
    }

    public function envelope(): Envelope
    {
        $n = $this->total === 1 ? '1 account' : "{$this->total} accounts";

        return new Envelope(subject: $this->subjectLine(
            "Close failed: {$this->ticker} {$this->side} may still be open on {$n} ({$this->broker})"
        ));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.trade-exit-stuck', with: [
            'positionsUrl' => self::siteUrl('/admin?tab=positions'),
            'logUrl' => self::siteUrl('/admin/trade-logs'),
        ]);
    }
}
