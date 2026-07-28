<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's saved bank account for wire payouts (bank_wire_accounts).
 * Detail fields are nullable — banks vary (IBAN vs account+routing); the form
 * collects what applies. is_main mirrors CryptoWallet::is_main.
 */
class BankWireAccount extends Model
{
    protected $table = 'bank_wire_accounts';

    protected $fillable = [
        'uni_id', 'label', 'account_holder', 'bank_name', 'account_number',
        'routing_number', 'iban', 'swift_bic', 'account_type', 'bank_address',
        'currency', 'is_main',
    ];

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
            'label' => $this->label,
            'account_holder' => $this->account_holder,
            'bank_name' => $this->bank_name,
            'account_number' => $this->account_number,
            'routing_number' => $this->routing_number,
            'iban' => $this->iban,
            'swift_bic' => $this->swift_bic,
            'account_type' => $this->account_type,
            'bank_address' => $this->bank_address,
            'currency' => $this->currency,
            'is_main' => $this->is_main,
        ];
    }
}
