<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Tests\Feature;

use Refatbd\LaravelChatGptMirror\Models\MirrorToken;
use Refatbd\LaravelChatGptMirror\Tests\TestCase;

class ArtisanCommandsTest extends TestCase
{
    public function test_mirror_token_list_empty_state(): void
    {
        $this->artisan('mirror:token:list')
            ->expectsOutputToContain('No tokens found')
            ->assertSuccessful();
    }

    public function test_mirror_token_list_displays_table_when_tokens_exist(): void
    {
        MirrorToken::create([
            'name' => 'Primary Account',
            'email' => 'rjrefat@gmail.com',
            'session_token' => 'sample-session-token',
            'access_token' => 'sample-access-token',
            'plan_type' => 'plus',
            'status' => 'active',
            'active_requests' => 2,
            'total_requests' => 150,
        ]);

        $this->artisan('mirror:token:list')
            ->expectsOutputToContain('Primary Account')
            ->assertSuccessful();
    }

    public function test_mirror_health_reports_pool_stats_without_warp(): void
    {
        MirrorToken::create([
            'name' => 'Health Test Account',
            'email' => 'health@mirror.local',
            'session_token' => 'sess-token-123',
            'access_token' => 'acc-token-123',
            'status' => 'active',
        ]);

        $this->artisan('mirror:health')
            ->expectsOutputToContain('Active Tokens in Pool')
            ->expectsOutputToContain('Direct Egress')
            ->doesntExpectOutputToContain('WARP');
    }
}
