<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Str;
use Refatbd\LaravelChatGptMirror\Exceptions\UpstreamRequestException;
use Refatbd\LaravelChatGptMirror\Models\MirrorToken;

/**
 * Communicates with ChatGPT Web private backend APIs using Sentinel PoW,
 * Conduit tokens, v1 SSE streaming, model/gizmo discovery, and file/asset APIs.
 */
class ChatGptClient
{
    protected Client $http;
    protected ProofOfWorkSolver $powSolver;

    public function __construct(?ProofOfWorkSolver $powSolver = null)
    {
        $this->powSolver = $powSolver ?? new ProofOfWorkSolver();
        $this->http = new Client([
            'base_uri' => rtrim((string) config('mirror.upstream', 'https://chatgpt.com'), '/'),
            'timeout' => (float) config('mirror.http.timeout', 120),
            'connect_timeout' => (float) config('mirror.http.connect_timeout', 15),
            'verify' => (bool) config('mirror.http.verify_ssl', true),
            'proxy' => config('mirror.http.proxy'),
            'http_errors' => false,
        ]);
    }

    /**
     * Route a requested model slug or Gizmo/Project ID (`g-...` / `g-p-...`).
     *
     * @param array<string, mixed> $metadata
     * @return array{model: string, gizmoId: ?string, private: ?bool}
     */
    public function routeModel(string $model, array $metadata = []): array
    {
        $override = isset($metadata['mirror_model']) && is_string($metadata['mirror_model'])
            ? trim($metadata['mirror_model'])
            : '';
        $privateMode = isset($metadata['private'])
            ? ($metadata['private'] === true || $metadata['private'] === 'true')
            : null;

        if (str_starts_with($model, 'g-')) {
            return [
                'model' => $override !== '' ? $override : 'auto',
                'gizmoId' => $model,
                'private' => $privateMode,
            ];
        }

        return [
            'model' => $override !== '' ? $override : $this->mapModel($model),
            'gizmoId' => null,
            'private' => $privateMode,
        ];
    }

    /**
     * Map legacy OpenAI aliases cleanly while preserving modern ChatGPT slugs.
     */
    public function mapModel(string $model): string
    {
        return match ($model) {
            'gpt-3.5-turbo', 'gpt-3.5-turbo-16k' => 'auto',
            'gpt-4-turbo', 'gpt-4-turbo-preview' => 'gpt-4',
            default => $model !== '' ? $model : 'auto',
        };
    }

