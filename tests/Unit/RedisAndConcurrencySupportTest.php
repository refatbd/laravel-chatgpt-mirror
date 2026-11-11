<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Tests\Unit;

use Illuminate\Support\Facades\Config;
use Mockery;
use Refatbd\LaravelChatGptMirror\Models\MirrorSetting;
use Refatbd\LaravelChatGptMirror\Models\MirrorToken;
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
}
