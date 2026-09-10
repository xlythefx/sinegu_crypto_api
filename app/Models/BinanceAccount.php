<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BinanceAccount extends Model
{
    use SoftDeletes;

    protected $table = 'binance_accounts';

    /** Table has created_at + deleted_at but no updated_at column. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'uni_id',
        'api_key',
        'secret_key',
        'name',
        'balance',
        'unrealized_pnl',
        'initial_deposit',
        'currency_type',
        'demo',
        'enabled',
        'key_status',
        'key_error_code',
        'key_error_reason',
        'key_error_message',
        'key_blocked_at',
        'key_checked_at',
    ];

    /** Never serialize the API secret back to clients. */
    protected $hidden = ['secret_key'];

    /** The exchange refuses these credentials from our server. */
    public const KEY_BLOCKED = 'blocked';

    public const KEY_OK = 'ok';

    /**
     * Days a blocked key may sit before the account is disconnected for the
     * user. Long enough to read an email and edit a Binance whitelist; short
     * enough that dead accounts do not accumulate forever.
     */
    public const KEY_GRACE_DAYS = 3;

    protected function casts(): array
    {
        return [
            'demo' => 'boolean',
            'enabled' => 'boolean',
            'created_at' => 'datetime',
            'key_blocked_at' => 'datetime',
            'key_checked_at' => 'datetime',
        ];
    }

    public function keyIsBlocked(): bool
    {
        return $this->key_status === self::KEY_BLOCKED;
    }

    /** When this account gets disconnected if the key is not fixed. */
    public function keyGraceEndsAt(): ?\Illuminate\Support\Carbon
    {
        return $this->keyIsBlocked() && $this->key_blocked_at
            ? $this->key_blocked_at->copy()->addDays(self::KEY_GRACE_DAYS)
            : null;
    }
}
