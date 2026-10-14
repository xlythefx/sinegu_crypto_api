<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One ticked "Where did you hear about us?" option. One row per option, so
 * statistics are a GROUP BY source. Written by App\Services\Signup\SignupSources.
 */
class UserSignupSource extends Model
{
    protected $table = 'user_signup_sources';

    public const UPDATED_AT = null;

    protected $fillable = ['uni_id', 'source', 'detail'];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
