<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Tests\Unit;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Refatbd\LaravelChatGptMirror\Services\StreamTransformer;

class StreamTransformerTest extends BaseTestCase
{
    public function test_it_transforms_chatgpt_cumulative_sse_into_openai_delta_chunks(): void
    {
        $transformer = new StreamTransformer();
        $model = 'gpt-4o';
        $chatId = 'chatcmpl-test1234';

        // 1st Chunk: "Hello"
        $raw1 = 'data: {"message": {"id": "msg-1", "author": {"role": "assistant"}, "content": {"parts": ["Hello"]}}, "conversation_id": "conv-1"}';
        $chunk1 = $transformer->transformLine($raw1, $model, $chatId);

        $this->assertNotNull($chunk1);
        $this->assertStringStartsWith('data: {', $chunk1);
        $data1 = json_decode(substr(trim($chunk1), 6), true);
        $this->assertEquals('Hello', $data1['choices'][0]['delta']['content']);
        $this->assertEquals('gpt-4o', $data1['model']);

        // 2nd Chunk: Cumulative "Hello World!" -> delta should be " World!"
        $raw2 = 'data: {"message": {"id": "msg-1", "author": {"role": "assistant"}, "content": {"parts": ["Hello World!"]}}, "conversation_id": "conv-1"}';
        $chunk2 = $transformer->transformLine($raw2, $model, $chatId);

        $this->assertNotNull($chunk2);
        $data2 = json_decode(substr(trim($chunk2), 6), true);
        $this->assertEquals(' World!', $data2['choices'][0]['delta']['content']);

        // Terminal Chunk: data: [DONE]
        $rawDone = 'data: [DONE]';
        $doneChunk = $transformer->transformLine($rawDone, $model, $chatId);

        $this->assertNotNull($doneChunk);
        $this->assertStringContainsString('"finish_reason":"stop"', $doneChunk);
        $this->assertStringContainsString('data: [DONE]', $doneChunk);
    }

    public function test_it_skips_non_data_and_comment_lines(): void
    {
        $transformer = new StreamTransformer();
        $this->assertNull($transformer->transformLine(': heartbeat', 'gpt-4o', 'test'));
        $this->assertNull($transformer->transformLine('event: ping', 'gpt-4o', 'test'));
        $this->assertNull($transformer->transformLine('', 'gpt-4o', 'test'));
    }

    public function test_it_correctly_handles_multibyte_bengali_characters(): void
    {
        $transformer = new StreamTransformer();
        $raw1 = 'data: {"message": {"id": "msg-bn", "author": {"role": "assistant"}, "content": {"parts": ["বাংলা"]}}, "conversation_id": "c-1"}';
        $chunk1 = $transformer->transformLine($raw1, 'gpt-4o', 'chat1');
        $data1 = json_decode(substr(trim($chunk1), 6), true);
        $this->assertEquals('বাংলা', $data1['choices'][0]['delta']['content']);

        $raw2 = 'data: {"message": {"id": "msg-bn", "author": {"role": "assistant"}, "content": {"parts": ["বাংলা ভাষা"]}}, "conversation_id": "c-1"}';
        $chunk2 = $transformer->transformLine($raw2, 'gpt-4o', 'chat1');
        $data2 = json_decode(substr(trim($chunk2), 6), true);
        $this->assertEquals(' ভাষা', $data2['choices'][0]['delta']['content']);
    }
}
