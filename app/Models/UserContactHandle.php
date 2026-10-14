<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A user's optional Telegram / Discord usernames, typed by them (unverified)
 * and editable in Settings. At most one row per user; no row = both unset.
 * Written only through App\Services\Signup\ContactHandles, which normalises
 * the values.
 */
class UserContactHandle extends Model
{
    protected $table = 'user_contact_handles';

    protected $primaryKey = 'uni_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['uni_id', 'telegram', 'discord'];
}
