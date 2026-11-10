<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Mirror Master Switch & Authentication
    |--------------------------------------------------------------------------
    |
    | When enabled, routes for OpenAI compatibility and browser proxying
    | will be registered. The api_key protects the /v1/chat/completions
    | and /v1/models endpoints.
    |
    */
    'enabled' => env('MIRROR_ENABLED', true),
    'api_key' => env('MIRROR_API_KEY', env('OPENAI_API_KEY', '')),

    /*
    |--------------------------------------------------------------------------
    | Route Prefixes
    |--------------------------------------------------------------------------
    |
    | Prefixes for the API endpoints and the proxied browser interface.
    |
    */
    'routes' => [
        'openai_prefix' => env('MIRROR_OPENAI_PREFIX', 'v1'),
        'browser_prefix' => env('MIRROR_BROWSER_PREFIX', 'mirror'),
        'backend_prefix' => env('MIRROR_BACKEND_PREFIX', 'backend-api'),
        'middleware' => ['api'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Upstream Target
    |--------------------------------------------------------------------------
    |
    | The official ChatGPT upstream server to proxy.
    |
    */
    'upstream' => env('MIRROR_UPSTREAM', 'https://chatgpt.com'),

    /*
    |--------------------------------------------------------------------------
    | High Concurrency & Multi-Token Load Balancing
    |--------------------------------------------------------------------------
    |
    | When serving 100-200 concurrent users, a single ChatGPT session token will
    | quickly encounter upstream 429 concurrency throttles. The pool manager
    | rotates requests across healthy tokens.
    |
    | Strategies:
    | - "least_busy": Dispatches to the token currently handling fewest active requests (Best for concurrency)
    | - "round_robin": Cycles through tokens in sequence
    | - "random": Randomly selects an active token
    |
    */
    'pool' => [
        'strategy' => env('MIRROR_POOL_STRATEGY', 'least_busy'),
        'cooldown_seconds' => (int) env('MIRROR_COOLDOWN_SECONDS', 60),
        'max_retries' => (int) env('MIRROR_MAX_RETRIES', 3),
        'circuit_breaker' => env('MIRROR_CIRCUIT_BREAKER', true),
        // Static tokens configured via .env as a fallback or bootstrap
        'static_tokens' => array_filter(explode(',', env('MIRROR_SESSION_TOKENS', ''))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Network Egress Configuration (Direct Mode - No Cloudflare WARP)
    |--------------------------------------------------------------------------
    |
    | Direct network connectivity without requiring Cloudflare WARP.
    | Standard cURL/Guzzle transport with customizable timeouts and browser
    | impersonation headers.
    |
    */
    'egress' => [
        'mode' => env('MIRROR_EGRESS_MODE', 'direct'),
        'proxy' => env('MIRROR_PROXY', null), // Optional HTTP/SOCKS5 proxy: "socks5://127.0.0.1:1080"
        'timeout' => (int) env('MIRROR_TIMEOUT', 90),
        'connect_timeout' => (int) env('MIRROR_CONNECT_TIMEOUT', 10),
        'verify_ssl' => env('MIRROR_VERIFY_SSL', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Caching Layer
    |--------------------------------------------------------------------------
    |
    | Caches model lists, session claims, and token metadata to minimize
    | unnecessary upstream requests under high load.
    |
    */
    'cache' => [
        'enabled' => env('MIRROR_CACHE_ENABLED', true),
        'driver' => env('MIRROR_CACHE_DRIVER', null), // null uses default Laravel cache driver
        'models_ttl' => (int) env('MIRROR_MODELS_CACHE_TTL', 3600), // 1 hour
        'session_ttl' => (int) env('MIRROR_SESSION_CACHE_TTL', 1800), // 30 minutes
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage & Encryption
    |--------------------------------------------------------------------------
    |
    | Tokens are encrypted at rest using Laravel's Crypt facade (AES-256-CBC).
    |
    */
    'storage' => [
        'driver' => env('MIRROR_STORAGE_DRIVER', 'database'), // "database" or "config"
        'table' => 'mirror_tokens',
        'conversations_table' => 'mirror_conversations',
    ],

    /*
    |--------------------------------------------------------------------------
    | Browser Proxy Patches
    |--------------------------------------------------------------------------
    |
    | Modifies proxied ChatGPT Web UI responses to bypass CSP and redirect
    | API calls to the local proxy.
    |
    */
    'browser' => [
        'strip_csp' => true,
        'inject_api_base' => true,
        'rewrite_codex' => true,
    ],
];
