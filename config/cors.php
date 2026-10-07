<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS)
    |--------------------------------------------------------------------------
    |
    | Which browser origins may call /api/*. Without this file the framework's
    | own default applies — allowed_origins ['*'] — which lets a page on ANY
    | site drive the API from a visitor's browser.
    |
    | Prod serves the SPA and the API from one origin (nginx, pixel-alpha.com
    | and /api), so a browser never needs CORS there and the list is belt and
    | braces. It is load-bearing on a developer's box, where the SPA on the
    | Vite port calls the API on another port — hence localhost:5173 and
    | 127.0.0.1:5173 in the default. CSV in CORS_ALLOWED_ORIGINS; whole
    | origins (scheme://host[:port]), never a wildcard.
    |
    | `supports_credentials` stays false: auth is a bearer token in the
    | Authorization header, never a cookie, so there is nothing to share and
    | no reason to let a browser attach credentials cross-site.
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'CORS_ALLOWED_ORIGINS',
            'https://pixel-alpha.com,https://www.pixel-alpha.com,http://localhost:5173,http://127.0.0.1:5173',
        )),
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
