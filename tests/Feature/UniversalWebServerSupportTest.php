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

    public function test_runtime_settings_rest_api_and_admin_form_support_live_redis_toggle_and_reset(): void
    {
        // 1. Inspect initial runtime settings via API
        $getRes = $this->withHeaders([
            'Authorization' => 'Bearer test-mirror-key-12345',
        ])->getJson('/api/settings/runtime');

        $getRes->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('runtime_settings.redis.enabled', 'auto');

        // 2. Enable & configure Redis + Webuzo dynamically via PUT /api/settings/runtime
        $putRes = $this->withHeaders([
            'Authorization' => 'Bearer test-mirror-key-12345',
        ])->putJson('/api/settings/runtime', [
            'redis_enabled' => 'enabled',
            'redis_client' => 'predis',
            'redis_host' => '127.0.0.1',
            'redis_port' => 6388,
            'redis_database' => 4,
            'redis_password' => 'secret-auth-pw-789',
            'server_platform' => 'webuzo',
            'server_force_https' => 'true',
        ]);

        $putRes->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('runtime_settings.redis.enabled', 'enabled')
            ->assertJsonPath('runtime_settings.redis.host', '127.0.0.1')
            ->assertJsonPath('runtime_settings.redis.port', 6388)
            ->assertJsonPath('runtime_settings.redis.database', 4)
            ->assertJsonPath('runtime_settings.redis.has_password', true)
            ->assertJsonPath('runtime_settings.server.platform', 'webuzo')
            ->assertJsonPath('runtime_settings.server.force_https', 'true');

        // Ensure Redis password is never leaked in API JSON
        $this->assertStringNotContainsString('secret-auth-pw-789', $putRes->getContent());

        // 3. Test Redis endpoint returns structured non-throwing JSON diagnostics
        $testRes = $this->withHeaders([
            'Authorization' => 'Bearer test-mirror-key-12345',
        ])->postJson('/api/settings/runtime/test-redis', [
            'redis_host' => '127.0.0.1',
            'redis_port' => 16399,
            'redis_client' => 'predis',
        ]);
        $testRes->assertOk()
            ->assertJsonStructure(['ok', 'client', 'host', 'port', 'database', 'latency_ms', 'error']);

        // 4. Reset via DELETE /api/settings/runtime restores defaults cleanly
        $delRes = $this->withHeaders([
            'Authorization' => 'Bearer test-mirror-key-12345',
        ])->deleteJson('/api/settings/runtime');
        $delRes->assertOk()
            ->assertJsonPath('runtime_settings.overrides_active', 0)
            ->assertJsonPath('runtime_settings.redis.enabled', 'auto');

        // 5. Exercise Admin HTML UI form POST /mirror/admin/runtime (disable Redis + set Caddy)
        $postUi = $this->post('/mirror/admin/runtime', [
            '_runtime_form' => '1',
            'redis_enabled' => 'disabled',
            'redis_client' => 'auto',
            'redis_host' => '127.0.0.1',
            'redis_port' => 6379,
            'redis_database' => 0,
            'server_platform' => 'caddy',
            'server_force_https' => 'false',
            'pool_strategy' => 'least_busy',
            'pool_max_concurrent_per_token' => 4,
            'pool_cooldown_seconds' => 45,
        ]);
        $postUi->assertRedirect();

        $adminHtml = $this->get('/mirror/admin');
        $adminHtml->assertOk()
            ->assertSee('Dynamic Server, Proxy &amp; Redis Configuration', false)
            ->assertSee('Redis Disabled', false)
            ->assertSee('Live DB Overrides Active', false)
            ->assertDontSee('WARP');

        // 6. Reset via Admin HTML UI button
        $this->post('/mirror/admin/runtime/reset')->assertRedirect();
        $this->get('/mirror/admin')->assertOk()->assertSee('Using .env / Config Defaults', false);
    }

    public function test_dynamic_subpath_forwarded_prefix_and_custom_route_prefixes_propagate(): void
    {
        Config::set('mirror.routes.openai_prefix', 'llm/v2');
        Config::set('mirror.routes.docs_prefix', 'api-reference');
        Config::set('mirror.routes.playground_prefix', 'dev/sandbox');

        $headers = [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'edge.example.org',
            'X-Forwarded-Prefix' => '/tenant-a',
        ];

        $openapi = $this->withHeaders($headers)->getJson('/mirror/openapi');
        $openapi->assertOk();
        $this->assertSame('https://edge.example.org/tenant-a', $openapi->json('servers.0.url'));
        $this->assertArrayHasKey('/llm/v2/chat/completions', $openapi->json('paths'));

        $docsUi = $this->withHeaders($headers)->get('/mirror/api-docs');
        $docsUi->assertOk()
            ->assertSee('url: "/tenant-a/api-reference/openapi"', false)
            ->assertSee('/tenant-a/api-reference/api-docs/swagger-ui.css', false);

        $caps = $this->withHeaders($headers)->getJson('/v1/capabilities');
        $caps->assertOk()
            ->assertJsonPath('routes.chat_completions', '/tenant-a/llm/v2/chat/completions')
            ->assertJsonPath('routes.playground', '/tenant-a/dev/sandbox')
            ->assertJsonPath('routes.api_docs', '/tenant-a/api-reference/api-docs')
            ->assertJsonPath('routes.runtime_settings', '/tenant-a/api/settings/runtime')
            ->assertJsonPath('features.dynamic_runtime_config', true);
    }

    public function test_mirror_server_command_supports_dynamic_domain_root_php_version_and_prefixes(): void
    {
        Config::set('mirror.routes.openai_prefix', 'llm/v2');
        Config::set('mirror.routes.backend_prefix', 'custom-backend');

        $this->artisan('mirror:server', [
            'target' => 'nginx',
            '--vhost' => true,
            '--domain' => 'chat.prod.example',
            '--root' => '/srv/mirror/public',
            '--php-version' => '8.4',
        ])
            ->expectsOutputToContain('server_name chat.prod.example;')
            ->expectsOutputToContain('root /srv/mirror/public;')
            ->expectsOutputToContain('fastcgi_pass unix:/run/php/php8.4-fpm.sock;')
            ->expectsOutputToContain('^/(llm/v2|api/chat|backend-api|custom-backend)/')
            ->assertSuccessful();

        $this->artisan('mirror:server', [
            'target' => 'octane',
            '--vhost' => true,
            '--host' => '0.0.0.0',
            '--port' => '9100',
        ])
            ->expectsOutputToContain('--host=0.0.0.0 --port=9100')
            ->assertSuccessful();

        $tempDir = sys_get_temp_dir() . '/mirror_dynamic_htaccess_' . bin2hex(random_bytes(4));
        File::ensureDirectoryExists($tempDir);
        $htaccessPath = $tempDir . '/.htaccess';

        try {
            $this->artisan('mirror:server', [
                'target' => 'apache',
                '--write' => true,
                '--path' => $htaccessPath,
            ])->assertSuccessful();

            $content = File::get($htaccessPath);
            $this->assertStringContainsString('llm/v2|api/chat|backend-api|custom-backend', $content);
            $this->assertStringContainsString('MIRROR_SSE=1', $content);
        } finally {
            File::deleteDirectory($tempDir);
        }
    }
}
