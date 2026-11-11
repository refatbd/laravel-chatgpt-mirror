<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Refatbd\LaravelChatGptMirror\Exceptions\UpstreamRequestException;
use Refatbd\LaravelChatGptMirror\Services\ChatGptClient;
use Refatbd\LaravelChatGptMirror\Services\ConversationService;
use Refatbd\LaravelChatGptMirror\Services\FailureTracker;
use Refatbd\LaravelChatGptMirror\Services\ResponsesWriter;
use Refatbd\LaravelChatGptMirror\Services\RichOutputService;
use Refatbd\LaravelChatGptMirror\Services\SecurityService;
use Refatbd\LaravelChatGptMirror\Services\StreamTransformer;
use Refatbd\LaravelChatGptMirror\Services\TokenPoolManager;
use Refatbd\LaravelChatGptMirror\Services\ToolBridgeService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exposes OpenAI-compatible `/v1/models`, `/v1/chat/completions`, and `/v1/responses`
 * backed by ChatGPT Web Sentinel PoW, Conduit follow-ups, rich asset resolution,
 * function-calling ToolBridge, and local conversation continuation/rebase tracking.
 */
class OpenAiApiController extends Controller
{
    public function __construct(
        protected TokenPoolManager $pool,
        protected ChatGptClient $client,
        protected StreamTransformer $transformer,
        protected ConversationService $conversations,
        protected RichOutputService $richOutput,
        protected ToolBridgeService $toolBridge,
        protected FailureTracker $failures,
        protected SecurityService $security
    ) {}

    public function models(): Response
    {
        $token = $this->pool->acquire();
        if (!$token) {
            return response()->json([
                'object' => 'list',
                'data' => [
                    ['id' => 'auto', 'object' => 'model', 'created' => 1715367049, 'owned_by' => 'openai'],
                    ['id' => 'gpt-4o', 'object' => 'model', 'created' => 1715367049, 'owned_by' => 'openai'],
                    ['id' => 'gpt-4o-mini', 'object' => 'model', 'created' => 1721260800, 'owned_by' => 'openai'],
                ],
            ]);
        }

        try {
            $models = $this->client->getModels($token);
            $data = array_map(fn (array $m) => [
                'id' => (string) ($m['slug'] ?? 'auto'),
                'object' => 'model',
                'created' => 1715367049,
                'owned_by' => 'openai',
                'title' => $m['title'] ?? $m['slug'],
            ], $models);

            return response()->json([
                'object' => 'list',
                'data' => $data,
            ]);
        } finally {
            $this->pool->release($token, true);
        }
    }

    public function chatCompletions(Request $request): Response
    {
        return $this->handleCompletion($request, false);
    }

    public function responses(Request $request): Response
    {
        return $this->handleCompletion($request, true);
    }

