<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Tests\Feature;

use Refatbd\LaravelChatGptMirror\Tests\TestCase;

class ApiDocsTest extends TestCase
{
    public function test_swagger_ui_renders_at_mirror_api_docs(): void
    {
        $response = $this->get('/mirror/api-docs');

        $response->assertStatus(200);
        $this->assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'));
        $response->assertSee('SwaggerUIBundle', false);
        $response->assertSee('/mirror/openapi', false);
        $response->assertSee('/mirror/api-docs/swagger-ui-bundle.js', false);
        $response->assertSee('/mirror/api-docs/swagger-ui.css', false);
        $response->assertSee('<meta name="color-scheme" content="light" />', false);
        $response->assertSee('pre.microlight { background:#f8fafc !important', false);
    }

    public function test_swagger_ui_static_assets_are_served_locally(): void
    {
        $js = $this->get('/mirror/api-docs/swagger-ui-bundle.js');
        $js->assertStatus(200);
        $this->assertStringContainsString('application/javascript', (string) $js->headers->get('Content-Type'));

        $css = $this->get('/mirror/api-docs/swagger-ui.css');
        $css->assertStatus(200);
        $this->assertStringContainsString('text/css', (string) $css->headers->get('Content-Type'));

        $missing = $this->get('/mirror/api-docs/nonexistent-asset.js');
        $missing->assertStatus(404);
    }

    public function test_openapi_endpoint_returns_valid_openapi_31_json(): void
    {
        $response = $this->get('/mirror/openapi');

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertSame('3.1.0', $data['openapi'] ?? null);
        $this->assertSame('Mirror API', $data['info']['title'] ?? null);
        $this->assertArrayHasKey('/v1/models', $data['paths'] ?? []);
        $this->assertArrayHasKey('/v1/chat/completions', $data['paths'] ?? []);
        $this->assertArrayHasKey('/v1/responses', $data['paths'] ?? []);
        $this->assertArrayHasKey('/v1/capabilities', $data['paths'] ?? []);
        $this->assertArrayHasKey('/api/health', $data['paths'] ?? []);
        $this->assertArrayHasKey('/api/diagnostics', $data['paths'] ?? []);
        $this->assertArrayHasKey('/api/session', $data['paths'] ?? []);
        $this->assertArrayHasKey('/mirror/admin/api/stats', $data['paths'] ?? []);
        $this->assertArrayHasKey('/mirror/openapi', $data['paths'] ?? []);
        $this->assertArrayHasKey('mirrorApiKey', $data['components']['securitySchemes'] ?? []);
    }

    public function test_openapi_endpoint_supports_yaml_format(): void
    {
        $response = $this->get('/mirror/openapi?format=yaml');

        $response->assertStatus(200);
        $this->assertStringContainsString('application/yaml', (string) $response->headers->get('Content-Type'));
        $body = (string) $response->getContent();
        $this->assertStringContainsString('openapi: 3.1.0', str_replace("'", '', $body));
        $this->assertStringContainsString('/v1/chat/completions', $body);
    }

    public function test_openapi_rejects_unsupported_format_parameter(): void
    {
        $response = $this->get('/mirror/openapi?format=xml');

        $response->assertStatus(400);
        $response->assertJsonPath('error.code', 'invalid_format');
    }
}
