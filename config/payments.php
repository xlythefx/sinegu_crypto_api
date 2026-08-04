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
    ],

];
