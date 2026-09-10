<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One incoming TRC-20 transfer we have seen on chain. See the migration for why
 * every transfer is stored (the scan cursor lives on this table) and why
 * `event_key` is a hashed tuple rather than the transaction id.
 *
 * NOTE ON CASTS: `value_raw`, `value_units` and `block_timestamp` are NOT cast.
 * The first is a chain-supplied decimal string that may exceed int64, the second
 * is integer base units compared through bcmath, and the third is milliseconds
 * since epoch used verbatim as a cursor.
 */
class TronTransfer extends Model
{
    protected $table = 'tron_transfers';

    public const STATUS_UNMATCHED = 'unmatched';

    public const STATUS_SETTLED = 'settled';

    public const STATUS_IGNORED = 'ignored';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'network', 'event_key', 'tx_hash', 'contract_address',
        'token_symbol', 'token_decimals', 'from_address', 'to_address',
        'value_raw', 'value_units', 'block_timestamp', 'confirmed',
        'status', 'reject_reason', 'intent_id', 'invoice_id',
        'settled_by', 'settled_at', 'note', 'payload',
    ];

    protected function casts(): array
    {
        return [
            'confirmed' => 'boolean',
            'settled_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * The identity of a transfer. Hashes the tuple because one TRON transaction
     * can carry several TRC-20 transfers and TronGrid exposes no log index — the
     * bare tx_hash would collapse them into one row and lose money.
     */
    public static function eventKey(
        string $txHash,
        string $from,
        string $to,
        string $contract,
        string $value,
        int $blockTimestamp
    ): string {
        return hash('sha256', implode('|', [
            $txHash, $from, $to, $contract, $value, (string) $blockTimestamp,
        ]));
    }

    public function scopeForNetwork($query, string $network)
    {
        return $query->where('network', $network);
    }

    public function scopeUnmatched($query)
    {
        return $query->where('status', self::STATUS_UNMATCHED);
    }

    public function isSettled(): bool
    {
        return $this->status === self::STATUS_SETTLED;
    }

    /** Was this transfer usable money at all, or did we reject it on sight? */
    public function isCredible(): bool
    {
        return $this->reject_reason === null;
    }

    public function intent()
    {
        return $this->belongsTo(PaymentIntent::class, 'intent_id');
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }
}
