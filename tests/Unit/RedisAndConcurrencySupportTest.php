<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Tests\Unit;

use Illuminate\Support\Facades\Config;
use Mockery;
use Refatbd\LaravelChatGptMirror\Models\MirrorSetting;
use Refatbd\LaravelChatGptMirror\Models\MirrorToken;
use Refatbd\LaravelChatGptMirror\Services\DynamicConfigService;
use Refatbd\LaravelChatGptMirror\Services\MirrorCache;
use Refatbd\LaravelChatGptMirror\Services\TokenPoolManager;
use Refatbd\LaravelChatGptMirror\Services\TokenRefresher;
use Refatbd\LaravelChatGptMirror\Tests\TestCase;

class RedisAndConcurrencySupportTest extends TestCase
{
    public function test_mirror_cache_prefixes_keys_increments_atomically_and_runs_locks(): void
    {
        $cache = $this->app->make(MirrorCache::class);

        $this->assertSame('mirror:sample:key', $cache->key('sample:key'));

        $this->assertTrue($cache->put('sample:key', ['ok' => true], 60));
        $this->assertSame(['ok' => true], $cache->get('sample:key'));

        $v1 = $cache->increment('pool:rr_counter', 1, 60);
        $v2 = $cache->increment('pool:rr_counter', 1, 60);
        $this->assertSame(1, $v1);
        $this->assertSame(2, $v2);

        $lockedValue = $cache->lock('unit:lock', 10, fn () => 'acquired-cleanly', 2);
        $this->assertSame('acquired-cleanly', $lockedValue);

        $diag = $cache->diagnostics();
        $this->assertArrayHasKey('configured_store', $diag);
        $this->assertArrayHasKey('driver', $diag);
        $this->assertArrayHasKey('redis_active', $diag);
        $this->assertArrayHasKey('locks_supported', $diag);
        $this->assertArrayHasKey('phpredis_loaded', $diag);
        $this->assertArrayHasKey('predis_installed', $diag);
        $this->assertTrue($diag['cache_operational']);
    }

    public function test_mirror_cache_gracefully_falls_back_when_unreachable_store_configured(): void
    {
        Config::set('mirror.cache.store', 'nonexistent_redis_store');
        $cache = new MirrorCache();

        $this->assertTrue($cache->put('fallback:test', 'hello', 60));
        $this->assertSame('hello', $cache->get('fallback:test'));

        $diag = $cache->diagnostics();
        $this->assertFalse($diag['redis_active']);
        $this->assertTrue($diag['cache_operational']);
    }

    public function test_mirror_token_atomic_slot_claim_prevents_concurrency_overshoot(): void
    {
        $token = MirrorToken::create([
            'name' => 'Concurrent Slot Token',
            'session_token' => 'sess-concurrent-test',
            'access_token' => 'acc-concurrent-test',
            'access_token_expires_at' => now()->addHour(),
            'status' => 'active',
            'active_requests' => 0,
        ]);

        $this->assertTrue($token->tryAcquireSlot(2));
        $this->assertSame(1, $token->active_requests);

        $this->assertTrue($token->tryAcquireSlot(2));
        $this->assertSame(2, $token->active_requests);

        // Third concurrent acquire against cap=2 must atomically fail.
        $this->assertFalse($token->tryAcquireSlot(2));
        $this->assertSame(2, $token->fresh()->active_requests);

        // Decrements clamp safely at zero even if released extra times.
        $token->decrementActive(true, 100);
        $token->decrementActive(true, 50);
        $token->decrementActive(true, 0);
        $fresh = $token->fresh();
        $this->assertSame(0, $fresh->active_requests);
        $this->assertSame(3, $fresh->total_requests);
        $this->assertSame(150, $fresh->total_tokens_used);
    }

