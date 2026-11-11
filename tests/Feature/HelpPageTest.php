<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Tests\Feature;

use Illuminate\Support\Facades\Config;
use Refatbd\LaravelChatGptMirror\Models\MirrorToken;
use Refatbd\LaravelChatGptMirror\Tests\TestCase;

class HelpPageTest extends TestCase
{
    public function test_help_page_renders_complete_guide_with_zero_secret_or_warp_leakage(): void
    {
        Config::set('mirror.redis.password', 'super-secret-redis-pass-987');

        MirrorToken::create([
            'name' => 'Secret-Account-Alpha',
            'email' => 'leak-check-account@example.com',
            'session_token' => 'raw-secret-session-token-abc',
            'access_token' => 'raw-secret-access-token-xyz',
            'status' => 'active',
        ]);

        $response = $this->get('/mirror/help');

        $response->assertStatus(200);
        $this->assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        // Core page sections & search/copy controls
        $response->assertSee('Laravel ChatGPT Mirror', false)
            ->assertSee('Direct PHP Upstream', false)
            ->assertSee('1 / 1 Active (0 Cooldown)', false)
            ->assertSee('1. How Laravel ChatGPT Mirror Works', false)
            ->assertSee('2. Where Is Everything? (Pages, Endpoints &amp; Project Files)', false)
            ->assertSee('3. Complete Features Inventory', false)
            ->assertSee('4. Step-by-Step Setup &amp; Token Configuration Guide', false)
            ->assertSee('5. Every Setting &amp; <code>.env</code> Key Explained', false)
            ->assertSee('6. Live Database Overrides vs <code>.env</code> Priority', false)
            ->assertSee('7. Production Server Deployment (Webuzo, Nginx, Caddy, Octane &amp; Redis)', false)
            ->assertSee('8. Client SDK, Tools &amp; Continuation Examples', false)
            ->assertSee('9. Artisan CLI Quick Reference', false)
            ->assertSee('10. Troubleshooting &amp; Error Diagnostics', false)
            ->assertSee('id="help-search"', false)
            ->assertSee('id="search-empty"', false)
            ->assertSee('data-copy-target="env-template-pre"', false);

        // Critical .env keys & operational commands documented
        $response->assertSee('MIRROR_API_KEY', false)
            ->assertSee('MIRROR_READ_TIMEOUT', false)
            ->assertSee('MIRROR_CORS_ENABLED', false)
            ->assertSee('MIRROR_RATE_LIMIT_ENABLED', false)
            ->assertSee('MIRROR_REDIS_ENABLED', false)
            ->assertSee('MIRROR_SERVER_PLATFORM', false)
            ->assertSee('MIRROR_HELP_PREFIX', false)
            ->assertSee('php artisan mirror:server', false);

        // Strict public security guarantees: zero secret or WARP disclosure
        $response->assertDontSee('WARP', false)
            ->assertDontSee('test-mirror-key-12345', false)
            ->assertDontSee('super-secret-redis-pass-987', false)
            ->assertDontSee('Secret-Account-Alpha', false)
            ->assertDontSee('leak-check-account@example.com', false)
            ->assertDontSee('raw-secret-session-token-abc', false)
            ->assertDontSee('raw-secret-access-token-xyz', false);
    }

    public function test_help_and_about_aliases_redirect_cleanly(): void
    {
        foreach (['/help', '/about', '/mirror/about'] as $alias) {
            $response = $this->get($alias);
            $response->assertStatus(302);
            $this->assertStringEndsWith('/mirror/help', (string) $response->headers->get('Location'));
        }
    }

    public function test_help_page_adapts_dynamically_to_reverse_proxy_and_subpath(): void
    {
        $response = $this->withHeaders([
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'docs.example.net',
            'X-Forwarded-Prefix' => '/sub',
        ])->get('/mirror/help');

        $response->assertStatus(200)
            ->assertSee('https://docs.example.net/sub', false)
            ->assertSee('https://docs.example.net/sub/v1', false)
            ->assertSee('/sub/mirror/admin', false)
            ->assertSee('/sub/mirror/playground', false)
            ->assertSee('/sub/mirror/api-docs', false)
            ->assertSee('/sub/mirror/help', false);
    }

    public function test_admin_swagger_capabilities_and_openapi_expose_help_links_dynamically(): void
    {
        $admin = $this->get('/mirror/admin');
        $admin->assertStatus(200)
            ->assertSee('/mirror/help', false)
            ->assertSee('Help &amp; About', false);

        $docs = $this->get('/mirror/api-docs');
        $docs->assertStatus(200)
            ->assertSee('/mirror/help', false)
            ->assertSee('Help &amp; About', false)
            ->assertSee('Settings &amp; Admin', false)
            ->assertSee('Playground', false);

        Config::set('mirror.routes.help_prefix', 'guide/manual');

        $headers = [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'edge.example.org',
            'X-Forwarded-Prefix' => '/tenant-a',
        ];

        $caps = $this->withHeaders($headers)->getJson('/v1/capabilities');
        $caps->assertOk()
            ->assertJsonPath('routes.help', '/tenant-a/guide/manual')
            ->assertJsonPath('endpoints.help', '/tenant-a/guide/manual');

        $openapi = $this->withHeaders($headers)->getJson('/mirror/openapi');
        $openapi->assertOk()
            ->assertJsonPath('externalDocs.url', 'https://edge.example.org/tenant-a/guide/manual');
    }
}
