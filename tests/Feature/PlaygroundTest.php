<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Tests\Feature;

use Refatbd\LaravelChatGptMirror\Tests\TestCase;

class PlaygroundTest extends TestCase
{
    public function test_playground_spa_renders_successfully(): void
    {
        $response = $this->get('/mirror/playground');

        $response->assertStatus(200);
        $response->assertSee('Mirror API Playground');
        $response->assertSee('/mirror/assets/index-BL-fCwg_.js');
        $response->assertSee('/mirror/assets/index-BMwL8PsE.css');
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

    public function test_playground_settings_endpoints(): void
    {
        $resInstructions = $this->getJson('/api/settings/default-system-instructions');
        $resInstructions->assertStatus(200);
        $resInstructions->assertJsonStructure(['content']);

        $resHotkeys = $this->getJson('/api/settings/hotkeys');
        $resHotkeys->assertStatus(200);
        $resHotkeys->assertJsonStructure(['hotkeys']);

        $resConversations = $this->getJson('/api/conversations');
        $resConversations->assertStatus(200);
        $resConversations->assertJsonStructure(['items', 'total', 'hasMore']);
    }
}
