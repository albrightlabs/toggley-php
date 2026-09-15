<?php

return [
    // The environment's server key (tgly_srv_...). Keep it out of version control.
    'server_key' => env('TOGGLEY_SERVER_KEY'),

    'base_url' => env('TOGGLEY_BASE_URL', 'https://my.toggleyapp.com'),

    // Cache store for the config and evaluation counts. null uses the default store.
    // Use a store shared by all your servers (redis, database, memcached) where possible.
    'cache_store' => env('TOGGLEY_CACHE_STORE'),

    // Seconds before the cached config is checked for changes (after the response).
    'poll_interval' => (int) env('TOGGLEY_POLL_INTERVAL', 30),

    'timeout' => (float) env('TOGGLEY_TIMEOUT', 2.0),

    // Send evaluation counts to Toggley (counts only, never user data).
    'events' => (bool) env('TOGGLEY_EVENTS', true),

    // Path to a config file written by "php artisan toggley:sync --out=...", used when Toggley can't be reached.
    'bootstrap' => env('TOGGLEY_BOOTSTRAP'),

    // Never contact Toggley; serve the bootstrap config only (tests, CI).
    'offline' => (bool) env('TOGGLEY_OFFLINE', false),

    // Builds the context when you don't pass one. Receives the signed-in user (or null);
    // return an array like ['key' => ..., 'email' => ...]. null uses the user's id as the key.
    'context_resolver' => null,
];