    /**
     * Build the upstream `/backend-api/f/conversation` payload from structured prompt or OpenAI messages.
     *
     * @param array<int, array<string, mixed>> $messages
     * @param array<int, array<string, mixed>> $attachments
     * @return array<string, mixed>
     */
    public function buildConversationPayload(
        array $messages,
        string $model,
        ?string $conversationId = null,
        ?string $parentMessageId = null,
        ?string $gizmoId = null,
        bool $privateChat = false,
        array $attachments = [],
        string $timezone = 'UTC',
        int $timezoneOffsetMin = 0
    ): array {
        $prompt = $this->synthesizePrompt($messages);
        $msgId = (string) Str::uuid();
        $parentId = $parentMessageId ?: 'client-created-root';
        $parts = [$prompt];

        $attachmentMeta = [];
        $contentParts = [];
        foreach ($attachments as $att) {
            $attachmentMeta[] = [
                'id' => $att['fileId'],
                'name' => $att['fileName'],
                'size' => $att['fileSize'] ?? 0,
                'mime_type' => $att['mimeType'] ?? 'application/octet-stream',
            ];
            if (($att['useCase'] ?? '') === 'multimodal') {
                $contentParts[] = [
                    'content_type' => 'image_asset_pointer',
                    'asset_pointer' => 'file-service://' . $att['fileId'],
                    'size_bytes' => $att['fileSize'] ?? 0,
                    'width' => $att['width'] ?? null,
                    'height' => $att['height'] ?? null,
                ];
            }
        }

        $content = $contentParts !== []
            ? ['content_type' => 'multimodal_text', 'parts' => array_merge($contentParts, [$prompt])]
            : ['content_type' => 'text', 'parts' => $parts];

        $metadata = $attachmentMeta !== []
            ? [
                'serialization_metadata' => ['custom_symbol_offsets' => []],
                'attachments' => $attachmentMeta,
            ]
            : new \stdClass();

        $messageObj = [
            'id' => $msgId,
            'author' => ['role' => 'user'],
            'create_time' => microtime(true),
            'content' => $content,
            'metadata' => $metadata,
        ];

        $conversationMode = ['kind' => 'primary_assistant'];
        if ($gizmoId) {
            $conversationMode = [
                'kind' => 'gizmo_interaction',
                'gizmo_id' => $gizmoId,
            ];
        }

        $firstTurn = !$conversationId;
        $payload = [
            'action' => 'next',
            'messages' => [$messageObj],
            'parent_message_id' => $parentId,
            'model' => $this->mapModel($model),
            'client_prepare_state' => $firstTurn ? 'none' : 'success',
            'timezone_offset_min' => $timezoneOffsetMin,
            'timezone' => $timezone,
            'conversation_mode' => $conversationMode,
            'enable_message_followups' => true,
            'system_hints' => [],
            'model_response_contracts' => [
                [
                    'id' => 'photo_upload_action.v1',
                    'protocol_version' => 1,
                    'presets' => ['cap:image', 'cap:file', 'placement:end'],
                ],
            ],
            'supports_buffering' => true,
            'supported_encodings' => ['v1'],
            'client_contextual_info' => [
                'app_name' => 'chatgpt.com',
                'has_web_push_capabilities' => false,
                'web_push_notification_permission' => 'default',
            ],
            'local_function_names' => ['local.continue_in_work'],
            'paragen_cot_summary_display_override' => 'allow',
            'force_parallel_switch' => 'auto',
        ];

        if ($privateChat) {
            $payload['history_and_training_disabled'] = true;
        }
        if ($conversationId) {
            $payload['conversation_id'] = $conversationId;
        }

        return $payload;
    }

    /**
     * Synthesize multi-message OpenAI history cleanly for single-turn or first ChatGPT Web turn.
     *
     * @param array<int, array<string, mixed>> $messages
     */
    protected function synthesizePrompt(array $messages): string
    {
        if (count($messages) === 1 && ($messages[0]['role'] ?? 'user') === 'user') {
            return (string) ($messages[0]['content'] ?? '');
        }

        $system = [];
        $conv = [];

        foreach ($messages as $m) {
            $role = (string) ($m['role'] ?? 'user');
            $content = (string) ($m['content'] ?? '');
            if ($role === 'system' || $role === 'developer') {
                $system[] = $content;
            } else {
                $conv[] = strtoupper($role) . ': ' . $content;
            }
        }

        $segments = [];
        if ($system !== []) {
            $segments[] = "Instructions:\n" . implode("\n", $system);
        }
        if ($conv !== []) {
            $segments[] = "Conversation context:\n\n" . implode("\n\n", $conv);
        }

        return implode("\n\n", $segments);
    }