    protected function handleCompletion(Request $request, bool $isResponsesEndpoint): Response
    {
        $requestId = (string) ($request->header('x-request-id') ?: Str::uuid());
        $body = $request->all();

        $requestedModel = (string) ($body['model'] ?? 'auto');
        $stream = (bool) ($body['stream'] ?? false);
        $metadata = is_array($body['metadata'] ?? null) ? $body['metadata'] : [];

        $responsesWriter = $isResponsesEndpoint ? new ResponsesWriter($requestedModel) : null;

        $rawMessages = $isResponsesEndpoint
            ? $responsesWriter->normalizeInputToMessages($body)
            : (is_array($body['messages'] ?? null) ? $body['messages'] : []);

        if ($rawMessages === []) {
            $err = $this->security->apiErrorEnvelope(400, $isResponsesEndpoint ? 'Missing or empty `input` payload.' : 'The `messages` array is required.', $requestId);
            return response()->json($err, 400);
        }

        // Inject synthetic tool-calling system block when tools are provided
        $toolSpec = $this->toolBridge->resolve(
            is_array($body['tools'] ?? null) ? $body['tools'] : null,
            $body['tool_choice'] ?? null
        );
        if ($toolSpec['enabled']) {
            array_unshift($rawMessages, [
                'role' => 'system',
                'content' => $this->toolBridge->buildInstructionBlock($toolSpec),
            ]);
        }

        $route = $this->client->routeModel($requestedModel, $metadata);
        $explicitConvId = isset($metadata['conversation_id']) && is_string($metadata['conversation_id'])
            ? trim($metadata['conversation_id'])
            : '';
        $oneShot = !empty($metadata['one_shot']) || !empty($metadata['ephemeral']);
        $privateMode = $route['private'] ?? $oneShot;

        $turnstileOverride = ($request->header('openai-sentinel-turnstile-token')
            ?: $request->header('x-turnstile-token')
            ?: ($metadata['turnstile_token'] ?? $metadata['mirror_turnstile_token'] ?? null));
        $turnstileToken = is_string($turnstileOverride) && trim($turnstileOverride) !== '' ? trim($turnstileOverride) : null;

        $accountId = $this->pool->primaryAccountId();
        $token = $this->pool->acquireForAccount(null, $accountId);
        if (!$token) {
            $this->failures->record('token_pool_exhausted', $requestId, 'no_healthy_token');
            $err = $this->security->apiErrorEnvelope(503, 'No active ChatGPT accounts available in token pool.', $requestId, 'token_pool_exhausted');
            return response()->json($err, 503);
        }

        $activeConv = $explicitConvId !== '' ? \Refatbd\LaravelChatGptMirror\Models\MirrorConversation::findByIdentifier($explicitConvId) : null;
        $continuing = $activeConv !== null && $activeConv->effectiveUpstreamConversationId() !== null && count($rawMessages) === 1;

        $normalizedMessages = array_map(fn (array $m) => [
            'role' => (string) ($m['role'] ?? 'user'),
            'content' => $this->extractMessageText($m['content'] ?? ''),
        ], $rawMessages);

        try {
            $attachments = $this->conversations->uploadOpenAiAttachments($token, $rawMessages, $accountId);
        } catch (\Throwable $e) {
            $this->pool->release($token, false);
            $err = $this->security->apiErrorEnvelope(400, $e->getMessage(), $requestId);
            return response()->json($err, 400);
        }

        $payloadMessages = $continuing
            ? [end($normalizedMessages)]
            : $normalizedMessages;

        $payload = $this->client->buildConversationPayload(
            $payloadMessages,
            $route['model'],
            $continuing ? $activeConv->effectiveUpstreamConversationId() : null,
            $continuing ? $activeConv->current_node_id : 'client-created-root',
            $route['gizmoId'] ?? $activeConv?->gizmo_id,
            $privateMode,
            $attachments
        );

        $chatId = 'chatcmpl-' . Str::random(24);
        $baseUrl = $request->getSchemeAndHttpHost();

        if ($stream) {
            return $this->handleStreamResponse(
                $token,
                $payload,
                $requestedModel,
                $chatId,
                $baseUrl,
                $accountId,
                $normalizedMessages,
                $explicitConvId,
                $activeConv,
                $oneShot,
                $privateMode,
                $route,
                $attachments,
                $turnstileToken,
                $requestId,
                $responsesWriter
            );
        }

        return $this->handleSyncResponse(
            $token,
            $payload,
            $requestedModel,
            $chatId,
            $baseUrl,
            $accountId,
            $normalizedMessages,
            $explicitConvId,
            $activeConv,
            $oneShot,
            $privateMode,
            $route,
            $attachments,
            $turnstileToken,
            $toolSpec,
            $requestId,
            $responsesWriter
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, array{role: string, content: string}> $normalizedMessages
     * @param array<string, mixed> $route
     * @param array<int, array<string, mixed>> $attachments
     */
    protected function handleStreamResponse(
        \Refatbd\LaravelChatGptMirror\Models\MirrorToken $token,
        array $payload,
        string $requestedModel,
        string $chatId,
        string $baseUrl,
        string $accountId,
        array $normalizedMessages,
        string $explicitConvId,
        ?\Refatbd\LaravelChatGptMirror\Models\MirrorConversation $activeConv,
        bool $oneShot,
        bool $privateMode,
        array $route,
        array $attachments,
        ?string $turnstileToken,
        string $requestId,
        ?ResponsesWriter $responsesWriter
    ): Response {
        $client = $this->client;
        $transformer = $this->transformer;
        $pool = $this->pool;
        $conversations = $this->conversations;
        $richOutput = $this->richOutput;
        $failures = $this->failures;

        $transformer->reset();

        return response()->stream(function () use (
            $token,
            $payload,
            $requestedModel,
            $chatId,
            $baseUrl,
            $accountId,
            $normalizedMessages,
            $explicitConvId,
            $activeConv,
            $oneShot,
            $privateMode,
            $route,
            $attachments,
            $turnstileToken,
            $requestId,
            $responsesWriter,
            $client,
            $transformer,
            $pool,
            $conversations,
            $richOutput,
            $failures
        ): void {
            if ($responsesWriter) {
                echo $responsesWriter->openStreamEvents();
                $this->flushBuffers();
            }

            $success = false;
            try {
                $client->streamConversation($token, $payload, function (string $eventName, string $json) use (
                    $transformer,
                    $requestedModel,
                    $chatId,
                    $responsesWriter
                ): void {
                    $events = $transformer->processEventPayload($eventName, $json, $requestedModel, $chatId);
                    foreach ($events as $ev) {
                        if (($ev['kind'] ?? '') === 'assistant_text_delta' && !empty($ev['delta'])) {
                            $delta = (string) $ev['delta'];
                            if ($responsesWriter) {
                                echo $responsesWriter->deltaEvent($delta);
                            } else {
                                echo 'data: ' . json_encode([
                                    'id' => $chatId,
                                    'object' => 'chat.completion.chunk',
                                    'created' => time(),
                                    'model' => $requestedModel,
                                    'choices' => [[
                                        'index' => 0,
                                        'delta' => ['content' => $delta],
                                        'finish_reason' => null,
                                    ]],
                                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
                            }
                            $this->flushBuffers();
                        }
                    }
                }, $turnstileToken);

                $success = true;
                $fullText = $transformer->getAccumulatedText();
                $capturedEvents = $transformer->getCapturedEvents();
                $upstreamConvId = $transformer->getConversationId() ?? $activeConv?->effectiveUpstreamConversationId();
                $assistantNodeId = $transformer->getAssistantMessageId();
                $userNodeId = $transformer->getUserMessageId();

                $extraMeta = [];
                if ($richOutput->needsRichOutput($capturedEvents, $fullText)) {
                    $rich = $richOutput->render($capturedEvents, $fullText, $baseUrl, $token, $upstreamConvId, $assistantNodeId);
                    $extraMeta = $rich['metadata'];
                    $responsesWriter?->setSummaries($rich['summaries']);
                    $fullText = $rich['text'];
                }

                $persistedId = $activeConv?->public_id;
                if (!$oneShot) {
                    $conv = $activeConv ?: $conversations->createConversation(
                        $accountId,
                        $route['model'],
                        $route['gizmoId'],
                        $explicitConvId !== '' ? $explicitConvId : null,
                        $privateMode,
                        Str::limit($this->lastUserPrompt($normalizedMessages), 60)
                    );
                    if ($upstreamConvId && !$conv->upstream_conversation_id) {
                        $conv->update(['upstream_conversation_id' => $upstreamConvId, 'conversation_id' => $upstreamConvId]);
                    }
                    $conversations->recordTurnMessages(
                        $conv,
                        $this->lastUserPrompt($normalizedMessages),
                        $fullText,
                        $userNodeId,
                        $assistantNodeId,
                        $capturedEvents,
                        $attachments
                    );
                    $persistedId = $conv->public_id;
                }

                if ($responsesWriter) {
                    if ($persistedId) {
                        $extraMeta['conversation_id'] = $persistedId;
                    }
                    echo $responsesWriter->completeEvents($fullText, $requestedModel, $extraMeta);
                } else {
                    if ($persistedId) {
                        echo ": mirror-conversation-id {$persistedId}\n\n";
                    }
                    $stopChunk = [
                        'id' => $chatId,
                        'object' => 'chat.completion.chunk',
                        'created' => time(),
                        'model' => $requestedModel,
                        'choices' => [['index' => 0, 'delta' => new \stdClass(), 'finish_reason' => 'stop']],
                    ];
                    if ($extraMeta !== []) {
                        $stopChunk['metadata'] = $extraMeta;
                    }
                    echo 'data: ' . json_encode($stopChunk, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
                    echo "data: [DONE]\n\n";
                }
                $this->flushBuffers();
            } catch (\Throwable $e) {
                if ($e instanceof UpstreamRequestException && $e->getCode() === 429) {
                    $pool->putInCooldown($token);
                }
                $cat = $failures->classifyProtocolFailure($e);
                $failures->record('upstream_stream_error', $requestId, $cat);

                if ($responsesWriter) {
                    echo $responsesWriter->failEvents($e->getMessage(), 'upstream_error');
                } else {
                    echo 'data: ' . json_encode(['error' => ['message' => $e->getMessage(), 'type' => 'upstream_error']]) . "\n\n";
                }
                $this->flushBuffers();
            } finally {
                $pool->release($token, $success);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=utf-8',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
            'X-Request-Id' => $requestId,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, array{role: string, content: string}> $normalizedMessages
     * @param array<string, mixed> $route
     * @param array<int, array<string, mixed>> $attachments
     * @param array<string, mixed> $toolSpec
     */
    protected function handleSyncResponse(
        \Refatbd\LaravelChatGptMirror\Models\MirrorToken $token,
        array $payload,
        string $requestedModel,
        string $chatId,
        string $baseUrl,
        string $accountId,
        array $normalizedMessages,
        string $explicitConvId,
        ?\Refatbd\LaravelChatGptMirror\Models\MirrorConversation $activeConv,
        bool $oneShot,
        bool $privateMode,
        array $route,
        array $attachments,
        ?string $turnstileToken,
        array $toolSpec,
        string $requestId,
        ?ResponsesWriter $responsesWriter
    ): Response {
        $this->transformer->reset();
        $success = false;

        try {
            $this->client->streamConversation($token, $payload, function (string $eventName, string $json) use ($requestedModel, $chatId): void {
                $this->transformer->processEventPayload($eventName, $json, $requestedModel, $chatId);
            }, $turnstileToken);

            $success = true;
            $fullText = $this->transformer->getAccumulatedText();
            $capturedEvents = $this->transformer->getCapturedEvents();
            $upstreamConvId = $this->transformer->getConversationId() ?? $activeConv?->effectiveUpstreamConversationId();
            $assistantNodeId = $this->transformer->getAssistantMessageId();
            $userNodeId = $this->transformer->getUserMessageId();

            $extraMeta = [];
            if ($this->richOutput->needsRichOutput($capturedEvents, $fullText)) {
                $rich = $this->richOutput->render($capturedEvents, $fullText, $baseUrl, $token, $upstreamConvId, $assistantNodeId);
                $extraMeta = $rich['metadata'];
                $responsesWriter?->setSummaries($rich['summaries']);
                $fullText = $rich['text'];
            }

            $persistedId = $activeConv?->public_id;
            if (!$oneShot) {
                $conv = $activeConv ?: $this->conversations->createConversation(
                    $accountId,
                    $route['model'],
                    $route['gizmoId'],
                    $explicitConvId !== '' ? $explicitConvId : null,
                    $privateMode,
                    Str::limit($this->lastUserPrompt($normalizedMessages), 60)
                );
                if ($upstreamConvId && !$conv->upstream_conversation_id) {
                    $conv->update(['upstream_conversation_id' => $upstreamConvId, 'conversation_id' => $upstreamConvId]);
                }
                $this->conversations->recordTurnMessages(
                    $conv,
                    $this->lastUserPrompt($normalizedMessages),
                    $fullText,
                    $userNodeId,
                    $assistantNodeId,
                    $capturedEvents,
                    $attachments
                );
                $persistedId = $conv->public_id;
            }

            if ($persistedId) {
                $extraMeta['conversation_id'] = $persistedId;
            }
            if ($responsesWriter) {
                $resp = response()->json($responsesWriter->buildResponseObject($fullText, 'completed', $requestedModel, $extraMeta));
                if ($persistedId) {
                    $resp->header('x-mirror-conversation-id', $persistedId);
                }
                return $resp->header('x-request-id', $requestId);
            }

            $toolCalls = $this->toolBridge->parseToolCalls($fullText, $toolSpec);
            $messagePayload = $toolCalls !== null
                ? ['role' => 'assistant', 'content' => null, 'tool_calls' => $toolCalls]
                : ['role' => 'assistant', 'content' => $fullText];

            $responseBody = [
                'id' => $chatId,
                'object' => 'chat.completion',
                'created' => time(),
                'model' => $requestedModel,
                'choices' => [[
                    'index' => 0,
                    'message' => $messagePayload,
                    'finish_reason' => $toolCalls !== null ? 'tool_calls' : 'stop',
                ]],
                'usage' => [
                    'prompt_tokens' => (int) ceil(strlen($this->lastUserPrompt($normalizedMessages)) / 4),
                    'completion_tokens' => (int) ceil(strlen($fullText) / 4),
                    'total_tokens' => (int) ceil((strlen($this->lastUserPrompt($normalizedMessages)) + strlen($fullText)) / 4),
                ],
            ];
            if ($extraMeta !== []) {
                $responseBody['metadata'] = $extraMeta;
            }

            $resp = response()->json($responseBody);
            if ($persistedId) {
                $resp->header('x-mirror-conversation-id', $persistedId);
            }
            return $resp->header('x-request-id', $requestId);
        } catch (UpstreamRequestException $e) {
            $status = $e->getCode() >= 400 ? $e->getCode() : 502;
            if ($status === 429) {
                $this->pool->putInCooldown($token);
            }
            $this->failures->record('upstream_error_' . $status, $requestId, $this->failures->classifyProtocolFailure($e));
            return response()->json($this->security->apiErrorEnvelope($status, $e->getMessage(), $requestId), $status);
        } catch (\Throwable $e) {
            $this->failures->record('internal_error', $requestId, $this->failures->classifyProtocolFailure($e));
            return response()->json($this->security->apiErrorEnvelope(502, $e->getMessage(), $requestId), 502);
        } finally {
            $this->pool->release($token, $success);
        }
    }

    /**
     * @param mixed $content
     */
    protected function extractMessageText(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }
        if (!is_array($content)) {
            return '';
        }

        $parts = [];
        foreach ($content as $part) {
            if (is_array($part) && in_array($part['type'] ?? '', ['text', 'input_text', 'output_text'], true) && isset($part['text'])) {
                $parts[] = (string) $part['text'];
            }
        }
        return implode("\n", $parts);
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     */
    protected function lastUserPrompt(array $messages): string
    {
        foreach (array_reverse($messages) as $m) {
            if ($m['role'] === 'user') {
                return $m['content'];
            }
        }
        return (string) (end($messages)['content'] ?? '');
    }

    protected function flushBuffers(): void
    {
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        @flush();
    }
}
