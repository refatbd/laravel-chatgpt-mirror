<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Tests\Feature;

use Refatbd\LaravelChatGptMirror\Models\MirrorToken;
use Refatbd\LaravelChatGptMirror\Services\UrlRewriter;
use Refatbd\LaravelChatGptMirror\Tests\TestCase;

class OpenAiApiTest extends TestCase
{
    public function test_v1_models_requires_valid_api_key(): void
    {
        $response = $this->getJson('/v1/models');
        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'invalid_api_key');

        // Spoofed Referer header must NEVER bypass API key verification
        $spoofed = $this->withHeader('Referer', 'http://localhost/mirror/playground')
            ->getJson('/v1/models');
        $spoofed->assertStatus(401);
    }

    public function test_v1_models_returns_model_list_with_valid_key(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer test-mirror-key-12345')
            ->getJson('/v1/models');

        $response->assertStatus(200);
        $response->assertJsonPath('object', 'list');
        $this->assertNotEmpty($response->json('data'));
    }

    public function test_auth_session_returns_synthesized_account_metadata_without_raw_jwt(): void
    {
        MirrorToken::create([
            'name' => 'Refat User',
            'email' => 'rjrefat@gmail.com',
            'session_token' => 'sample-session',
            'access_token' => 'secret-upstream-jwt-access-token',
            'plan_type' => 'free',
            'status' => 'active',
        ]);

        $response = $this->getJson('/api/auth/session');

        $response->assertStatus(200);
        $response->assertJsonPath('user.email', 'rjrefat@gmail.com');
        $response->assertJsonPath('accessToken', UrlRewriter::BROWSER_TOKEN);
        $response->assertJsonPath('account.planType', 'free');
        $response->assertJsonPath('account.structure', 'personal');
        $this->assertStringNotContainsString('secret-upstream-jwt-access-token', (string) $response->getContent());
    }
}
