<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Mockery;
use Refatbd\LaravelChatGptMirror\Models\MirrorToken;
use Refatbd\LaravelChatGptMirror\Services\ChatGptClient;
use Refatbd\LaravelChatGptMirror\Services\SecurityService;
use Refatbd\LaravelChatGptMirror\Tests\TestCase;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

class UniversalWebServerSupportTest extends TestCase
{
    public function test_apache_redirect_http_authorization_restores_bearer_auth(): void
    {
        // Middleware must restore Authorization on Apache/CGI/FastCGI so ValidateApiKey passes.
        $res = $this->withServerVariables([
            'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer test-mirror-key-12345',
        ])->getJson('/v1/models');

        $res->assertOk()
            ->assertJsonStructure(['object', 'data']);
    }

    public function test_reverse_proxy_https_and_host_detection_across_servers(): void
    {
        $security = $this->app->make(SecurityService::class);

        // 1. Standard Nginx / Apache X-Forwarded-Proto + X-Forwarded-Host
        $nginxReq = Request::create('/mirror/openapi', 'GET', [], [], [], [
            'HTTP_HOST' => '127.0.0.1:8000',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'api.mirror.example.com',
        ]);
        $this->assertTrue($security->isSecureRequest($nginxReq));
        $this->assertSame('https://api.mirror.example.com', $security->resolveOrigin($nginxReq));

        // 2. Cloudflare CF-Visitor JSON header
        $cfReq = Request::create('/mirror/openapi', 'GET', [], [], [], [
            'HTTP_HOST' => 'mirror.cf.example',
            'HTTP_CF_VISITOR' => '{"scheme":"https"}',
        ]);
        $this->assertTrue($security->isSecureRequest($cfReq));
        $this->assertSame('https://mirror.cf.example', $security->resolveOrigin($cfReq));

        // 3. RFC 7239 Forwarded header
        $rfcReq = Request::create('/mirror/openapi', 'GET', [], [], [], [
            'HTTP_HOST' => 'internal.local',
            'HTTP_FORWARDED' => 'for=1.2.3.4;proto=https;host="edge.example.org"',
        ]);
        $this->assertTrue($security->isSecureRequest($rfcReq));
        $this->assertSame('https://edge.example.org', $security->resolveOrigin($rfcReq));
    }

    public function test_prepare_mirror_runtime_does_not_leak_static_trusted_proxies(): void
    {
        $beforeProxies = SymfonyRequest::getTrustedProxies();

        $res = $this->withHeaders([
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'proxy.example.com',
        ])->getJson('/mirror/openapi');

        $res->assertOk();
        $servers = $res->json('servers');
        $this->assertSame('https://proxy.example.com', $servers[0]['url'] ?? null);

        $afterProxies = SymfonyRequest::getTrustedProxies();
        $this->assertSame($beforeProxies, $afterProxies);
    }