    /**
     * Run Sentinel chat-requirements prepare -> PoW -> finalize handshake.
     *
     * @return array{
     *   chatRequirementsToken: ?string,
     *   proofToken: ?string,
     *   turnstileToken: ?string
     * }
     */
    public function sentinelHandshake(MirrorToken $token, ?string $turnstileOverride = null): array
    {
        $prepareRes = $this->http->post('/backend-api/sentinel/chat-requirements/prepare', [
            'headers' => $this->commonHeaders($token, ['Content-Type' => 'application/json']),
            'json' => ['p' => ''],
        ]);

        if ($prepareRes->getStatusCode() >= 400) {
            throw UpstreamRequestException::fromStatus(
                $prepareRes->getStatusCode(),
                'Sentinel prepare failed: ' . substr((string) $prepareRes->getBody(), 0, 300)
            );
        }

        $prepareData = json_decode((string) $prepareRes->getBody(), true) ?: [];
        $prepareToken = $prepareData['prepare_token'] ?? null;

        $pow = is_array($prepareData['proofofwork'] ?? null) ? $prepareData['proofofwork'] : [];
        $powRequired = (bool) ($pow['required'] ?? false);
        $proofToken = null;

        if ($powRequired) {
            $dxConfig = $this->powSolver->decodeProofConfig(is_string($pow['dx'] ?? null) ? $pow['dx'] : null);
            $proofToken = $this->powSolver->generateProofToken(
                true,
                (string) ($pow['seed'] ?? ''),
                (string) ($pow['difficulty'] ?? ''),
                (string) config('mirror.http.user_agent'),
                $dxConfig
            );
        }

        $turnstileToken = $turnstileOverride ?: $token->getTurnstileToken();

        if ($prepareToken) {
            $payload = [
                'prepare_token' => $prepareToken,
                'proofofwork' => $proofToken,
                'turnstile' => $turnstileToken ?: null,
            ];

            $finalizeRes = $this->http->post('/backend-api/sentinel/chat-requirements/finalize', [
                'headers' => $this->commonHeaders($token, ['Content-Type' => 'application/json']),
                'json' => $payload,
            ]);

            if ($finalizeRes->getStatusCode() >= 400) {
                throw UpstreamRequestException::fromStatus(
                    $finalizeRes->getStatusCode(),
                    'Sentinel finalize failed: ' . substr((string) $finalizeRes->getBody(), 0, 300)
                );
            }

            $finData = json_decode((string) $finalizeRes->getBody(), true) ?: [];
            $chatReqToken = $finData['token'] ?? $prepareData['token'] ?? null;
        } else {
            $chatReqToken = $prepareData['token'] ?? null;
        }

        return [
            'chatRequirementsToken' => is_string($chatReqToken) ? $chatReqToken : null,
            'proofToken' => $proofToken,
            'turnstileToken' => $turnstileToken,
        ];
    }