    public function test_token_pool_round_robin_and_failover_skip_saturated_tokens(): void
    {
        Config::set('mirror.pool.strategy', 'round_robin');
        Config::set('mirror.pool.max_concurrent_per_token', 1);

        $t1 = MirrorToken::create([
            'name' => 'RR Account 1',
            'session_token' => 'sess-rr-1',
            'access_token' => 'acc-rr-1',
            'access_token_expires_at' => now()->addHour(),
            'status' => 'active',
            'active_requests' => 0,
        ]);

        $t2 = MirrorToken::create([
            'name' => 'RR Account 2',
            'session_token' => 'sess-rr-2',
            'access_token' => 'acc-rr-2',
            'access_token_expires_at' => now()->addHour(),
            'status' => 'active',
            'active_requests' => 0,
        ]);

        $refresher = Mockery::mock(TokenRefresher::class);
        $pool = new TokenPoolManager($refresher, $this->app->make(MirrorCache::class));

        $first = $pool->acquire();
        $second = $pool->acquire();
        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNotSame($first->id, $second->id);

        // Both saturated at max_concurrent_per_token=1 -> returns null rather than oversubscribing.
        $this->assertNull($pool->acquire());

        $pool->release($first, true);
        $pool->release($second, true);
        $this->assertSame(0, $t1->fresh()->active_requests);
        $this->assertSame(0, $t2->fresh()->active_requests);
    }

    public function test_refresh_lock_rechecks_db_state_and_avoids_duplicate_upstream_refresh(): void
    {
        $token = MirrorToken::create([
            'name' => 'Refresh Lock Token',
            'session_token' => 'sess-refresh-lock',
            'access_token' => 'old-jwt',
            'access_token_expires_at' => now()->subMinute(),
            'status' => 'active',
        ]);

        // Simulate another worker having already refreshed the row right before the lock runs.
        MirrorToken::whereKey($token->id)->update([
            'access_token' => 'fresh-jwt-from-worker-b',
            'access_token_expires_at' => now()->addHours(2),
        ]);

        $refresher = Mockery::mock(TokenRefresher::class);
        $refresher->shouldNotReceive('refresh');

        $pool = new TokenPoolManager($refresher, $this->app->make(MirrorCache::class));
        $this->assertTrue($pool->ensureTokenFreshness($token));
        $this->assertSame('fresh-jwt-from-worker-b', $token->access_token);
    }

    public function test_mirror_setting_caches_and_invalidates_on_save(): void
    {
        MirrorSetting::setValue('pool.notice', 'initial');
        $this->assertSame('initial', MirrorSetting::getValue('pool.notice'));

        // Corrupt underlying cache manually to verify save() invalidates cache automatically.
        $cache = $this->app->make(MirrorCache::class);
        $cache->put('setting:pool.notice', 'stale', 60);
        $this->assertSame('stale', MirrorSetting::getValue('pool.notice'));

        MirrorSetting::setValue('pool.notice', 'updated');
        $this->assertSame('updated', MirrorSetting::getValue('pool.notice'));
    }

