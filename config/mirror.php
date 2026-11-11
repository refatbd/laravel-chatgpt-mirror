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
        'read_timeout' => (int) env('MIRROR_READ_TIMEOUT', 120),
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
        'static_tokens_raw' => (string) env('MIRROR_SESSION_TOKENS', ''),
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
        'playground_prefix' => env('MIRROR_PLAYGROUND_PREFIX', 'mirror/playground'),
        'help_prefix' => env('MIRROR_HELP_PREFIX', 'mirror/help'),
        'assets_prefix' => env('MIRROR_ASSETS_PREFIX', 'mirror/assets'),
        'docs_prefix' => env('MIRROR_DOCS_PREFIX', 'mirror'),
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

    /*
    |--------------------------------------------------------------------------
    | Redis & High-Concurrency Cache Configuration
    |--------------------------------------------------------------------------
    */
    'cache' => [
        'store' => env('MIRROR_CACHE_STORE'),
        'prefix' => env('MIRROR_CACHE_PREFIX', 'mirror:'),
        'models_ttl' => (int) env('MIRROR_MODELS_CACHE_TTL', 60),
        'settings_ttl' => (int) env('MIRROR_SETTINGS_CACHE_TTL', 300),
        'assets_ttl' => (int) env('MIRROR_ASSETS_CACHE_TTL', 3600),
        'assets_in_redis' => env('MIRROR_CACHE_ASSETS_IN_REDIS', true),
        'assets_redis_max_bytes' => (int) env('MIRROR_ASSETS_REDIS_MAX_BYTES', 1048576),
        'lock_ttl' => (int) env('MIRROR_LOCK_TTL', 15),
        'lock_wait' => (int) env('MIRROR_LOCK_WAIT', 10),
    ],

    'redis' => [
        'enabled' => env('MIRROR_REDIS_ENABLED', 'auto'), // auto | enabled | disabled
        'client' => env('MIRROR_REDIS_CLIENT', 'auto'), // auto | phpredis | predis
        'url' => env('MIRROR_REDIS_URL'),
        'host' => env('MIRROR_REDIS_HOST'),
        'port' => (int) env('MIRROR_REDIS_PORT', 6379),
        'password' => env('MIRROR_REDIS_PASSWORD'),
        'database' => (int) env('MIRROR_REDIS_DB', 0),
        'persistent' => env('MIRROR_REDIS_PERSISTENT', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Universal Web Server & Reverse Proxy Runtime Settings
    | (Supports Apache, Nginx, LiteSpeed/OpenLiteSpeed, Caddy, Octane)
    |--------------------------------------------------------------------------
    */
    'server' => [
        'platform' => env('MIRROR_SERVER_PLATFORM', 'auto'),
        'trust_proxies' => env('MIRROR_TRUST_PROXIES', true),
        'trusted_proxies' => env('MIRROR_TRUSTED_PROXIES', '*'),
        'force_https' => env('MIRROR_FORCE_HTTPS', null),
        'stream_max_execution_time' => (int) env('MIRROR_STREAM_MAX_EXECUTION_TIME', 300),
        'disable_output_compression' => env('MIRROR_DISABLE_OUTPUT_COMPRESSION', true),
    ],

    'cors' => [
        'enabled' => env('MIRROR_CORS_ENABLED', true),
        'allowed_origins' => env('MIRROR_CORS_ALLOWED_ORIGINS', '*'),
        'allow_methods' => env('MIRROR_CORS_ALLOW_METHODS', 'GET, POST, PUT, PATCH, DELETE, OPTIONS'),
        'allow_headers' => env('MIRROR_CORS_ALLOW_HEADERS', 'Authorization, Content-Type, X-Requested-With, X-Request-Id, Accept, Origin'),
        'expose_headers' => env('MIRROR_CORS_EXPOSE_HEADERS', 'X-Request-Id, X-Mirror-Conversation-Id, X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset, Retry-After'),
    ],

    'rate_limit' => [
        'enabled' => env('MIRROR_RATE_LIMIT_ENABLED', false),
        'max_attempts' => (int) env('MIRROR_RATE_LIMIT_MAX_ATTEMPTS', 180),
        'decay_seconds' => (int) env('MIRROR_RATE_LIMIT_DECAY_SECONDS', 60),
    ],

];
