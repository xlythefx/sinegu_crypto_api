<?php

namespace App\Models;

/**
 * A connected Binance USDⓈ-M futures account. Everything but the table name
 * lives on ExchangeAccount — see there. `demo = 1` routes the engine to the
 * Binance futures testnet.
 */
class BinanceAccount extends ExchangeAccount
{
    protected $table = 'binance_accounts';
}
