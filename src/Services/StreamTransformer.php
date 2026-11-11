<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Services;

use Refatbd\LaravelChatGptMirror\Contracts\StreamTransformerInterface;

/**
 * Stateful SSE parser & transformer supporting BOTH:
 *  - ChatGPT Web's compressed `v1` JSON-patch protocol (delta / patch / encoding)
 *  - Legacy direct-message snapshot SSE chunks
 *
 * Converts upstream SSE frames into normalized events AND OpenAI-compatible chunks
 * while suppressing internal hidden reasoning/analysis/non-visible tool messages.
 */
class StreamTransformer implements StreamTransformerInterface
{
    /** @var array<string, string> */
    protected array $messageSnapshots = [];

    protected ?string $lastMessageId = null;

    /** @var mixed */
    protected mixed $activePayload = null;

    protected ?string $conversationId = null;

    protected ?string $userMessageId = null;

    protected ?string $assistantMessageId = null;

    protected ?string $assistantStatus = null;

    protected ?string $assistantChannel = null;

    protected bool $isHiddenStream = false;

    protected string $lastPatchPath = '';

    protected string $lastPatchOp = '';

    protected ?string $resumeToken = null;

    protected ?string $errorCode = null;

    /** @var array<int, array<string, mixed>> */
    protected array $capturedEvents = [];

    public function reset(): void
    {
        $this->messageSnapshots = [];
        $this->lastMessageId = null;
        $this->activePayload = null;
        $this->conversationId = null;
        $this->userMessageId = null;
        $this->assistantMessageId = null;
        $this->assistantStatus = null;
        $this->assistantChannel = null;
        $this->isHiddenStream = false;
        $this->lastPatchPath = '';
        $this->lastPatchOp = '';
        $this->resumeToken = null;
        $this->errorCode = null;
        $this->capturedEvents = [];
    }

    /**
     * Legacy stateless single-line helper preserved for StreamTransformerInterface compatibility.
     */
    public function transformLine(string $sseLine, string $model, string $chatId): ?string
    {
        $line = trim($sseLine);
        if ($line === '' || !str_starts_with($line, 'data:')) {
            return null;
        }

        $dataStr = trim(substr($line, 5));
        if ($dataStr === '') {
            return null;
        }
        if ($dataStr === '[DONE]') {
            return $this->terminalChunk($model, $chatId);
        }

        $events = $this->processEventPayload('delta', $dataStr, $model, $chatId);
        $deltas = '';
        foreach ($events as $ev) {
            if ($ev['kind'] === 'assistant_text_delta' && !empty($ev['delta'])) {
                $deltas .= (string) $ev['delta'];
            }
        }

        if ($deltas === '') {
            return null;
        }

        return 'data: ' . json_encode([
            'id' => $chatId,
            'object' => 'chat.completion.chunk',
            'created' => time(),
            'model' => $model,
            'choices' => [
                [
                    'index' => 0,
                    'delta' => ['content' => $deltas],
                    'finish_reason' => null,
                ],
            ],
        ]) . "\n\n";
    }

    public function terminalChunk(string $model, string $chatId): string
    {
        $stopChunk = [
            'id' => $chatId,
            'object' => 'chat.completion.chunk',
            'created' => time(),
            'model' => $model,
            'choices' => [
                [
                    'index' => 0,
                    'delta' => new \stdClass(),
                    'finish_reason' => 'stop',
                ],
            ],
        ];

        return 'data: ' . json_encode($stopChunk) . "\n\n" . "data: [DONE]\n\n";
    }

