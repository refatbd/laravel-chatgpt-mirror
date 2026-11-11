<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mirror Upstream Configuration
    |--------------------------------------------------------------------------
    */
    'upstream' => env('MIRROR_UPSTREAM', 'https://chatgpt.com'),

    /*
    |--------------------------------------------------------------------------
    | API Security
    |--------------------------------------------------------------------------
    */
    'api_key' => env('MIRROR_API_KEY', ''),

    'allowed_hosts' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('MIRROR_ALLOWED_HOSTS', ''))
    ))),

    'asset_ticket_secret' => env('MIRROR_ASSET_SECRET', ''),

    /*
    |--------------------------------------------------------------------------
    | HTTP Client Configuration (Direct Egress)
    |--------------------------------------------------------------------------
    */
    'http' => [
        'proxy' => env('MIRROR_HTTP_PROXY', null),
        'timeout' => (int) env('MIRROR_REQUEST_TIMEOUT', 120),
        'connect_timeout' => (int) env('MIRROR_CONNECT_TIMEOUT', 15),
        'verify_ssl' => env('MIRROR_VERIFY_SSL', true),
        'user_agent' => env(
            'MIRROR_USER_AGENT',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/134.0.0.0 Safari/537.36'
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Token Pool & Rotation Settings
    |--------------------------------------------------------------------------
    */
    'pool' => [
        'strategy' => env('MIRROR_POOL_STRATEGY', 'least_busy'),
        'max_concurrent_per_token' => (int) env('MIRROR_MAX_CONCURRENT_PER_TOKEN', 5),
        'cooldown_seconds' => (int) env('MIRROR_COOLDOWN_SECONDS', 60),
        'refresh_buffer_seconds' => (int) env('MIRROR_REFRESH_BUFFER_SECONDS', 300),
        'static_tokens' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('MIRROR_SESSION_TOKENS', ''))
        ))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Route Prefixes & Middleware
    |--------------------------------------------------------------------------
    */
    'routes' => [
        'openai_prefix' => env('MIRROR_OPENAI_PREFIX', 'v1'),
        'browser_prefix' => env('MIRROR_BROWSER_PREFIX', ''),
        'backend_prefix' => env('MIRROR_BACKEND_PREFIX', 'backend-api'),
        'admin_prefix' => env('MIRROR_ADMIN_PREFIX', 'mirror/admin'),
        'middleware' => ['api'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging & Telemetry
    |--------------------------------------------------------------------------
    */
    'logging' => [
        'enabled' => env('MIRROR_LOGGING', true),
        'channel' => env('MIRROR_LOG_CHANNEL', 'stack'),
    ],

];
