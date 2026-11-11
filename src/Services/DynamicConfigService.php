<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Refatbd\LaravelChatGptMirror\Models\MirrorSetting;

/**
 * Loads, validates, persists, and live-applies runtime overrides (Redis, Web Server, Pool)
 * from database settings so nothing stays hardcoded and servers can toggle features dynamically.
 */
class DynamicConfigService
{
    public const SETTINGS_KEY = 'runtime.overrides';

    /** @var array<string, mixed>|null */
    protected ?array $baselineMirrorConfig = null;

    public function ensureApplied(bool $forceReload = false): void
    {
        if ($this->baselineMirrorConfig === null) {
            $base = config('mirror', []);
            if (is_array($base)) {
                $this->baselineMirrorConfig = $base;
            }
        }

        $overrides = $this->readOverridesFromDatabase();
        if ($overrides === [] && !$forceReload) {
            // Still ensure configured mirror Redis store tracks active config/env cleanly.
            $this->configureMirrorRedis();
            return;
        }

        if ($this->baselineMirrorConfig !== null) {
            config(['mirror' => $this->baselineMirrorConfig]);
        }

        foreach ($overrides as $path => $value) {
            config(['mirror.' . $path => $value]);
        }

        $this->configureMirrorRedis();
    }

    /**
     * @return array<string, mixed>
     */
    public function readOverridesFromDatabase(): array
    {
        try {
            $row = MirrorSetting::where('key', self::SETTINGS_KEY)->first();
            if (!$row || trim((string) $row->value) === '') {
                return [];
            }

            $decoded = json_decode((string) $row->value, true);
            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            // Table may not exist yet during first install/migration or isolated unit tests.
            return [];
        }
    }

