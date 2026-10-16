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

    /**
     * The internal TEST account role (`user_credentials.type = 'tester'`).
     *
     * Like a developer account for trying the product on prod, with none of
     * its reach:
     *  - **Test feed only.** Its exchange accounts are listed to the engine
     *    with `feed: test` (EngineController::accounts), so they receive
     *    signals ONLY from the engine's test webhooks
     *    (App\Services\Engine\WebhookPaths::TEST) and never from the main
     *    TradingView feed — and a customer never receives a test signal.
     *    That is what lets someone fire experimental signals (streak sizing,
     *    another signal provider) at prod without touching anyone's money.
     *  - **No billing.** Monthly invoicing keys on `type = 'user'`, so a tester
     *    is never invoiced; demo and real-money accounts alike.
     *  - **Admin portal, full access** (owner, 2026-10-16 — it was trader-only
     *    for a day): in EnsureAdmin::ROLES, so it opens every admin page and
     *    may connect the staff-only venues. Not the developer-only surfaces
     *    (Database console, test-mode payments), which check `developer`.
     *  - **Fetch now** on its own Positions page (POST /user/positions/refresh,
     *    PositionRefreshController): a test signal's position shows at once
     *    instead of after the 300 s poller tick.
     *  - **Not a customer.** AdminInsights counts `type = 'user'` only, and no
     *    Discord Trader role is granted (an internal account, not a trader).
     * Changing a user to or from this role is refused while they hold an open
     * position (AdminUserController::update): a position opened by one feed
     * would never receive its exit from the other.
     */
    public const TESTER = 'tester';

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
            'verification_code_expires_at' => 'datetime',
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

    /** Whether this is an internal test account — see {@see self::TESTER}. */
    public function isTester(): bool
    {
        return $this->type === self::TESTER;
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
            // False until the mailed 6-digit code is accepted; every guarded
            // route answers 403 EMAIL_UNVERIFIED until then.
            'email_verified' => (bool) $this->email_verified,
            // An email change in flight: the new address waits here until its
            // own code is accepted, and `email` stays the live one meanwhile.
            'pending_email' => $this->pending_email,
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
