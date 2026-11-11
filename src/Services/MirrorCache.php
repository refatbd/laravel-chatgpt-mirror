<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Services;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

/**
 * Redis-first cache, atomic counter, and distributed lock manager with graceful fallback
 * so multi-worker Apache/PHP-FPM production uses Redis while local/unit environments never crash.
 */
class MirrorCache
{
    public function isRedisDesired(): bool
    {
        $raw = config('mirror.redis.enabled', 'auto');
        if (is_bool($raw)) {
            $mode = $raw ? 'enabled' : 'disabled';
        } else {
            $val = strtolower(trim((string) $raw));
            $mode = match ($val) {
                '1', 'true', 'yes', 'on', 'enabled', 'enable' => 'enabled',
                '0', 'false', 'no', 'off', 'disabled', 'disable' => 'disabled',
                default => 'auto',
            };
        }

        if ($mode === 'disabled') {
            return false;
        }

        if ($mode === 'enabled') {
            return true;
        }

        $explicitStore = is_string(config('mirror.cache.store')) ? trim((string) config('mirror.cache.store')) : '';
        if ($explicitStore === 'mirror_redis' || $explicitStore === 'redis') {
            return true;
        }

        return !empty(config('mirror.redis.host')) || !empty(config('mirror.redis.url'));
    }

    public function configuredStoreName(): string
    {
        $configured = config('mirror.cache.store');
        $configuredStr = is_string($configured) ? trim($configured) : '';

        if (!$this->isRedisDesired() && ($configuredStr === 'mirror_redis' || $configuredStr === 'redis')) {
            return (string) config('cache.default', 'array');
        }

        if ($configuredStr !== '') {
            return $configuredStr;
        }

        if ($this->isRedisDesired()) {
            return 'mirror_redis';
        }

        return (string) config('cache.default', 'array');
    }

    public function prefix(): string
    {
        $prefix = (string) config('mirror.cache.prefix', 'mirror:');
        return str_ends_with($prefix, ':') ? $prefix : ($prefix . ':');
    }

    public function key(string $suffix): string
    {
        return $this->prefix() . ltrim($suffix, ':');
    }

    public function store(): Repository
    {
        $preferred = $this->configuredStoreName();

        try {
            return Cache::store($preferred);
        } catch (\Throwable) {
            return Cache::store();
        }
    }

    public function driverName(): string
    {
        try {
            $store = $this->store()->getStore();
            return strtolower(class_basename($store));
        } catch (\Throwable) {
            return 'unknown';
        }
    }

    public function isRedisActive(): bool
    {
        $driver = $this->driverName();
        return str_contains($driver, 'redis');
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $fullKey = $this->key($key);
        try {
            return $this->store()->get($fullKey, $default);
        } catch (\Throwable) {
            return $this->fallbackStoreCall(fn (Repository $s) => $s->get($fullKey, $default), $default);
        }
    }

    public function put(string $key, mixed $value, int $ttlSeconds = 300): bool
    {
        $fullKey = $this->key($key);
        $expires = max(1, $ttlSeconds);
        try {
            return $this->store()->put($fullKey, $value, $expires);
        } catch (\Throwable) {
            return (bool) $this->fallbackStoreCall(fn (Repository $s) => $s->put($fullKey, $value, $expires), false);
        }
    }

    public function remember(string $key, int $ttlSeconds, callable $resolver): mixed
    {
        $cached = $this->get($key);
        if ($cached !== null) {
            return $cached;
        }

        $value = $resolver();
        if ($value !== null) {
            $this->put($key, $value, $ttlSeconds);
        }

        return $value;
    }

    public function forget(string $key): bool
    {
        $fullKey = $this->key($key);
        try {
            return $this->store()->forget($fullKey);
        } catch (\Throwable) {
            return (bool) $this->fallbackStoreCall(fn (Repository $s) => $s->forget($fullKey), false);
        }
    }

