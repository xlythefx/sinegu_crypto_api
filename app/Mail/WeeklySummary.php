<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The customer's week in one email: P&L in dollars (after exchange fees, with
 * the before-fees figure beside it), the week's return, trades, win rate, the
 * best day, the balance, and a per-asset breakdown.
 *
 * A PRIVATE email, so dollar amounts are allowed — unlike the public channel
 * and /api/public/*, which publish percentages only.
 */
class WeeklySummary extends PixelMail
{
    /**
     * @param  list<array{symbol:string,trades:int,pnl:float}>  $assets
     */
    public function __construct(
        public string $name,
        /** "22 – 28 Sep 2026". */
        public string $weekLabel,
        /** After exchange fees — what actually landed. */
        public float $pnl,
        public float $fees,
        /** The week's return on the capital each day started with. */
        public float $returnPct,
        public int $trades,
        public int $wins,
        public float $balance,
        public string $bestDayLabel,
        public float $bestDayPnl,
        public array $assets,
    ) {
    }

    public function envelope(): Envelope
    {
        $sign = $this->pnl >= 0 ? '+' : '−';

        return new Envelope(subject: $this->subjectLine(
            "Your week with Pixel Alpha: {$sign}\$".number_format(abs($this->pnl), 2)." ({$this->weekLabel})"
        ));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.weekly-summary', with: [
            'dashboardUrl' => self::siteUrl('/dashboard'),
            'analyticsUrl' => self::siteUrl('/dashboard/analytics'),
        ]);
    }
}