    /**
     * Process a complete SSE frame (`event:` name + raw JSON `data:` payload).
     *
     * @return array<int, array<string, mixed>>
     */
    public function processEventPayload(string $eventName, string $rawJson, string $model, string $chatId): array
    {
        $data = json_decode($rawJson, true);
        if (!is_array($data)) {
            return [];
        }

        if (isset($data['conversation_id']) && is_string($data['conversation_id'])) {
            $this->conversationId = $data['conversation_id'];
        }

        // 1. Metadata envelope events
        if (($data['type'] ?? '') === 'resume_conversation_token') {
            if (!empty($data['token']) && is_string($data['token'])) {
                $this->resumeToken = $data['token'];
            }
            $this->record(['kind' => 'raw', 'raw' => $data, 'displayHidden' => false]);
            return [];
        }

        if (($data['type'] ?? '') === 'message_marker') {
            $marker = $data['marker'] ?? null;
            $event = $data['event'] ?? null;
            if ($marker === 'user_visible_token' && in_array($event, ['start', 'first'], true) && $this->assistantChannel !== 'analysis') {
                $this->isHiddenStream = false;
            }
            if (!empty($data['message_id']) && is_string($data['message_id'])) {
                $this->assistantMessageId = $data['message_id'];
            }
            $this->record([
                'kind' => 'marker',
                'messageId' => $data['message_id'] ?? $this->assistantMessageId,
                'marker' => $marker,
                'event' => $event,
                'raw' => $data,
                'displayHidden' => false,
            ]);
            return [];
        }

        if (($data['type'] ?? '') === 'title_generation' && !empty($data['title'])) {
            $ev = ['kind' => 'title', 'title' => (string) $data['title'], 'raw' => $data, 'displayHidden' => false];
            $this->record($ev);
            return [$ev];
        }

        if (($data['type'] ?? '') === 'content_references_patch') {
            $ev = [
                'kind' => 'citation_patch',
                'messageId' => isset($data['message_id']) ? (string) $data['message_id'] : $this->assistantMessageId,
                'contentReferences' => is_array($data['content_references'] ?? null) ? $data['content_references'] : [],
                'raw' => $data,
                'displayHidden' => false,
            ];
            $this->record($ev);
            return [$ev];
        }

        // 2. Patch/Delta v1 protocol: `o` (op), `p` (path), `v` (value), or nested `{v: [...], o: 'patch'}` with inheritance
        if ($this->isPatchFrame($data)) {
            return $this->applyPatchFrame($data);
        }

        // 3. Direct snapshot envelope (legacy or `{v: {message: ...}}`)
        if (isset($data['v']['message']) && is_array($data['v']['message'])) {
            $this->lastPatchPath = '';
            $this->lastPatchOp = 'add';
            $this->activePayload = $data['v'];
            if (!empty($data['v']['conversation_id']) && is_string($data['v']['conversation_id'])) {
                $this->conversationId = $data['v']['conversation_id'];
            }
            if (!empty($data['v']['error_code']) && is_string($data['v']['error_code'])) {
                $this->errorCode = $data['v']['error_code'];
            }
            return $this->inspectMessageSnapshot($data['v']['message']);
        }

        if (isset($data['message']) && is_array($data['message'])) {
            $this->activePayload = $data;
            return $this->inspectMessageSnapshot($data['message']);
        }

        return [];
    }

    public function getConversationId(): ?string
    {
        return $this->conversationId;
    }

    public function getUserMessageId(): ?string
    {
        return $this->userMessageId;
    }

    public function getAssistantMessageId(): ?string
    {
        return $this->assistantMessageId;
    }

    public function getAssistantStatus(): ?string
    {
        return $this->assistantStatus;
    }

