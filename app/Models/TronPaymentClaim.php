<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A customer pasting a transaction ID to say "that payment is mine". See the
 * create_tron_payment_claims migration for why it exists.
 *
 * `accepted` settled the claimant's invoice. `disputed` means the payment was
 * already on another invoice when they claimed it — two customers saying the
 * same money is theirs — and stays open until an admin resolves it.
 */
class TronPaymentClaim extends Model
{
    protected $table = 'tron_payment_claims';

    public const OUTCOME_ACCEPTED = 'accepted';

    public const OUTCOME_DISPUTED = 'disputed';

    protected $fillable = [
        'tron_transfer_id', 'invoice_id', 'user_id', 'network', 'tx_hash',
        'outcome', 'against_invoice_id', 'resolved_at', 'resolved_by', 'resolution_note',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /** Disputes nobody has looked at yet — what the Admin Overview counts. */
    public function scopeOpenDisputes($query)
    {
        return $query->where('outcome', self::OUTCOME_DISPUTED)->whereNull('resolved_at');
    }

    public function transfer()
    {
        return $this->belongsTo(TronTransfer::class, 'tron_transfer_id');
    }
}
