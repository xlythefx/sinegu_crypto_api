<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A key the employer's AI connector (Grok) presents to POST /api/mcp.
 * Only the SHA-256 is stored — see App\Services\Ai\AiAccessKeys.
 *
 * @property int $id
 * @property string $name
 * @property string $prefix
 * @property string $key_hash
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property \Illuminate\Support\Carbon|null $last_used_at
 * @property \Illuminate\Support\Carbon|null $revoked_at
 */
class AiAccessKey extends Model
{
    protected $table = 'ai_access_keys';

    protected $fillable = [
        'name', 'prefix', 'key_hash', 'created_by', 'created_by_name',
        'expires_at', 'last_used_at', 'last_used_ip', 'revoked_at', 'revoked_by_name',
    ];

    /** The hash never leaves the server, not even to an admin. */
    protected $hidden = ['key_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /** active | expired | revoked — what the admin page prints. */
    public function state(): string
    {
        if ($this->revoked_at !== null) {
            return 'revoked';
        }

        return $this->expires_at !== null && ! $this->expires_at->isFuture() ? 'expired' : 'active';
    }
}
