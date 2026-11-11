<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Refatbd\LaravelChatGptMirror\Events\MirrorCompletionCompleted;
use Refatbd\LaravelChatGptMirror\Events\MirrorCompletionFailed;
use Refatbd\LaravelChatGptMirror\Exceptions\UpstreamRequestException;
use Refatbd\LaravelChatGptMirror\Models\MirrorConversation;
use Refatbd\LaravelChatGptMirror\Models\MirrorToken;
use Refatbd\LaravelChatGptMirror\Services\ChatGptClient;
use Refatbd\LaravelChatGptMirror\Services\ConversationService;
use Refatbd\LaravelChatGptMirror\Services\FailureTracker;
use Refatbd\LaravelChatGptMirror\Services\MirrorCache;
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
        protected SecurityService $security,
        protected ?MirrorCache $cache = null
    ) {
        $this->cache ??= app()->bound(MirrorCache::class) ? app(MirrorCache::class) : new MirrorCache();
    }

    protected function cache(): MirrorCache
    {
        return $this->cache ??= app()->bound(MirrorCache::class) ? app(MirrorCache::class) : new MirrorCache();
    }

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
            $ttl = max(0, (int) config('mirror.cache.models_ttl', 300));
            $cacheKey = 'models:' . $token->getEffectiveAccountId();
            $models = $ttl > 0
                ? $this->cache()->remember($cacheKey, $ttl, fn (): array => $this->client->getModels($token))
                : $this->client->getModels($token);

            $data = array_map(fn (array $m) => [
                'id' => (string) ($m['slug'] ?? 'auto'),
                'object' => 'model',
                'created' => 1715367049,
                'owned_by' => 'openai',
                'title' => $m['title'] ?? $m['slug'],
            ], is_array($models) ? $models : []);

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
        $startedAt = microtime(true);
        $authContext = $request->attributes->get('mirror.auth_context');
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

        $route = $this->client->routeModel($requestedModel, $metadata);
        $routedModel = (string) ($route['model'] ?? $requestedModel);
        if (str_ends_with($requestedModel, '-wm') || str_ends_with($routedModel, '-wm')) {
            return response()->json(
                $this->security->apiErrorEnvelope(400, 'Work Mode is not supported by Mirror; select an interactive model.', $requestId, 'unsupported_parameter'),
                400
            );
        }

        $hasToolsInput = array_key_exists('tools', $body) && $body['tools'] !== null;
        if (!$isResponsesEndpoint && $hasToolsInput) {
            try {
                $this->toolBridge->validateTools($body['tools']);
            } catch (\InvalidArgumentException $e) {
                return response()->json($this->security->apiErrorEnvelope(400, $e->getMessage(), $requestId), 400);
            }
        }

        $toolSpec = $this->toolBridge->resolve(
            is_array($body['tools'] ?? null) ? $body['tools'] : null,
            $body['tool_choice'] ?? null
        );
        $hasToolMessages = $this->toolBridge->hasToolMessages($rawMessages);

        if ($isResponsesEndpoint && $hasToolMessages) {
            return response()->json(
                $this->security->apiErrorEnvelope(400, 'Tool messages are not supported by /v1/responses.', $requestId, 'unsupported_parameter'),
                400
            );
        }

        $turnstileOverride = ($request->header('openai-sentinel-turnstile-token')
            ?: $request->header('x-turnstile-token')
            ?: ($metadata['turnstile_token'] ?? $metadata['mirror_turnstile_token'] ?? null));
        $turnstileToken = is_string($turnstileOverride) && trim($turnstileOverride) !== '' ? trim($turnstileOverride) : null;

        if (!$isResponsesEndpoint && ($toolSpec['enabled'] || $hasToolMessages)) {
            return $this->handleToolBridgeCompletion(
                $rawMessages,
                $toolSpec,
                $route,
                $requestedModel,
                $stream,
                $turnstileToken,
                $requestId,
                $startedAt,
                $authContext
            );
        }

        $explicitConvId = isset($metadata['conversation_id']) && is_string($metadata['conversation_id'])
            ? trim($metadata['conversation_id'])
            : '';
        $oneShot = !empty($metadata['one_shot']) || !empty($metadata['ephemeral']);
        $privateMode = $route['private'] ?? $oneShot;

        $accountId = $this->pool->primaryAccountId();
        $token = $this->pool->acquireForAccount(null, $accountId);
        if (!$token) {
            $this->failures->record('token_pool_exhausted', $requestId, 'no_healthy_token');
            $this->dispatchCompletionFailure(
                $requestId,
                $isResponsesEndpoint ? 'responses' : 'chat.completions',
                $requestedModel,
                $stream,
                503,
                'no_healthy_token',
                'No active ChatGPT accounts available in token pool.',
                $startedAt,
                $authContext
            );
            $err = $this->security->apiErrorEnvelope(503, 'No active ChatGPT accounts available in token pool.', $requestId, 'token_pool_exhausted');
            return response()->json($err, 503);
        }

        $normalizedMessages = array_map(fn (array $m) => [
            'role' => (string) ($m['role'] ?? 'user'),
            'content' => $this->extractMessageText($m['content'] ?? ''),
        ], $rawMessages);

        $explicitConv = $explicitConvId !== '' ? MirrorConversation::findByIdentifier($explicitConvId) : null;
        if ($explicitConv && $explicitConv->account_id !== $accountId && $explicitConv->account_id !== 'default' && $accountId !== 'default') {
            $this->pool->release($token, true);
            return response()->json(
                $this->security->apiErrorEnvelope(400, "metadata.conversation_id is already in use: {$explicitConvId}", $requestId),
                400
            );
        }

        // Single-turn `cm` contract expands stored instructions + canonical rows cleanly before hashing.
        if ($explicitConv && count($normalizedMessages) === 1) {
            $storedInstr = is_array($explicitConv->instructions_payload) ? $explicitConv->instructions_payload : [];
            $storedRows = $this->conversations->getStoredMessages($explicitConv);
            $expanded = [];
            foreach ($storedInstr as $instr) {
                if (is_array($instr) && isset($instr['role'], $instr['content'])) {
                    $expanded[] = ['role' => (string) $instr['role'], 'content' => (string) $instr['content']];
                }
            }
            foreach ($storedRows as $row) {
                if (in_array($row->role, ['user', 'assistant'], true)) {
                    $expanded[] = ['role' => (string) $row->role, 'content' => (string) $row->content];
                }
            }
            $expanded[] = $normalizedMessages[0];
            $normalizedMessages = $expanded;
        }

        $priorTranscript = count($normalizedMessages) > 1 ? array_slice($normalizedMessages, 0, -1) : [];
        $priorTranscriptHash = $priorTranscript !== [] ? $this->conversations->fingerprintValue($priorTranscript) : null;

        // Auto-infer matching conversation when caller passes full history without explicit conversation_id.
        $inferredConv = null;
        if (!$oneShot && $explicitConv === null && $explicitConvId === '' && $priorTranscriptHash !== null) {
            $candidate = $this->conversations->findConversationByTranscript($accountId, $priorTranscriptHash);
            if ($candidate && $this->isCompatibleCandidate($candidate, $route)) {
                $inferredConv = $candidate;
            }
        }

        $activeConv = $explicitConv ?? $inferredConv;
        $needsRebase = false;
        $isUserHistoryEdit = false;
        $rebaseState = null;

        if ($explicitConv) {
            $storedRows = $this->conversations->getStoredMessages($explicitConv);
            $storedConvRows = $storedRows->filter(
                fn ($r) => in_array((string) $r->role, ['user', 'assistant'], true)
            )->values();
            $storedConversational = $storedConvRows->map(fn ($r) => [
                'role' => (string) $r->role,
                'content' => (string) $r->content,
            ])->values()->all();

            $incomingPriorConv = $this->conversations->conversationalMessages($priorTranscript);
            $diffIdx = $this->conversations->firstHistoryDifference($storedConversational, $incomingPriorConv);

            if (isset($storedConversational[$diffIdx]) && $storedConversational[$diffIdx]['role'] === 'assistant') {
                $this->pool->release($token, true);
                return response()->json(
                    $this->security->apiErrorEnvelope(
                        400,
                        'Assistant messages are read-only and cannot be changed or removed while continuing a Mirror conversation.',
                        $requestId
                    ),
                    400
                );
            }

            $currentContextHash = $this->conversations->instructionsHash($normalizedMessages);
            $instructionsChanged = $explicitConv->openai_context_hash !== null
                && $explicitConv->openai_context_hash !== $currentContextHash;

            $transcriptChanged = false;
            if ($explicitConv->openai_transcript_hash !== null && $priorTranscriptHash !== null) {
                $transcriptChanged = $explicitConv->openai_transcript_hash !== $priorTranscriptHash;
            } elseif ($priorTranscriptHash !== null && $storedConversational !== []) {
                $transcriptChanged = $this->conversations->fingerprintValue($storedConversational)
                    !== $this->conversations->fingerprintValue($incomingPriorConv);
            }

            $needsRebase = $instructionsChanged || $transcriptChanged;
            $isUserHistoryEdit = $needsRebase
                && !$instructionsChanged
                && isset($storedConversational[$diffIdx])
                && $storedConversational[$diffIdx]['role'] === 'user';

            if (!$needsRebase) {
                if (($route['gizmoId'] ?? null) !== null && ($route['gizmoId'] ?? null) !== $explicitConv->gizmo_id) {
                    $this->pool->release($token, true);
                    return response()->json(
                        $this->security->apiErrorEnvelope(400, 'The GPT/Project cannot change while continuing a Mirror conversation; start a new conversation id.', $requestId),
                        400
                    );
                }
                if ($routedModel !== '' && $routedModel !== 'auto' && $explicitConv->model && $routedModel !== $explicitConv->model) {
                    $this->pool->release($token, true);
                    return response()->json(
                        $this->security->apiErrorEnvelope(400, 'The model cannot change while continuing a Mirror conversation; start a new conversation id.', $requestId),
                        400
                    );
                }
                if (isset($route['private']) && (bool) $route['private'] !== (bool) $explicitConv->is_private) {
                    $this->pool->release($token, true);
                    return response()->json(
                        $this->security->apiErrorEnvelope(400, 'Private-chat mode cannot change while continuing a Mirror conversation; start a new conversation id.', $requestId),
                        400
                    );
                }
            } else {
                $backupMessages = $storedRows->map(fn ($r) => [
                    'id' => $r->id,
                    'upstream_node_id' => $r->upstream_node_id,
                    'role' => $r->role,
                    'content' => $r->content,
                    'status' => $r->status,
                    'events_payload' => $r->events_payload ?? [],
                    'attachments_payload' => $r->attachments_payload ?? [],
                ])->all();

                $backupConv = [
                    'model' => $explicitConv->model,
                    'gizmo_id' => $explicitConv->gizmo_id,
                    'is_private' => $explicitConv->is_private,
                    'upstream_conversation_id' => $explicitConv->upstream_conversation_id,
                    'conversation_id' => $explicitConv->conversation_id,
                    'initialized' => $explicitConv->initialized,
                    'current_node_id' => $explicitConv->current_node_id,
                ];

                $lastRow = $storedRows->last();
                if ($lastRow && $lastRow->upstream_node_id) {
                    $this->conversations->branchConversation(
                        $explicitConv,
                        (string) $lastRow->id,
                        'Before edit: ' . ($explicitConv->title ?: 'Conversation')
                    );
                }

                $targetParentNode = 'client-created-root';
                $resetUpstream = !$isUserHistoryEdit;
                if ($isUserHistoryEdit) {
                    if ($diffIdx > 0) {
                        $predecessor = $storedConvRows->get($diffIdx - 1);
                        $targetParentNode = ($predecessor && $predecessor->upstream_node_id)
                            ? (string) $predecessor->upstream_node_id
                            : 'client-created-root';
                        if ($targetParentNode === 'client-created-root') {
                            $resetUpstream = true;
                        }
                    } else {
                        $resetUpstream = true;
                    }
                }

                $keptConvRows = $storedConvRows->slice(0, $diffIdx)->map(fn ($r) => [
                    'id' => $r->id,
                    'upstream_node_id' => $r->upstream_node_id,
                    'role' => $r->role,
                    'content' => $r->content,
                    'status' => $r->status,
                    'events_payload' => $r->events_payload ?? [],
                    'attachments_payload' => $r->attachments_payload ?? [],
                ])->values()->all();
                $trimmedPrefix = $keptConvRows;
                $this->conversations->rebaseConversationUpstream($explicitConv, [
                    'model' => $routedModel,
                    'gizmo_id' => $route['gizmoId'] ?? null,
                    'is_private' => $privateMode,
                    'current_node_id' => $targetParentNode,
                    'reset_upstream' => $resetUpstream,
                ]);
                $this->conversations->replaceMessages($explicitConv, $trimmedPrefix);
                $explicitConv->refresh();

                $rebaseState = [
                    'backupMessages' => $backupMessages,
                    'backupConv' => $backupConv,
                ];
            }
        }

        $continuing = $activeConv !== null
            && $activeConv->effectiveUpstreamConversationId() !== null
            && (!$needsRebase || $isUserHistoryEdit);

        try {
            $attachments = $this->conversations->uploadOpenAiAttachments($token, $rawMessages, $accountId);
        } catch (\Throwable $e) {
            if ($rebaseState !== null && $activeConv !== null) {
                $this->restoreRebaseBackup($activeConv, $rebaseState);
            }
            $this->pool->release($token, false);
            $err = $this->security->apiErrorEnvelope(400, $e->getMessage(), $requestId);
            return response()->json($err, 400);
        }

        $payloadMessages = $continuing
            ? [end($normalizedMessages)]
            : $normalizedMessages;

        $payload = $this->client->buildConversationPayload(
            $payloadMessages,
            $routedModel,
            $continuing ? $activeConv->effectiveUpstreamConversationId() : null,
            $continuing ? ($activeConv->current_node_id ?: 'client-created-root') : 'client-created-root',
            $route['gizmoId'] ?? $activeConv?->gizmo_id,
            (bool) $privateMode,
            $attachments
        );

        $chatId = 'chatcmpl-' . Str::random(24);
        $baseUrl = $this->security->resolveOrigin($request);

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
                (bool) $privateMode,
                $route,
                $attachments,
                $turnstileToken,
                $requestId,
                $responsesWriter,
                $continuing,
                $rebaseState,
                $startedAt,
                $authContext
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
            (bool) $privateMode,
            $route,
            $attachments,
            $turnstileToken,
            $toolSpec,
            $requestId,
            $responsesWriter,
            $continuing,
            $rebaseState,
            $startedAt,
            $authContext
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, array{role: string, content: string}> $normalizedMessages
     * @param array<string, mixed> $route
     * @param array<int, array<string, mixed>> $attachments
     */
    protected function handleStreamResponse(
        MirrorToken $token,
        array $payload,
        string $requestedModel,
        string $chatId,
        string $baseUrl,
        string $accountId,
        array $normalizedMessages,
        string $explicitConvId,
        ?MirrorConversation $activeConv,
        bool $oneShot,
        bool $privateMode,
        array $route,
        array $attachments,
        ?string $turnstileToken,
        string $requestId,
        ?ResponsesWriter $responsesWriter,
        bool $continuing = false,
        ?array $rebaseState = null,
        float $startedAt = 0.0,
        mixed $authContext = null
    ): Response {
        $client = $this->client;
        $transformer = $this->transformer;
        $pool = $this->pool;
        $conversations = $this->conversations;
        $richOutput = $this->richOutput;
        $failures = $this->failures;
        $endpointName = $responsesWriter !== null ? 'responses' : 'chat.completions';
        $promptText = $this->lastUserPrompt($normalizedMessages);
        $startedAt = $startedAt > 0.0 ? $startedAt : microtime(true);

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
            $continuing,
            $rebaseState,
            $client,
            $transformer,
            $pool,
            $conversations,
            $richOutput,
            $failures,
            $endpointName,
            $promptText,
            $startedAt,
            $authContext
        ): void {
            if (!app()->runningUnitTests()) {
                $execTime = max(0, (int) config('mirror.server.stream_max_execution_time', 0));
                if (function_exists('set_time_limit')) {
                    @set_time_limit($execTime);
                }
                if (config('mirror.server.disable_output_compression', true)) {
                    @ini_set('zlib.output_compression', '0');
                }
            }

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
                    $persistedId = $this->persistCompletedTurn(
                        $accountId,
                        $activeConv,
                        $explicitConvId,
                        $route,
                        $privateMode,
                        $normalizedMessages,
                        $fullText,
                        $upstreamConvId,
                        $userNodeId,
                        $assistantNodeId,
                        $capturedEvents,
                        $attachments,
                        $continuing
                    );
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
                    if ($persistedId) {
                        $extraMeta['conversation_id'] = $persistedId;
                    }
                    if ($extraMeta !== []) {
                        $stopChunk['metadata'] = $extraMeta;
                    }
                    echo 'data: ' . json_encode($stopChunk, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
                    echo "data: [DONE]\n\n";
                }
                $this->flushBuffers();
                $this->dispatchCompletionSuccess(
                    $requestId,
                    $endpointName,
                    $requestedModel,
                    true,
                    $persistedId,
                    $token->id ?? null,
                    $promptText,
                    $fullText,
                    $startedAt,
                    $authContext
                );
            } catch (\Throwable $e) {
                if ($rebaseState !== null && $activeConv !== null) {
                    $this->restoreRebaseBackup($activeConv, $rebaseState);
                }
                if ($e instanceof UpstreamRequestException && $e->getCode() === 429) {
                    $pool->putInCooldown($token);
                }
                $cat = $failures->classifyProtocolFailure($e);
                $failures->record('upstream_stream_error', $requestId, $cat);

                $this->dispatchCompletionFailure(
                    $requestId,
                    $endpointName,
                    $requestedModel,
                    true,
                    $e instanceof UpstreamRequestException && $e->getCode() >= 400 ? $e->getCode() : 502,
                    $cat,
                    $e->getMessage(),
                    $startedAt,
                    $authContext
                );

                if ($responsesWriter) {
                    echo $responsesWriter->failEvents($e->getMessage(), 'upstream_error');
                } else {
                    echo 'data: ' . json_encode(['error' => ['message' => $e->getMessage(), 'type' => 'upstream_error']]) . "\n\n";
                    echo "data: [DONE]\n\n";
                }
                $this->flushBuffers();
            } finally {
                $pool->release($token, $success);
            }
        }, 200, $this->security->streamingHeaders($requestId));
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, array{role: string, content: string}> $normalizedMessages
     * @param array<string, mixed> $route
     * @param array<int, array<string, mixed>> $attachments
     * @param array<string, mixed> $toolSpec
     * @param array<string, mixed>|null $rebaseState
     */
    protected function handleSyncResponse(
        MirrorToken $token,
        array $payload,
        string $requestedModel,
        string $chatId,
        string $baseUrl,
        string $accountId,
        array $normalizedMessages,
        string $explicitConvId,
        ?MirrorConversation $activeConv,
        bool $oneShot,
        bool $privateMode,
        array $route,
        array $attachments,
        ?string $turnstileToken,
        array $toolSpec,
        string $requestId,
        ?ResponsesWriter $responsesWriter,
        bool $continuing = false,
        ?array $rebaseState = null,
        float $startedAt = 0.0,
        mixed $authContext = null
    ): Response {
        $startedAt = $startedAt > 0.0 ? $startedAt : microtime(true);
        $endpointName = $responsesWriter !== null ? 'responses' : 'chat.completions';
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
                $persistedId = $this->persistCompletedTurn(
                    $accountId,
                    $activeConv,
                    $explicitConvId,
                    $route,
                    $privateMode,
                    $normalizedMessages,
                    $fullText,
                    $upstreamConvId,
                    $userNodeId,
                    $assistantNodeId,
                    $capturedEvents,
                    $attachments,
                    $continuing
                );
            }

            if ($persistedId) {
                $extraMeta['conversation_id'] = $persistedId;
            }
            $promptText = $this->lastUserPrompt($normalizedMessages);
            $promptTokens = (int) ceil(strlen($promptText) / 4);
            $completionTokens = (int) ceil(strlen($fullText) / 4);

            if ($responsesWriter) {
                $resp = response()->json($responsesWriter->buildResponseObject($fullText, 'completed', $requestedModel, $extraMeta));
                if ($persistedId) {
                    $resp->header('x-mirror-conversation-id', $persistedId);
                }
                $this->dispatchCompletionSuccess($requestId, $endpointName, $requestedModel, false, $persistedId, $token->id ?? null, $promptText, $fullText, $startedAt, $authContext);
                return $resp->header('x-request-id', $requestId);
            }

            $responseBody = [
                'id' => $chatId,
                'object' => 'chat.completion',
                'created' => time(),
                'model' => $requestedModel,
                'choices' => [[
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => $fullText],
                    'finish_reason' => 'stop',
                ]],
                'usage' => [
                    'prompt_tokens' => $promptTokens,
                    'completion_tokens' => $completionTokens,
                    'total_tokens' => $promptTokens + $completionTokens,
                ],
            ];
            if ($extraMeta !== []) {
                $responseBody['metadata'] = $extraMeta;
            }

            $resp = response()->json($responseBody);
            if ($persistedId) {
                $resp->header('x-mirror-conversation-id', $persistedId);
            }
            $this->dispatchCompletionSuccess($requestId, $endpointName, $requestedModel, false, $persistedId, $token->id ?? null, $promptText, $fullText, $startedAt, $authContext);
            return $resp->header('x-request-id', $requestId);
        } catch (UpstreamRequestException $e) {
            if ($rebaseState !== null && $activeConv !== null) {
                $this->restoreRebaseBackup($activeConv, $rebaseState);
            }
            $status = $e->getCode() >= 400 ? $e->getCode() : 502;
            if ($status === 429) {
                $this->pool->putInCooldown($token);
            }
            $cat = $this->failures->classifyProtocolFailure($e);
            $this->failures->record('upstream_error_' . $status, $requestId, $cat);
            $this->dispatchCompletionFailure($requestId, $endpointName, $requestedModel, false, $status, $cat, $e->getMessage(), $startedAt, $authContext);
            return response()->json($this->security->apiErrorEnvelope($status, $e->getMessage(), $requestId), $status);
        } catch (\Throwable $e) {
            if ($rebaseState !== null && $activeConv !== null) {
                $this->restoreRebaseBackup($activeConv, $rebaseState);
            }
            $cat = $this->failures->classifyProtocolFailure($e);
            $this->failures->record('internal_error', $requestId, $cat);
            $this->dispatchCompletionFailure($requestId, $endpointName, $requestedModel, false, 502, $cat, $e->getMessage(), $startedAt, $authContext);
            return response()->json($this->security->apiErrorEnvelope(502, $e->getMessage(), $requestId), 502);
        } finally {
            $this->pool->release($token, $success);
        }
    }

    /**
     * Execute Chat Completions client-owned tool calling (`tools` / `role=tool`) in both sync & SSE stream mode.
     *
     * @param array<int, array<string, mixed>> $rawMessages
     * @param array{enabled: bool, mode: string, forcedName: ?string, tools: array<int, array<string, mixed>>} $toolSpec
     * @param array<string, mixed> $route
     */
    protected function handleToolBridgeCompletion(
        array $rawMessages,
        array $toolSpec,
        array $route,
        string $requestedModel,
        bool $stream,
        ?string $turnstileToken,
        string $requestId,
        float $startedAt = 0.0,
        mixed $authContext = null
    ): Response {
        $startedAt = $startedAt > 0.0 ? $startedAt : microtime(true);
        $accountId = $this->pool->primaryAccountId();
        $token = $this->pool->acquireForAccount(null, $accountId);
        if (!$token) {
            $this->failures->record('token_pool_exhausted', $requestId, 'no_healthy_token');
            $this->dispatchCompletionFailure($requestId, 'tool_bridge', $requestedModel, $stream, 503, 'no_healthy_token', 'No active ChatGPT accounts available in token pool.', $startedAt, $authContext);
            return response()->json(
                $this->security->apiErrorEnvelope(503, 'No active ChatGPT accounts available in token pool.', $requestId, 'token_pool_exhausted'),
                503
            );
        }

        $prompt = $this->toolBridge->buildToolBridgePrompt($rawMessages, $toolSpec);
        $payload = $this->client->buildConversationPayload(
            [['role' => 'user', 'content' => $prompt]],
            (string) ($route['model'] ?? $requestedModel),
            null,
            'client-created-root',
            $route['gizmoId'] ?? null,
            true,
            []
        );

        $chatId = 'chatcmpl-' . Str::random(24);
        $created = time();

        if ($stream) {
            $client = $this->client;
            $transformer = $this->transformer;
            $pool = $this->pool;
            $toolBridge = $this->toolBridge;
            $failures = $this->failures;

            $transformer->reset();

            return response()->stream(function () use (
                $token,
                $payload,
                $requestedModel,
                $chatId,
                $created,
                $toolSpec,
                $turnstileToken,
                $requestId,
                $prompt,
                $startedAt,
                $authContext,
                $client,
                $transformer,
                $pool,
                $toolBridge,
                $failures
            ): void {
                if (!app()->runningUnitTests()) {
                    $execTime = max(0, (int) config('mirror.server.stream_max_execution_time', 0));
                    if (function_exists('set_time_limit')) {
                        @set_time_limit($execTime);
                    }
                    if (config('mirror.server.disable_output_compression', true)) {
                        @ini_set('zlib.output_compression', '0');
                    }
                }

                $openChunk = [
                    'id' => $chatId,
                    'object' => 'chat.completion.chunk',
                    'created' => $created,
                    'model' => $requestedModel,
                    'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => ''], 'finish_reason' => null]],
                ];
                echo 'data: ' . json_encode($openChunk, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
                $this->flushBuffers();

                $success = false;
                try {
                    $client->streamConversation($token, $payload, function (string $eventName, string $json) use ($transformer, $requestedModel, $chatId): void {
                        $transformer->processEventPayload($eventName, $json, $requestedModel, $chatId);
                    }, $turnstileToken);

                    $fullText = $transformer->getAccumulatedText();
                    $parsed = $toolBridge->parseToolBridgeResult($fullText, $toolSpec);
                    $success = true;

                    $finishReason = $parsed['toolCalls'] !== null ? 'tool_calls' : 'stop';
                    $deltaPayload = $parsed['toolCalls'] !== null
                        ? [
                            'tool_calls' => array_map(
                                static fn (array $call, int $idx): array => array_merge(['index' => $idx], $call),
                                $parsed['toolCalls'],
                                array_keys($parsed['toolCalls'])
                            ),
                        ]
                        : ['content' => (string) ($parsed['content'] ?? '')];

                    echo 'data: ' . json_encode([
                        'id' => $chatId,
                        'object' => 'chat.completion.chunk',
                        'created' => $created,
                        'model' => $requestedModel,
                        'choices' => [['index' => 0, 'delta' => $deltaPayload, 'finish_reason' => null]],
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";

                    echo 'data: ' . json_encode([
                        'id' => $chatId,
                        'object' => 'chat.completion.chunk',
                        'created' => $created,
                        'model' => $requestedModel,
                        'choices' => [['index' => 0, 'delta' => new \stdClass(), 'finish_reason' => $finishReason]],
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
                    echo "data: [DONE]\n\n";
                    $this->flushBuffers();
                    $this->dispatchCompletionSuccess($requestId, 'tool_bridge', $requestedModel, true, null, $token->id ?? null, $prompt, $fullText, $startedAt, $authContext);
                } catch (\Throwable $e) {
                    if ($e instanceof UpstreamRequestException && $e->getCode() === 429) {
                        $pool->putInCooldown($token);
                    }
                    $cat = $failures->classifyProtocolFailure($e);
                    $failures->record('tool_bridge_stream_error', $requestId, $cat);
                    $this->dispatchCompletionFailure($requestId, 'tool_bridge', $requestedModel, true, $e instanceof UpstreamRequestException && $e->getCode() >= 400 ? $e->getCode() : 502, $cat, $e->getMessage(), $startedAt, $authContext);
                    echo 'data: ' . json_encode(['error' => ['message' => $e->getMessage(), 'type' => 'upstream_error']]) . "\n\n";
                    echo "data: [DONE]\n\n";
                    $this->flushBuffers();
                } finally {
                    $pool->release($token, $success);
                }
            }, 200, $this->security->streamingHeaders($requestId));
        }

        $this->transformer->reset();
        $success = false;
        try {
            $this->client->streamConversation($token, $payload, function (string $eventName, string $json) use ($requestedModel, $chatId): void {
                $this->transformer->processEventPayload($eventName, $json, $requestedModel, $chatId);
            }, $turnstileToken);

            $fullText = $this->transformer->getAccumulatedText();
            $parsed = $this->toolBridge->parseToolBridgeResult($fullText, $toolSpec);
            $success = true;

            $messagePayload = $parsed['toolCalls'] !== null
                ? ['role' => 'assistant', 'content' => null, 'tool_calls' => $parsed['toolCalls']]
                : ['role' => 'assistant', 'content' => (string) ($parsed['content'] ?? '')];

            $this->dispatchCompletionSuccess($requestId, 'tool_bridge', $requestedModel, false, null, $token->id ?? null, $prompt, $fullText, $startedAt, $authContext);

            return response()->json([
                'id' => $chatId,
                'object' => 'chat.completion',
                'created' => $created,
                'model' => $requestedModel,
                'choices' => [[
                    'index' => 0,
                    'message' => $messagePayload,
                    'finish_reason' => $parsed['toolCalls'] !== null ? 'tool_calls' : 'stop',
                ]],
                'usage' => null,
            ])->header('x-request-id', $requestId);
        } catch (UpstreamRequestException $e) {
            $status = $e->getCode() >= 400 ? $e->getCode() : 502;
            if ($status === 429) {
                $this->pool->putInCooldown($token);
            }
            $cat = $this->failures->classifyProtocolFailure($e);
            $this->failures->record('tool_bridge_upstream_' . $status, $requestId, $cat);
            $this->dispatchCompletionFailure($requestId, 'tool_bridge', $requestedModel, false, $status, $cat, $e->getMessage(), $startedAt, $authContext);
            return response()->json($this->security->apiErrorEnvelope($status, $e->getMessage(), $requestId), $status);
        } catch (\Throwable $e) {
            $cat = $this->failures->classifyProtocolFailure($e);
            $this->failures->record('tool_bridge_error', $requestId, $cat);
            $this->dispatchCompletionFailure($requestId, 'tool_bridge', $requestedModel, false, 502, $cat, $e->getMessage(), $startedAt, $authContext);
            return response()->json($this->security->apiErrorEnvelope(502, $e->getMessage(), $requestId), 502);
        } finally {
            $this->pool->release($token, $success);
        }
    }

    /**
     * Persist canonical OpenAI conversation turns and update transcript/context hashes
     * consistently across sync and stream completions.
     *
     * @param array<string, mixed> $route
     * @param array<int, array{role: string, content: string}> $normalizedMessages
     * @param array<int, array<string, mixed>> $capturedEvents
     * @param array<int, array<string, mixed>> $attachments
     */
    protected function persistCompletedTurn(
        string $accountId,
        ?MirrorConversation $activeConv,
        string $explicitConvId,
        array $route,
        bool $privateMode,
        array $normalizedMessages,
        string $assistantText,
        ?string $upstreamConvId,
        ?string $userNodeId,
        ?string $assistantNodeId,
        array $capturedEvents,
        array $attachments,
        bool $continuing
    ): string {
        $instructions = $this->conversations->extractInstructionMessages($normalizedMessages);
        $contextHash = $this->conversations->fingerprintValue($instructions);
        $canonicalConv = $this->conversations->conversationalMessages($normalizedMessages);
        $fullTranscript = array_merge($instructions, $canonicalConv, [['role' => 'assistant', 'content' => $assistantText]]);
        $transcriptHash = $this->conversations->fingerprintValue($fullTranscript);

        $conv = $activeConv ?: $this->conversations->createConversation(
            $accountId,
            (string) ($route['model'] ?? 'auto'),
            $route['gizmoId'] ?? null,
            $explicitConvId !== '' ? $explicitConvId : null,
            $privateMode,
            Str::limit($this->lastUserPrompt($normalizedMessages), 60)
        );

        if ($upstreamConvId && !$conv->upstream_conversation_id) {
            $conv->update(['upstream_conversation_id' => $upstreamConvId, 'conversation_id' => $upstreamConvId]);
        }

        if (!$continuing && count($canonicalConv) > 1) {
            $lastUserIdx = null;
            foreach ($canonicalConv as $i => $m) {
                if ($m['role'] === 'user') {
                    $lastUserIdx = $i;
                }
            }

            $rows = [];
            foreach ($canonicalConv as $i => $m) {
                $row = ['role' => $m['role'], 'content' => $m['content'], 'status' => 'done'];
                if ($i === $lastUserIdx) {
                    $row['upstream_node_id'] = $userNodeId;
                    $row['attachments_payload'] = $attachments;
                }
                $rows[] = $row;
            }
            $rows[] = [
                'role' => 'assistant',
                'content' => $assistantText,
                'upstream_node_id' => $assistantNodeId,
                'status' => 'done',
                'events_payload' => $capturedEvents,
            ];

            $this->conversations->replaceMessages($conv, $rows);
            $conv->update([
                'instructions_payload' => $instructions,
                'openai_context_hash' => $contextHash,
                'openai_transcript_hash' => $transcriptHash,
                'current_node_id' => $assistantNodeId ?: $conv->current_node_id,
                'last_active_at' => now(),
            ]);
        } else {
            $this->conversations->recordTurnMessages(
                $conv,
                $this->lastUserPrompt($normalizedMessages),
                $assistantText,
                $userNodeId,
                $assistantNodeId,
                $capturedEvents,
                $attachments,
                $instructions,
                $contextHash,
                $transcriptHash
            );
        }

        return (string) $conv->public_id;
    }

    /**
     * @param array<string, mixed> $route
     */
    protected function isCompatibleCandidate(MirrorConversation $candidate, array $route): bool
    {
        if (array_key_exists('gizmoId', $route) && ($route['gizmoId'] ?? null) !== $candidate->gizmo_id) {
            return false;
        }
        $routedModel = (string) ($route['model'] ?? 'auto');
        if ($routedModel !== '' && $routedModel !== 'auto' && $routedModel !== $candidate->model) {
            return false;
        }
        if (array_key_exists('private', $route) && $route['private'] !== null && (bool) $route['private'] !== (bool) $candidate->is_private) {
            return false;
        }

        return true;
    }

    /**
     * @param array{backupMessages: array<int, array<string, mixed>>, backupConv: array<string, mixed>} $rebaseState
     */
    protected function restoreRebaseBackup(MirrorConversation $conv, array $rebaseState): void
    {
        $conv->update($rebaseState['backupConv']);
        $this->conversations->replaceMessages($conv, $rebaseState['backupMessages']);
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

    protected function dispatchCompletionSuccess(
        string $requestId,
        string $endpoint,
        string $model,
        bool $stream,
        ?string $conversationId,
        ?int $tokenId,
        string $promptText,
        string $completionText,
        float $startedAt,
        mixed $authContext
    ): void {
        if (!function_exists('event')) {
            return;
        }

        $promptTokens = (int) ceil(strlen($promptText) / 4);
        $completionTokens = (int) ceil(strlen($completionText) / 4);
        $durationMs = round(max(0.0, (microtime(true) - $startedAt) * 1000), 2);

        try {
            event(new MirrorCompletionCompleted(
                $requestId,
                $endpoint,
                $model,
                $stream,
                $conversationId,
                $tokenId,
                $promptTokens,
                $completionTokens,
                $promptTokens + $completionTokens,
                $durationMs,
                $authContext
            ));
        } catch (\Throwable) {
            // Listener exceptions must never tear down completed responses or SSE streams.
        }
    }

    protected function dispatchCompletionFailure(
        string $requestId,
        string $endpoint,
        string $model,
        bool $stream,
        int $statusCode,
        string $category,
        string $errorMessage,
        float $startedAt,
        mixed $authContext
    ): void {
        if (!function_exists('event')) {
            return;
        }

        $durationMs = round(max(0.0, (microtime(true) - $startedAt) * 1000), 2);

        try {
            event(new MirrorCompletionFailed(
                $requestId,
                $endpoint,
                $model,
                $stream,
                $statusCode,
                $category,
                $errorMessage,
                $durationMs,
                $authContext
            ));
        } catch (\Throwable) {
        }
    }

    protected function flushBuffers(): void
    {
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        @flush();
    }
}
