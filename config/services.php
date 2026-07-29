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

    // The Python trading engine (trading-flask, package binance_abcd).
    //
    // `secret`         — engine -> API auth (X-Engine-Secret, VerifyEngineSecret).
    // `webhook_secret` — API -> engine auth, i.e. BINANCE_ABCD_WEBHOOK_SECRET.
    //                    Stays server-side: the admin manual-trade proxy signs
    //                    requests with it so the browser never sees it.
    // `targets`        — the only engine URLs the proxy may post to. Admins pick
    //                    a key, never a URL, so no arbitrary host can be reached.
    'engine' => [
        'secret' => env('ENGINE_SECRET'),
        'webhook_secret' => env('ENGINE_WEBHOOK_SECRET'),
        'targets' => [
            'local' => env('ENGINE_URL_LOCAL', 'http://127.0.0.1:5010'),
            'prod' => env('ENGINE_URL_PROD', 'http://127.0.0.1:5010'),
        ],
    ],

];
