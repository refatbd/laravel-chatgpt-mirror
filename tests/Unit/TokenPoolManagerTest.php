<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Tests\Unit;

use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Mockery;
use Refatbd\LaravelChatGptMirror\Models\MirrorToken;
use Refatbd\LaravelChatGptMirror\Services\TokenPoolManager;
use Refatbd\LaravelChatGptMirror\Services\TokenRefresher;
use Refatbd\LaravelChatGptMirror\Tests\TestCase;

class TokenPoolManagerTest extends TestCase
{
    public function test_refresh_keeps_the_account_active_on_upstream_forbidden_response(): void
    {
        $token = MirrorToken::create([
            'name' => 'Retryable Token',
            'session_token' => 'retryable-session',
            'status' => 'active',
        ]);

        $http = new Client([
            'handler' => HandlerStack::create(new MockHandler([
                new Response(403, ['Content-Type' => 'application/json'], '{"error":"Unavailable"}'),
                new Response(401, ['Content-Type' => 'application/json'], '{}'),
            ])),
            'http_errors' => false,
        ]);
        $refresher = new class($http) extends TokenRefresher
        {
            public function __construct(Client $http)
            {
                $this->http = $http;
            }
        };

        $this->assertFalse($refresher->refresh($token));
        $this->assertSame('active', $token->refresh()->status);
        $this->assertSame('Upstream session refresh failed with HTTP 403', $token->last_error);

        $this->assertFalse($refresher->refresh($token));
        $this->assertSame('expired', $token->refresh()->status);
    }

    public function test_a_successful_session_refresh_cannot_reactivate_a_revoked_access_token(): void
    {
        $token = MirrorToken::create([
            'name' => 'Revoked Token',
            'session_token' => 'revoked-session',
            'status' => 'active',
        ]);

        $http = new Client([
            'handler' => HandlerStack::create(new MockHandler([
                new Response(200, ['Content-Type' => 'application/json'], '{"accessToken":"revoked-access"}'),
                new Response(401, ['Content-Type' => 'application/json'], '{"detail":{"code":"token_revoked"}}'),
            ])),
            'http_errors' => false,
        ]);
        $refresher = new class($http) extends TokenRefresher
        {
            public function __construct(Client $http)
            {
                $this->http = $http;
            }
        };

        $this->assertFalse($refresher->refresh($token));
        $this->assertSame(401, $refresher->lastVerificationStatus());
        $this->assertSame('expired', $token->refresh()->status);
        $this->assertNull($token->access_token);
    }

    public function test_least_busy_strategy_picks_token_with_fewest_active_requests(): void
    {
        $refresher = Mockery::mock(TokenRefresher::class);
        $manager = new TokenPoolManager($refresher);

        // Token 1: Busy with 5 active requests
        $token1 = MirrorToken::create([
            'name' => 'Busy Token',
            'session_token' => 'sess-1',
            'access_token' => 'acc-1',
            'access_token_expires_at' => Carbon::now()->addHour(),
            'active_requests' => 5,
            'status' => 'active',
        ]);

        // Token 2: Free with 0 active requests
        $token2 = MirrorToken::create([
            'name' => 'Free Token',
            'session_token' => 'sess-2',
            'access_token' => 'acc-2',
            'access_token_expires_at' => Carbon::now()->addHour(),
            'active_requests' => 0,
            'status' => 'active',
        ]);

        // Token 3: Moderate with 2 active requests
        $token3 = MirrorToken::create([
            'name' => 'Moderate Token',
            'session_token' => 'sess-3',
            'access_token' => 'acc-3',
            'access_token_expires_at' => Carbon::now()->addHour(),
            'active_requests' => 2,
            'status' => 'active',
        ]);

        $acquired = $manager->acquire();

        $this->assertNotNull($acquired);
        $this->assertEquals($token2->id, $acquired->id);
        $this->assertEquals(1, $acquired->fresh()->active_requests);

        // Release token
        $manager->release($acquired, true);
        $this->assertEquals(0, $acquired->fresh()->active_requests);
    }

