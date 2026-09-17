<?php

namespace App\Models;

/**
 * A connected MEXC USDT-M futures account. Everything but the table name
 * lives on ExchangeAccount — see there.
 *
 * `demo = 1` routes the engine to MEXC's futures testnet
 * (futures.testnet.mexc.com) — the same login and API keys as live with a
 * separate 10,000-USDT test balance. The testnet refuses IP-bound keys (it
 * sits behind a CDN), so a demo key is created without "Link IP address".
 */
class MexcAccount extends ExchangeAccount
{
    protected $table = 'mexc_accounts';
}
