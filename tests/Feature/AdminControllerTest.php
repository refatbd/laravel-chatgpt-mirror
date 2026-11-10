<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Tests\Feature;

use Refatbd\LaravelChatGptMirror\Models\MirrorToken;
use Refatbd\LaravelChatGptMirror\Tests\TestCase;

class AdminControllerTest extends TestCase
{
    public function test_admin_dashboard_renders_successfully(): void
    {
        MirrorToken::create([
            'name' => 'Test Account Alpha',
            'email' => 'test@example.com',
            'session_token' => 'sample-session-token-for-test',
            'access_token' => 'sample-access-token',
            'plan_type' => 'plus',
            'status' => 'active',
        ]);

        $response = $this->get('/mirror/admin');

        $response->assertStatus(200);
        $response->assertSee('Laravel ChatGPT Mirror');
        $response->assertSee('Settings & Admin Dashboard', false);
        $response->assertSee('Test Account Alpha');
        $response->assertSee('test@example.com');
        $response->assertSee('Total Accounts in Pool');
        $response->assertSee('Project &amp; Gateway Settings', false);
    }

    public function test_admin_alias_redirects_to_mirror_admin(): void
    {
        $response = $this->get('/admin');
        $response->assertRedirect('/mirror/admin');
    }

    public function test_admin_stats_returns_json(): void
    {
        MirrorToken::create([
            'name' => 'Active Account',
            'email' => 'active@example.com',
            'session_token' => 'active-session-token',
            'status' => 'active',
        ]);

        $response = $this->getJson('/mirror/admin/api/stats');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'total_tokens',
            'active_healthy',
            'in_cooldown',
            'total_requests_served',
            'total_in_flight_requests',
            'strategy',
        ]);
    }

    public function test_delete_token_from_pool(): void
    {
        $token = MirrorToken::create([
            'name' => 'Deletable Account',
            'email' => 'delete@example.com',
            'session_token' => 'token-to-delete',
            'status' => 'active',
        ]);

        $response = $this->post("/mirror/admin/tokens/{$token->id}/delete");
        $response->assertRedirect();

        $this->assertDatabaseMissing('mirror_tokens', [
            'id' => $token->id,
        ]);
    }
}
