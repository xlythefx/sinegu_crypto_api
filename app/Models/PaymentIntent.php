<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One invoice's reservation of an exact on-chain amount. See the migration for
 * why the amount is the correlation key and why `open_units` goes NULL on close.
 *
 * NOTE ON CASTS: the `*_units` columns are deliberately NOT cast. They are
 * integer base units of a 6-decimal token and are compared and arithmetic'd
 * through bcmath (App\Services\Payments\TronUnits); casting any of them to
 * float would reintroduce exactly the precision bug Invoice::feeCents() exists
 * to avoid.
 */
class PaymentIntent extends Model
{
    protected $table = 'payment_intents';

    /**
     * Whether openFor() handed back an existing reservation rather than minting
     * one. A plain property, not an attribute, so it never reaches the database
     * — it describes this call, not this row.
     */
    public bool $reused = false;

    public const STATUS_OPEN = 'open';

    public const STATUS_SETTLED = 'settled';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'provider', 'network', 'invoice_id', 'user_id', 'account_id',
        'address', 'contract_address', 'asset', 'decimals',
        'expected_units', 'expected_usd', 'shortfall_units', 'overpay_units',
        'open_units', 'status', 'expires_at',
        'tx_hash', 'received_units', 'settled_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'settled_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function scopeForNetwork($query, string $network)
    {
        return $query->where('network', $network);
    }

    public function scopeOpen($query)
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Lowest amount that still settles this intent, in base units. */
    public function floorUnits(): string
    {
        return bcsub((string) $this->expected_units, (string) $this->shortfall_units);
    }

    /** Highest amount that still settles this intent, in base units. */
    public function ceilingUnits(): string
    {
        return bcadd((string) $this->expected_units, (string) $this->overpay_units);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }
}
