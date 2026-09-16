<?php

namespace App\Models;

/**
 * A connected MEXC USDT-M futures account. Everything but the table name
 * lives on ExchangeAccount — see there.
 *
 * MEXC has NO futures testnet. The `demo` column exists for schema parity
 * only: the engine refuses to trade a MEXC row flagged demo (entries AND
 * exits — nothing was ever opened through us on it), and the connect endpoint,
 * when it lands, must reject `demo = true` for this exchange rather than store
 * a row that can never trade.
 */
class MexcAccount extends ExchangeAccount
{
    protected $table = 'mexc_accounts';
}
