<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Refatbd\LaravelChatGptMirror\Services\StreamTransformer;

class ModernStreamTransformerTest extends TestCase
{
    public function test_reduces_v1_json_patches_and_suppresses_hidden_analysis_channel(): void
    {
        $tx = new StreamTransformer();

        // Title generation event
        $titleEv = $tx->processEventPayload(
            'delta',
            (string) json_encode(['type' => 'title_generation', 'title' => 'Bengali Poetics', 'conversation_id' => 'up-conv-1']),
            'gpt-4o',
            'chatcmpl-1'
        );
        $this->assertCount(1, $titleEv);
        $this->assertSame('title', $titleEv[0]['kind']);
        $this->assertSame('up-conv-1', $tx->getConversationId());

        // Hidden analysis snapshot must NOT leak into visible accumulated text
        $tx->processEventPayload('delta', (string) json_encode([
            'o' => 'add',
            'p' => '',
            'v' => [
                'conversation_id' => 'up-conv-1',
                'message' => [
                    'id' => 'msg-analysis',
                    'author' => ['role' => 'assistant'],
                    'channel' => 'analysis',
                    'status' => 'in_progress',
                    'content' => ['content_type' => 'text', 'parts' => ['internal chain of thought']],
                    'metadata' => ['is_visually_hidden_from_conversation' => true],
                ],
            ],
        ]), 'gpt-4o', 'chatcmpl-1');

        $hiddenAppend = $tx->processEventPayload('delta', (string) json_encode([
            'o' => 'append',
            'p' => '/message/content/parts/0',
            'v' => ' secret reasoning token',
        ]), 'gpt-4o', 'chatcmpl-1');
        $this->assertSame([], $hiddenAppend);
        $this->assertSame('', $tx->getAccumulatedText());

        // Visible assistant message starts
        $tx->processEventPayload('delta', (string) json_encode([
            'o' => 'add',
            'p' => '',
            'v' => [
                'conversation_id' => 'up-conv-1',
                'message' => [
                    'id' => 'msg-visible',
                    'author' => ['role' => 'assistant'],
                    'channel' => 'final',
                    'status' => 'in_progress',
                    'content' => ['content_type' => 'text', 'parts' => ['']],
                    'metadata' => [],
                ],
            ],
        ]), 'gpt-4o', 'chatcmpl-1');

        $delta1 = $tx->processEventPayload('delta', (string) json_encode([
            'o' => 'patch',
            'v' => [
                ['o' => 'append', 'p' => '/message/content/parts/0', 'v' => 'হ্যালো '],
                ['o' => 'append', 'p' => '/message/content/parts/0', 'v' => 'বিশ্ব!'],
                ['o' => 'add', 'p' => '/message/metadata/content_references', 'v' => [['matched_text' => 'citeturn0search0', 'safe_urls' => ['https://example.com']]]],
            ],
        ]), 'gpt-4o', 'chatcmpl-1');

        $this->assertNotEmpty($delta1);
        $this->assertSame('হ্যালো বিশ্ব!', $tx->getAccumulatedText());
        $this->assertSame('msg-visible', $tx->getAssistantMessageId());

        $kinds = array_column($tx->getCapturedEvents(), 'kind');
        $this->assertContains('citation_patch', $kinds);

        $tx->reset();
        $this->assertSame('', $tx->getAccumulatedText());
        $this->assertNull($tx->getConversationId());
    }

    public function test_suppresses_non_recap_commentary_and_keeps_reasoning_recap(): void
    {
        $tx = new StreamTransformer();

        $tx->processEventPayload('delta', (string) json_encode([
            'v' => [
                'message' => [
                    'id' => 'msg-commentary',
                    'author' => ['role' => 'assistant'],
                    'channel' => 'commentary',
                    'content' => ['content_type' => 'text', 'parts' => ['hidden planning step']],
                ],
            ],
        ]), 'o3-mini', 'chatcmpl-2');

        $this->assertSame('', $tx->getAccumulatedText());

        $recapEvents = $tx->processEventPayload('delta', (string) json_encode([
            'v' => [
                'message' => [
                    'id' => 'msg-recap',
                    'author' => ['role' => 'assistant'],
                    'channel' => 'commentary',
                    'content' => ['content_type' => 'reasoning_recap', 'text' => 'Thought for 3 seconds'],
                ],
            ],
        ]), 'o3-mini', 'chatcmpl-2');

        $this->assertCount(1, $recapEvents);
        $this->assertFalse($recapEvents[0]['displayHidden']);
    }

