<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;
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

        return url(\Illuminate\Support\Facades\Storage::url($path));
    }
}
