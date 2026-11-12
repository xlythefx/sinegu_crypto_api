<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The hourly failed-trade summary (App\Services\Admin\TradeFailureDigest,
 * `trade-alerts:digest`). Sent only when an hour had something a person
 * should look at; a close that needs someone NOW goes out on its own
 * (TradeExitStuckNotice).
 */
class TradeFailureSummary extends PixelMail
{
    /**
     * @param  list<array<string, mixed>>  $signals  TradeFailureDigest::build()['signals']
     * @param  array<string, int>  $counts
     */
    public function __construct(
        public string $window,
        public array $signals,
        public array $counts,
    ) {
    }

    public function envelope(): Envelope
    {
        $parts = [];
        if ($this->counts['failed'] > 0) {
            $parts[] = $this->counts['failed'].' failed';
        }
        if ($this->counts['limit'] > 0) {
            $parts[] = $this->counts['limit'].' at the limit';
        }
        if ($this->counts['shrunk'] > 0) {
            $parts[] = $this->counts['shrunk'].' shrunk';
        }
        if ($this->counts['rejected'] > 0) {
            $parts[] = $this->counts['rejected'].' rejected';
        }

        return new Envelope(subject: $this->subjectLine(
            'Trades: '.($parts === [] ? 'summary' : implode(', ', $parts)).' · '.$this->window
        ));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.trade-failure-summary', with: [
            'issuesUrl' => self::siteUrl('/admin/trade-logs?view=issues'),
            'assetsUrl' => self::siteUrl('/admin/assets'),
        ]);
    }
}
