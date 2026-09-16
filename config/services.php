<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Binance's own published fee schedule — not a credential, but a figure the
    // exchange sets and can change, so it lives in config rather than in code.
    //
    // `taker_fee_rate` is the USDⓈ-M futures TAKER commission per side (0.05%
    // at VIP 0). Taker is the only rate that applies: the engine places market
    // orders exclusively. App\Services\Pnl\TradingFee charges it on both legs of
    // a close, which is what makes a stored trade match what the customer sees
    // in the Binance app. Raise it here if the account's VIP tier or a BNB
    // discount ever changes what we actually pay.
    'binance' => [
        'taker_fee_rate' => (float) env('BINANCE_TAKER_FEE_RATE', 0.0005),
    ],

    // Same figure for MEXC USDT-M futures: standard TAKER commission 0.02% per
    // side. Many MEXC pairs run 0-fee promotions, so this estimate can overstate
    // the fee on those — the receipts ledger replaces it with the actual charge
    // (FeeRebase) as soon as the close's fills are attributed, exactly as for
    // Binance. ExchangeSchema maps the exchange name to this key.
    'mexc' => [
        'taker_fee_rate' => (float) env('MEXC_TAKER_FEE_RATE', 0.0002),
    ],

    // The Python trading engine (trading-flask, package binance_abcd).
    //
    // `secret`          — engine -> API auth (X-Engine-Secret, VerifyEngineSecret).
    // `webhook_secrets` — API/TradingView -> engine auth, keyed by exchange so
    //                     bybit/mexc can each carry their own token when their
    //                     engines land. binance = BINANCE_ABCD_WEBHOOK_SECRET on
    //                     the engine side. Stays server-side: the manual-trade
    //                     proxy signs with it and only ever shows a masked copy.
    // `targets`         — the only engine URLs the proxy may post to. Admins pick
    //                     a key, never a URL, so no arbitrary host can be reached.
    'engine' => [
        'secret' => env('ENGINE_SECRET'),
        // systemd unit name for the local engine service (AdminEngineController).
        'service' => env('ENGINE_SERVICE', 'sinegualerts-engine'),
        // null = auto-detect (Linux only); tests override to fake availability.
        'systemd' => env('ENGINE_SYSTEMD'),
        'webhook_secrets' => [
            // Legacy ENGINE_WEBHOOK_SECRET stays as a fallback so already-deployed
            // .env files keep working until they're renamed.
            'binance' => env('BINANCE_ENGINE_WEBHOOK_SECRET', env('ENGINE_WEBHOOK_SECRET')),
        ],
        'targets' => [
            'local' => env('ENGINE_URL_LOCAL', 'http://127.0.0.1:5010'),
            'prod' => env('ENGINE_URL_PROD', 'http://127.0.0.1:5010'),
        ],
        // The outbound address the exchange sees when this box calls it — what
        // a user must allow-list on their API key. Shown verbatim in the
        // "key blocked" modal, so it must be the IP, never a URL.
        'public_ip' => env('ENGINE_PUBLIC_IP', '2.24.139.176'),
    ],

    'track_record' => [
        // The calendar the PUBLISHED track record buckets its days in. Rows are
        // stored in UTC; this decides where "a day" starts and ends for the
        // landing-page chart AND the Telegram recaps (the engine reads it off
        // the payload), so the two can never disagree about which day a trade
        // belongs to. The team and the audience run on Manila time, and a
        // "today" recap that ended at 08:00 local was the complaint.
        'timezone' => env('TRACK_RECORD_TIMEZONE', 'Asia/Manila'),
    ],

];