    public function test_handles_live_chatgpt_v1_omitted_p_o_inheritance_and_unhide_patch_order(): void
    {
        $tx = new StreamTransformer();

        // 1. Delta encoding & resume token
        $this->assertSame([], $tx->processEventPayload('delta_encoding', '"v1"', 'auto', 'chatcmpl-live'));
        $this->assertSame([], $tx->processEventPayload('message', json_encode([
            'type' => 'resume_conversation_token',
            'token' => 'tok-123',
            'conversation_id' => 'conv-live-1',
        ]), 'auto', 'chatcmpl-live'));
        $this->assertSame('tok-123', $tx->getResumeToken());

        // 2. Initial system root add sets inherited p="" o="add"
        $tx->processEventPayload('delta', json_encode([
            'p' => '',
            'o' => 'add',
            'v' => [
                'conversation_id' => 'conv-live-1',
                'message' => [
                    'id' => 'sys-1',
                    'author' => ['role' => 'system'],
                    'content' => ['content_type' => 'text', 'parts' => ['']],
                    'metadata' => ['is_visually_hidden_from_conversation' => true],
                ],
            ],
        ]), 'auto', 'chatcmpl-live');

        // 3. Real assistant placeholder arrives WITHOUT p/o, AND starts visually hidden
        $tx->processEventPayload('delta', json_encode([
            'c' => 8,
            'v' => [
                'conversation_id' => 'conv-live-1',
                'message' => [
                    'id' => 'asst-1',
                    'author' => ['role' => 'assistant'],
                    'channel' => 'final',
                    'recipient' => 'all',
                    'status' => 'in_progress',
                    'content' => ['content_type' => 'text', 'parts' => ['']],
                    'metadata' => ['is_visually_hidden_from_conversation' => true],
                ],
            ],
        ]), 'auto', 'chatcmpl-live');

        // 4. user_visible_token (first) marker
        $tx->processEventPayload('message', json_encode([
            'type' => 'message_marker',
            'conversation_id' => 'conv-live-1',
            'message_id' => 'asst-1',
            'marker' => 'user_visible_token',
            'event' => 'first',
        ]), 'auto', 'chatcmpl-live');

        // 5. Batch patch appends text BEFORE removing is_visually_hidden_from_conversation
        $firstDelta = $tx->processEventPayload('delta', json_encode([
            'o' => 'patch',
            'v' => [
                ['p' => '/message/content/parts/0', 'o' => 'append', 'v' => 'OK'],
                ['p' => '/message/end_turn', 'o' => 'replace', 'v' => null],
                ['p' => '/message/metadata/is_visually_hidden_from_conversation', 'o' => 'remove'],
            ],
        ]), 'auto', 'chatcmpl-live');

        $this->assertCount(1, $firstDelta);
        $this->assertSame('assistant_text_delta', $firstDelta[0]['kind']);
        $this->assertSame('OK', $firstDelta[0]['delta']);

        // 6. Status patch arrives WITHOUT top-level o (inherits o="patch")
        $tx->processEventPayload('delta', json_encode([
            'v' => [
                ['p' => '/message/status', 'o' => 'replace', 'v' => 'finished_successfully'],
            ],
        ]), 'auto', 'chatcmpl-live');
        $this->assertSame('finished_successfully', $tx->getAssistantStatus());

        // 7. Standalone append followed by bare `{v: "..."}` continuation inherits p & o
        $tx->processEventPayload('delta', json_encode([
            'o' => 'append',
            'p' => '/message/content/parts/0',
            'v' => ' বাংলা',
        ]), 'auto', 'chatcmpl-live');
        $contDelta = $tx->processEventPayload('delta', json_encode([
            'v' => '!',
        ]), 'auto', 'chatcmpl-live');

        $this->assertCount(1, $contDelta);
        $this->assertSame('!', $contDelta[0]['delta']);
        $this->assertSame('OK বাংলা!', $tx->getAccumulatedText());
        $this->assertSame('asst-1', $tx->getAssistantMessageId());
    }
}
