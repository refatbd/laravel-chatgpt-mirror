<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Refatbd\LaravelChatGptMirror\Models\MirrorConversation;
use Refatbd\LaravelChatGptMirror\Models\MirrorFile;
use Refatbd\LaravelChatGptMirror\Services\AssetTicketService;
use Refatbd\LaravelChatGptMirror\Services\ChatGptClient;
use Refatbd\LaravelChatGptMirror\Services\ConversationService;
use Refatbd\LaravelChatGptMirror\Services\FailureTracker;
use Refatbd\LaravelChatGptMirror\Services\MirrorCache;
use Refatbd\LaravelChatGptMirror\Services\SecurityService;
use Refatbd\LaravelChatGptMirror\Services\StreamTransformer;
use Refatbd\LaravelChatGptMirror\Services\TokenPoolManager;
use Symfony\Component\HttpFoundation\Response;

/**
 * Implements Mirror's native Playground/UI endpoints matching the Node.js API contract:
 *  - GET /api/models, GET /api/gpts
 *  - CRUD /api/conversations, /search, /stop, /branch, /branches, /export
 *  - POST /api/chat (SSE native chat event stream)
 *  - POST /api/files, GET /api/assets, GET /api/asset-content (7-day HMAC sealed tickets)
 */
class MirrorApiController extends Controller
{
    public function __construct(
        protected TokenPoolManager $pool,
        protected ChatGptClient $client,
        protected ConversationService $conversations,
        protected StreamTransformer $transformer,
        protected AssetTicketService $tickets,
        protected FailureTracker $failures,
        protected ?SecurityService $security = null,
        protected ?MirrorCache $cache = null
    ) {
        $this->security ??= app()->bound(SecurityService::class) ? app(SecurityService::class) : new SecurityService();
        $this->cache ??= app()->bound(MirrorCache::class) ? app(MirrorCache::class) : new MirrorCache();
    }

    protected function security(): SecurityService
    {
        return $this->security ??= app()->bound(SecurityService::class) ? app(SecurityService::class) : new SecurityService();
    }

    protected function cache(): MirrorCache
    {
        return $this->cache ??= app()->bound(MirrorCache::class) ? app(MirrorCache::class) : new MirrorCache();
    }

    public function models(): Response
    {
        $token = $this->pool->acquire();
        if (!$token) {
            return response()->json(['error' => 'No active ChatGPT session token'], 503);
        }

        try {
            $ttl = max(0, (int) config('mirror.cache.models_ttl', 300));
            $cacheKey = 'models:' . $token->getEffectiveAccountId();
            $models = $ttl > 0
                ? $this->cache()->remember($cacheKey, $ttl, fn (): array => $this->client->getModels($token))
                : $this->client->getModels($token);

            return response()->json($models);
        } finally {
            $this->pool->release($token, true);
        }
    }

    public function gpts(): Response
    {
        $token = $this->pool->acquire();
        if (!$token) {
            return response()->json([]);
        }

        try {
            $ttl = max(0, (int) config('mirror.cache.models_ttl', 300));
            $cacheKey = 'gpts:' . $token->getEffectiveAccountId();
            $gpts = $ttl > 0
                ? $this->cache()->remember($cacheKey, $ttl, fn (): array => $this->client->getGpts($token))
                : $this->client->getGpts($token);

            return response()->json($gpts);
        } finally {
            $this->pool->release($token, true);
        }
    }

    public function conversations(Request $request): Response
    {
        $limit = max(1, min(200, (int) $request->query('limit', 50)));
        $offset = max(0, (int) $request->query('offset', 0));
        $sync = filter_var($request->query('sync', 'false'), FILTER_VALIDATE_BOOLEAN);
        $resync = filter_var($request->query('resync', 'false'), FILTER_VALIDATE_BOOLEAN);

        return response()->json(
            $this->conversations->listConversations($this->pool->primaryAccountId(), $limit, $offset, $sync, $resync)
        );
    }

    public function createConversation(Request $request): Response
    {
        $model = (string) ($request->input('model') ?: 'auto');
        $gizmoId = $request->input('gizmoId') ? (string) $request->input('gizmoId') : null;
        $isPrivate = filter_var($request->input('private', false), FILTER_VALIDATE_BOOLEAN);

        $conv = $this->conversations->createConversation(
            $this->pool->primaryAccountId(),
            $model,
            $gizmoId,
            null,
            $isPrivate
        );

        return response()->json($conv->toPlaygroundArray(), 201);
    }

    public function searchConversations(Request $request): Response
    {
        $q = (string) ($request->query('q') ?? $request->query('query') ?? '');
        $limit = max(1, min(100, (int) $request->query('limit', 50)));

        return response()->json(
            $this->conversations->searchConversations($this->pool->primaryAccountId(), $q, $limit)
        );
    }

