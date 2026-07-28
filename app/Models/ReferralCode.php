<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One shareable affiliate code per referrer (referral_codes).
 * The invite link is built frontend-side as {origin}/auth?ref={code}.
 */
class ReferralCode extends Model
{
    protected $table = 'referral_codes';

    protected $fillable = ['user_uni_id', 'code'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(UserCredential::class, 'user_uni_id', 'uni_id');
    }

    public function community(): HasOne
    {
        return $this->hasOne(CommunityDetail::class, 'referral_id');
    }
}
