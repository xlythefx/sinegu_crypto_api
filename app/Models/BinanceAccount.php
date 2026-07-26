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
    ];

    /** Never serialize the API secret back to clients. */
    protected $hidden = ['secret_key'];

    protected function casts(): array
    {
        return [
            'demo' => 'boolean',
            'enabled' => 'boolean',
            'created_at' => 'datetime',
        ];
    }
}
