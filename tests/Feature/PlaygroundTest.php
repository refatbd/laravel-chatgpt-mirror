<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Tests\Feature;

use Refatbd\LaravelChatGptMirror\Services\SecurityService;
use Refatbd\LaravelChatGptMirror\Tests\TestCase;

class PlaygroundTest extends TestCase
{
    public function test_playground_spa_renders_successfully_and_issues_control_cookie(): void
    {
        $response = $this->get('/mirror/playground');

        $response->assertStatus(200);
        $response->assertSee('Mirror API Playground');
        $response->assertSee('/mirror/assets/index-BL-fCwg_.js');
        $response->assertSee('/mirror/assets/index-BMwL8PsE.css');
        $response->assertCookie(SecurityService::CONTROL_COOKIE_NAME);
    }

    public function test_playground_alias_redirects(): void
    {
        $response = $this->get('/playground');
        $response->assertRedirect('/mirror/playground');
    }

    public function test_playground_javascript_asset_served(): void
    {
        $response = $this->get('/mirror/assets/index-BL-fCwg_.js');

        $response->assertStatus(200);
        $this->assertStringContainsString('application/javascript', (string) $response->headers->get('Content-Type'));
    }

    public function test_playground_css_asset_served(): void
    {
        $response = $this->get('/mirror/assets/index-BMwL8PsE.css');

        $response->assertStatus(200);
        $this->assertStringContainsString('text/css', (string) $response->headers->get('Content-Type'));
    }

    public function test_playground_settings_endpoints_enforce_auth_and_accept_control_cookie(): void
    {
        // Unauthenticated + spoofed Referer must fail when api_key is configured
        $unauth = $this->withHeader('Referer', 'http://localhost/mirror/playground')
            ->getJson('/api/settings/default-system-instructions');
        $unauth->assertStatus(401);

        // Visiting playground issues a signed HttpOnly mirror_control cookie usable by the SPA
        $page = $this->get('/mirror/playground');
        $cookieValue = $page->getCookie(SecurityService::CONTROL_COOKIE_NAME, false)?->getValue();
        $this->assertNotEmpty($cookieValue);

        $resInstructions = $this->withCredentials()
            ->withUnencryptedCookie(SecurityService::CONTROL_COOKIE_NAME, (string) $cookieValue)
            ->getJson('/api/settings/default-system-instructions');
        $resInstructions->assertStatus(200);
        $resInstructions->assertJsonStructure(['content']);

        $resHotkeys = $this->withCredentials()
            ->withUnencryptedCookie(SecurityService::CONTROL_COOKIE_NAME, (string) $cookieValue)
            ->getJson('/api/settings/hotkeys');
        $resHotkeys->assertStatus(200);
        $resHotkeys->assertJsonStructure(['hotkeys']);

        $resConversations = $this->withCredentials()
            ->withUnencryptedCookie(SecurityService::CONTROL_COOKIE_NAME, (string) $cookieValue)
            ->getJson('/api/conversations');
        $resConversations->assertStatus(200);
        $resConversations->assertJsonStructure(['items', 'total', 'hasMore']);
    }
}