    public function test_request_ceiling_can_be_disabled_without_skipping_token_health(): void
    {
        config(['mirror.pool.max_concurrent_per_token' => 1]);
        $token = MirrorToken::create([
            'name' => 'Capacity test token',
            'session_token' => 'fixture-session-token',
            'access_token' => 'fixture-access-token',
            'access_token_expires_at' => Carbon::now()->addHour(),
            'status' => 'active',
            'active_requests' => 1,
        ]);
        $pool = new TokenPoolManager(Mockery::mock(TokenRefresher::class));

        $this->assertNull($pool->acquire());
        config(['mirror.pool.enforce_token_concurrency' => false]);
        $this->assertSame($token->id, $pool->acquire()?->id);
        $this->assertSame(2, $token->refresh()->active_requests);

        $token->update(['status' => 'cooldown', 'cooldown_until' => Carbon::now()->addMinute()]);
        $this->assertNull($pool->acquire());
    }

    public function test_it_skips_tokens_in_cooldown(): void
    {
        $refresher = Mockery::mock(TokenRefresher::class);
        $manager = new TokenPoolManager($refresher);

        // Token in cooldown
        MirrorToken::create([
            'name' => 'Cooldown Token',
            'session_token' => 'sess-cd',
            'access_token' => 'acc-cd',
            'access_token_expires_at' => Carbon::now()->addHour(),
            'active_requests' => 0,
            'status' => 'cooldown',
            'cooldown_until' => Carbon::now()->addMinutes(10),
        ]);

        // Healthy active token
        $healthy = MirrorToken::create([
            'name' => 'Healthy Token',
            'session_token' => 'sess-healthy',
            'access_token' => 'acc-healthy',
            'access_token_expires_at' => Carbon::now()->addHour(),
            'active_requests' => 1,
            'status' => 'active',
        ]);

        $acquired = $manager->acquire();

        $this->assertNotNull($acquired);
        $this->assertEquals($healthy->id, $acquired->id);
    }

    public function test_putting_token_in_cooldown_updates_cooldown_until(): void
    {
        $refresher = Mockery::mock(TokenRefresher::class);
        $manager = new TokenPoolManager($refresher);

        $token = MirrorToken::create([
            'name' => 'Rate Limited Token',
            'session_token' => 'sess-rl',
            'access_token' => 'acc-rl',
            'access_token_expires_at' => Carbon::now()->addHour(),
            'active_requests' => 1,
            'status' => 'active',
        ]);

        $manager->putInCooldown($token, 120);

        $fresh = $token->fresh();
        $this->assertEquals('cooldown', $fresh->status);
        $this->assertNotNull($fresh->cooldown_until);
        $this->assertTrue($fresh->cooldown_until->isFuture());
    }
    public function test_explicit_account_scope_cannot_acquire_a_different_preferred_account(): void
    {
        $manager = new TokenPoolManager(Mockery::mock(TokenRefresher::class));
        $other = MirrorToken::create([
            'name' => 'Other account',
            'session_token' => 'fixture-session',
            'access_token' => 'fixture-access',
            'account_id' => 'other',
            'status' => 'active',
        ]);

        $this->assertNull($manager->acquireForAccount((string) $other->id, 'requested'));
        $this->assertSame(0, $other->fresh()->active_requests);
        $this->assertSame($other->id, $manager->acquire()?->id);
    }

    public function test_access_only_credentials_are_usable_until_actual_expiry(): void
    {
        $manager = new TokenPoolManager(Mockery::mock(TokenRefresher::class));
        $token = new MirrorToken([
            'access_token' => 'fixture-access',
            'access_token_expires_at' => Carbon::now()->subMinute(),
        ]);

        $this->assertFalse($manager->ensureTokenFreshness($token));

        $token->access_token_expires_at = Carbon::now()->addMinute();
        $this->assertTrue($manager->ensureTokenFreshness($token));
    }
}
