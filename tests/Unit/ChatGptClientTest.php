<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Tests\Unit;

use Refatbd\LaravelChatGptMirror\Services\ChatGptClient;
use Refatbd\LaravelChatGptMirror\Tests\TestCase;

class ChatGptClientTest extends TestCase
{
    public function test_it_maps_standard_openai_models_to_chatgpt_slugs(): void
    {
        $client = new ChatGptClient();

        $this->assertEquals('gpt-4o-mini', $client->mapModel('gpt-4o-mini'));
        $this->assertEquals('gpt-4', $client->mapModel('gpt-4'));
        $this->assertEquals('gpt-4', $client->mapModel('gpt-4-turbo'));
        $this->assertEquals('o1', $client->mapModel('o1'));
        $this->assertEquals('o3-mini', $client->mapModel('o3-mini'));
        $this->assertEquals('gpt-4o', $client->mapModel('gpt-4o'));
        $this->assertEquals('auto', $client->mapModel('gpt-3.5-turbo'));
        $this->assertEquals('auto', $client->mapModel(''));
        $this->assertEquals('o4-mini', $client->mapModel('o4-mini'));
    }

    public function test_it_builds_valid_chatgpt_conversation_payload(): void
    {
        $client = new ChatGptClient();

        $messages = [
            ['role' => 'system', 'content' => 'You are a helpful assistant.'],
            ['role' => 'user', 'content' => 'Hello from Refat!'],
        ];

        $payload = $client->buildConversationPayload($messages, 'gpt-4o');

        $this->assertEquals('next', $payload['action']);
        $this->assertEquals('gpt-4o', $payload['model']);
        $this->assertNotEmpty($payload['parent_message_id']);
        $this->assertCount(1, $payload['messages']);
        $this->assertStringContainsString('You are a helpful assistant.', $payload['messages'][0]['content']['parts'][0]);
        $this->assertStringContainsString('Hello from Refat!', $payload['messages'][0]['content']['parts'][0]);
        $this->assertEquals('user', $payload['messages'][0]['author']['role']);
        $this->assertEquals('primary_assistant', $payload['conversation_mode']['kind']);
    }

    public function test_get_models_returns_cached_fallback_list_when_no_token(): void
    {
        $client = new ChatGptClient();
        $models = $client->getModels();

        $this->assertNotEmpty($models);
        $slugs = array_column($models, 'slug');
        $this->assertContains('auto', $slugs);
        $this->assertContains('gpt-4o', $slugs);
        $this->assertContains('o1', $slugs);
        $this->assertContains('o3-mini', $slugs);
    }
}
