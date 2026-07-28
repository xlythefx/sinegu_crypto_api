<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's saved crypto payout wallet (crypto_wallets). is_main marks the
 * preferred wallet, pre-selected on the admin affiliate release screen.
 */
class CryptoWallet extends Model
{
    protected $table = 'crypto_wallets';

    protected $fillable = ['uni_id', 'network', 'address', 'name', 'is_main'];

    protected function casts(): array
    {
        return ['is_main' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(UserCredential::class, 'uni_id', 'uni_id');
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'network' => $this->network,
            'address' => $this->address,
            'name' => $this->name,
            'is_main' => $this->is_main,
        ];
    }
}
