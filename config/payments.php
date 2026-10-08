<?php

/*
|--------------------------------------------------------------------------
| Invoice payment providers — Stripe (cards) and Coinsbuy (crypto)
|--------------------------------------------------------------------------
|
| Shaped like the `engine` block in config/services.php: per-provider
| sub-arrays, secrets that never leave the server, allow-listed URLs.
|
| IMPORTANT: this file holds BOTH key sets side by side and only the raw
| signals used to choose between them. It must not decide anything itself —
| the deployer runs `php artisan config:cache`, which would bake a CLI-time
| verdict into bootstrap/cache/config.php and freeze it for every HTTP
| request thereafter. App\Services\Payments\PaymentEnvironment computes the
| verdict per process, reading only config() (never env() at runtime), so it
| behaves identically cached and uncached.
|
*/

return [

    /*
    | Escape hatch. 'production' | 'sandbox' | null (auto-detect from the
    | machine). Set it only to force a box; leave it blank in normal operation
    | so a .env copied between servers can never take live money by accident.
    */
    'force' => env('PAYMENTS_ENV'),

    /*
    | Which crypto rail a trader is offered by default. A raw signal only — who
    | actually sees what is resolved in PaymentController. Deliberately separate
    | from payments.tron.public: you will want TRON VISIBLE to everyone for some
    | time before you want it DEFAULT for everyone, and collapsing the two into
    | one switch removes the step where both rails run side by side.
    */
    'default_provider' => env('PAYMENTS_DEFAULT_PROVIDER', 'coinsbuy'),

    'environments' => [

        'production' => [
            // Matched (prefix) against $_SERVER['SERVER_ADDR'].
            'server_ips' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('PAYMENTS_LIVE_IPS', '2.24.139.176'))
            ))),
            // Matched (substring) against the request host. Add the domain here
            // once DNS points at the VPS.
            'hosts' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('PAYMENTS_LIVE_HOSTS', '2.24.139.176'))
            ))),
            // Matched (case-insensitive) against gethostname() — the CLI path,
            // used by the scheduler and queue workers where SERVER_ADDR is absent.
            'hostnames' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('PAYMENTS_LIVE_HOSTNAMES', 'srv1860230'))
            ))),

            'frontend_url' => env('PAYMENTS_FRONTEND_URL', 'http://2.24.139.176'),
            'api_url' => env('PAYMENTS_API_URL', 'http://2.24.139.176/api'),
            // Same origin serves the SPA and Laravel on prod, so providers reach
            // the same URL the browser does.
            'public_api_url' => env('PAYMENTS_PUBLIC_API_URL'),
        ],

        'sandbox' => [
            'server_ips' => [],
            'hosts' => [],
            'hostnames' => [],

            'frontend_url' => env('PAYMENTS_FRONTEND_URL_DEV', 'http://localhost:5173'),
            'api_url' => env('PAYMENTS_API_URL_DEV', 'http://127.0.0.1:8000/api'),
            // A tunnel (cloudflared / ngrok) providers can actually reach in
            // local dev. Deliberately separate from api_url: the browser keeps
            // talking to 127.0.0.1 while callbacks come in over the tunnel.
            'public_api_url' => env('PAYMENTS_PUBLIC_API_URL_DEV'),
        ],

    ],

    'stripe' => [
        'live' => [
            'secret' => env('STRIPE_SECRET_KEY'),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        ],
        'test' => [
            'secret' => env('STRIPE_SECRET_KEY_TEST'),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET_TEST'),
        ],
        'currency' => 'usd',

        /*
        | Live keys require HTTPS callbacks. Stripe will not accept a plaintext
        | live-mode webhook endpoint, and shipping live keys without a reachable
        | webhook means cards get charged while invoices stay pending forever
        | (and accounts stay disabled by engine:mark-overdue). With this on, a
        | production box whose callback URL is still http:// resolves to the
        | TEST key set and flips to live on its own once certbot has run.
        */
        'require_https_in_live' => true,
    ],

    'coinsbuy' => [
        'production' => [
            'base_url' => env('COINSBUY_BASE_URL', 'https://v3.api.coinsbuy.com'),
            'client_id' => env('COINSBUY_API_KEY'),
            'client_secret' => env('COINSBUY_API_SECRET'),
            'webhook_secret' => env('COINSBUY_WEBHOOK_SECRET'),
            'wallet_id' => env('COINSBUY_USD_WALLET_ID', '287'),
            'default_crypto' => 'USDT',
        ],
        'sandbox' => [
            'base_url' => env('COINSBUY_BASE_URL_SANDBOX', 'https://v3.api-sandbox.coinsbuy.com'),
            'client_id' => env('COINSBUY_API_KEY_SANDBOX'),
            'client_secret' => env('COINSBUY_API_SECRET_SANDBOX'),
            'webhook_secret' => env('COINSBUY_WEBHOOK_SECRET_SANDBOX'),
            'wallet_id' => env('COINSBUY_USD_WALLET_ID_SANDBOX', '873'),
            'default_crypto' => 'BTC',
        ],

        'cryptocurrencies' => ['BTC', 'ETH', 'USDT', 'USDC'],
        'fiat_currency' => 'USD',

        /*
        | Rate-fluctuation tolerance, as a percentage of the invoice. It is BOTH
        | the `inaccuracy` we ask Coinsbuy for when creating the deposit AND the
        | shortfall the webhook accepts — one number, so the two can never
        | contradict. (The mother requested 1% and then rejected anything more
        | than $0.01 off, which silently refused legitimate payments on any
        | invoice above $1: money taken, invoice left unpaid.)
        */
        'inaccuracy_pct' => 1.0,

        'time_limit' => 3600,
        'confirmations' => 1,
        'token_ttl_buffer' => 60,

        /*
        | Transport. `connect_timeout` is the one that bites: where outbound 443
        | to the Coinsbuy hosts is blocked (some ISPs and AV suites filter them),
        | cURL sits at connect until it expires and the trader watches a spinner.
        | Keep it short — a reachable Coinsbuy connects in well under a second.
        */
        'timeout' => (int) env('COINSBUY_TIMEOUT', 20),
        'connect_timeout' => (int) env('COINSBUY_CONNECT_TIMEOUT', 8),

        /*
        | TLS. WAMP ships without a usable cacert.pem, which shows up as cURL
        | error 60 on Windows only — point COINSBUY_CACERT at a cacert.pem, or
        | set COINSBUY_VERIFY_SSL=false for LOCAL DEV ONLY. Verification stays on
        | everywhere else, so a copied .env cannot disable it in production.
        */
        'verify_ssl' => filter_var(
            env('COINSBUY_VERIFY_SSL', true),
            FILTER_VALIDATE_BOOLEAN
        ),
        'ca_bundle' => env('COINSBUY_CACERT'),

        /*
        | Egress address family. Coinsbuy authorises by source IP, and the VPS
        | has BOTH an A and an AAAA address while the Coinsbuy hosts publish
        | AAAA records — so by default the call goes out over IPv6 and the
        | address being judged is not the IPv4 one in the dashboard's allow-list.
        | Forcing IPv4 makes the source address predictable and equal to the
        | value a human can actually paste there. Turn off only if this box ever
        | becomes IPv6-only.
        */
        'force_ipv4' => filter_var(
            env('COINSBUY_FORCE_IPV4', true),
            FILTER_VALIDATE_BOOLEAN
        ),
    ],

    /*
    |----------------------------------------------------------------------
    | TRON — direct USDT-TRC20, no provider in the middle
    |----------------------------------------------------------------------
    |
    | Unlike Stripe and Coinsbuy this rail has NO CREDENTIALS: the "credential"
    | is a public address on a public chain, and detecting a payment is a read
    | of public data. That changes two things about how it is configured.
    |
    | 1. There is no live/test KEY SET to pin, so PaymentEnvironment's
    |    forceSandbox() has nothing to act on. What separates real money from
    |    test money is the NETWORK, and the watcher that detects payments is a
    |    scheduled command with no request and no user — a request-scoped flag
    |    could never reach it. So the network is chosen per caller at intent
    |    creation, written onto the intent row, and the watcher simply scans
    |    every network configured below.
    | 2. Nothing here is secret except the optional TronGrid API key. The
    |    addresses are public by construction.
    |
    */
    'tron' => [

        /*
        | Visible to non-developers. Until this is true only `developer`
        | accounts are offered the rail at all — which is what lets the whole
        | flow be exercised on the production box with no customer seeing it.
        */
        'public' => filter_var(env('TRON_PUBLIC', false), FILTER_VALIDATE_BOOLEAN),

        /*
        | Which network a caller's intent belongs to. Developers get the test
        | network on EVERY machine, production included — the TRON equivalent of
        | PaymentController pinning them to sandbox provider keys.
        |
        | Everyone else is gated on the machine verdict rather than a single
        | flat default, so a non-developer on a dev box can never be shown the
        | real mainnet receiving address.
        */
        'developer_network' => env('TRON_DEVELOPER_NETWORK', 'nile'),
        'default_network' => [
            'production' => env('TRON_NETWORK_PRODUCTION', 'mainnet'),
            'sandbox' => env('TRON_NETWORK_SANDBOX', 'nile'),
        ],

        'networks' => [
            'mainnet' => [
                'base_url' => env('TRON_MAINNET_BASE_URL', 'https://api.trongrid.io'),
                'api_key' => env('TRON_MAINNET_API_KEY'),
                // The receiving wallet. Empty until a real one exists; every
                // entry point reports "not configured" rather than guessing.
                'address' => env('TRON_MAINNET_ADDRESS'),
                // Tether's own USDT-TRC20 contract. VERIFY against the live
                // chain before shipping: this constant being wrong means money
                // goes somewhere else, and nothing downstream would notice.
                'contract' => env('TRON_MAINNET_USDT_CONTRACT', 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t'),
                'asset' => 'USDT',
                'decimals' => 6,
                'label' => 'TRC-20 (TRON)',
                'explorer_tx' => 'https://tronscan.org/#/transaction/',
                'explorer_address' => 'https://tronscan.org/#/address/',
            ],
            'nile' => [
                'base_url' => env('TRON_NILE_BASE_URL', 'https://nile.trongrid.io'),
                'api_key' => env('TRON_NILE_API_KEY'),
                'address' => env('TRON_NILE_ADDRESS'),
                // No default on purpose — look the test token up on
                // nile.tronscan.org rather than inheriting a guess.
                'contract' => env('TRON_NILE_USDT_CONTRACT'),
                'asset' => 'USDT',
                'decimals' => 6,
                'label' => 'TRC-20 (TRON Nile testnet)',
                'explorer_tx' => 'https://nile.tronscan.org/#/transaction/',
                'explorer_address' => 'https://nile.tronscan.org/#/address/',
            ],
        ],

        /*
        | How long a quoted amount stays reserved. A payment arriving after this
        | is not lost — the intent keeps its expected_units, so the admin screen
        | can still name the invoice it was meant for.
        */
        'intent_ttl' => (int) env('TRON_INTENT_TTL', 3600),

        /*
        | How long an EXPIRED reservation still counts as "someone was paying
        | this". A payment arriving inside this window that fits both an open
        | reservation and a recently expired one is NOT handed to the open one:
        | it is held, and both customers are asked for their transaction ID.
        | Without it, a withdrawal that cleared after its payer's timer ran out
        | settled whoever happened to be waiting for a similar amount.
        */
        'late_match_hours' => (int) env('TRON_LATE_MATCH_HOURS', 24),

        /*
        | How long a held payment keeps asking its possible owners for their
        | transaction ID on the pay sheet. After this only an admin places it.
        */
        'claim_window_days' => (int) env('TRON_CLAIM_WINDOW_DAYS', 14),

        /*
        | Accepted shortfall — deliberately generous, and floored at an absolute
        | amount rather than being purely proportional.
        |
        | EVERY MAJOR EXCHANGE DEDUCTS ITS WITHDRAWAL FEE FROM THE AMOUNT THE
        | CUSTOMER TYPES. A trader who enters exactly the figure we display
        | arrives short by that fee (1 USDT, then 0.2 USDT, on Binance's TRC-20
        | at different times) — so a percentage-only tolerance rejects every
        | small invoice paid from an exchange. This is the same shape as the
        | mother's bug: 1% requested, $0.01 accepted, legitimate payments
        | refused with the money already taken.
        */
        'shortfall_pct' => 1.0,
        'shortfall_min_usd' => 1.00,
        'overpay_pct' => 5.0,

        /*
        | Sub-cent amount fingerprint, in base units. 0 = OFF, and off is the
        | current posture.
        |
        | The idea is to add a random sub-cent offset so each quoted amount is
        | unique. It cannot coexist with the tolerance above: a band wide enough
        | to absorb an exchange's withdrawal fee is roughly 100,000x wider than
        | the spacing between fingerprints, so any window that lets real
        | payments settle also makes fingerprints ambiguous. Uniqueness comes
        | from UNIQUE(network, address, open_units) instead, which is a stronger
        | guarantee than a random offset that can still collide.
        |
        | Turn this on ONLY after measuring, per exchange, that the decimals
        | actually survive a real withdrawal and how much fee is deducted.
        */
        'fingerprint_units' => (int) env('TRON_FINGERPRINT_UNITS', 0),
        'fingerprint_attempts' => 8,

        /*
        | Scanning. The receiving address is public, so anyone can spray dust at
        | it for free — every bound here exists to keep that from costing us
        | anything. `min_value_units` drops sub-$0.10 noise before it can reach
        | the audit table; `max_pages` stops one flood making a single run
        | outlast its own minute (with ascending order, an exhausted page budget
        | simply means the next run continues where this one stopped).
        */
        'min_value_units' => (int) env('TRON_MIN_VALUE_UNITS', 100000),
        'scan_overlap_minutes' => 30,
        'scan_max_lookback_hours' => 72,
        'scan_stale_minutes' => 10,
        'page_limit' => 200,
        'max_pages' => 10,

        'timeout' => (int) env('TRON_TIMEOUT', 20),
        'connect_timeout' => (int) env('TRON_CONNECT_TIMEOUT', 8),

        // Same WAMP cURL-error-60 story as Coinsbuy above.
        'verify_ssl' => filter_var(env('TRON_VERIFY_SSL', true), FILTER_VALIDATE_BOOLEAN),
        'ca_bundle' => env('TRON_CACERT'),
        'force_ipv4' => filter_var(env('TRON_FORCE_IPV4', true), FILTER_VALIDATE_BOOLEAN),
    ],

];
