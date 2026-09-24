<?php

namespace App\Models;

/**
 * A connected Bybit USDT perpetual (linear) account. Everything but the table
 * name lives on ExchangeAccount — see there.
 *
 * `demo = 1` routes the engine to Bybit **Demo Trading** (api-demo.bybit.com),
 * not to testnet.bybit.com. Demo is reached from the ordinary bybit.com login,
 * but its API keys are minted in the Demo Trading UI and are NOT the live
 * ones — a live key is refused on the demo host and a demo key on the live
 * host. Demo balances are topped up with POST /v5/account/demo-apply-money.
 */
class BybitAccount extends ExchangeAccount
{
    protected $table = 'bybit_accounts';
}
