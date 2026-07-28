<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The network edge: one row per referred user (referral_tracking).
 * created_at doubles as "referred at". Deleting a row removes the referral
 * from the network only — payout history is intentionally untouched.
 */
class ReferralTracking extends Model
{
    protected $table = 'referral_tracking';

    public const UPDATED_AT = null;

    protected $fillable = ['referral_code', 'referrer_uni_id', 'referred_user_uni_id'];

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(UserCredential::class, 'referrer_uni_id', 'uni_id');
    }

    public function referred(): BelongsTo
    {
        return $this->belongsTo(UserCredential::class, 'referred_user_uni_id', 'uni_id');
    }
}