    public function test_streaming_endpoints_emit_universal_unbuffered_sse_headers(): void
    {
        MirrorToken::create([
            'name' => 'Stream Header Test',
            'session_token' => 'sess-stream-header-test',
            'access_token' => 'acc-stream-header-test',
            'access_token_expires_at' => now()->addHour(),
            'account_id' => 'acct-stream-test',
            'status' => 'active',
        ]);

        $mockClient = Mockery::mock(ChatGptClient::class);
        $mockClient->shouldReceive('routeModel')->andReturn(['model' => 'auto', 'gizmoId' => null, 'private' => false]);
        $mockClient->shouldReceive('buildConversationPayload')->andReturn(['messages' => []]);
        $mockClient->shouldReceive('streamConversation')->andReturnNull();
        $this->app->instance(ChatGptClient::class, $mockClient);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer test-mirror-key-12345',
        ])->postJson('/v1/chat/completions', [
            'model' => 'auto',
            'stream' => true,
            'messages' => [
                ['role' => 'user', 'content' => 'ping'],
            ],
        ]);

        $response->assertOk();
        $this->assertSame('no', $response->headers->get('X-Accel-Buffering'));
        $this->assertSame('no-cache', $response->headers->get('X-LiteSpeed-Cache-Control'));
        $this->assertSame('none', $response->headers->get('Content-Encoding'));
        $this->assertStringContainsString('no-transform', (string) $response->headers->get('Cache-Control'));
    }

    public function test_health_diagnostics_capabilities_and_admin_report_runtime_and_cache(): void
    {
        MirrorToken::create([
            'name' => 'Telemetry Token',
            'session_token' => 'sess-telemetry',
            'access_token' => 'acc-telemetry',
            'access_token_expires_at' => now()->addHour(),
            'status' => 'active',
        ]);

        $health = $this->getJson('/api/health');
        $health->assertOk()
            ->assertJsonStructure([
                'cache' => ['configured_store', 'driver', 'redis_active', 'locks_supported'],
                'runtime' => ['php_version', 'sapi', 'detected_platform', 'zlib_output_compression', 'https_detected'],
            ]);

        $diag = $this->withHeaders([
            'Authorization' => 'Bearer test-mirror-key-12345',
        ])->getJson('/api/diagnostics');
        $diag->assertOk()
            ->assertJsonStructure([
                'cache' => ['configured_store', 'driver', 'redis_active'],
                'runtime' => ['php_version', 'sapi', 'detected_platform'],
            ]);

        $caps = $this->getJson('/v1/capabilities');
        $caps->assertOk()
            ->assertJsonPath('features.multi_worker_safe', true)
            ->assertJsonPath('features.reverse_proxy_headers', true)
            ->assertJsonStructure(['cache', 'runtime']);

        $admin = $this->get('/mirror/admin');
        $admin->assertOk()
            ->assertSee('Runtime, Web Server &amp; Redis Readiness', false)
            ->assertDontSee('WARP');
    }

    public function test_mirror_server_command_writes_idempotent_htaccess_and_prints_server_templates(): void
    {
        $tempDir = sys_get_temp_dir() . '/mirror_htaccess_test_' . bin2hex(random_bytes(4));
        File::ensureDirectoryExists($tempDir);
        $htaccessPath = $tempDir . '/.htaccess';
        File::put($htaccessPath, "<IfModule mod_rewrite.c>\n    RewriteEngine On\n    RewriteRule ^ index.php [L]\n</IfModule>\n");

        try {
            $this->artisan('mirror:server', [
                'target' => 'apache',
                '--write' => true,
                '--path' => $htaccessPath,
            ])->expectsOutputToContain('Updated')->assertSuccessful();

            $firstWrite = File::get($htaccessPath);
            $this->assertStringContainsString('# BEGIN LARAVEL CHATGPT MIRROR', $firstWrite);
            $this->assertStringContainsString('HTTP_AUTHORIZATION', $firstWrite);
            $this->assertStringContainsString('MIRROR_SSE=1', $firstWrite);
            $this->assertStringContainsString('RewriteRule ^ index.php [L]', $firstWrite);

            // Second run must replace cleanly without duplicating blocks.
            $this->artisan('mirror:server', [
                'target' => 'apache',
                '--write' => true,
                '--path' => $htaccessPath,
            ])->assertSuccessful();

            $secondWrite = File::get($htaccessPath);
            $this->assertSame(1, substr_count($secondWrite, '# BEGIN LARAVEL CHATGPT MIRROR'));
            $this->assertSame(1, substr_count($secondWrite, 'RewriteRule ^ index.php [L]'));
        } finally {
            File::deleteDirectory($tempDir);
        }

        $this->artisan('mirror:apache', ['--vhost' => true])
            ->expectsOutputToContain('AllowEncodedSlashes NoDecode')
            ->expectsOutputToContain('CGIPassAuth On')
            ->assertSuccessful();

        $this->artisan('mirror:server', ['target' => 'nginx', '--vhost' => true])
            ->expectsOutputToContain('fastcgi_buffering off')
            ->expectsOutputToContain('merge_slashes off')
            ->assertSuccessful();

        $this->artisan('mirror:server', ['target' => 'caddy', '--vhost' => true])
            ->expectsOutputToContain('flush_interval -1')
            ->assertSuccessful();
    }
}