    /**
     * Normalize and persist partial/full runtime settings to DB, then apply immediately.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function saveOverrides(array $input): array
    {
        $existing = $this->readOverridesFromDatabase();
        $next = $existing;

        if (array_key_exists('redis_enabled', $input) || array_key_exists('redis.enabled', $input)) {
            $raw = $input['redis_enabled'] ?? $input['redis.enabled'];
            $next['redis.enabled'] = $this->normalizeTriState($raw);
        }

        if (array_key_exists('redis_client', $input) || array_key_exists('redis.client', $input)) {
            $client = strtolower(trim((string) ($input['redis_client'] ?? $input['redis.client'])));
            $next['redis.client'] = in_array($client, ['auto', 'phpredis', 'predis'], true) ? $client : 'auto';
        }

        $urlUpdatedInInput = array_key_exists('redis_url', $input) || array_key_exists('redis.url', $input);
        if ($urlUpdatedInInput) {
            $url = trim((string) ($input['redis_url'] ?? $input['redis.url'] ?? ''));
            $next['redis.url'] = $url !== '' ? $url : null;
        }

        $hostOrPortUpdated = array_key_exists('redis_host', $input)
            || array_key_exists('redis.host', $input)
            || array_key_exists('redis_port', $input)
            || array_key_exists('redis.port', $input);
        if ($hostOrPortUpdated && !$urlUpdatedInInput) {
            $next['redis.url'] = null;
        }

        if (array_key_exists('redis_host', $input) || array_key_exists('redis.host', $input)) {
            $host = trim((string) ($input['redis_host'] ?? $input['redis.host'] ?? ''));
            $next['redis.host'] = $host !== '' ? $host : null;
        }

        if (array_key_exists('redis_port', $input) || array_key_exists('redis.port', $input)) {
            $port = (int) ($input['redis_port'] ?? $input['redis.port']);
            $next['redis.port'] = max(1, min(65535, $port > 0 ? $port : 6379));
        }

        if (!empty($input['clear_redis_password'])) {
            $next['redis.password'] = null;
        } elseif (array_key_exists('redis_password', $input) || array_key_exists('redis.password', $input)) {
            $pw = (string) ($input['redis_password'] ?? $input['redis.password'] ?? '');
            if ($pw !== '') {
                $next['redis.password'] = $pw;
            }
        }

        if (array_key_exists('redis_database', $input) || array_key_exists('redis.database', $input)) {
            $db = (int) ($input['redis_database'] ?? $input['redis.database']);
            $next['redis.database'] = max(0, min(15, $db));
        }

        if (array_key_exists('redis_persistent', $input) || array_key_exists('redis.persistent', $input)) {
            $next['redis.persistent'] = filter_var(
                $input['redis_persistent'] ?? $input['redis.persistent'],
                FILTER_VALIDATE_BOOLEAN
            );
        }

        if (array_key_exists('cache_store', $input) || array_key_exists('cache.store', $input)) {
            $store = trim((string) ($input['cache_store'] ?? $input['cache.store'] ?? ''));
            $next['cache.store'] = $store !== '' ? $store : null;
        }

        if (array_key_exists('cache_models_ttl', $input) || array_key_exists('cache.models_ttl', $input)) {
            $next['cache.models_ttl'] = max(0, min(86400, (int) ($input['cache_models_ttl'] ?? $input['cache.models_ttl'])));
        }

        if (array_key_exists('cache_assets_in_redis', $input) || array_key_exists('cache.assets_in_redis', $input)) {
            $next['cache.assets_in_redis'] = filter_var(
                $input['cache_assets_in_redis'] ?? $input['cache.assets_in_redis'],
                FILTER_VALIDATE_BOOLEAN
            );
        }

        if (array_key_exists('server_platform', $input) || array_key_exists('server.platform', $input)) {
            $platform = strtolower(trim((string) ($input['server_platform'] ?? $input['server.platform'])));
            $allowed = ['auto', 'apache', 'webuzo', 'nginx', 'litespeed', 'openlitespeed', 'caddy', 'frankenphp', 'octane'];
            $next['server.platform'] = in_array($platform, $allowed, true) ? $platform : 'auto';
        }

        if (array_key_exists('server_trust_proxies', $input) || array_key_exists('server.trust_proxies', $input)) {
            $next['server.trust_proxies'] = filter_var(
                $input['server_trust_proxies'] ?? $input['server.trust_proxies'],
                FILTER_VALIDATE_BOOLEAN
            );
        }

        if (array_key_exists('server_trusted_proxies', $input) || array_key_exists('server.trusted_proxies', $input)) {
            $trusted = trim((string) ($input['server_trusted_proxies'] ?? $input['server.trusted_proxies'] ?? '*'));
            $next['server.trusted_proxies'] = $trusted !== '' ? $trusted : '*';
        }

        if (array_key_exists('server_force_https', $input) || array_key_exists('server.force_https', $input)) {
            $fh = strtolower(trim((string) ($input['server_force_https'] ?? $input['server.force_https'] ?? 'auto')));
            $next['server.force_https'] = match ($fh) {
                '1', 'true', 'on', 'yes', 'https' => true,
                '0', 'false', 'off', 'no', 'http' => false,
                default => null,
            };
        }

        if (array_key_exists('server_stream_max_execution_time', $input) || array_key_exists('server.stream_max_execution_time', $input)) {
            $next['server.stream_max_execution_time'] = max(0, min(3600, (int) ($input['server_stream_max_execution_time'] ?? $input['server.stream_max_execution_time'])));
        }

        if (array_key_exists('server_disable_output_compression', $input) || array_key_exists('server.disable_output_compression', $input)) {
            $next['server.disable_output_compression'] = filter_var(
                $input['server_disable_output_compression'] ?? $input['server.disable_output_compression'],
                FILTER_VALIDATE_BOOLEAN
            );
        }

        if (array_key_exists('pool_strategy', $input) || array_key_exists('pool.strategy', $input)) {
            $strategy = strtolower(trim((string) ($input['pool_strategy'] ?? $input['pool.strategy'])));
            $next['pool.strategy'] = in_array($strategy, ['least_busy', 'round_robin', 'random'], true)
                ? $strategy
                : 'least_busy';
        }

        if (array_key_exists('pool_max_concurrent_per_token', $input) || array_key_exists('pool.max_concurrent_per_token', $input)) {
            $next['pool.max_concurrent_per_token'] = max(1, min(100, (int) ($input['pool_max_concurrent_per_token'] ?? $input['pool.max_concurrent_per_token'])));
        }

        if (array_key_exists('pool_cooldown_seconds', $input) || array_key_exists('pool.cooldown_seconds', $input)) {
            $next['pool.cooldown_seconds'] = max(1, min(3600, (int) ($input['pool_cooldown_seconds'] ?? $input['pool.cooldown_seconds'])));
        }

        if (array_key_exists('http_read_timeout', $input) || array_key_exists('http.read_timeout', $input)) {
            $next['http.read_timeout'] = max(5, min(3600, (int) ($input['http_read_timeout'] ?? $input['http.read_timeout'])));
        }

        if (array_key_exists('cors_enabled', $input) || array_key_exists('cors.enabled', $input)) {
            $rawCors = $input['cors_enabled'] ?? $input['cors.enabled'];
            $next['cors.enabled'] = filter_var($rawCors, FILTER_VALIDATE_BOOLEAN);
        }

        if (array_key_exists('cors_allowed_origins', $input) || array_key_exists('cors.allowed_origins', $input)) {
            $origins = trim((string) ($input['cors_allowed_origins'] ?? $input['cors.allowed_origins'] ?? '*'));
            $next['cors.allowed_origins'] = $origins !== '' ? $origins : '*';
        }

        if (array_key_exists('rate_limit_enabled', $input) || array_key_exists('rate_limit.enabled', $input)) {
            $rawRl = $input['rate_limit_enabled'] ?? $input['rate_limit.enabled'];
            $next['rate_limit.enabled'] = filter_var($rawRl, FILTER_VALIDATE_BOOLEAN);
        }

        if (array_key_exists('rate_limit_max_attempts', $input) || array_key_exists('rate_limit.max_attempts', $input)) {
            $next['rate_limit.max_attempts'] = max(1, min(100000, (int) ($input['rate_limit_max_attempts'] ?? $input['rate_limit.max_attempts'])));
        }

        if (array_key_exists('rate_limit_decay_seconds', $input) || array_key_exists('rate_limit.decay_seconds', $input)) {
            $next['rate_limit.decay_seconds'] = max(1, min(86400, (int) ($input['rate_limit_decay_seconds'] ?? $input['rate_limit.decay_seconds'])));
        }

        // If admin explicitly enabled Redis without typing a host/url yet, default host gracefully.
        if (($next['redis.enabled'] ?? null) === 'enabled' && empty($next['redis.host']) && empty($next['redis.url']) && empty(config('mirror.redis.host')) && empty(config('mirror.redis.url'))) {
            $next['redis.host'] = '127.0.0.1';
        }

        MirrorSetting::updateOrCreate(
            ['key' => self::SETTINGS_KEY],
            ['value' => (string) json_encode($next, JSON_UNESCAPED_SLASHES)]
        );

        $this->ensureApplied(true);
        $this->purgeRedisConnections();

        return $this->publicState();
    }

    public function resetOverrides(): array
    {
        MirrorSetting::where('key', self::SETTINGS_KEY)->delete();
        $this->ensureApplied(true);
        $this->purgeRedisConnections();

        return $this->publicState();
    }

    /**
     * Dynamically configure Laravel's `database.redis.mirror` and `cache.stores.mirror_redis`
     * whenever Redis mode is enabled or auto-detected cleanly.
     */
    public function configureMirrorRedis(): void
    {
        $enabledMode = $this->normalizeTriState(config('mirror.redis.enabled', 'auto'));
        if ($enabledMode === 'disabled') {
            return;
        }

        $redisUrl = trim((string) (config('mirror.redis.url') ?? ''));
        $redisHost = trim((string) (config('mirror.redis.host') ?? ''));

        if ($enabledMode === 'auto' && $redisUrl === '' && $redisHost === '' && config('mirror.cache.store') !== 'mirror_redis') {
            return;
        }

        $effectiveHost = $redisHost !== '' ? $redisHost : '127.0.0.1';
        $conn = array_filter([
            'url' => $redisUrl !== '' ? $redisUrl : null,
            'host' => $effectiveHost,
            'password' => config('mirror.redis.password'),
            'port' => (int) config('mirror.redis.port', 6379),
            'database' => (int) config('mirror.redis.database', 0),
            'persistent' => (bool) config('mirror.redis.persistent', true),
        ], static fn ($value) => $value !== null && $value !== '');

        config(['database.redis.mirror' => $conn]);

        $client = $this->resolvedRedisClient();
        config(['database.redis.client' => $client]);

        config([
            'cache.stores.mirror_redis' => [
                'driver' => 'redis',
                'connection' => 'mirror',
                'lock_connection' => 'mirror',
            ],
        ]);
    }