    public function showConversation(string $id): Response
    {
        $detail = $this->conversations->getConversationDetail($id, $this->pool->primaryAccountId());
        if (!$detail) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }

        return response()->json([
            'conversation' => $detail['conversation']->toPlaygroundArray(),
            'messages' => $detail['messages'],
            'instructions' => $detail['instructions'],
        ]);
    }

    public function updateConversation(Request $request, string $id): Response
    {
        $conv = MirrorConversation::findByIdentifier($id);
        if (!$conv) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }

        $model = trim((string) $request->input('model', ''));
        if ($model === '') {
            return response()->json(['error' => 'model is required'], 400);
        }

        $conv->update(['model' => $model]);
        return response()->json($conv->toPlaygroundArray());
    }

    public function deleteConversation(string $id): Response
    {
        $conv = MirrorConversation::findByIdentifier($id);
        if (!$conv) {
            return response()->json(['ok' => false]);
        }

        $publicId = $conv->public_id ?: (string) $conv->id;
        $this->conversations->requestStop($publicId);
        \Refatbd\LaravelChatGptMirror\Models\MirrorMessage::where('conversation_public_id', $publicId)->delete();
        $conv->delete();

        return response()->json(['ok' => true]);
    }

    public function stopConversation(string $id): Response
    {
        $conv = MirrorConversation::findByIdentifier($id);
        if (!$conv) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }

        $publicId = $conv->public_id ?: (string) $conv->id;
        $this->conversations->requestStop($publicId);
        return response()->json(['ok' => true]);
    }

    public function branchConversation(Request $request, string $id): Response
    {
        $conv = MirrorConversation::findByIdentifier($id);
        if (!$conv) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }

        $messageId = trim((string) $request->input('messageId', ''));
        $title = $request->input('title') ? (string) $request->input('title') : null;
        if ($messageId === '') {
            return response()->json(['error' => 'messageId is required'], 400);
        }

        $branch = $this->conversations->branchConversation($conv, $messageId, $title);
        if (!$branch) {
            return response()->json(['error' => 'That message cannot be used as a branch point'], 400);
        }

        return response()->json($branch->toPlaygroundArray(), 201);
    }

    public function branches(string $id): Response
    {
        $conv = MirrorConversation::findByIdentifier($id);
        if (!$conv) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }

        return response()->json([
            'conversationId' => $conv->public_id ?: (string) $conv->id,
            'branches' => $this->conversations->listBranches($conv),
        ]);
    }

    public function exportConversation(Request $request, string $id): Response
    {
        $conv = MirrorConversation::findByIdentifier($id);
        if (!$conv) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }

        $format = strtolower((string) $request->query('format', 'json'));
        $publicId = $conv->public_id ?: (string) $conv->id;

        if ($format === 'markdown' || $format === 'md') {
            $md = $this->conversations->exportMarkdown($conv);
            return response($md, 200, [
                'Content-Type' => 'text/markdown; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="' . AssetTicketService::sanitizeDownloadFilename($conv->title ?: $publicId) . '.md"',
            ]);
        }

        $detail = $this->conversations->getConversationDetail($publicId, $this->pool->primaryAccountId());
        return response()->json($detail);
    }

    public function chat(Request $request): Response
    {
        $prompt = trim((string) $request->input('prompt', ''));
        if ($prompt === '') {
            return response()->json(['error' => 'prompt is required'], 400);
        }

        $model = (string) ($request->input('model') ?: 'auto');
        $convIdInput = $request->input('conversationId') ? trim((string) $request->input('conversationId')) : null;
        $gizmoId = $request->input('gizmoId') ? trim((string) $request->input('gizmoId')) : null;
        $timezone = (string) ($request->input('timezone') ?: 'UTC');
        $timezoneOffsetMin = (int) ($request->input('timezoneOffsetMin') ?? 0);
        $attachments = is_array($request->input('attachments')) ? $request->input('attachments') : [];
        $turnstileOverride = $request->input('turnstileToken')
            ?: $request->header('openai-sentinel-turnstile-token')
            ?: $request->header('x-turnstile-token');
        $turnstileToken = is_string($turnstileOverride) && trim($turnstileOverride) !== '' ? trim($turnstileOverride) : null;

        $accountId = $this->pool->primaryAccountId();
        foreach ($attachments as $att) {
            $fid = is_array($att) ? (string) ($att['fileId'] ?? '') : '';
            if ($fid !== '' && !MirrorFile::where('file_id', $fid)->exists()) {
                return response()->json(['error' => 'One or more attachments were not found in Mirror storage'], 404);
            }
        }

        $token = $this->pool->acquireForAccount(null, $accountId);
        if (!$token) {
            return response()->json(['error' => 'No healthy ChatGPT account available'], 503);
        }

        $conv = $convIdInput ? MirrorConversation::findByIdentifier($convIdInput) : null;
        if ($convIdInput && !$conv) {
            $this->pool->release($token, true);
            return response()->json(['error' => 'Conversation not found'], 404);
        }

        if (!$conv) {
            $conv = $this->conversations->createConversation(
                $accountId,
                $model,
                $gizmoId,
                null,
                false,
                Str::limit($prompt, 60)
            );
        }

        $publicId = $conv->public_id ?: (string) $conv->id;
        $this->conversations->clearStop($publicId);

        $client = $this->client;
        $transformer = $this->transformer;
        $conversations = $this->conversations;
        $pool = $this->pool;
        $failures = $this->failures;

        $transformer->reset();

        return response()->stream(function () use (
            $token,
            $conv,
            $publicId,
            $prompt,
            $model,
            $gizmoId,
            $timezone,
            $timezoneOffsetMin,
            $attachments,
            $turnstileToken,
            $client,
            $transformer,
            $conversations,
            $pool,
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

            $send = function (string $event, array $data): void {
                echo "event: {$event}\ndata: " . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                @flush();
            };

            $success = false;
            try {
                if (!$conv->initialized) {
                    $initData = $client->initConversation($token, [
                        'timezone' => $timezone,
                        'timezoneOffsetMin' => $timezoneOffsetMin,
                        'gizmoId' => $conv->gizmo_id ?: $gizmoId,
                        'requestedModel' => $conv->model ?: $model,
                        'conversationId' => $conv->effectiveUpstreamConversationId(),
                        'historyAndTrainingDisabled' => (bool) $conv->is_private,
                    ]);
                    $conv->update([
                        'initialized' => true,
                        'init_payload' => $initData ?: null,
                    ]);
                }

                $payload = $client->buildConversationPayload(
                    [['role' => 'user', 'content' => $prompt]],
                    $conv->model ?: $model,
                    $conv->effectiveUpstreamConversationId(),
                    $conv->current_node_id ?: 'client-created-root',
                    $conv->gizmo_id ?: $gizmoId,
                    (bool) $conv->is_private,
                    $attachments,
                    $timezone,
                    $timezoneOffsetMin
                );

                $client->streamConversation($token, $payload, function (string $evName, string $json) use (
                    $transformer,
                    $conv,
                    $publicId,
                    $conversations,
                    $send
                ): void {
                    if ($conversations->isStopRequested($publicId)) {
                        throw new \RuntimeException('Generation stopped');
                    }

                    $events = $transformer->processEventPayload($evName, $json, $conv->model ?: 'auto', 'chat-' . $publicId);
                    foreach ($events as $ev) {
                        $kind = (string) ($ev['kind'] ?? '');
                        if ($kind === 'assistant_text_delta' && !empty($ev['delta'])) {
                            $send('delta', ['delta' => (string) $ev['delta']]);
                        } elseif (!empty($ev['displayHidden']) === false && !in_array($kind, ['raw', 'assistant_text', 'message', 'assistant_text_delta'], true)) {
                            $safe = $ev;
                            unset($safe['raw']);
                            $send('event', $safe);
                        }
                    }
                }, $turnstileToken);

                $success = true;
                $fullText = $transformer->getAccumulatedText();
                $upstreamId = $transformer->getConversationId() ?? $conv->effectiveUpstreamConversationId();
                $assistantNodeId = $transformer->getAssistantMessageId();
                $userNodeId = $transformer->getUserMessageId();

                if ($upstreamId && !$conv->upstream_conversation_id) {
                    $conv->update([
                        'upstream_conversation_id' => $upstreamId,
                        'conversation_id' => $upstreamId,
                    ]);
                }

                $assistantMsg = $conversations->recordTurnMessages(
                    $conv,
                    $prompt,
                    $fullText,
                    $userNodeId,
                    $assistantNodeId,
                    $transformer->getCapturedEvents(),
                    $attachments
                );

                $send('done', [
                    'text' => $fullText,
                    'conversationId' => $publicId,
                    'upstreamConversationId' => $upstreamId,
                    'messageId' => $assistantNodeId,
                    'assistantMessageId' => $assistantMsg->id,
                    'model' => $conv->model,
                    'init' => $conv->init_payload,
                ]);
            } catch (\Throwable $e) {
                $failures->record('mirror_chat_error', null, $failures->classifyProtocolFailure($e));
                $send('error', ['message' => $e->getMessage()]);
            } finally {
                $conversations->clearStop($publicId);
                $pool->release($token, $success);
            }
        }, 200, $this->security()->streamingHeaders());
    }

    public function uploadFile(Request $request): Response
    {
        $file = $request->file('file');
        if (!$file || !$file->isValid()) {
            return response()->json(['error' => 'No valid file uploaded in `file` field'], 400);
        }

        $accountId = $this->pool->primaryAccountId();
        $token = $this->pool->acquireForAccount(null, $accountId);
        if (!$token) {
            return response()->json(['error' => 'No healthy ChatGPT account available'], 503);
        }

        try {
            $bytes = (string) file_get_contents($file->getRealPath());
            $fileName = $file->getClientOriginalName() ?: 'upload.bin';
            $mime = $file->getMimeType() ?: 'application/octet-stream';

            $uploaded = $this->client->uploadFile($token, $bytes, $fileName, $mime);

            $record = MirrorFile::updateOrCreate(
                ['file_id' => $uploaded['fileId']],
                [
                    'account_id' => $accountId,
                    'token_id' => $token->id,
                    'file_name' => $uploaded['fileName'],
                    'file_size' => $uploaded['fileSize'],
                    'mime_type' => $uploaded['mimeType'],
                    'use_case' => $uploaded['useCase'],
                    'width' => $uploaded['width'],
                    'height' => $uploaded['height'],
                    'raw_payload' => [],
                ]
            );

            $this->pool->release($token, true);
            return response()->json($record->toPublicArray(), 201);
        } catch (\Throwable $e) {
            $this->pool->release($token, false);
            return response()->json(['error' => $e->getMessage()], 502);
        }
    }

    public function assets(Request $request): Response
    {
        $pointer = trim((string) $request->query('pointer', ''));
        $upstreamConvId = $request->query('upstreamConversationId') ? trim((string) $request->query('upstreamConversationId')) : null;

        if (!str_starts_with($pointer, 'file-service://') && !str_starts_with($pointer, 'sediment://')) {
            return response()->json(['error' => 'Unsupported asset pointer'], 400);
        }

        $accountId = $this->pool->primaryAccountId();
        if (str_starts_with($pointer, 'file-service://')) {
            $fileId = substr($pointer, strlen('file-service://'));
            if (!MirrorFile::where('file_id', $fileId)->exists()) {
                return response()->json(['error' => 'File not found'], 404);
            }
        } elseif (!$upstreamConvId || !MirrorConversation::where('upstream_conversation_id', $upstreamConvId)->exists()) {
            return response()->json(['error' => 'Conversation asset not found'], 404);
        }

        $token = $this->pool->acquireForAccount(null, $accountId);
        if (!$token) {
            return response()->json(['error' => 'No active token'], 503);
        }

        try {
            $meta = $this->client->resolveAssetDownloadMetadata($token, $pointer, $upstreamConvId);
            $this->pool->release($token, true);
            return redirect($meta['download_url']);
        } catch (\Throwable $e) {
            $this->pool->release($token, false);
            return response()->json(['error' => $e->getMessage()], 404);
        }
    }

    public function assetContent(Request $request): Response
    {
        $ticket = (string) $request->query('ticket', '');
        $sig = (string) $request->query('sig', '');
        $forceDownload = filter_var($request->query('download', 'false'), FILTER_VALIDATE_BOOLEAN);

        $claims = $this->tickets->verifyTicket($ticket, $sig);
        if (!$claims) {
            return response()->json(['error' => 'Invalid or expired asset ticket'], 403);
        }

        $token = $this->pool->acquireForAccount(null, (string) ($claims['accountId'] ?? 'default'));
        if (!$token) {
            return response()->json(['error' => 'No active token to resolve asset'], 503);
        }

        try {
            $meta = $this->client->resolveAssetDownloadMetadata(
                $token,
                (string) $claims['pointer'],
                $claims['conversationId'] ?? null,
                $claims['messageId'] ?? null
            );

            $fetched = $this->client->fetchAssetBytes($meta['download_url'], $token);
            $this->pool->release($token, true);

            $fileName = AssetTicketService::sanitizeDownloadFilename($claims['fileName'] ?? $meta['file_name'] ?? 'asset.bin');
            $contentType = $fetched['contentType'];
            $disposition = $forceDownload ? 'attachment' : 'inline';

            $headers = [
                'Content-Type' => $contentType,
                'Content-Disposition' => "{$disposition}; filename=\"{$fileName}\"",
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, max-age=3600',
            ];

            if (stripos($contentType, 'svg') !== false || stripos($contentType, 'html') !== false) {
                $headers['Content-Security-Policy'] = "default-src 'none'; sandbox";
            }

            return response($fetched['body'], 200, $headers);
        } catch (\Throwable $e) {
            $this->pool->release($token, false);
            return response()->json(['error' => 'Asset fetch failed: ' . $e->getMessage()], 502);
        }
    }
}
