<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;

class UserCredential extends Authenticatable
{
    use HasApiTokens, HasUuids;

    protected $table = 'user_credentials';

    protected $primaryKey = 'uni_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'uni_id',
        'name',
        'email',
        'password',
        'status',
        'type',
        'email_verified',
        'user_profile',
        'user_banner',
        'is_sandbox',
    ];

    protected $hidden = [
        'password',
        'reset_code',
        'verification_code',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'email_verified' => 'boolean',
            'is_sandbox' => 'boolean',
            'realized_percentage' => 'decimal:2',
            'unrealized_percentage' => 'decimal:2',
            'affiliate_percentage' => 'decimal:2',
            'last_activity' => 'datetime',
        ];
    }

    /**
     * Connected (non-deleted) exchange accounts. SoftDeletes on BinanceAccount
     * means disconnected accounts drop out automatically.
     */
    public function binanceAccounts()
    {
        return $this->hasMany(BinanceAccount::class, 'uni_id', 'uni_id');
    }

    /**
     * The user shape returned by auth/profile endpoints.
     */
    public function toAuthPayload(): array
    {
        return [
            'uni_id' => $this->uni_id,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $this->status,
            'type' => $this->type,
            'created_at' => $this->created_at?->toISOString(),
            'user_profile' => $this->imageUrl($this->user_profile),
            'user_banner' => $this->imageUrl($this->user_banner),
            'has_exchange_account' => $this->binanceAccounts()->exists(),
        ];
    }

    /**
     * Absolute URL for a stored image path. Already-absolute values (http/data)
     * pass through unchanged; NULL stays NULL.
     */
    private function imageUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }
        if (preg_match('#^(https?://|data:)#i', $path)) {
            return $path;
        }

        return url(Storage::url($path));
    }
}
