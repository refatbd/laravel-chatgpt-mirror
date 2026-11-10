<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Tests\Unit;

use Carbon\Carbon;
use Mockery;
use Refatbd\LaravelChatGptMirror\Models\MirrorToken;
use Refatbd\LaravelChatGptMirror\Services\TokenPoolManager;
use Refatbd\LaravelChatGptMirror\Services\TokenRefresher;
use Refatbd\LaravelChatGptMirror\Tests\TestCase;

class TokenPoolManagerTest extends TestCase
{
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
}