    /**
     * Mint per-turn `x-conduit-token` before posting follow-ups to an existing conversation.
     */
    public function prepareFollowup(
        MirrorToken $token,
        string $conversationId,
        string $parentMessageId,
        string $model,
        ?string $chatRequirementsToken,
        ?string $proofToken
    ): ?string {
        try {
            $headers = $this->commonHeaders($token, ['Content-Type' => 'application/json']);
            if ($chatRequirementsToken) {
                $headers['OpenAI-Sentinel-Chat-Requirements-Token'] = $chatRequirementsToken;
            }
            if ($proofToken) {
                $headers['OpenAI-Sentinel-Proof-Token'] = $proofToken;
            }

            $res = $this->http->post('/backend-api/f/conversation/prepare', [
                'headers' => $headers,
                'json' => [
                    'conversation_id' => $conversationId,
                    'parent_message_id' => $parentMessageId,
                    'model' => $this->mapModel($model),
                    'action' => 'next',
                ],
            ]);

            if ($res->getStatusCode() >= 400) {
                return null;
            }

            $data = json_decode((string) $res->getBody(), true);
            return is_string($data['conduit_token'] ?? null) ? $data['conduit_token'] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Initialize upstream conversation metadata (/backend-api/f/conversation/init).
     *
     * @param array<string, mixed> $opts
     * @return array<string, mixed>
     */
    public function initConversation(MirrorToken $token, array $opts = []): array
    {
        try {
            $res = $this->http->post('/backend-api/f/conversation/init', [
                'headers' => $this->commonHeaders($token, ['Content-Type' => 'application/json']),
                'json' => [
                    'timezone' => $opts['timezone'] ?? 'UTC',
                    'timezone_offset_min' => $opts['timezoneOffsetMin'] ?? 0,
                    'gizmo_id' => $opts['gizmoId'] ?? null,
                    'requested_default_model' => ($opts['requestedModel'] ?? null) !== 'auto' ? ($opts['requestedModel'] ?? null) : null,
                    'conversation_id' => $opts['conversationId'] ?? null,
                    'history_and_training_disabled' => (bool) ($opts['historyAndTrainingDisabled'] ?? false),
                ],
            ]);

            if ($res->getStatusCode() >= 400) {
                return [];
            }

            return json_decode((string) $res->getBody(), true) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Legacy streamConversation entry point kept compatible while executing full Sentinel + /f/conversation flow.
     *
     * @param callable(string, string): void $onChunk Receives (eventName, jsonDataPayload)
     */
    public function streamConversation(MirrorToken $token, array $payload, callable $onChunk, ?string $turnstileOverride = null): void
    {
        $sentinel = $this->sentinelHandshake($token, $turnstileOverride);

        $headers = $this->commonHeaders($token, [
            'Accept' => 'text/event-stream',
            'Content-Type' => 'application/json',
            'x-oai-turn-trace-id' => (string) Str::uuid(),
        ]);

        if ($sentinel['chatRequirementsToken']) {
            $headers['OpenAI-Sentinel-Chat-Requirements-Token'] = $sentinel['chatRequirementsToken'];
        }
        if ($sentinel['proofToken']) {
            $headers['OpenAI-Sentinel-Proof-Token'] = $sentinel['proofToken'];
        }
        if ($sentinel['turnstileToken']) {
            $headers['OpenAI-Sentinel-Turnstile-Token'] = $sentinel['turnstileToken'];
        }

        if (!empty($payload['conversation_id']) && !empty($payload['parent_message_id']) && $payload['parent_message_id'] !== 'client-created-root') {
            $conduit = $this->prepareFollowup(
                $token,
                (string) $payload['conversation_id'],
                (string) $payload['parent_message_id'],
                (string) ($payload['model'] ?? 'auto'),
                $sentinel['chatRequirementsToken'],
                $sentinel['proofToken']
            );
            if ($conduit) {
                $headers['x-conduit-token'] = $conduit;
            }
        }

        try {
            $response = $this->http->post('/backend-api/f/conversation', [
                'headers' => $headers,
                'json' => $payload,
                'stream' => true,
            ]);

            $status = $response->getStatusCode();
            if ($status >= 400) {
                $errorBody = (string) $response->getBody();
                throw UpstreamRequestException::fromStatus($status, $errorBody);
            }

            $body = $response->getBody();
            $buffer = '';
            $currentEvent = 'message';

            while (!$body->eof()) {
                $buffer .= $body->read(4096);

                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = rtrim(substr($buffer, 0, $pos), "\r");
                    $buffer = substr($buffer, $pos + 1);

                    if ($line === '') {
                        $currentEvent = 'message';
                        continue;
                    }
                    if (str_starts_with($line, 'event:')) {
                        $currentEvent = trim(substr($line, 6));
                        continue;
                    }
                    if (str_starts_with($line, 'data:')) {
                        $data = trim(substr($line, 5));
                        if ($data === '[DONE]') {
                            return;
                        }
                        if ($data !== '') {
                            $onChunk($currentEvent, $data);
                        }
                    }
                }
            }

            if (trim($buffer) !== '') {
                foreach (preg_split('/\r?\n/', $buffer) ?: [] as $line) {
                    $trimmed = trim($line);
                    if (str_starts_with($trimmed, 'data:')) {
                        $data = trim(substr($trimmed, 5));
                        if ($data !== '' && $data !== '[DONE]') {
                            $onChunk($currentEvent, $data);
                        }
                    }
                }
            }
        } catch (GuzzleException $e) {
            throw new UpstreamRequestException('Upstream HTTP transport error: ' . $e->getMessage(), 502, null, $e);
        }
    }

    /**
     * Fetch normalized ChatGPT models from /backend-api/models.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getModels(?MirrorToken $token = null): array
    {
        if ($token === null) {
            return $this->fallbackModels();
        }

        try {
            $res = $this->http->get('/backend-api/models', [
                'headers' => $this->commonHeaders($token),
            ]);
            if ($res->getStatusCode() !== 200) {
                return $this->fallbackModels();
            }

            $data = json_decode((string) $res->getBody(), true);
            $rawModels = is_array($data['models'] ?? null) ? $data['models'] : [];
            if ($rawModels === []) {
                return $this->fallbackModels();
            }

            $out = [];
            foreach ($rawModels as $m) {
                if (!is_array($m) || empty($m['slug'])) {
                    continue;
                }
                $out[] = [
                    'slug' => (string) $m['slug'],
                    'title' => (string) ($m['title'] ?? $m['slug']),
                    'description' => (string) ($m['description'] ?? ''),
                    'max_tokens' => $m['max_tokens'] ?? null,
                    'tags' => is_array($m['tags'] ?? null) ? $m['tags'] : [],
                    'capabilities' => is_array($m['capabilities'] ?? null) ? $m['capabilities'] : [],
                ];
            }

            return $out !== [] ? $out : $this->fallbackModels();
        } catch (\Throwable) {
            return $this->fallbackModels();
        }
    }

    /**
     * Fetch normalized Custom GPTs & ChatGPT Projects (Snorlax).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getGpts(MirrorToken $token): array
    {
        $seen = [];
        $items = [];

        foreach ([
            '/backend-api/gizmos/bootstrap?limit=20',
            '/backend-api/gizmos/snorlax/sidebar?owned_only=false&limit=50&conversations_per_gizmo=0',
        ] as $endpoint) {
            try {
                $res = $this->http->get($endpoint, ['headers' => $this->commonHeaders($token)]);
                if ($res->getStatusCode() !== 200) {
                    continue;
                }
                $data = json_decode((string) $res->getBody(), true);
                if (!is_array($data)) {
                    continue;
                }

                $candidates = [];
                if (isset($data['categories']) && is_array($data['categories'])) {
                    foreach ($data['categories'] as $cat) {
                        foreach ($cat['gizmos'] ?? [] as $entry) {
                            $candidates[] = $entry;
                        }
                    }
                }
                foreach (['items', 'gizmos', 'cutoffs'] as $listKey) {
                    if (isset($data[$listKey]) && is_array($data[$listKey])) {
                        foreach ($data[$listKey] as $entry) {
                            $candidates[] = $entry;
                        }
                    }
                }

                foreach ($candidates as $entry) {
                    $g = $entry['resource']['gizmo'] ?? $entry['gizmo'] ?? $entry;
                    if (!is_array($g) || empty($g['id'])) {
                        continue;
                    }
                    $id = (string) $g['id'];
                    if (isset($seen[$id])) {
                        continue;
                    }
                    $seen[$id] = true;
                    $display = is_array($g['display'] ?? null) ? $g['display'] : [];
                    $items[] = [
                        'id' => $id,
                        'name' => (string) ($display['name'] ?? $g['name'] ?? $id),
                        'description' => (string) ($display['description'] ?? ''),
                        'profilePictureUrl' => $display['profile_picture_url'] ?? null,
                        'kind' => str_starts_with($id, 'g-p-') ? 'project' : 'gpt',
                    ];
                }
            } catch (\Throwable) {
            }
        }

        return $items;
    }

    /**
     * Fetch a page of upstream ChatGPT sidebar conversations.
     *
     * @return array<string, mixed>
     */
    public function fetchConversations(MirrorToken $token, int $offset = 0, int $limit = 50): array
    {
        $res = $this->http->get('/backend-api/conversations?' . http_build_query([
            'offset' => $offset,
            'limit' => $limit,
            'order' => 'updated',
        ]), [
            'headers' => $this->commonHeaders($token),
        ]);

        if ($res->getStatusCode() >= 400) {
            throw UpstreamRequestException::fromStatus($res->getStatusCode(), (string) $res->getBody());
        }

        return json_decode((string) $res->getBody(), true) ?: [];
    }

    /**
     * Fetch full upstream conversation mapping tree.
     *
     * @return array<string, mixed>
     */
    public function fetchConversation(MirrorToken $token, string $upstreamId): array
    {
        $res = $this->http->get('/backend-api/conversation/' . rawurlencode($upstreamId), [
            'headers' => $this->commonHeaders($token),
        ]);

        if ($res->getStatusCode() >= 400) {
            throw UpstreamRequestException::fromStatus($res->getStatusCode(), (string) $res->getBody());
        }

        return json_decode((string) $res->getBody(), true) ?: [];
    }

    /**
     * 3-step file upload to ChatGPT Web (/backend-api/files -> PUT upload_url -> /uploaded).
     *
     * @return array<string, mixed>
     */
    public function uploadFile(MirrorToken $token, string $bytes, string $fileName, string $mimeType): array
    {
        $size = strlen($bytes);
        $isImage = str_starts_with($mimeType, 'image/');
        $useCase = $isImage ? 'multimodal' : 'my_files';
        $width = null;
        $height = null;

        if ($isImage) {
            $imgInfo = @getimagesizefromstring($bytes);
            if (is_array($imgInfo)) {
                $width = $imgInfo[0] ?? null;
                $height = $imgInfo[1] ?? null;
            }
        }

        $initRes = $this->http->post('/backend-api/files', [
            'headers' => $this->commonHeaders($token, ['Content-Type' => 'application/json']),
            'json' => [
                'file_name' => $fileName,
                'file_size' => $size,
                'use_case' => $useCase,
                'reset_rate_limits' => false,
            ],
        ]);

        if ($initRes->getStatusCode() >= 400) {
            throw UpstreamRequestException::fromStatus($initRes->getStatusCode(), (string) $initRes->getBody());
        }

        $initData = json_decode((string) $initRes->getBody(), true) ?: [];
        $fileId = (string) ($initData['file_id'] ?? '');
        $uploadUrl = (string) ($initData['upload_url'] ?? '');
        if ($fileId === '' || $uploadUrl === '') {
            throw new UpstreamRequestException('Upstream file upload init did not return file_id/upload_url', 502);
        }

        $blobClient = new Client([
            'timeout' => (float) config('mirror.http.timeout', 120),
            'verify' => (bool) config('mirror.http.verify_ssl', true),
            'proxy' => config('mirror.http.proxy'),
            'http_errors' => false,
        ]);

        $putRes = $blobClient->put($uploadUrl, [
            'headers' => [
                'x-ms-blob-type' => 'BlockBlob',
                'Content-Type' => $mimeType,
            ],
            'body' => $bytes,
        ]);

        if ($putRes->getStatusCode() >= 400) {
            throw UpstreamRequestException::fromStatus($putRes->getStatusCode(), 'Blob upload failed');
        }

        $completeRes = $this->http->post("/backend-api/files/{$fileId}/uploaded", [
            'headers' => $this->commonHeaders($token, ['Content-Type' => 'application/json']),
            'json' => new \stdClass(),
        ]);

        if ($completeRes->getStatusCode() >= 400) {
            throw UpstreamRequestException::fromStatus($completeRes->getStatusCode(), 'File upload finalization failed');
        }

        return [
            'fileId' => $fileId,
            'fileName' => $fileName,
            'fileSize' => $size,
            'mimeType' => $mimeType,
            'useCase' => $useCase,
            'width' => $width,
            'height' => $height,
            'raw' => [],
        ];
    }

    /**
     * Resolve downloadable URL & filename for file-service://, sediment://, or sandbox:/ pointers.
     *
     * @return array{download_url: string, file_name: ?string}
     */
    public function resolveAssetDownloadMetadata(
        MirrorToken $token,
        string $pointer,
        ?string $upstreamConversationId = null,
        ?string $messageId = null
    ): array {
        if (str_starts_with($pointer, 'file-service://')) {
            $fileId = substr($pointer, strlen('file-service://'));
            $res = $this->http->get('/backend-api/files/' . rawurlencode($fileId) . '/download', [
                'headers' => $this->commonHeaders($token),
            ]);
        } elseif (str_starts_with($pointer, 'sandbox:/') && $upstreamConversationId && $messageId) {
            $res = $this->http->get('/backend-api/conversation/' . rawurlencode($upstreamConversationId) . '/interpreter/download?' . http_build_query([
                'message_id' => $messageId,
                'sandbox_path' => '/' . ltrim(substr($pointer, strlen('sandbox:')), '/'),
            ]), [
                'headers' => $this->commonHeaders($token),
            ]);
        } else {
            $assetId = str_starts_with($pointer, 'sediment://')
                ? substr($pointer, strlen('sediment://'))
                : $pointer;
            $res = $this->http->get('/backend-api/files/download/' . rawurlencode($assetId) . ($upstreamConversationId ? '?' . http_build_query(['conversation_id' => $upstreamConversationId]) : ''), [
                'headers' => $this->commonHeaders($token),
            ]);
        }

        if ($res->getStatusCode() >= 400) {
            throw UpstreamRequestException::fromStatus($res->getStatusCode(), (string) $res->getBody());
        }

        $data = json_decode((string) $res->getBody(), true) ?: [];
        $downloadUrl = (string) ($data['download_url'] ?? $data['url'] ?? '');
        if ($downloadUrl === '') {
            throw new UpstreamRequestException('Upstream did not provide a download_url for pointer', 404);
        }

        return [
            'download_url' => $downloadUrl,
            'file_name' => isset($data['file_name']) ? (string) $data['file_name'] : (isset($data['filename']) ? (string) $data['filename'] : null),
        ];
    }

    /**
     * Download bytes from an upstream signed CDN/asset URL.
     *
     * @return array{body: string, contentType: string}
     */
    public function fetchAssetBytes(string $url, MirrorToken $token): array
    {
        $client = new Client([
            'timeout' => (float) config('mirror.http.timeout', 60),
            'verify' => (bool) config('mirror.http.verify_ssl', true),
            'proxy' => config('mirror.http.proxy'),
            'http_errors' => false,
        ]);

        $res = $client->get($url, [
            'headers' => [
                'User-Agent' => (string) config('mirror.http.user_agent'),
                'Accept' => '*/*',
            ],
        ]);

        if ($res->getStatusCode() >= 400) {
            throw UpstreamRequestException::fromStatus($res->getStatusCode(), 'Asset download failed');
        }

        return [
            'body' => (string) $res->getBody(),
            'contentType' => $res->getHeaderLine('Content-Type') ?: 'application/octet-stream',
        ];
    }

    /**
     * @param array<string, string> $extra
     * @return array<string, string>
     */
    public function commonHeaders(MirrorToken $token, array $extra = []): array
    {
        $upstream = rtrim((string) config('mirror.upstream', 'https://chatgpt.com'), '/');
        $headers = [
            'Authorization' => 'Bearer ' . (string) $token->access_token,
            'User-Agent' => (string) config('mirror.http.user_agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/134.0.0.0 Safari/537.36'),
            'Accept-Language' => 'en-US,en;q=0.9',
            'Origin' => $upstream,
            'Referer' => $upstream . '/',
            'oai-device-id' => $token->getDeviceId(),
            'oai-language' => 'en-US',
        ];

        if ($token->account_id) {
            $headers['ChatGPT-Account-ID'] = $token->account_id;
        }

        return array_merge($headers, $extra);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function fallbackModels(): array
    {
        return [
            ['slug' => 'auto', 'title' => 'Auto', 'description' => 'Automatic ChatGPT routing', 'max_tokens' => 128000, 'tags' => [], 'capabilities' => []],
            ['slug' => 'gpt-4o', 'title' => 'GPT-4o', 'description' => 'Omni flagship model', 'max_tokens' => 128000, 'tags' => ['multimodal'], 'capabilities' => []],
            ['slug' => 'gpt-4o-mini', 'title' => 'GPT-4o mini', 'description' => 'Fast lightweight model', 'max_tokens' => 128000, 'tags' => [], 'capabilities' => []],
            ['slug' => 'o1', 'title' => 'o1', 'description' => 'Reasoning model', 'max_tokens' => 128000, 'tags' => ['reasoning'], 'capabilities' => []],
            ['slug' => 'o3-mini', 'title' => 'o3-mini', 'description' => 'Fast reasoning model', 'max_tokens' => 128000, 'tags' => ['reasoning'], 'capabilities' => []],
        ];
    }
}
