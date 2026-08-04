<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per thing a payment provider told us. Append-only — nothing ever
 * updates or deletes these, so the table carries created_at and no updated_at.
 */
class PaymentEvent extends Model
{
    protected $table = 'payment_events';

    public $timestamps = false;

    protected $fillable = [
        'provider', 'event_id', 'external_id', 'transfer_id', 'tracking_id',
        'invoice_id', 'user_id', 'account_id', 'outcome', 'provider_status',
        'secondary_status', 'amount', 'amount_currency', 'expected_amount',
        'crypto_currency', 'crypto_amount', 'tx_hash', 'message', 'payload',
        'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
