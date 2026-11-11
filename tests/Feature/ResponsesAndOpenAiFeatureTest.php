<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Tests\Feature;

use Refatbd\LaravelChatGptMirror\Exceptions\UpstreamRequestException;
use Refatbd\LaravelChatGptMirror\Models\MirrorToken;
use Refatbd\LaravelChatGptMirror\Services\ChatGptClient;
use Refatbd\LaravelChatGptMirror\Services\ConversationService;
use Refatbd\LaravelChatGptMirror\Tests\TestCase;

class ResponsesAndOpenAiFeatureTest extends TestCase
{
    public function test_chat_completions_sync_streaming_tools_and_continuation(): void
    {
        MirrorToken::create([
            'name' => 'Gateway Account',
            'email' => 'gw@example.com',
            'account_id' => 'acct-gw-1',
            'session_token' => 'gw-session-token-123456',
            'access_token' => 'gw-access-token',
            'status' => 'active',
        ]);

        $mockClient = $this->createMock(ChatGptClient::class);
        $mockClient->method('routeModel')->willReturn(['model' => 'gpt-4o', 'gizmoId' => null, 'private' => null]);
        $mockClient->method('prepareFollowup')->willReturn('conduit-xyz');
        $mockClient->method('buildConversationPayload')->willReturn(['model' => 'gpt-4o', 'messages' => []]);
        $mockClient->method('streamConversation')->willReturnCallback(
            function (MirrorToken $t, array $payload, callable $onChunk): void {
                $callIndex = $GLOBALS['__mirror_test_call_idx'] = ($GLOBALS['__mirror_test_call_idx'] ?? 0) + 1;
                if ($callIndex === 3) {
                    $onChunk('delta', (string) json_encode([
                        'conversation_id' => 'up-conv-tools',
                        'message' => [
                            'id' => 'msg-tool-res',
                            'author' => ['role' => 'assistant'],
                            'channel' => 'final',
                            'content' => ['content_type' => 'text', 'parts' => ["```mirror_tool_calls\n{\"tool_calls\":[{\"name\":\"lookup\",\"arguments\":{\"id\":42}}]}\n```"]],
                        ],
                    ]));
                    return;
                }

                // First emit hidden analysis, then visible answer
                $onChunk('delta', (string) json_encode([
                    'o' => 'add',
                    'p' => '',
                    'v' => [
                        'conversation_id' => 'up-conv-99',
                        'message' => [
                            'id' => 'msg-hid',
                            'author' => ['role' => 'assistant'],
                            'channel' => 'analysis',
                            'content' => ['content_type' => 'text', 'parts' => ['hidden reasoning']],
                            'metadata' => ['is_visually_hidden_from_conversation' => true],
                        ],
                    ],
                ]));
                $onChunk('delta', (string) json_encode([
                    'o' => 'add',
                    'p' => '',
                    'v' => [
                        'conversation_id' => 'up-conv-99',
                        'message' => [
                            'id' => 'msg-vis-' . $callIndex,
                            'author' => ['role' => 'assistant'],
                            'channel' => 'final',
                            'content' => ['content_type' => 'text', 'parts' => ['']],
                        ],
                    ],
                ]));
                $onChunk('delta', (string) json_encode(['o' => 'append', 'p' => '/message/content/parts/0', 'v' => 'Hello ']));
                $onChunk('delta', (string) json_encode(['o' => 'append', 'p' => '/message/content/parts/0', 'v' => 'World!']));
            }
        );

        $GLOBALS['__mirror_test_call_idx'] = 0;
        $this->app->instance(ChatGptClient::class, $mockClient);

        // 1. Sync completion suppresses hidden analysis and returns clean text + conversation metadata
        $syncRes = $this->withHeader('Authorization', 'Bearer test-mirror-key-12345')
            ->postJson('/v1/chat/completions', [
                'model' => 'gpt-4o',
                'messages' => [['role' => 'user', 'content' => 'Say hello']],
            ]);

        $syncRes->assertStatus(200);
        $syncRes->assertJsonPath('choices.0.message.content', 'Hello World!');
        $this->assertStringNotContainsString('hidden reasoning', (string) $syncRes->getContent());
        $convId = $syncRes->headers->get('x-mirror-conversation-id') ?: $syncRes->json('metadata.conversation_id');
        $this->assertNotEmpty($convId);
        $this->assertSame($convId, $syncRes->json('metadata.conversation_id'));

        // 2. Streaming SSE completion emits OpenAI delta chunks and [DONE]
        $streamRes = $this->withHeader('Authorization', 'Bearer test-mirror-key-12345')
            ->postJson('/v1/chat/completions', [
                'model' => 'gpt-4o',
                'stream' => true,
                'metadata' => ['conversation_id' => $convId],
                'messages' => [['role' => 'user', 'content' => 'Again']],
            ]);
        $streamRes->assertStatus(200);
        $sse = $streamRes->streamedContent();
        $this->assertStringContainsString('Hello ', $sse);
        $this->assertStringContainsString('World!', $sse);
        $this->assertStringContainsString('data: [DONE]', $sse);

        // 3. ToolBridge tool_calls parsing
        $toolRes = $this->withHeader('Authorization', 'Bearer test-mirror-key-12345')
            ->postJson('/v1/chat/completions', [
                'model' => 'gpt-4o',
                'tools' => [
                    [
                        'type' => 'function',
                        'function' => ['name' => 'lookup', 'parameters' => ['type' => 'object']],
                    ],
                ],
                'tool_choice' => 'required',
                'messages' => [['role' => 'user', 'content' => 'Lookup item 42']],
            ]);
        $toolRes->assertStatus(200);
        $toolRes->assertJsonPath('choices.0.finish_reason', 'tool_calls');
        $toolRes->assertJsonPath('choices.0.message.tool_calls.0.function.name', 'lookup');
    }

