<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One payout envelope per admin release action (referrer_payouts).
 * Deleting an envelope is the "Undo": items cascade-delete, which makes those
 * lines releasable again. proof_file is a PRIVATE-disk path — never expose it
 * raw; the API returns has_proof and admins download via the proof route.
 */
class ReferrerPayout extends Model
{
    protected $table = 'referrer_payouts';

    protected $fillable = [
        'referrer_uni_id', 'month_year', 'total_amount', 'payout_address',
        'tx_hash', 'paid_at', 'status', 'payment_method', 'proof_file',
    ];

    protected function casts(): array
    {
        return ['paid_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReferrerPayoutItem::class, 'payout_id');
    }

    /** API shape. month_year === null means the envelope spans months ("Multiple months" label is frontend-side). */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'referrer_uni_id' => $this->referrer_uni_id,
            'month_year' => $this->month_year,
            'total_amount' => round((float) $this->total_amount, 2),
            'payout_address' => $this->payout_address,
            'tx_hash' => $this->tx_hash,
            'payment_method' => $this->payment_method,
            'has_proof' => $this->proof_file !== null,
            'paid_at' => $this->paid_at?->toDateTimeString(),
            'status' => $this->status,
            'items_count' => $this->items_count ?? ($this->relationLoaded('items') ? $this->items->count() : null),
        ];
    }
}
