<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "Two customers say the same crypto payment is theirs" — to the team.
 *
 * Sent when a customer pastes a transaction ID that is already settled on
 * someone else's invoice (TronPaymentClaims). One of the two is wrong, and only
 * a human can tell which; until then one invoice may be paid with the other
 * customer's money. Shown beside the Admin Overview's caution strip.
 */
class PaymentDisputedNotice extends PixelMail
{
    /**
     * @param  string  $firstVia  how the payment reached its current invoice:
     *                            "the automatic matcher", "the customer's
     *                            transaction ID" or "an admin"
     */
    public function __construct(
        public string $amount,
        public string $asset,
        public string $network,
        public string $txHash,
        public string $receivedAt,
        public string $holderName,
        public string $holderEmail,
        public int $holderInvoiceId,
        public string $holderPeriod,
        public string $firstVia,
        public string $claimantName,
        public string $claimantEmail,
        public int $claimantInvoiceId,
        public string $claimantPeriod,
        public string $claimedAt,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine(
            "Disputed payment: {$this->amount} {$this->asset} claimed by two customers"
        ));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.team-payment-disputed', with: [
            'reviewUrl' => self::siteUrl('/admin/tron-transfers?status=disputed'),
        ]);
    }
}