    public function test_v1_responses_sync_and_stream_and_failure_tracking(): void
    {
        MirrorToken::create([
            'name' => 'Responses Account',
            'account_id' => 'acct-resp-1',
            'session_token' => 'resp-session-token-12345',
            'access_token' => 'resp-access-token',
            'status' => 'active',
        ]);

        $mockClient = $this->createMock(ChatGptClient::class);
        $mockClient->method('routeModel')->willReturn(['model' => 'gpt-4o', 'gizmoId' => null, 'private' => null]);
        $mockClient->method('buildConversationPayload')->willReturn(['model' => 'gpt-4o', 'messages' => []]);
        $mockClient->method('streamConversation')->willReturnCallback(
            function (MirrorToken $t, array $payload, callable $onChunk): void {
                $call = $GLOBALS['__mirror_resp_call'] = ($GLOBALS['__mirror_resp_call'] ?? 0) + 1;
                if ($call === 3) {
                    throw new UpstreamRequestException('Sentinel Turnstile rejected request', 403);
                }
                $onChunk('delta', (string) json_encode([
                    'conversation_id' => 'up-resp-' . $call,
                    'message' => [
                        'id' => 'msg-resp-' . $call,
                        'author' => ['role' => 'assistant'],
                        'channel' => 'final',
                        'content' => ['content_type' => 'text', 'parts' => ['Pong!']],
                    ],
                ]));
            }
        );

        $GLOBALS['__mirror_resp_call'] = 0;
        $this->app->instance(ChatGptClient::class, $mockClient);

        // Sync /v1/responses
        $sync = $this->withHeader('Authorization', 'Bearer test-mirror-key-12345')
            ->postJson('/v1/responses', [
                'model' => 'gpt-4o',
                'input' => 'Ping',
            ]);
        $sync->assertStatus(200);
        $sync->assertJsonPath('object', 'response');
        $sync->assertJsonPath('status', 'completed');
        $sync->assertJsonPath('output_text', 'Pong!');

        // Streaming /v1/responses
        $stream = $this->withHeader('Authorization', 'Bearer test-mirror-key-12345')
            ->postJson('/v1/responses', [
                'model' => 'gpt-4o',
                'stream' => true,
                'input' => 'Ping stream',
            ]);
        $stream->assertStatus(200);
        $sse = $stream->streamedContent();
        $this->assertStringContainsString('event: response.created', $sse);
        $this->assertStringContainsString('event: response.output_text.delta', $sse);
        $this->assertStringContainsString('event: response.completed', $sse);

        // Upstream 403 failure propagates structured envelope and logs sanitized FailureTracker entry
        $fail = $this->withHeader('Authorization', 'Bearer test-mirror-key-12345')
            ->withHeader('X-Request-Id', 'req-fail-test')
            ->postJson('/v1/responses', [
                'model' => 'gpt-4o',
                'input' => 'Trigger failure',
            ]);
        $fail->assertStatus(403);

        $diag = $this->withHeader('Authorization', 'Bearer test-mirror-key-12345')
            ->getJson('/api/diagnostics');
        $diag->assertStatus(200);
        $failures = $diag->json('recentFailures');
        $this->assertNotEmpty($failures);
        $this->assertSame('req-fail-test', $failures[0]['requestId']);
    }

