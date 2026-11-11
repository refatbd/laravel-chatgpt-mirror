<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Tests\Feature;

use Refatbd\LaravelChatGptMirror\Models\MirrorToken;
use Refatbd\LaravelChatGptMirror\Services\SecurityService;
use Refatbd\LaravelChatGptMirror\Services\TokenRefresher;
use Refatbd\LaravelChatGptMirror\Tests\TestCase;

class AdminControllerTest extends TestCase
{
    public function test_admin_dashboard_renders_successfully_and_escapes_xss(): void
    {
        MirrorToken::create([
            'name' => '<script>alert("xss")</script>Test Account Alpha',
            'email' => 'test@example.com',
            'session_token' => 'sample-session-token-for-test',
            'access_token' => 'sample-access-token',
            'plan_type' => 'plus',
            'status' => 'active',
        ]);

        $response = $this->get('/mirror/admin');

        $response->assertStatus(200);
        $response->assertCookie(SecurityService::CONTROL_COOKIE_NAME);
        $response->assertSee('Laravel ChatGPT Mirror');
        $response->assertSee('Settings & Admin Dashboard', false);
        $response->assertSee('Test Account Alpha');
        $response->assertSee('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', false);
        $response->assertDontSee('<script>alert("xss")</script>', false);
        $response->assertSee('test@example.com');
        $response->assertSee('Active Accounts');
        $response->assertSee('Direct OpenAI Gateway');
        $response->assertDontSee('WARP: Off');
        $response->assertDontSee('WARP: Disabled');
    }

    public function test_admin_alias_redirects_to_mirror_admin(): void
    {
        $response = $this->get('/admin');
        $response->assertRedirect('/mirror/admin');
    }

    public function test_control_endpoints_reject_unauthenticated_and_spoofed_referer_requests(): void
    {
        $this->withHeader('Referer', 'http://localhost/mirror/admin')
            ->getJson('/mirror/admin/api/stats')
            ->assertStatus(401);

        $this->withHeader('Referer', 'http://localhost/mirror/playground')
            ->getJson('/api/diagnostics')
            ->assertStatus(401);

        $this->post('/mirror/admin/tokens/reset-counters')
            ->assertStatus(401);
    }

    public function test_admin_stats_returns_json_with_bearer_auth(): void
    {
        MirrorToken::create([
            'name' => 'Active Account',
            'email' => 'active@example.com',
            'session_token' => 'active-session-token',
            'status' => 'active',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer test-mirror-key-12345')
            ->getJson('/mirror/admin/api/stats');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'total_tokens',
            'active_healthy',
            'in_cooldown',
            'total_requests_served',
            'total_in_flight_requests',
            'strategy',
            'pool',
            'active_conversations',
        ]);
    }

    public function test_reset_in_flight_counters_with_control_cookie(): void
    {
        $token = MirrorToken::create([
            'name' => 'Stuck Token',
            'email' => 'stuck@example.com',
            'session_token' => 'stuck-token',
            'status' => 'active',
            'active_requests' => 5,
        ]);

        $cookie = $this->get('/mirror/admin')->getCookie(SecurityService::CONTROL_COOKIE_NAME, false)?->getValue();

        $response = $this->withUnencryptedCookie(SecurityService::CONTROL_COOKIE_NAME, (string) $cookie)
            ->post('/mirror/admin/tokens/reset-counters');
        $response->assertRedirect();

        $token->refresh();
        $this->assertEquals(0, $token->active_requests);
    }

    public function test_health_endpoint(): void
    {
        MirrorToken::create([
            'name' => 'Healthy Token',
            'session_token' => 'token-abc',
            'status' => 'active',
        ]);

        $response = $this->getJson('/api/health');

        $response->assertStatus(200);
        $response->assertJson([
            'ok' => true,
            'configured' => true,
            'egressMode' => 'direct',
        ]);
    }

    public function test_diagnostics_endpoint(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer test-mirror-key-12345')
            ->getJson('/api/diagnostics');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'schemaVersion',
            'build' => ['package', 'version', 'framework', 'php'],
            'storage' => ['engine', 'healthy'],
            'pool',
            'config',
            'recentFailures',
        ]);
    }

    public function test_capabilities_endpoint(): void
    {
        $response = $this->getJson('/v1/capabilities');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'schemaVersion',
            'routes',
            'fields',
            'supported',
            'endpoints',
            'features',
        ]);
    }

    public function test_session_endpoints_with_verified_candidate_mock(): void
    {
        $mockRefresher = $this->createMock(TokenRefresher::class);
        $mockRefresher->method('verifyCandidateSessionToken')->willReturn([
            'session_token' => str_repeat('s', 30),
            'access_token' => 'jwt-verified-123',
            'expires_at' => now()->addHour(),
            'email' => 'verified@example.com',
            'name' => 'Verified User',
            'account_id' => 'acct-verified-1',
            'plan_type' => 'plus',
        ]);
        $this->app->instance(TokenRefresher::class, $mockRefresher);

        $postRes = $this->withHeader('Authorization', 'Bearer test-mirror-key-12345')
            ->postJson('/api/session', [
                'sessionToken' => str_repeat('s', 30),
                'turnstileToken' => 'ts-token-999',
            ]);
        $postRes->assertStatus(200);
        $postRes->assertJsonPath('ok', true);
        $postRes->assertJsonPath('account.email', 'verified@example.com');

        $getRes = $this->withHeader('Authorization', 'Bearer test-mirror-key-12345')
            ->getJson('/api/session');
        $getRes->assertStatus(200);
        $getRes->assertJsonPath('configured', true);
        $getRes->assertJsonPath('hasTurnstileToken', true);
        $getRes->assertJsonStructure(['configured', 'total_tokens', 'active_tokens', 'accounts']);
    }

    public function test_delete_token_from_pool(): void
    {
        $token = MirrorToken::create([
            'name' => 'Deletable Account',
            'email' => 'delete@example.com',
            'session_token' => 'token-to-delete',
            'status' => 'active',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer test-mirror-key-12345')
            ->post("/mirror/admin/tokens/{$token->id}/delete");
        $response->assertRedirect();

        $this->assertDatabaseMissing('mirror_tokens', [
            'id' => $token->id,
        ]);
    }
}
