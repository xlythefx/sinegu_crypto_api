<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A released commission line inside a payout envelope (referrer_payout_items).
 * Identity is (referrer, referred user, exchange, month_year) — deliberately not
 * an invoice id, so history survives invoice regeneration (spec §6.10).
 * fee_paid / commission are decimal(20,8) strings on the model (uncast);
 * commission is frozen at release time and never recomputed.
 */
class ReferrerPayoutItem extends Model
{
    protected $table = 'referrer_payout_items';

    public const UPDATED_AT = null;

    protected $fillable = [
        'payout_id', 'referrer_uni_id', 'referred_user_uni_id',
        'exchange', 'month_year', 'fee_paid', 'commission', 'released_at',
    ];

    protected function casts(): array
    {
        return ['released_at' => 'datetime'];
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(ReferrerPayout::class, 'payout_id');
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'payout_id' => $this->payout_id,
            'referred_user_uni_id' => $this->referred_user_uni_id,
            'exchange' => $this->exchange,
            'month_year' => $this->month_year,
            'fee_paid' => round((float) $this->fee_paid, 2),
            'commission' => round((float) $this->commission, 2),
            'released_at' => $this->released_at?->toDateTimeString(),
        ];
    }
}
