<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Tests\Unit;

use Refatbd\LaravelChatGptMirror\Services\AssetTicketService;
use Refatbd\LaravelChatGptMirror\Services\ResponsesWriter;
use Refatbd\LaravelChatGptMirror\Services\SecurityService;
use Refatbd\LaravelChatGptMirror\Services\ToolBridgeService;
use Refatbd\LaravelChatGptMirror\Services\UrlRewriter;
use Refatbd\LaravelChatGptMirror\Tests\TestCase;

class UrlRewriterAndRichOutputTest extends TestCase
{
    public function test_url_rewriter_rewrites_origins_and_scrubs_ssr_access_tokens(): void
    {
        $rewriter = new UrlRewriter();
        $input = '{"api":"https:\/\/chatgpt.com\/backend-api","cdn":"https://cdn.oaistatic.com/assets/app.js","ws":"wss://ws.chatgpt.com/ws","accessToken":"eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiIxMjMifQ.c2lnbmF0dXJl"}';

        $out = $rewriter->rewriteText($input, 'http://127.0.0.1:8000', 'text/html');

        $this->assertStringContainsString('http:\\/\\/127.0.0.1:8000\\/backend-api', $out);
        $this->assertStringContainsString('http://127.0.0.1:8000/cdn/assets/app.js', $out);
        $this->assertStringContainsString('ws://127.0.0.1:8000/ws', $out);
        $this->assertStringContainsString('"accessToken":"' . UrlRewriter::BROWSER_TOKEN . '"', $out);
        $this->assertStringNotContainsString('eyJhbGciOiJSUzI1NiJ9', $out);
    }

    public function test_asset_ticket_service_verifies_and_revokes_on_generation_rotation(): void
    {
        /** @var SecurityService $security */
        $security = $this->app->make(SecurityService::class);
        /** @var AssetTicketService $tickets */
        $tickets = $this->app->make(AssetTicketService::class);

        $links = $tickets->createSignedLinks(
            'http://127.0.0.1:8000',
            'sediment://file_001',
            'conv-1',
            'msg-1',
            'acct-1',
            'chart.png',
            true
        );

        $this->assertStringContainsString('/api/asset-content?ticket=', $links['url']);
        $verified = $tickets->verifyTicket($links['ticket'], $links['sig']);
        $this->assertNotNull($verified);
        $this->assertSame('sediment://file_001', $verified['pointer']);

        // Tampered signature rejected
        $this->assertNull($tickets->verifyTicket($links['ticket'], 'tampered-sig'));

        // Generation rotation revokes prior ticket
        $security->rotateAssetLinkGeneration();
        $this->assertNull($tickets->verifyTicket($links['ticket'], $links['sig']));
    }

    public function test_tool_bridge_resolves_and_parses_structured_tool_calls(): void
    {
        $bridgeService = new ToolBridgeService();
        $bridge = $bridgeService->resolve([
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_weather',
                    'description' => 'Get forecast',
                    'parameters' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]],
                ],
            ],
        ], ['type' => 'function', 'function' => ['name' => 'get_weather']]);

        $this->assertTrue($bridge['enabled']);
        $this->assertSame('function', $bridge['mode']);
        $this->assertSame('get_weather', $bridge['forcedName']);
        $this->assertStringContainsString('get_weather', $bridgeService->buildInstructionBlock($bridge));

        $reply = "```mirror_tool_calls\n{\"tool_calls\":[{\"name\":\"get_weather\",\"arguments\":{\"city\":\"Dhaka\"}},{\"name\":\"unknown_tool\",\"arguments\":{}}]}\n```";
        $calls = $bridgeService->parseToolCalls($reply, $bridge);

        $this->assertNotNull($calls);
        $this->assertCount(1, $calls);
        $this->assertSame('get_weather', $calls[0]['function']['name']);
        $this->assertSame('{"city":"Dhaka"}', $calls[0]['function']['arguments']);
    }

    public function test_responses_writer_normalizes_input_and_emits_lifecycle_events(): void
    {
        $writer = new ResponsesWriter('gpt-4o');
        $messages = $writer->normalizeInputToMessages([
            'instructions' => 'Be brief.',
            'input' => [
                ['role' => 'user', 'content' => [['type' => 'input_text', 'text' => 'Say hi']]],
            ],
        ]);

        $this->assertCount(2, $messages);
        $this->assertSame('system', $messages[0]['role']);
        $this->assertSame([['type' => 'text', 'text' => 'Say hi']], $messages[1]['content']);

        $writer->setSummaries([['messageId' => 'm1', 'text' => 'Checked context']]);
        $stream = $writer->openStreamEvents()
            . $writer->deltaEvent('Hi!')
            . $writer->completeEvents('Hi!', 'gpt-4o', ['conversation_id' => 'c1']);

        $this->assertStringContainsString('event: response.created', $stream);
        $this->assertStringContainsString('event: response.output_text.delta', $stream);
        $this->assertStringContainsString('event: response.completed', $stream);

        $obj = $writer->buildResponseObject('Hi!', 'completed', 'gpt-4o', ['conversation_id' => 'c1']);
        $this->assertSame('completed', $obj['status']);
        $this->assertSame('reasoning', $obj['output'][0]['type']);
        $this->assertSame('Hi!', $obj['output_text']);
    }
}
