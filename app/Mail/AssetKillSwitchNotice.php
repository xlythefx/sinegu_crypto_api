<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "The kill switch turned a coin off" — to the team.
 *
 * Sent once when the master's losing run on a coin reaches the asset's kill
 * switch count (App\Services\Assets\KillSwitch). Nobody opens new trades on
 * that coin until an admin turns it back on; open positions still close.
 * Shown beside the Admin Overview's caution strip.
 */
class AssetKillSwitchNotice extends PixelMail
{
    public function __construct(
        public string $ticker,
        public string $broker,
        public int $losses,
        public string $reason,
        public string $trippedAt,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine(
            "Kill switch: {$this->ticker} switched off after {$this->losses} master losses in a row"
        ));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.team-kill-switch', with: [
            'reviewUrl' => self::siteUrl('/admin/assets'),
        ]);
    }
}