    public function test_dynamic_config_service_toggles_redis_without_env_masks_secrets_and_resets_cleanly(): void
    {
        $dynamic = $this->app->make(DynamicConfigService::class);
        $cache = $this->app->make(MirrorCache::class);

        $dynamic->ensureApplied();
        $initial = $dynamic->publicState();
        $this->assertSame('auto', $initial['redis']['enabled']);
        $this->assertSame('auto', $initial['redis']['client']);

        // 1. Disabling Redis must force non-Redis operation even when host/URL are provided,
        // and publicState() must mask Redis URL credentials cleanly.
        $dynamic->saveOverrides([
            'redis_enabled' => 'disabled',
            'redis_host' => '10.10.0.5',
            'redis_password' => 'super-secret-pw-123',
            'redis_url' => 'redis://default:urlsecret99@10.10.0.5:6379/1',
        ]);

        $this->assertFalse($cache->isRedisDesired());
        $disabledDiag = $cache->diagnostics();
        $this->assertSame('disabled', $disabledDiag['redis_mode']);
        $this->assertFalse($disabledDiag['redis_desired']);
        $this->assertFalse($disabledDiag['redis_active']);

        $maskedState = $dynamic->publicState();
        $this->assertTrue($maskedState['redis']['has_password']);
        $this->assertStringContainsString('://***@', $maskedState['redis']['url']);
        $this->assertStringNotContainsString('urlsecret99', $maskedState['redis']['url']);
        $this->assertStringNotContainsString('super-secret-pw-123', json_encode($maskedState) ?: '');

        // 2. Enabling Redis dynamically via DB configures mirror_redis + database.redis.mirror live without .env.
        $dynamic->saveOverrides([
            'redis_enabled' => 'enabled',
            'redis_client' => 'predis',
            'redis_host' => '127.0.0.1',
            'redis_port' => 6385,
            'redis_database' => 3,
            'server_platform' => 'nginx',
            'pool_strategy' => 'round_robin',
        ]);

        $this->assertTrue($cache->isRedisDesired());
        $this->assertSame('enabled', config('mirror.redis.enabled'));
        $this->assertSame('mirror_redis', $cache->configuredStoreName());
        $this->assertSame('mirror', config('cache.stores.mirror_redis.connection'));
        $this->assertSame('predis', config('database.redis.client'));
        $this->assertSame('127.0.0.1', config('database.redis.mirror.host'));
        $this->assertSame(6385, config('database.redis.mirror.port'));
        $this->assertSame(3, config('database.redis.mirror.database'));
        $this->assertSame('nginx', config('mirror.server.platform'));
        $this->assertSame('round_robin', config('mirror.pool.strategy'));

        // 3. Test Redis connection never crashes with uncaught exceptions when unreachable/missing driver.
        $probe = $dynamic->testRedisConnection([
            'redis_host' => '127.0.0.1',
            'redis_port' => 16399,
            'redis_client' => 'predis',
        ]);
        $this->assertIsArray($probe);
        $this->assertArrayHasKey('ok', $probe);
        $this->assertArrayHasKey('latency_ms', $probe);
        $this->assertArrayHasKey('error', $probe);
        $this->assertSame(16399, $probe['port']);

        // 4. Resetting overrides restores original .env / config baseline cleanly.
        $dynamic->resetOverrides();
        $resetState = $dynamic->publicState();
        $this->assertSame(0, $resetState['overrides_active']);
        $this->assertSame('auto', config('mirror.redis.enabled'));
        $this->assertSame('least_busy', config('mirror.pool.strategy'));
    }

    public function test_dynamic_config_service_persists_timeout_cors_and_rate_limit_overrides(): void
    {
        /** @var DynamicConfigService $dynamic */
        $dynamic = $this->app->make(DynamicConfigService::class);

        $state = $dynamic->saveOverrides([
            'http_read_timeout' => 240,
            'cors_enabled' => false,
            'cors_allowed_origins' => 'https://studio.example.net',
            'rate_limit_enabled' => true,
            'rate_limit_max_attempts' => 90,
            'rate_limit_decay_seconds' => 30,
        ]);

        $this->assertSame(240, $state['http']['read_timeout']);
        $this->assertFalse($state['cors']['enabled']);
        $this->assertSame('https://studio.example.net', $state['cors']['allowed_origins']);
        $this->assertTrue($state['rate_limit']['enabled']);
        $this->assertSame(90, $state['rate_limit']['max_attempts']);
        $this->assertSame(30, $state['rate_limit']['decay_seconds']);
        $this->assertSame(240, config('mirror.http.read_timeout'));
        $this->assertTrue(config('mirror.rate_limit.enabled'));

        $dynamic->resetOverrides();
        $this->assertSame(120, config('mirror.http.read_timeout'));
        $this->assertTrue(config('mirror.cors.enabled'));
        $this->assertFalse(config('mirror.rate_limit.enabled'));
    }
}