    public function resolvedRedisClient(): string
    {
        $configured = strtolower(trim((string) config('mirror.redis.client', 'auto')));
        if ($configured === 'phpredis' || $configured === 'predis') {
            return $configured;
        }

        if (extension_loaded('redis')) {
            return 'phpredis';
        }

        if (class_exists(\Predis\Client::class)) {
            return 'predis';
        }

        return 'phpredis';
    }

    /**
     * Probe a Redis endpoint safely (using supplied params or active config) without crashing the app.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function testRedisConnection(array $params = []): array
    {
        $clientPref = strtolower(trim((string) ($params['client'] ?? $params['redis_client'] ?? $params['redis.client'] ?? config('mirror.redis.client', 'auto'))));
        $client = ($clientPref === 'phpredis' || $clientPref === 'predis') ? $clientPref : $this->resolvedRedisClient();

        $explicitUrl = trim((string) ($params['url'] ?? $params['redis_url'] ?? $params['redis.url'] ?? ''));
        $hasExplicitHostOrPort = array_key_exists('host', $params)
            || array_key_exists('redis_host', $params)
            || array_key_exists('redis.host', $params)
            || array_key_exists('port', $params)
            || array_key_exists('redis_port', $params)
            || array_key_exists('redis.port', $params);

        $url = $explicitUrl !== ''
            ? $explicitUrl
            : ($hasExplicitHostOrPort ? '' : trim((string) (config('mirror.redis.url') ?? '')));

        $host = trim((string) (config('mirror.redis.host') ?? '127.0.0.1')) ?: '127.0.0.1';
        $port = max(1, min(65535, (int) config('mirror.redis.port', 6379)));
        $password = (string) (config('mirror.redis.password') ?? '');
        $database = max(0, min(15, (int) config('mirror.redis.database', 0)));

        if ($url !== '') {
            $parsed = parse_url($url);
            if (is_array($parsed)) {
                $host = $parsed['host'] ?? $host;
                $port = isset($parsed['port']) ? (int) $parsed['port'] : $port;
                if (isset($parsed['pass'])) {
                    $password = urldecode((string) $parsed['pass']);
                }
                if (isset($parsed['path'])) {
                    $dbCandidate = trim((string) $parsed['path'], '/');
                    if (ctype_digit($dbCandidate)) {
                        $database = (int) $dbCandidate;
                    }
                }
            }
        }

        $overrideHost = $params['host'] ?? $params['redis_host'] ?? $params['redis.host'] ?? null;
        if (is_string($overrideHost) && trim($overrideHost) !== '') {
            $host = trim($overrideHost);
        }

        $overridePort = $params['port'] ?? $params['redis_port'] ?? $params['redis.port'] ?? null;
        if ($overridePort !== null && $overridePort !== '') {
            $port = max(1, min(65535, (int) $overridePort));
        }

        $overridePw = $params['password'] ?? $params['redis_password'] ?? $params['redis.password'] ?? null;
        if (is_string($overridePw) && $overridePw !== '') {
            $password = $overridePw;
        }

        $overrideDb = $params['database'] ?? $params['redis_database'] ?? $params['redis.database'] ?? null;
        if ($overrideDb !== null && $overrideDb !== '') {
            $database = max(0, min(15, (int) $overrideDb));
        }

        $t0 = hrtime(true);
        try {
            if ($client === 'predis' || (!extension_loaded('redis') && class_exists(\Predis\Client::class))) {
                if (!class_exists(\Predis\Client::class)) {
                    return [
                        'ok' => false,
                        'client' => 'predis',
                        'host' => $host,
                        'port' => $port,
                        'database' => $database,
                        'ping' => null,
                        'latency_ms' => round((hrtime(true) - $t0) / 1_000_000, 2),
                        'error' => 'Predis package is not installed (`composer require predis/predis`).',
                    ];
                }

                $predis = new \Predis\Client(array_filter([
                    'scheme' => str_starts_with($url, 'rediss://') ? 'tls' : 'tcp',
                    'host' => $host,
                    'port' => $port,
                    'password' => $password !== '' ? $password : null,
                    'database' => $database,
                    'timeout' => 2.5,
                ], static fn ($v) => $v !== null));
                $pong = (string) $predis->ping();

                return [
                    'ok' => true,
                    'client' => 'predis',
                    'host' => $host,
                    'port' => $port,
                    'database' => $database,
                    'ping' => $pong !== '' ? $pong : 'PONG',
                    'latency_ms' => round((hrtime(true) - $t0) / 1_000_000, 2),
                    'error' => null,
                ];
            }

            if (!extension_loaded('redis')) {
                return [
                    'ok' => false,
                    'client' => $client,
                    'host' => $host,
                    'port' => $port,
                    'database' => $database,
                    'ping' => null,
                    'latency_ms' => round((hrtime(true) - $t0) / 1_000_000, 2),
                    'error' => 'Neither PHP ext-redis (phpredis) nor predis/predis is installed on this PHP runtime.',
                ];
            }

            $redis = new \Redis();
            $connected = @$redis->connect($host, $port, 2.0);
            if (!$connected) {
                throw new \RuntimeException("Unable to connect to Redis at {$host}:{$port}");
            }
            if ($password !== '' && !@$redis->auth($password)) {
                throw new \RuntimeException('Redis authentication failed (invalid password).');
            }
            if ($database > 0) {
                @$redis->select($database);
            }
            $pong = $redis->ping();
            @$redis->close();

            return [
                'ok' => true,
                'client' => 'phpredis',
                'host' => $host,
                'port' => $port,
                'database' => $database,
                'ping' => is_bool($pong) ? ($pong ? 'PONG' : 'ERR') : (string) $pong,
                'latency_ms' => round((hrtime(true) - $t0) / 1_000_000, 2),
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'client' => $client,
                'host' => $host,
                'port' => $port,
                'database' => $database,
                'ping' => null,
                'latency_ms' => round((hrtime(true) - $t0) / 1_000_000, 2),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Sanitized runtime configuration payload safe for UI/API inspection (never leaks passwords).
     *
     * @return array<string, mixed>
     */
    public function publicState(): array
    {
        $overrides = $this->readOverridesFromDatabase();
        $rawUrl = (string) (config('mirror.redis.url') ?? '');
        $maskedUrl = $rawUrl !== ''
            ? (string) preg_replace('#://([^:@/]+:)?[^@/]+@#', '://***@', $rawUrl)
            : '';

        return [
            'overrides_active' => count($overrides),
            'override_keys' => array_keys($overrides),
            'redis' => [
                'enabled' => $this->normalizeTriState(config('mirror.redis.enabled', 'auto')),
                'client' => (string) config('mirror.redis.client', 'auto'),
                'resolved_client' => $this->resolvedRedisClient(),
                'host' => (string) (config('mirror.redis.host') ?? ''),
                'port' => (int) config('mirror.redis.port', 6379),
                'database' => (int) config('mirror.redis.database', 0),
                'url' => $maskedUrl,
                'has_password' => trim((string) (config('mirror.redis.password') ?? '')) !== '',
                'persistent' => (bool) config('mirror.redis.persistent', true),
            ],
            'cache' => [
                'store' => (string) (config('mirror.cache.store') ?? ''),
                'models_ttl' => (int) config('mirror.cache.models_ttl', 60),
                'assets_in_redis' => (bool) config('mirror.cache.assets_in_redis', true),
            ],
            'server' => [
                'platform' => (string) config('mirror.server.platform', 'auto'),
                'trust_proxies' => (bool) config('mirror.server.trust_proxies', true),
                'trusted_proxies' => (string) config('mirror.server.trusted_proxies', '*'),
                'force_https' => config('mirror.server.force_https') === null
                    ? 'auto'
                    : (config('mirror.server.force_https') ? 'true' : 'false'),
                'stream_max_execution_time' => (int) config('mirror.server.stream_max_execution_time', 300),
                'disable_output_compression' => (bool) config('mirror.server.disable_output_compression', true),
            ],
            'pool' => [
                'strategy' => (string) config('mirror.pool.strategy', 'least_busy'),
                'max_concurrent_per_token' => (int) config('mirror.pool.max_concurrent_per_token', 5),
                'cooldown_seconds' => (int) config('mirror.pool.cooldown_seconds', 60),
            ],
            'http' => [
                'timeout' => (int) config('mirror.http.timeout', 120),
                'read_timeout' => (int) config('mirror.http.read_timeout', 120),
            ],
            'cors' => [
                'enabled' => (bool) config('mirror.cors.enabled', true),
                'allowed_origins' => (string) config('mirror.cors.allowed_origins', '*'),
            ],
            'rate_limit' => [
                'enabled' => (bool) config('mirror.rate_limit.enabled', false),
                'max_attempts' => (int) config('mirror.rate_limit.max_attempts', 180),
                'decay_seconds' => (int) config('mirror.rate_limit.decay_seconds', 60),
            ],
        ];
    }

    public function normalizeTriState(mixed $raw): string
    {
        if (is_bool($raw)) {
            return $raw ? 'enabled' : 'disabled';
        }

        $val = strtolower(trim((string) $raw));
        return match ($val) {
            '1', 'true', 'yes', 'on', 'enabled', 'enable' => 'enabled',
            '0', 'false', 'no', 'off', 'disabled', 'disable' => 'disabled',
            default => 'auto',
        };
    }

    protected function purgeRedisConnections(): void
    {
        try {
            Cache::purge('mirror_redis');
        } catch (\Throwable) {
        }

        try {
            Redis::purge('mirror');
        } catch (\Throwable) {
        }
    }
}
