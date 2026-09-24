<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Venues customers may not connect yet
    |--------------------------------------------------------------------------
    |
    | A venue can be fully live in the engine — tables, adapter, pollers — and
    | still not be something to hand a paying customer. MEXC and Bybit are both
    | exactly that today: they trade, but neither has been run long enough on a
    | real customer account to sell, so only staff (admin / master / developer,
    | the same roles the admin portal uses) may connect one.
    |
    | This gates CONNECTING ONLY. An account already connected keeps trading,
    | keeps syncing, and can always be renamed or disconnected — restricting a
    | venue must never trap someone's keys inside it.
    |
    | CSV in the env so opening a venue is a config change on the box
    | (`EXCHANGES_STAFF_ONLY=mexc` + `php artisan config:cache` to open Bybit
    | alone), never a deploy. Note the consequence of the CSV: clearing the key
    | entirely opens EVERY listed venue at once, so name the ones that stay shut
    | rather than emptying it.
    | Deliberately NOT mirrored by the deploy script: it is rollout state, like
    | TRON_PUBLIC and DISCORD_LOGIN_PUBLIC — a local experiment must not open a
    | venue to every customer on the next sync. The default is the closed state,
    | so a box with no such key set still protects it — which is why a NEW venue
    | must be added to this default in the same change that registers it.
    |
    */

    'staff_only' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('EXCHANGES_STAFF_ONLY', 'mexc,bybit'))
    ))),

];
