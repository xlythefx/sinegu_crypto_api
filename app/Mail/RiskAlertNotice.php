<?php

namespace App\Mail;

use App\Models\RiskAlert;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "High-risk: …" — one serious failure that needs a person, sent to the
 * failed-trade list (App\Services\Alerts\RiskAlerts). One template for every
 * kind: what happened in plain sentences, and the page that fixes it.
 */
class RiskAlertNotice extends PixelMail
{
    /** Where each kind is fixed — a path on the site. */
    private const FIX_PAGES = [
        RiskAlert::FEED_BREACH => ['/admin/trade-logs', 'Open the Signal Log →'],
        RiskAlert::EXIT_STUCK => ['/admin?tab=positions', 'Open positions →'],
        RiskAlert::KILL_SWITCH => ['/admin/assets', 'Open Trading Assets →'],
        RiskAlert::KEY_REFUSED => ['/admin/api-keys', 'Open API Keys →'],
        RiskAlert::ENGINE_DOWN => ['/admin/engine', 'Open Bot Engine →'],
        RiskAlert::ASSETS_UNAVAILABLE => ['/admin/trade-logs', 'Open the Signal Log →'],
    ];

    /**
     * @param  list<string>  $lines
     */
    public function __construct(
        public string $kind,
        public string $label,
        public string $title,
        public array $lines,
        public int $occurrences,
        public string $at,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine("High-risk: {$this->title}"));
    }

    public function content(): Content
    {
        [$path, $button] = self::FIX_PAGES[$this->kind] ?? ['/admin', 'Open the Admin Overview →'];

        return new Content(view: 'emails.risk-alert', with: [
            'fixUrl' => self::siteUrl($path),
            'fixLabel' => $button,
            'overviewUrl' => self::siteUrl('/admin?tab=overview&view=attention'),
        ]);
    }
}
