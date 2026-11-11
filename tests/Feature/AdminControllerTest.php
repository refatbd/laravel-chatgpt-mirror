<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Tests\Feature;

use Illuminate\Http\UploadedFile;
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
        $response->assertSee('<meta name="color-scheme" content="light" />', false);
        $response->assertSee('color-scheme:light', false);
        $response->assertDontSee('pre { background:#0f172a', false);
        $response->assertDontSee('background:#111827', false);
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

    public function test_admin_and_post_session_extract_full_token_txt_json_and_uploaded_file(): void
    {
        $extractedSession = 'sess_extracted_' . str_repeat('q', 32);
        $b64 = static fn (array $d): string => rtrim(strtr(base64_encode((string) json_encode($d)), '+/', '-_'), '=');
        $jwt = $b64(['alg' => 'RS256']) . '.' . $b64([
            'iss' => 'https://auth.openai.com/',
            'exp' => time() + 7200,
            'https://api.openai.com/auth' => ['user_id' => 'u-bundle-1'],
        ]) . '.sig12345';

        $fullTokenTxtPayload = json_encode([
            'user' => ['id' => 'u-bundle-1', 'name' => 'Bundle User', 'email' => 'bundle@example.com'],
            'account' => ['id' => 'acct-bundle-1', 'planType' => 'plus'],
            'expires' => '2027-02-08T02:07:02.327Z',
            'accessToken' => $jwt,
            'sessionToken' => $extractedSession,
            'WARNING_BANNER' => 'Never share this session JSON.',
        ], JSON_UNESCAPED_SLASHES);

        // Mock TokenRefresher verifying that ONLY the clean extracted session_token was sent upstream
        $mockRefresher = $this->createMock(TokenRefresher::class);
        $mockRefresher->method('verifyCandidateSessionToken')
            ->with($extractedSession)
            ->willReturn([
                'session_token' => $extractedSession,
                'access_token' => $jwt,
                'expires_at' => now()->addHour(),
                'email' => null, // omit upstream email to prove metadata backfills from token.txt
                'name' => null,
                'account_id' => 'acct-bundle-1',
                'plan_type' => null,
            ]);
        $this->app->instance(TokenRefresher::class, $mockRefresher);

        // 1. Pasting entire token.txt into /mirror/admin/tokens textarea
        $postAdmin = $this->withHeader('Authorization', 'Bearer test-mirror-key-12345')
            ->post('/mirror/admin/tokens', [
                'session_token' => (string) $fullTokenTxtPayload,
            ]);
        $postAdmin->assertRedirect();

        $saved = MirrorToken::where('account_id', 'acct-bundle-1')->first();
        $this->assertNotNull($saved);
        $this->assertSame($extractedSession, $saved->session_token);
        $this->assertSame('bundle@example.com', $saved->email);
        $this->assertSame('Bundle User', $saved->name);
        $this->assertSame('plus', $saved->plan_type);

        // 2. Uploading token.txt via multipart file input
        $uploadedFile = UploadedFile::fake()->createWithContent('token.txt', (string) $fullTokenTxtPayload);
        $this->withHeader('Authorization', 'Bearer test-mirror-key-12345')
            ->post('/mirror/admin/tokens', [
                'name' => 'Uploaded File Override',
                'token_file' => $uploadedFile,
            ])
            ->assertRedirect();
        $this->assertSame('Uploaded File Override', $saved->fresh()->name);

        // 3. POSTing entire /api/auth/session JSON directly to /api/session
        $apiRes = $this->withHeader('Authorization', 'Bearer test-mirror-key-12345')
            ->postJson('/api/session', json_decode((string) $fullTokenTxtPayload, true));
        $apiRes->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('email', 'bundle@example.com')
            ->assertJsonPath('source_format', 'session_json');
    }

    public function test_session_bundle_fallback_works_only_on_transient_error_not_on_401_reject(): void
    {
        $b64 = static fn (array $d): string => rtrim(strtr(base64_encode((string) json_encode($d)), '+/', '-_'), '=');
        $validJwt = $b64(['alg' => 'RS256']) . '.' . $b64([
            'iss' => 'https://auth.openai.com/',
            'exp' => time() + 7200,
        ]) . '.sig12345';

        $bundleJson = (string) json_encode([
            'user' => ['email' => 'waf@example.com', 'name' => 'WAF Fallback User'],
            'account' => ['id' => 'acct-waf-1', 'planType' => 'pro'],
            'accessToken' => $validJwt,
            'sessionToken' => 'sess_waf_fallback_' . str_repeat('f', 24),
        ]);

        // Case A: Upstream explicitly rejects (401 -> transient=false) => MUST reject with 401
        $rejectMock = $this->createMock(TokenRefresher::class);
        $rejectMock->method('verifyCandidateSessionToken')->willReturn(null);
        $rejectMock->method('lastVerificationWasTransientFailure')->willReturn(false);
        $this->app->instance(TokenRefresher::class, $rejectMock);

        $this->withHeader('Authorization', 'Bearer test-mirror-key-12345')
            ->postJson('/api/session', ['sessionToken' => $bundleJson])
            ->assertStatus(401);

        // Case B: Upstream hits WAF/network transient failure (transient=true) + valid unexpired JWT => fallback saves cleanly
        $wafMock = $this->createMock(TokenRefresher::class);
        $wafMock->method('verifyCandidateSessionToken')->willReturn(null);
        $wafMock->method('lastVerificationWasTransientFailure')->willReturn(true);
        $this->app->instance(TokenRefresher::class, $wafMock);

        $fallbackRes = $this->withHeader('Authorization', 'Bearer test-mirror-key-12345')
            ->postJson('/api/session', ['sessionToken' => $bundleJson]);
        $fallbackRes->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('verification_mode', 'session_bundle_fallback')
            ->assertJsonPath('email', 'waf@example.com')
            ->assertJsonPath('plan_type', 'pro');
    }
}
