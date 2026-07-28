<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Optional public community profile, 1:1 with a referral code
 * (community_details). Only community_name + bio are surfaced by the UI;
 * profile_banner / profile_image exist for spec parity and stay null.
 */
class CommunityDetail extends Model
{
    protected $table = 'community_details';

    protected $fillable = [
        'referral_id', 'community_name', 'bio', 'profile_banner', 'profile_image',
    ];

    public function referralCode(): BelongsTo
    {
        return $this->belongsTo(ReferralCode::class, 'referral_id');
    }

    public function toApiArray(): array
    {
        return [
            'community_name' => $this->community_name,
            'bio' => $this->bio,
            'profile_banner' => $this->profile_banner,
            'profile_image' => $this->profile_image,
        ];
    }
}