    public function test_ssrf_guard_blocks_private_hosts(): void
    {
        /** @var ConversationService $service */
        $service = $this->app->make(ConversationService::class);

        $this->assertFalse($service->isPublicHostname('localhost'));
        $this->assertFalse($service->isPublicHostname('127.0.0.1'));
        $this->assertFalse($service->isPublicHostname('169.254.169.254'));
        $this->assertFalse($service->isPublicHostname('10.2.3.4'));
        $this->assertFalse($service->isPublicHostname('192.168.1.1'));
        $this->assertTrue($service->isPublicHostname('8.8.8.8'));
    }

    public function test_chat_completions_rejects_work_mode_and_supports_streaming_tools_and_role_tool_followup(): void
    {
        MirrorToken::create([
            'name' => 'Tool Parity Account',
            'email' => 'tools@example.com',
            'account_id' => 'acct-tools-1',
            'session_token' => 'tools-session-token-123456',
            'access_token' => 'tools-access-token',
            'status' => 'active',
        ]);

        // Mock upstream before any /v1/chat/completions request resolves OpenAiApiController via Laravel Route cache
        $capturedPayloads = [];
        $mockClient = $this->createMock(ChatGptClient::class);
        $mockClient->method('routeModel')->willReturn(['model' => 'gpt-4o', 'gizmoId' => null, 'private' => null]);
        $mockClient->method('prepareFollowup')->willReturn('conduit-tools');
        $mockClient->method('buildConversationPayload')->willReturnCallback(
            function (array $messages, string $model) use (&$capturedPayloads): array {
                $capturedPayloads[] = $messages;
                return ['model' => $model, 'messages' => $messages];
            }
        );

        $callCount = 0;
        $mockClient->method('streamConversation')->willReturnCallback(
            function (MirrorToken $t, array $payload, callable $onChunk) use (&$callCount): void {
                $callCount++;
                if ($callCount === 1) {
                    $onChunk('delta', (string) json_encode([
                        'conversation_id' => 'up-conv-stream-tools',
                        'message' => [
                            'id' => 'msg-tool-call-1',
                            'author' => ['role' => 'assistant'],
                            'channel' => 'final',
                            'content' => ['content_type' => 'text', 'parts' => ["```mirror_tool_calls\n{\"tool_calls\":[{\"name\":\"get_weather\",\"arguments\":{\"city\":\"Dhaka\"}}]}\n```"]],
                        ],
                    ]));
                    return;
                }

                $onChunk('delta', (string) json_encode([
                    'conversation_id' => 'up-conv-final-answer',
                    'message' => [
                        'id' => 'msg-final-1',
                        'author' => ['role' => 'assistant'],
                        'channel' => 'final',
                        'content' => ['content_type' => 'text', 'parts' => ['The weather in Dhaka is 30C.']],
                    ],
                ]));
            }
        );
        $this->app->instance(ChatGptClient::class, $mockClient);

        // 1. Reject `-wm` Work Mode parameter cleanly with 400 unsupported_parameter
        $wmReject = $this->withHeader('Authorization', 'Bearer test-mirror-key-12345')
            ->postJson('/v1/chat/completions', [
                'model' => 'gpt-5-wm',
                'messages' => [['role' => 'user', 'content' => 'Hello']],
            ]);
        $wmReject->assertStatus(400)
            ->assertJsonPath('error.code', 'unsupported_parameter');

        // 2. Reject OpenAI `role: tool` on `/v1/responses` cleanly
        $respToolReject = $this->withHeader('Authorization', 'Bearer test-mirror-key-12345')
            ->postJson('/v1/responses', [
                'model' => 'gpt-4o',
                'input' => [['role' => 'tool', 'content' => '42']],
            ]);
        $respToolReject->assertStatus(400);

        // Streamed request with tools must emit SSE `delta.tool_calls` and `finish_reason: "tool_calls"`
        $streamTools = $this->withHeader('Authorization', 'Bearer test-mirror-key-12345')
            ->postJson('/v1/chat/completions', [
                'model' => 'gpt-4o',
                'stream' => true,
                'tools' => [[
                    'type' => 'function',
                    'function' => ['name' => 'get_weather', 'parameters' => ['type' => 'object']],
                ]],
                'messages' => [['role' => 'user', 'content' => 'Weather in Dhaka?']],
            ]);

        $streamTools->assertStatus(200);
        $sse = $streamTools->streamedContent();
        $this->assertStringContainsString('"tool_calls"', $sse);
        $this->assertStringContainsString('get_weather', $sse);
        $this->assertStringContainsString('"finish_reason":"tool_calls"', $sse);
        $this->assertStringContainsString('data: [DONE]', $sse);

        // Multi-turn follow-up carrying assistant tool_calls + role=tool must embed tool results and complete
        $followup = $this->withHeader('Authorization', 'Bearer test-mirror-key-12345')
            ->postJson('/v1/chat/completions', [
                'model' => 'gpt-4o',
                'tools' => [[
                    'type' => 'function',
                    'function' => ['name' => 'get_weather', 'parameters' => ['type' => 'object']],
                ]],
                'messages' => [
                    ['role' => 'user', 'content' => 'Weather in Dhaka?'],
                    [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_dhaka_1',
                            'type' => 'function',
                            'function' => ['name' => 'get_weather', 'arguments' => '{"city":"Dhaka"}'],
                        ]],
                    ],
                    [
                        'role' => 'tool',
                        'tool_call_id' => 'call_dhaka_1',
                        'name' => 'get_weather',
                        'content' => '{"temp":"30C"}',
                    ],
                ],
            ]);

        $followup->assertStatus(200)
            ->assertJsonPath('choices.0.message.content', 'The weather in Dhaka is 30C.');
        $this->assertCount(2, $capturedPayloads);
        $this->assertStringContainsString('role=tool results are included here', (string) ($capturedPayloads[1][0]['content'] ?? ''));
        $this->assertStringContainsString('call_dhaka_1', (string) ($capturedPayloads[1][0]['content'] ?? ''));
        $this->assertStringContainsString('30C', (string) ($capturedPayloads[1][0]['content'] ?? ''));
    }

    public function test_chat_completions_full_history_inference_assistant_edit_guard_and_user_history_rebase(): void
    {
        MirrorToken::create([
            'name' => 'History Parity Account',
            'email' => 'hist@example.com',
            'account_id' => 'acct-hist-1',
            'session_token' => 'hist-session-token-123456',
            'access_token' => 'hist-access-token',
            'status' => 'active',
        ]);

        $capturedUpstreamArgs = [];
        $turn = 0;
        $mockClient = $this->createMock(ChatGptClient::class);
        $mockClient->method('routeModel')->willReturn(['model' => 'gpt-4o', 'gizmoId' => null, 'private' => null]);
        $mockClient->method('prepareFollowup')->willReturn('conduit-hist');
        $mockClient->method('buildConversationPayload')->willReturnCallback(
            function (array $messages, string $model, ?string $upstreamConvId, string $parentNodeId) use (&$capturedUpstreamArgs): array {
                $capturedUpstreamArgs[] = [
                    'messages' => $messages,
                    'upstreamConvId' => $upstreamConvId,
                    'parentNodeId' => $parentNodeId,
                ];
                return ['model' => $model, 'messages' => $messages];
            }
        );

        $mockClient->method('streamConversation')->willReturnCallback(
            function (MirrorToken $t, array $payload, callable $onChunk) use (&$turn): void {
                $turn++;
                if ($turn === 3) {
                    throw new \RuntimeException('Simulated transient upstream glitch during rebase');
                }
                $reply = match ($turn) {
                    1 => 'Answer One',
                    2 => 'Answer Two',
                    default => 'Answer Two Revised',
                };
                $onChunk('delta', (string) json_encode([
                    'conversation_id' => 'up-conv-hist-1',
                    'message' => [
                        'id' => 'msg-hist-' . $turn,
                        'author' => ['role' => 'assistant'],
                        'channel' => 'final',
                        'content' => ['content_type' => 'text', 'parts' => [$reply]],
                    ],
                ]));
            }
        );
        $this->app->instance(ChatGptClient::class, $mockClient);

        // Turn 1: [system, user]
        $t1 = $this->withHeader('Authorization', 'Bearer test-mirror-key-12345')
            ->postJson('/v1/chat/completions', [
                'model' => 'gpt-4o',
                'messages' => [
                    ['role' => 'system', 'content' => 'Be concise'],
                    ['role' => 'user', 'content' => 'Question One'],
                ],
            ]);
        $t1->assertStatus(200)->assertJsonPath('choices.0.message.content', 'Answer One');
        $convId = (string) $t1->json('metadata.conversation_id');
        $this->assertNotEmpty($convId);

        // Turn 2: Omit conversation_id and send full history -> must auto-infer & reuse Turn 1 conversation
        $t2 = $this->withHeader('Authorization', 'Bearer test-mirror-key-12345')
            ->postJson('/v1/chat/completions', [
                'model' => 'gpt-4o',
                'messages' => [
                    ['role' => 'system', 'content' => 'Be concise'],
                    ['role' => 'user', 'content' => 'Question One'],
                    ['role' => 'assistant', 'content' => 'Answer One'],
                    ['role' => 'user', 'content' => 'Question Two'],
                ],
            ]);
        $t2->assertStatus(200)->assertJsonPath('choices.0.message.content', 'Answer Two');
        $this->assertSame($convId, $t2->json('metadata.conversation_id'));
        $this->assertSame('up-conv-hist-1', $capturedUpstreamArgs[1]['upstreamConvId']);
        $this->assertSame('msg-hist-1', $capturedUpstreamArgs[1]['parentNodeId']);

        // Assistant-message edit guard must reject tampering with 400
        $editAssistant = $this->withHeader('Authorization', 'Bearer test-mirror-key-12345')
            ->postJson('/v1/chat/completions', [
                'model' => 'gpt-4o',
                'metadata' => ['conversation_id' => $convId],
                'messages' => [
                    ['role' => 'system', 'content' => 'Be concise'],
                    ['role' => 'user', 'content' => 'Question One'],
                    ['role' => 'assistant', 'content' => 'TAMPERED ANSWER'],
                    ['role' => 'user', 'content' => 'Question Three'],
                ],
            ]);
        $editAssistant->assertStatus(400);
        $this->assertStringContainsString('Assistant messages', (string) $editAssistant->json('error.message'));

        // User-turn edit that hits an upstream failure must roll back original conversation rows cleanly
        $editUserFail = $this->withHeader('Authorization', 'Bearer test-mirror-key-12345')
            ->postJson('/v1/chat/completions', [
                'model' => 'gpt-4o',
                'metadata' => ['conversation_id' => $convId],
                'messages' => [
                    ['role' => 'system', 'content' => 'Be concise'],
                    ['role' => 'user', 'content' => 'Question One'],
                    ['role' => 'assistant', 'content' => 'Answer One'],
                    ['role' => 'user', 'content' => 'Question Two Edited'],
                ],
            ]);
        $editUserFail->assertStatus(502);

        $convModel = \Refatbd\LaravelChatGptMirror\Models\MirrorConversation::where('public_id', $convId)->firstOrFail();
        $rowsAfterRollback = $convModel->messages()->orderBy('created_at')->pluck('content')->all();
        $this->assertSame(['Question One', 'Answer One', 'Question Two', 'Answer Two'], $rowsAfterRollback);

        // Succeeding user-turn edit creates backup branch and rewinds parent node to Answer One (`msg-hist-1`)
        $editUserOk = $this->withHeader('Authorization', 'Bearer test-mirror-key-12345')
            ->postJson('/v1/chat/completions', [
                'model' => 'gpt-4o',
                'metadata' => ['conversation_id' => $convId],
                'messages' => [
                    ['role' => 'system', 'content' => 'Be concise'],
                    ['role' => 'user', 'content' => 'Question One'],
                    ['role' => 'assistant', 'content' => 'Answer One'],
                    ['role' => 'user', 'content' => 'Question Two Edited'],
                ],
            ]);
        $editUserOk->assertStatus(200)->assertJsonPath('choices.0.message.content', 'Answer Two Revised');
        $this->assertSame('up-conv-hist-1', $capturedUpstreamArgs[3]['upstreamConvId']);
        $this->assertSame('msg-hist-1', $capturedUpstreamArgs[3]['parentNodeId']);

        $rowsAfterRebase = $convModel->fresh()->messages()->orderBy('created_at')->orderBy('id')->pluck('content')->all();
        $this->assertSame(['Question One', 'Answer One', 'Question Two Edited', 'Answer Two Revised'], $rowsAfterRebase);
        $this->assertGreaterThanOrEqual(1, \Refatbd\LaravelChatGptMirror\Models\MirrorConversation::where('branched_from_id', $convModel->public_id)->count());
    }
}