    /**
     * Atomically increment a numeric counter in Redis / active cache store.
     */
    public function increment(string $key, int $by = 1, int $defaultTtlSeconds = 3600): int
    {
        $fullKey = $this->key($key);
        try {
            $store = $this->store();
            $val = $store->increment($fullKey, $by);
            if (is_int($val)) {
                return $val;
            }
        } catch (\Throwable) {
        }

        $current = (int) $this->get($key, 0);
        $next = $current + $by;
        $this->put($key, $next, $defaultTtlSeconds);
        return $next;
    }

    /**
     * Execute a critical section inside a distributed atomic lock (Redis SET NX PX / Laravel LockProvider)
     * with clean fallback when using a non-lock store.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function lock(string $name, int $ttlSeconds, callable $callback, int $waitSeconds = 5): mixed
    {
        $lockKey = $this->key('lock:' . ltrim($name, ':'));
        try {
            $rawStore = $this->store()->getStore();
            if ($rawStore instanceof LockProvider) {
                $lock = $rawStore->lock($lockKey, max(1, $ttlSeconds));
                return $lock->block(max(0, $waitSeconds), $callback);
            }
        } catch (\Throwable) {
            // Fall back safely so Redis lock transient timeouts do not crash single-worker requests.
        }

        return $callback();
    }

    /**
     * Inspect live Redis & cache backend readiness without throwing exceptions.
     *
     * @return array<string, mixed>
     */
    public function diagnostics(): array
    {
        $configured = $this->configuredStoreName();
        $driver = $this->driverName();
        $phpredis = extension_loaded('redis');
        $predis = class_exists(\Predis\Client::class);
        $locksSupported = false;
        $connected = false;
        $fallbackActive = false;
        $redisPing = null;
        $latencyMs = null;
        $error = null;

        try {
            $repo = $this->store();
            $locksSupported = $repo->getStore() instanceof LockProvider;
            $t0 = hrtime(true);
            $probeKey = $this->key('health:probe');
            $repo->put($probeKey, 'ok', 10);
            $connected = $repo->get($probeKey) === 'ok';
            $latencyMs = round((hrtime(true) - $t0) / 1_000_000, 2);

            if ($this->isRedisActive()) {
                $redisConnName = config('cache.stores.' . $configured . '.connection', 'default');
                try {
                    $pong = Redis::connection($redisConnName)->ping();
                    $redisPing = is_bool($pong) ? ($pong ? 'PONG' : 'ERR') : (string) $pong;
                } catch (\Throwable) {
                    $redisPing = $connected ? 'PONG' : null;
                }
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            $probeKey = $this->key('health:probe');
            $fallbackActive = (bool) $this->fallbackStoreCall(function (Repository $s) use ($probeKey): bool {
                $s->put($probeKey, 'ok', 10);
                return $s->get($probeKey) === 'ok';
            }, false);
        }

        return [
            'configured_store' => $configured,
            'driver' => $driver,
            'redis_mode' => is_bool(config('mirror.redis.enabled', 'auto'))
                ? (config('mirror.redis.enabled') ? 'enabled' : 'disabled')
                : (string) config('mirror.redis.enabled', 'auto'),
            'redis_desired' => $this->isRedisDesired(),
            'redis_active' => $this->isRedisActive() && $connected,
            'redis_reachable' => $this->isRedisActive() ? $connected : false,
            'redis_ping' => $redisPing,
            'cache_operational' => $connected || $fallbackActive,
            'fallback_active' => $fallbackActive,
            'locks_supported' => $locksSupported,
            'phpredis_loaded' => $phpredis,
            'predis_installed' => $predis,
            'prefix' => $this->prefix(),
            'latency_ms' => $latencyMs,
            'error' => $error,
        ];
    }

    protected function fallbackStoreCall(callable $fn, mixed $default): mixed
    {
        try {
            $defaultName = (string) config('cache.default', 'array');
            if ($defaultName !== $this->configuredStoreName()) {
                return $fn(Cache::store($defaultName));
            }
        } catch (\Throwable) {
        }

        return $default;
    }
}