    public function getResumeToken(): ?string
    {
        return $this->resumeToken;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getCapturedEvents(): array
    {
        return $this->capturedEvents;
    }

    public function getAccumulatedText(): string
    {
        $combined = '';
        $prevText = '';

        foreach ($this->capturedEvents as $ev) {
            if (($ev['kind'] ?? '') === 'assistant_text' && empty($ev['displayHidden'])) {
                $full = (string) ($ev['text'] ?? '');
                if ($full !== '' && $full !== $prevText) {
                    $combined = $full;
                    $prevText = $full;
                }
            }
        }

        if ($combined === '' && $this->messageSnapshots !== []) {
            return (string) end($this->messageSnapshots);
        }

        return $combined;
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function isPatchFrame(array $data): bool
    {
        if (isset($data['type']) || isset($data['message'])) {
            return false;
        }
        if (isset($data['o']) && (array_key_exists('v', $data) || isset($data['p']))) {
            return true;
        }
        if (isset($data['p']) && array_key_exists('v', $data)) {
            return true;
        }
        // Compressed v1 continuation frame omitting `p` / `o`, e.g. `{v: " text"}`, `{v: {message: ...}, c: 8}`, `{v: [{p, o, v}]}`
        if (array_key_exists('v', $data) && count($data) <= 4) {
            return is_string($data['v']) || is_array($data['v']);
        }

        return false;
    }

    /**
     * @param array<string, mixed> $frame
     * @return array<int, array<string, mixed>>
     */
    protected function applyPatchFrame(array $frame): array
    {
        $op = isset($frame['o']) && is_string($frame['o'])
            ? $frame['o']
            : ($this->lastPatchOp !== '' ? $this->lastPatchOp : 'append');
        $path = isset($frame['p']) && is_string($frame['p'])
            ? $frame['p']
            : $this->lastPatchPath;
        $value = $frame['v'] ?? null;

        // If a bare `{v: [...]}` batch arrives before `o: 'patch'` was explicitly seen, or `{v: {message: ...}}`, infer cleanly
        if (!isset($frame['o']) && is_array($value)) {
            if (isset($value['message']) && is_array($value['message'])) {
                $op = 'add';
                $path = '';
            } elseif (array_is_list($value) && isset($value[0]) && is_array($value[0]) && (isset($value[0]['p']) || isset($value[0]['o']))) {
                $op = 'patch';
            }
        }

        $this->lastPatchPath = $path;
        $this->lastPatchOp = $op;

        if ($op === 'patch' && is_array($value)) {
            $batchEmitted = [];
            foreach ($value as $subOp) {
                if (!is_array($subOp)) {
                    continue;
                }
                $subP = isset($subOp['p']) && is_string($subOp['p']) ? $subOp['p'] : '';
                $subO = isset($subOp['o']) && is_string($subOp['o']) ? $subOp['o'] : 'replace';
                $subV = $subOp['v'] ?? null;
                $batchEmitted = array_merge($batchEmitted, $this->applySingleOp($subP, $subO, $subV, $subOp, true));
            }
            if (is_array($this->activePayload['message'] ?? null)) {
                $batchEmitted = array_merge($batchEmitted, $this->inspectMessageSnapshot($this->activePayload['message']));
            }
            return $batchEmitted;
        }

        return $this->applySingleOp($path, $op, $value, $frame, false);
    }

    /**
     * @param array<string, mixed> $rawFrame
     * @return array<int, array<string, mixed>>
     */
    protected function applySingleOp(string $path, string $op, mixed $value, array $rawFrame, bool $deferSnapshotInspect): array
    {
        $emitted = [];

        if ($path === '' && ($op === 'add' || $op === 'replace') && is_array($value)) {
            $this->activePayload = $value;
            if (!empty($value['conversation_id']) && is_string($value['conversation_id'])) {
                $this->conversationId = $value['conversation_id'];
            }
            if (!empty($value['error_code']) && is_string($value['error_code'])) {
                $this->errorCode = $value['error_code'];
            }
            if (isset($value['message']) && is_array($value['message']) && !$deferSnapshotInspect) {
                return $this->inspectMessageSnapshot($value['message']);
            }
            return [];
        }

        if ($path === '/message/id' && is_string($value) && is_array($this->activePayload['message'] ?? null)) {
            $this->activePayload['message']['id'] = $value;
            if (!$deferSnapshotInspect) {
                return $this->inspectMessageSnapshot($this->activePayload['message']);
            }
            return [];
        }

        // Capture late citation patches `/message/metadata/content_references`
        if (str_contains($path, 'content_references') && is_array($value)) {
            $ev = [
                'kind' => 'citation_patch',
                'messageId' => $this->assistantMessageId,
                'contentReferences' => $value,
                'raw' => $rawFrame,
                'displayHidden' => false,
            ];
            $this->record($ev);
            $emitted[] = $ev;
        }

        if ($path === '/message/status' && is_string($value)) {
            $this->assistantStatus = $value;
            if (is_array($this->activePayload['message'] ?? null)) {
                $this->activePayload['message']['status'] = $value;
            }
            $this->record([
                'kind' => 'status',
                'messageId' => $this->assistantMessageId,
                'status' => $value,
                'displayHidden' => false,
            ]);
            return $emitted;
        }

        if (str_starts_with($path, '/message/') && is_array($this->activePayload['message'] ?? null)) {
            $this->mutateMessageByPointer(
                $this->activePayload['message'],
                substr($path, strlen('/message/')),
                $op,
                $value
            );
            if (str_ends_with($path, '/channel')) {
                $this->assistantChannel = is_string($value) ? $value : null;
            }
            if (str_ends_with($path, '/metadata/is_visually_hidden_from_conversation')) {
                $this->isHiddenStream = $op === 'remove' ? false : (bool) $value;
            }
            if ($op === 'add' && str_starts_with($path, '/message/content/parts/') && is_array($value)) {
                $special = $this->classifySpecialPart($value);
                if ($special !== null) {
                    $this->record($special);
                    $emitted[] = $special;
                }
            }
            if (!$deferSnapshotInspect) {
                $emitted = array_merge($emitted, $this->inspectMessageSnapshot($this->activePayload['message']));
            }
            return $emitted;
        }

        // Fallback append on raw parts when activePayload was not initialized with a full message skeleton
        if (($op === 'append' || $op === '') && ($path === '' || str_starts_with($path, '/message/content/parts/')) && is_string($value) && $value !== '') {
            if ($this->shouldSuppressActiveMessage()) {
                return $emitted;
            }
            $msgId = $this->assistantMessageId ?? 'v1';
            $prev = $this->messageSnapshots[$msgId] ?? '';
            $next = $prev . $value;
            $this->messageSnapshots[$msgId] = $next;

            $this->record([
                'kind' => 'assistant_text',
                'messageId' => $msgId,
                'text' => $next,
                'displayHidden' => false,
            ]);
            $emitted[] = [
                'kind' => 'assistant_text_delta',
                'messageId' => $msgId,
                'delta' => $value,
                'text' => $next,
            ];
        }

        return $emitted;
    }

    /**
     * Mutate active upstream message array using JSON-pointer segments (supporting append/replace/add/remove/truncate).
     *
     * @param array<string, mixed> $message
     */
    protected function mutateMessageByPointer(array &$message, string $relativePointer, string $op, mixed $value): void
    {
        if ($relativePointer === '') {
            return;
        }
        $rawSegments = explode('/', $relativePointer);
        $segments = array_map(
            static fn (string $seg): string => str_replace(['~1', '~0'], ['/', '~'], $seg),
            $rawSegments
        );

        $target = &$message;
        $lastIdx = count($segments) - 1;
        for ($i = 0; $i < $lastIdx; $i++) {
            $seg = $segments[$i];
            $nextSeg = $segments[$i + 1];
            if (!is_array($target)) {
                return;
            }
            if (!array_key_exists($seg, $target) || !is_array($target[$seg])) {
                $target[$seg] = ctype_digit($nextSeg) ? [] : [];
            }
            $target = &$target[$seg];
        }

        if (!is_array($target)) {
            return;
        }

        $leaf = $segments[$lastIdx];
        if ($op === 'remove') {
            if (array_is_list($target) && ctype_digit($leaf)) {
                array_splice($target, (int) $leaf, 1);
            } else {
                unset($target[$leaf]);
            }
            return;
        }

        if ($op === 'truncate' && is_string($target[$leaf] ?? null) && is_int($value)) {
            $target[$leaf] = substr($target[$leaf], 0, max(0, $value));
            return;
        }

        if ($op === 'append') {
            $existing = $target[$leaf] ?? null;
            if (is_string($existing) && is_string($value)) {
                $target[$leaf] = $existing . $value;
            } elseif (is_array($existing) && is_array($value)) {
                if (array_is_list($existing) && array_is_list($value)) {
                    $target[$leaf] = array_merge($existing, $value);
                } else {
                    $target[$leaf] = array_merge($existing, $value);
                }
            } elseif ($existing === null && is_string($value)) {
                $target[$leaf] = $value;
            } elseif ($existing === null && is_array($value)) {
                $target[$leaf] = $value;
            } else {
                $target[$leaf] = $value;
            }
            return;
        }

        if ($leaf === '-' && array_is_list($target)) {
            $target[] = $value;
        } else {
            $target[$leaf] = $value;
        }
    }

    /**
     * @param array<string, mixed> $message
     * @return array<int, array<string, mixed>>
     */
    protected function inspectMessageSnapshot(array $message): array
    {
        $emitted = [];
        $role = (string) ($message['author']['role'] ?? 'assistant');
        $messageId = isset($message['id']) ? (string) $message['id'] : null;

        if ($role === 'user' || $role === 'system') {
            if ($role === 'user' && $messageId) {
                $this->userMessageId = $messageId;
            }
            return [];
        }

        $channel = isset($message['channel']) ? (string) $message['channel'] : null;
        $recipient = isset($message['recipient']) ? (string) $message['recipient'] : 'all';
        $content = is_array($message['content'] ?? null) ? $message['content'] : [];
        $contentType = (string) ($content['content_type'] ?? 'text');
        $metadata = is_array($message['metadata'] ?? null) ? $message['metadata'] : [];
        $hidden = (bool) ($metadata['is_visually_hidden_from_conversation'] ?? false)
            || ($channel === 'analysis')
            || ($channel === 'commentary' && !in_array($contentType, ['reasoning_recap', 'summary'], true))
            || ($recipient !== 'all' && $role === 'assistant');

        if ($messageId) {
            $this->assistantMessageId = $messageId;
        }
        $this->assistantChannel = $channel;
        $this->assistantStatus = isset($message['status']) ? (string) $message['status'] : $this->assistantStatus;
        $this->isHiddenStream = $hidden;

        if ($role === 'tool') {
            $ev = [
                'kind' => 'tool',
                'name' => (string) ($message['author']['name'] ?? 'tool'),
                'messageId' => $messageId,
                'status' => $message['status'] ?? null,
                'raw' => $message,
                'displayHidden' => $hidden,
            ];
            $this->record($ev);
            $emitted[] = $ev;
            return $emitted;
        }

        if ($contentType === 'thoughts' || ($contentType === 'reasoning_recap' || $contentType === 'summary')) {
            $ev = [
                'kind' => 'message',
                'role' => $role,
                'messageId' => $messageId,
                'raw' => $message,
                'displayHidden' => $contentType === 'thoughts' || $hidden,
            ];
            $this->record($ev);
            $emitted[] = $ev;
            return $emitted;
        }

        if (!empty($metadata['content_references']) || !empty($metadata['citations'])) {
            $ev = [
                'kind' => 'message',
                'role' => $role,
                'messageId' => $messageId,
                'raw' => $message,
                'displayHidden' => $hidden,
            ];
            $this->record($ev);
        }

        if ($hidden) {
            return [];
        }

        $parts = is_array($content['parts'] ?? null) ? $content['parts'] : [];
        foreach ($parts as $part) {
            if (is_array($part)) {
                $special = $this->classifySpecialPart($part);
                if ($special !== null) {
                    $this->record($special);
                    $emitted[] = $special;
                }
                continue;
            }
            if (!is_string($part) || $part === '') {
                continue;
            }

            $key = $messageId ?: ($this->lastMessageId ?? 'default');
            $this->lastMessageId = $key;
            $previous = $this->messageSnapshots[$key] ?? '';

            if ($part === $previous) {
                continue;
            }

            $delta = ($previous !== '' && str_starts_with($part, $previous))
                ? substr($part, strlen($previous))
                : $part;

            $this->messageSnapshots[$key] = $part;

            $this->record([
                'kind' => 'assistant_text',
                'messageId' => $key,
                'text' => $part,
                'displayHidden' => false,
            ]);

            if ($delta !== '') {
                $emitted[] = [
                    'kind' => 'assistant_text_delta',
                    'messageId' => $key,
                    'delta' => $delta,
                    'text' => $part,
                ];
            }
        }

        return $emitted;
    }

    /**
     * @param array<string, mixed> $part
     * @return array<string, mixed>|null
     */
    protected function classifySpecialPart(array $part): ?array
    {
        $contentType = (string) ($part['content_type'] ?? '');
        $pointer = (string) ($part['asset_pointer'] ?? '');

        if ($pointer === '' && !empty($part['file_id'])) {
            $pointer = 'file-service://' . $part['file_id'];
        }

        if ($pointer === '') {
            return null;
        }

        $isImage = $contentType === 'image_asset_pointer' || str_starts_with($pointer, 'sediment://');
        return [
            'kind' => $isImage ? 'image' : 'file',
            'assetPointer' => $pointer,
            'title' => (string) ($part['file_name'] ?? $part['filename'] ?? $part['title'] ?? 'Download file'),
            'raw' => $part,
            'displayHidden' => false,
        ];
    }

    protected function shouldSuppressActiveMessage(): bool
    {
        if ($this->isHiddenStream) {
            return true;
        }
        if ($this->assistantChannel === 'analysis' || $this->assistantChannel === 'commentary') {
            return true;
        }
        $contentType = (string) ($this->activePayload['message']['content']['content_type'] ?? 'text');
        if ($contentType === 'thoughts') {
            return true;
        }
        $recipient = (string) ($this->activePayload['message']['recipient'] ?? 'all');
        return $recipient !== 'all';
    }

    /**
     * @param array<string, mixed> $event
     */
    protected function record(array $event): void
    {
        $this->capturedEvents[] = $event;
    }
}
