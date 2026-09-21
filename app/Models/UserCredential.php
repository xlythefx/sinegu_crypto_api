<?php

namespace App\Models;

use App\Services\Exchanges\ExchangeSchema;
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
        'terms_accepted_at',
        'terms_version',
        'discord_id',
        'discord_username',
        'discord_linked_at',
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
            'discord_linked_at' => 'datetime',
        ];
    }

    /**
     * Whether the account can be entered with a password at all. NULL means a
     * Discord-only account: login answers DISCORD_ONLY, Settings offers "Set a
     * password" instead of "Change password", and unlinking Discord is refused
     * (it would leave no way in).
     */
    public function hasPassword(): bool
    {
        return $this->password !== null;
    }

    /** Whether a Discord identity is linked to this account. */
    public function hasDiscord(): bool
    {
        return $this->discord_id !== null;
    }

    /**
     * Connected (non-deleted) Binance accounts. SoftDeletes on the model
     * means disconnected accounts drop out automatically.
     */
    public function binanceAccounts()
    {
        return $this->hasMany(BinanceAccount::class, 'uni_id', 'uni_id');
    }

    /** Connected (non-deleted) MEXC accounts. */
    public function mexcAccounts()
    {
        return $this->hasMany(MexcAccount::class, 'uni_id', 'uni_id');
    }

    /**
     * Whether the user holds a live account on ANY exchange — what the
     * onboarding nudges and the dashboard's "connect an exchange" empty
     * states key off. One query per exchange table; there is no cross-table
     * index to ask.
     */
    public function hasExchangeAccount(): bool
    {
        return $this->binanceAccounts()->exists() || $this->mexcAccounts()->exists();
    }

    /**
     * Whether the user holds a LIVE account (demo = 0) on any exchange — what
     * earns the Discord "Trader" role. A testnet account trades play money,
     * so it counts for the onboarding nudge above but not here.
     */
    public function hasLiveExchangeAccount(): bool
    {
        foreach (ExchangeSchema::supported() as $exchange) {
            $exists = ExchangeSchema::for($exchange)->accountQuery()
                ->where('uni_id', $this->uni_id)
                ->where('demo', 0)
                ->exists();
            if ($exists) {
                return true;
            }
        }

        return false;
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
            'has_exchange_account' => $this->hasExchangeAccount(),
            'has_password' => $this->hasPassword(),
            // The id stays a string: a Discord snowflake overflows a JS number.
            'discord' => $this->hasDiscord() ? [
                'id' => (string) $this->discord_id,
                'username' => $this->discord_username,
                'linked_at' => $this->discord_linked_at?->toISOString(),
            ] : null,
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
